<?php

namespace plagiarism_docguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Document text extractor for PDF and DOCX files.
 *
 * PDF extraction strategy (in order):
 *   1. pdftotext CLI (poppler-utils)   — best quality
 *   2. Ghostscript CLI (gs)            — widely available on Linux/cPanel
 *   3. Pure-PHP fallback               — correct two-phase architecture:
 *        Phase A: index objects from the RAW unmodified PDF
 *        Phase B: inflate each object's stream independently
 *        Phase C: find CMap tables from inflated streams
 *        Phase D: map font resource names to their CMaps
 *        Phase E: extract text from content streams
 *
 * FIX-DG-PDF-ARCH (v1.0.54): Complete rewrite of the pure-PHP fallback.
 * Previous versions inflated streams INTO the raw PDF string and then tried
 * to parse the object graph from that corrupted mix — inflated content could
 * contain bytes resembling PDF keywords (endobj, stream, object headers),
 * which confused both the object indexer and the CMap discovery, causing the
 * font → CMap correlation to fail entirely and raw glyph-index bytes to be
 * output as ASCII garbage.  The new two-phase approach keeps object structure
 * parsing completely separate from stream inflation.
 */
class extractor {

    const SUPPORTED_MIMETYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/zip',
    ];

    const SUPPORTED_EXTENSIONS = ['pdf', 'docx', 'doc'];

    public static function is_supported(\stored_file $file): bool {
        $ext  = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $mime = strtolower($file->get_mimetype());
        if (in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
            return true;
        }
        foreach (self::SUPPORTED_MIMETYPES as $m) {
            if (strpos($mime, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function filetype(\stored_file $file): string {
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['docx', 'doc'], true)) {
            return 'docx';
        }
        $mime = strtolower($file->get_mimetype());
        if (strpos($mime, 'pdf') !== false) {
            return 'pdf';
        }
        if (strpos($mime, 'wordprocessingml') !== false || strpos($mime, 'msword') !== false) {
            return 'docx';
        }
        return 'unsupported';
    }

    public static function extract(\stored_file $file): string {
        $type = self::filetype($file);
        if ($type === 'unsupported') {
            throw new \Exception('Unsupported file type: ' . $file->get_filename());
        }

        $tmpfile = make_temp_directory('docguard') . '/' . clean_filename($file->get_filename());
        $file->copy_content_to($tmpfile);

        try {
            if ($type === 'pdf') {
                $text = self::extract_pdf($tmpfile);
            } else {
                $text = self::extract_docx($tmpfile);
            }
        } finally {
            @unlink($tmpfile);
        }

        return $text;
    }

    // ── DOCX ─────────────────────────────────────────────────────────────────

    public static function extract_docx(string $filepath): string {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('ZipArchive extension is not available.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \Exception('Cannot open DOCX file: ' . basename($filepath));
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \Exception('Not a valid DOCX file (word/document.xml not found).');
        }

        $dom = new \DOMDocument();
        @$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $ns         = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraphs = $dom->getElementsByTagNameNS($ns, 'p');
        $lines      = [];
        foreach ($paragraphs as $para) {
            $line = trim($para->textContent);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n\n", $lines);
    }

    // ── PDF top-level: try CLI tools, then PHP fallback ───────────────────────

    public static function extract_pdf(string $filepath): string {
        // 1. pdftotext (poppler-utils)
        if (self::cli_available('pdftotext')) {
            $out    = [];
            $retval = 0;
            // timeout 60: prevents corrupt/malformed PDFs hanging the cron worker indefinitely.
            exec('timeout 60 pdftotext -layout -enc UTF-8 ' . escapeshellarg($filepath) . ' - 2>/dev/null',
                 $out, $retval);
            if ($retval === 0 && !empty($out)) {
                $t = implode("\n", $out);
                if (self::looks_readable($t)) {
                    return $t;
                }
            }
        }

        // 2. Ghostscript (gs) — text device
        if (self::cli_available('gs')) {
            $tmpout = tempnam(sys_get_temp_dir(), 'dg_gs_');
            // timeout 60: gs can hang indefinitely on corrupt PDFs (confirmed incident July 2026
            // where a single bad PDF spawned 20+ stuck gs processes at 92% CPU each, load avg 47).
            $cmd    = 'timeout 60 gs -dNOPAUSE -dBATCH -dQUIET -sDEVICE=txtwrite -dNoOutputFonts'
                    . ' -sOutputFile=' . escapeshellarg($tmpout)
                    . ' ' . escapeshellarg($filepath) . ' 2>/dev/null';
            $retval = 0;
            exec($cmd, $dummy, $retval);
            if ($retval === 0 && file_exists($tmpout)) {
                $t = file_get_contents($tmpout);
                @unlink($tmpout);
                if ($t !== false && self::looks_readable($t)) {
                    return $t;
                }
            } else {
                @unlink($tmpout);
            }
        }

        // 3. Pure-PHP fallback
        return self::extract_pdf_php($filepath);
    }

    /**
     * Check whether a CLI command exists and is executable.
     */
    private static function cli_available(string $cmd): bool {
        static $cache = [];
        if (!isset($cache[$cmd])) {
            $out    = [];
            $retval = 0;
            exec('which ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $retval);
            $cache[$cmd] = ($retval === 0 && !empty($out));
        }
        return $cache[$cmd];
    }

    /**
     * Heuristic: does the extracted text look like real readable content?
     * Returns false if it appears to be mostly garbled bytes.
     */
    private static function looks_readable(string $text): bool {
        $text = trim($text);
        if (strlen($text) < 20) {
            return false;
        }
        // Count printable ASCII letters (a-z A-Z space) vs total non-whitespace chars.
        $letters = preg_match_all('/[a-zA-Z ]/', $text);
        $total   = max(1, strlen(preg_replace('/\s+/', '', $text)));
        return ($letters / $total) > 0.45;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Pure-PHP PDF extraction — two-phase architecture (FIX-DG-PDF-ARCH v1.0.54)
    // ═══════════════════════════════════════════════════════════════════════════

    public static function extract_pdf_php(string $filepath): string {
        $raw = file_get_contents($filepath);
        if ($raw === false) {
            throw new \Exception('Cannot read PDF file.');
        }

        // ── Phase A: Index object bodies from the RAW, unmodified PDF ─────────
        // CRITICAL: do NOT inflate anything here. Inflated bytes can look like
        // PDF keywords (endobj, stream, etc.) and corrupt subsequent parsing.
        $obj_bodies = [];
        if (preg_match_all(
            '/\b(\d+)\s+\d+\s+obj\b([\s\S]*?)\bendobj\b/',
            $raw, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $o) {
                $obj_bodies[(int)$o[1]] = $o[2];
            }
        }

        if (empty($obj_bodies)) {
            // Fallback: try without word boundaries (some PDFs omit whitespace).
            if (preg_match_all(
                '/(\d+)\s+\d+\s+obj([\s\S]*?)endobj/',
                $raw, $m, PREG_SET_ORDER
            )) {
                foreach ($m as $o) {
                    $obj_bodies[(int)$o[1]] = $o[2];
                }
            }
        }

        // ── Phase B: Inflate each object's stream independently ───────────────
        $obj_streams = []; // obj_num => inflated (or raw) stream bytes
        foreach ($obj_bodies as $num => $body) {
            $stream = self::extract_stream($body);
            if ($stream !== null) {
                $obj_streams[$num] = $stream;
            }
        }

        // ── Phase C: Find all ToUnicode CMap tables ───────────────────────────
        $tounicode_by_obj = []; // obj_num => [int_code => utf8_string]
        foreach ($obj_streams as $num => $stream) {
            if (strpos($stream, 'beginbfchar') !== false ||
                strpos($stream, 'beginbfrange') !== false) {
                $table = self::parse_cmap_stream($stream);
                if (!empty($table)) {
                    $tounicode_by_obj[$num] = $table;
                }
            }
        }

        // ── Phase D: Map font resource names to their CMaps ───────────────────
        $font_cmaps = []; // font_resource_name => cmap_table

        // D-1: Scan every object for /Type /Font with a /ToUnicode reference.
        $font_obj_to_cmap = []; // font_obj_num => cmap_table
        foreach ($obj_bodies as $num => $body) {
            if (strpos($body, '/ToUnicode') === false) {
                continue;
            }
            // Direct ToUnicode on this font object.
            if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $tm)) {
                $tu = (int)$tm[1];
                if (isset($tounicode_by_obj[$tu])) {
                    $font_obj_to_cmap[$num] = $tounicode_by_obj[$tu];
                }
            }
        }

        // D-2: Walk /Font resource dicts to find name → font-obj-num mapping.
        // Search both the raw PDF and every inflated content stream for /Font dicts,
        // because in some PDFs the Resources dict lives inside a content stream.
        $search_sources = [$raw];
        foreach ($obj_streams as $s) {
            $search_sources[] = $s;
        }

        foreach ($search_sources as $src) {
            // Match /Font << ... >> — allow up to 4 KB for the dict contents.
            if (preg_match_all('/\/Font\s*<<([\s\S]{1,4096}?)>>/', $src, $fdm)) {
                foreach ($fdm[1] as $font_dict) {
                    // Each entry: /ResourceName N 0 R
                    if (!preg_match_all(
                        '/\/(\w+)\s+(\d+)\s+\d+\s+R/',
                        $font_dict, $refs, PREG_SET_ORDER
                    )) {
                        continue;
                    }
                    foreach ($refs as $ref) {
                        $rname = $ref[1];
                        $fnum  = (int)$ref[2];
                        if (isset($font_obj_to_cmap[$fnum]) && !isset($font_cmaps[$rname])) {
                            $font_cmaps[$rname] = $font_obj_to_cmap[$fnum];
                        }
                        // Also check the referenced object's own body for /ToUnicode.
                        if (!isset($font_cmaps[$rname]) && isset($obj_bodies[$fnum])) {
                            $fbody = $obj_bodies[$fnum];
                            if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fbody, $tum)) {
                                $tu = (int)$tum[1];
                                if (isset($tounicode_by_obj[$tu])) {
                                    $font_cmaps[$rname] = $tounicode_by_obj[$tu];
                                }
                            }
                            // Type0: check DescendantFonts array for /ToUnicode.
                            if (!isset($font_cmaps[$rname]) &&
                                preg_match('/\/DescendantFonts\s*\[\s*(\d+)\s+\d+\s+R/', $fbody, $dm)) {
                                $dnum = (int)$dm[1];
                                if (isset($obj_bodies[$dnum]) &&
                                    preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $obj_bodies[$dnum], $tum2)) {
                                    $tu2 = (int)$tum2[1];
                                    if (isset($tounicode_by_obj[$tu2])) {
                                        $font_cmaps[$rname] = $tounicode_by_obj[$tu2];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // ── Phase E: Build union fallback CMap ────────────────────────────────
        // Used when the active font name is unknown or has no individual CMap.
        $union_cmap = [];
        foreach ($tounicode_by_obj as $cmap) {
            $union_cmap += $cmap; // first mapping wins (array union)
        }

        // ── Phase F: Extract text from content streams ────────────────────────
        $text = '';
        foreach ($obj_streams as $num => $stream) {
            // Skip streams that are clearly not page content (CMap, image data, etc.)
            if (strpos($stream, 'BT') === false && strpos($stream, 'Tj') === false &&
                strpos($stream, 'TJ') === false) {
                continue;
            }
            $text .= self::extract_from_stream($stream, $font_cmaps, $union_cmap);
        }

        // ── Phase G: Last-resort scan if nothing found ────────────────────────
        if (strlen(trim($text)) < 30) {
            foreach ($obj_streams as $stream) {
                // <hex> Tj
                if (preg_match_all('/<([0-9a-fA-F\s]{2,})>\s*Tj/', $stream, $hm)) {
                    foreach ($hm[1] as $h) {
                        $text .= self::decode_hex($h, $union_cmap) . ' ';
                    }
                }
                // (lit) Tj
                if (preg_match_all('/\(([^)]{2,})\)\s*Tj/s', $stream, $lm)) {
                    foreach ($lm[1] as $l) {
                        $text .= self::decode_literal($l, $union_cmap) . ' ';
                    }
                }
            }
        }

        // Normalise whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/(\r\n|\r|\n){3,}/', "\n\n", $text);
        return trim($text);
    }

    // ── Object / stream helpers ───────────────────────────────────────────────

    /**
     * Extract and inflate the stream from a PDF object body string.
     * Returns null if the object has no stream.
     */
    private static function extract_stream(string $obj_body): ?string {
        if (!preg_match('/\bstream\b([\s\S]*?)\bendstream\b/', $obj_body, $m)) {
            return null;
        }
        // ltrim ONE leading \r\n or \n (PDF spec: stream keyword followed by single EOL).
        $data = $m[1];
        if (substr($data, 0, 2) === "\r\n") {
            $data = substr($data, 2);
        } elseif ($data[0] === "\n" || $data[0] === "\r") {
            $data = substr($data, 1);
        }

        // Inflate FlateDecode (including when Filter is an array).
        if (preg_match('/\/Filter\s*(?:\/FlateDecode|\[[\s\S]*?\/FlateDecode[\s\S]*?\])/',
                       $obj_body)) {
            if (function_exists('gzuncompress')) {
                $inf = @gzuncompress($data);
                if ($inf === false) {
                    $inf = @gzinflate($data);
                }
                if ($inf !== false) {
                    return $inf;
                }
            }
        }

        return $data;
    }

    // ── Content stream text extraction ────────────────────────────────────────

    /**
     * Extract all text from a single content stream, respecting font changes.
     */
    private static function extract_from_stream(
        string $stream,
        array $font_cmaps,
        array $union_cmap
    ): string {
        $text         = '';
        $current_font = null;

        // Process BT...ET blocks.
        if (preg_match_all('/BT([\s\S]*?)ET/', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                $pos = 0;
                $len = strlen($block);

                while ($pos < $len) {
                    // /FontName size Tf
                    if (preg_match('/\G\s*\/(\w+)\s+[\d.]+\s+Tf/', $block, $m, 0, $pos)) {
                        $current_font = $m[1];
                        $pos += strlen($m[0]);
                        continue;
                    }

                    $cmap = (!empty($current_font) && isset($font_cmaps[$current_font]))
                        ? $font_cmaps[$current_font]
                        : $union_cmap;

                    // [...] TJ
                    if (preg_match('/\G\s*\[([\s\S]*?)\]\s*TJ/', $block, $m, 0, $pos)) {
                        $text .= self::decode_tj_array($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // <hex> Tj
                    if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>\s*Tj/', $block, $m, 0, $pos)) {
                        $text .= self::decode_hex($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // (lit) Tj
                    if (preg_match(
                        '/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s',
                        $block, $m, 0, $pos
                    )) {
                        $text .= self::decode_literal($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // <hex> newline operators ' "
                    if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>\s*[\'"]/s', $block, $m, 0, $pos)) {
                        $text .= self::decode_hex($m[1], $cmap) . "\n";
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // (lit) newline operators ' "
                    if (preg_match(
                        '/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*[\'"]/s',
                        $block, $m, 0, $pos
                    )) {
                        $text .= self::decode_literal($m[1], $cmap) . "\n";
                        $pos += strlen($m[0]);
                        continue;
                    }

                    $pos++;
                }
            }
        }

        return $text;
    }

    // ── String decoders ───────────────────────────────────────────────────────

    /**
     * Decode a TJ array: interleaved (lit) / <hex> strings and numeric kerning.
     */
    private static function decode_tj_array(string $arr, array $cmap): string {
        $result = '';
        $pos    = 0;
        $len    = strlen($arr);

        while ($pos < $len) {
            // <hex>
            if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>/', $arr, $m, 0, $pos)) {
                $result .= self::decode_hex($m[1], $cmap);
                $pos += strlen($m[0]);
                continue;
            }
            // (lit)
            if (preg_match('/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $arr, $m, 0, $pos)) {
                $result .= self::decode_literal($m[1], $cmap);
                $pos += strlen($m[0]);
                continue;
            }
            // numeric kerning — skip
            if (preg_match('/\G\s*-?[\d.]+/', $arr, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            $pos++;
        }

        return $result;
    }

    /**
     * Decode a PDF literal string (between parentheses) and apply CMap.
     */
    private static function decode_literal(string $s, array $cmap): string {
        return self::apply_cmap(self::pdf_unescape($s), $cmap);
    }

    /**
     * Decode a PDF hex string <XXXX...> and apply CMap.
     * Auto-detects UTF-16BE when no CMap is present (common in Word exports).
     */
    private static function decode_hex(string $hex, array $cmap): string {
        $hex = preg_replace('/\s+/', '', $hex);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0'; // pad odd-length per PDF spec
        }
        $bytes = hex2bin($hex);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        if (!empty($cmap)) {
            return self::apply_cmap($bytes, $cmap);
        }

        // No CMap: auto-detect UTF-16BE (frequent null bytes on even indices).
        if (strlen($bytes) >= 4 && strlen($bytes) % 2 === 0) {
            $nulls = 0;
            for ($i = 0; $i < strlen($bytes); $i += 2) {
                if (ord($bytes[$i]) === 0) {
                    $nulls++;
                }
            }
            if ($nulls >= strlen($bytes) / 4) {
                if (function_exists('mb_convert_encoding')) {
                    $u = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
                    if ($u !== false && $u !== '') {
                        return $u;
                    }
                }
            }
        }

        // Try interpreting as WinAnsi if not valid UTF-8.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($bytes, 'UTF-8')) {
            $u = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
            if ($u !== false) {
                return $u;
            }
        }

        return $bytes;
    }

    // ── CMap application ──────────────────────────────────────────────────────

    /**
     * Apply a CMap table to a decoded byte string, producing UTF-8.
     * Supports 1-byte and 2-byte character code keys.
     * Bytes that have no mapping in a non-empty CMap are dropped.
     */
    private static function apply_cmap(string $s, array $cmap): string {
        if (empty($cmap)) {
            return $s;
        }

        // Detect if any key needs 2-byte lookup.
        $two_byte = false;
        foreach ($cmap as $key => $_) {
            if ($key > 0xFF) {
                $two_byte = true;
                break;
            }
        }

        $result = '';
        $len    = strlen($s);
        $i      = 0;

        while ($i < $len) {
            // 2-byte lookup.
            if ($two_byte && $i + 1 < $len) {
                $code = (ord($s[$i]) << 8) | ord($s[$i + 1]);
                if (isset($cmap[$code])) {
                    $result .= $cmap[$code];
                    $i += 2;
                    continue;
                }
            }
            // 1-byte lookup.
            $byte = ord($s[$i]);
            if (isset($cmap[$byte])) {
                $result .= $cmap[$byte];
            }
            // Unmapped bytes are dropped (they are glyph indices, not characters).
            $i++;
        }

        return $result;
    }

    // ── CMap parsing ──────────────────────────────────────────────────────────

    /**
     * Parse a ToUnicode CMap stream into an int → UTF-8 string table.
     * Keys may be 1-byte (0x00–0xFF) or 2-byte (0x0000–0xFFFF).
     */
    private static function parse_cmap_stream(string $cmap): array {
        $table = [];

        // beginbfrange: <from> <to> <start>
        if (preg_match_all('/beginbfrange([\s\S]*?)endbfrange/', $cmap, $secs, PREG_SET_ORDER)) {
            foreach ($secs as $sec) {
                if (preg_match_all(
                    '/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/',
                    $sec[1], $triples, PREG_SET_ORDER
                )) {
                    foreach ($triples as $t) {
                        $from = hexdec($t[1]);
                        $to   = hexdec($t[2]);
                        $uni  = hexdec($t[3]);
                        for ($i = $from; $i <= $to; $i++) {
                            $table[$i] = self::cp_to_utf8($uni + ($i - $from));
                        }
                    }
                }
            }
        }

        // beginbfchar: <from> <unicode>
        if (preg_match_all('/beginbfchar([\s\S]*?)endbfchar/', $cmap, $secs, PREG_SET_ORDER)) {
            foreach ($secs as $sec) {
                if (preg_match_all(
                    '/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/',
                    $sec[1], $pairs, PREG_SET_ORDER
                )) {
                    foreach ($pairs as $p) {
                        $table[hexdec($p[1])] = self::cp_to_utf8(hexdec($p[2]));
                    }
                }
            }
        }

        return $table;
    }

    // ── PDF literal string unescaping ─────────────────────────────────────────

    /**
     * Unescape a raw PDF literal string (content between parentheses).
     * Handles octal escapes, named escapes, UTF-16BE BOM, and WinAnsi fallback.
     */
    private static function pdf_unescape(string $s): string {
        // Octal escapes: \nnn
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $s);

        // Named escapes.
        $s = str_replace(
            ['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'],
            ["\n",  "\r",  "\t",  '\\',   '(',   ')'],
            $s
        );

        // PDF spec: backslash before unrecognised char → drop backslash.
        $s = preg_replace('/\\\\(.)/', '$1', $s);

        // UTF-16BE BOM detection.
        if (strlen($s) >= 2 && $s[0] === "\xfe" && $s[1] === "\xff") {
            if (function_exists('mb_convert_encoding')) {
                $u = mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
                if ($u !== false && mb_check_encoding($u, 'UTF-8')) {
                    return $u;
                }
            }
        }

        // WinAnsi fallback for non-UTF-8 strings.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            $u = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
            if ($u !== false) {
                return $u;
            }
        }

        return $s;
    }

    // ── Unicode utilities ─────────────────────────────────────────────────────

    private static function cp_to_utf8(int $cp): string {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12))
                 . chr(0x80 | (($cp >> 6) & 0x3F))
                 . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18))
             . chr(0x80 | (($cp >> 12) & 0x3F))
             . chr(0x80 | (($cp >> 6) & 0x3F))
             . chr(0x80 | ($cp & 0x3F));
    }
}
