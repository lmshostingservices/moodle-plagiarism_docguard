<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace plagiarism_docguard;

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
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extractor {
    // V1.0.80: 'application/zip' REMOVED.
    //
    // It was accepted here and then guaranteed to fail: filetype() maps a .zip file to
    // 'unsupported' (its extension is not pdf/docx/doc and its mimetype contains neither
    // 'pdf' nor 'wordprocessingml'/'msword'), and extract() throws on 'unsupported'
    // immediately. So every .zip submission was picked up, a DB record created, analysis
    // attempted, and the teacher shown a purple "Plagiarism Check Error" badge reading
    // "Unsupported file type" — for a file type the plugin advertised as supported. Cron
    // then retried it on the pending sweep. Supporting .zip properly would mean unpacking
    // arbitrary student archives on the Moodle server and deciding what to do with the
    // contents; that is a feature, not a bug fix. Declining it up front is honest: the
    // file is now skipped silently, exactly like a .txt or an image, and the documented
    // supported types (PDF and DOCX) are what the code actually accepts.
    //
    // .doc / application/msword are deliberately KEPT: extract_docx() detects the OLE2
    // magic number and returns a specific, actionable message telling the student to
    // resubmit as .docx or PDF. That is a useful failure, not a silent one.
    /**
     * MIME types is_supported() accepts when the filename extension is not decisive.
     *
     * @var string[]
     */
    const SUPPORTED_MIMETYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
    ];

    /**
     * Filename extensions is_supported() accepts outright.
     *
     * @var string[]
     */
    const SUPPORTED_EXTENSIONS = ['pdf', 'docx', 'doc'];

    /**
     * Whether DocGuard can extract text from this submitted file.
     *
     * Accepts a file whose extension is pdf, docx or doc, or whose MIME type matches
     * one of the supported types.
     *
     * @param \stored_file $file The submitted file.
     * @return bool True when the file is a supported document type.
     */
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

    /**
     * Classify a submitted file as a PDF or a Word document.
     *
     * Decides on the filename extension first and falls back to the MIME type.
     *
     * @param \stored_file $file The submitted file.
     * @return string One of "pdf", "docx" or "unsupported".
     */
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

    /**
     * Extract the plain text of a submitted document.
     *
     * Copies the file to a collision-free temporary path, dispatches to the PDF or
     * DOCX extractor for its type, and removes the temporary copy afterwards.
     *
     * @param \stored_file $file The submitted file.
     * @return string The extracted text.
     * @throws \Exception If the file type is unsupported or extraction fails.
     */
    public static function extract(\stored_file $file): string {
        $type = self::filetype($file);
        if ($type === 'unsupported') {
            throw new \Exception('Unsupported file type: ' . $file->get_filename());
        }

        // FIX-DG-TEMPFILE-COLLISION (v1.0.78): the temp path was built from the
        // submitted filename alone. Two students submitting "assignment.pdf", or the
        // observer and the cron task touching the same file concurrently, wrote to
        // the same path — and the unlink() in the finally block below could delete
        // the other worker's file mid-read, producing corrupt extractions or
        // spurious errors. uniqid() makes each extraction independent.
        // The uniqid prefix adds ~27 characters, so the original name is trimmed to
        // keep the whole path component inside the 255-byte filesystem limit that
        // ext4/XFS enforce — Moodle itself permits filenames up to 255 characters.
        $basename = clean_filename($file->get_filename());
        if (strlen($basename) > 120) {
            $basename = substr($basename, -120);
        }
        $tmpfile = make_temp_directory('docguard') . '/' . uniqid('dg_', true) . '_' . $basename;
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

    /* ── DOCX ───────────────────────────────────────────────────────────────── */

    /**
     * Extract the text of a .docx file from its word/document.xml part.
     *
     * @param string $filepath Absolute path to a readable copy of the file.
     * @return string The extracted text, paragraphs separated by blank lines.
     * @throws \Exception If ZipArchive is unavailable or the file cannot be opened
     *                    as a .docx (including a genuine legacy binary .doc).
     */
    public static function extract_docx(string $filepath): string {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('ZipArchive extension is not available.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($filepath) !== true) {
            // FIX-DG-LEGACY-DOC (v1.0.78): is_supported() accepts the 'doc'
            // extension and filetype() maps it to 'docx', but a legacy OLE2 binary
            // .doc is not a ZIP container, so ZipArchive always fails and every
            // .doc submission produced the opaque error "Cannot open DOCX file"
            // with no breakdown and nothing the teacher could act on. Detect the
            // OLE2 magic number and say something useful instead.
            $magic = '';
            $fh    = @fopen($filepath, 'rb');
            if ($fh) {
                $magic = (string)fread($fh, 8);
                fclose($fh);
            }
            if (strncmp($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
                // Kept under 120 characters: lib.php renders badge errors as
                // substr($errmsg, 0, 120) and a longer message is cut mid-word.
                throw new \Exception('Legacy .doc format is not supported. Ask the student to resubmit as .docx or PDF.');
            }
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

    /* ── PDF top-level: try CLI tools, then PHP fallback ─────────────────────── */

    /**
     * Extract the text of a PDF, preferring external tools over the PHP parser.
     *
     * Tries pdftotext, then Ghostscript, each under a 60 second timeout so a malformed
     * PDF cannot hang the cron worker, and falls back to extract_pdf_php().
     *
     * @param string $filepath Absolute path to a readable copy of the file.
     * @return string The extracted text.
     * @throws \Exception If no method produced readable text.
     */
    public static function extract_pdf(string $filepath): string {
        // 1. pdftotext (poppler-utils)
        if (self::cli_available('pdftotext')) {
            $out    = [];
            $retval = 0;
            // Timeout 60: prevents corrupt/malformed PDFs hanging the cron worker indefinitely.
            exec(
                'timeout 60 pdftotext -layout -enc UTF-8 ' . escapeshellarg($filepath) . ' - 2>/dev/null',
                $out,
                $retval
            );
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
            // Timeout 60: gs can hang indefinitely on corrupt PDFs (confirmed incident July 2026
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
     *
     * @param string $cmd The command name to look for, e.g. "pdftotext".
     * @return bool True when the command was found on the PATH.
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
     *
     * @param string $text The candidate extracted text.
     * @return bool True when enough of the characters are letters for the text to be usable.
     */
    private static function looks_readable(string $text): bool {
        $text = trim($text);
        if (strlen($text) < 20) {
            return false;
        }

        // V1.0.80: this test was ASCII-only — it counted /[a-zA-Z ]/ BYTES against the
        // total BYTE length. A perfectly extracted Chinese, Arabic, Greek, Hebrew,
        // Japanese, Thai or Cyrillic document scores ~0 on that ratio (its letters are
        // multi-byte and none of them are a-z), so looks_readable() returned false, both
        // CLI extractors were rejected in turn, and the pure-PHP fallback ran on a file
        // pdftotext had already read correctly — usually ending in "Extracted text is too
        // short or empty. The file may be scanned/image-based." A French or German
        // document was penalised too: every é, ü and ß counted against it.
        //
        // \p{L} (letters, any script), \p{M} (combining marks — essential for Indic and
        // Arabic) and \p{N} (digits), counted in CHARACTERS against the character count of
        // the non-whitespace text.
        //
        // v1.0.82: numerator and denominator now measure the SAME thing. Two rounds of
        // change got this wrong in opposite directions. v1.0.80 counted letters, marks,
        // digits AND SPACES in the numerator while the denominator stayed
        // whitespace-stripped, so the ratio could exceed 1.0 and digit-heavy garbage from a
        // failed pdftotext pass scored ~1.0 and was accepted. v1.0.81 then removed \p{N}
        // entirely - which fixed the garbage case and broke the legitimate one: a maths
        // worksheet or a lab results table that pdftotext had extracted perfectly scored
        // 0.42, was discarded, fell through to the PHP fallback, and reached the teacher as
        // "the file may be scanned/image-based" on a document both CLI extractors read
        // flawlessly.
        //
        // The defect was never \p{N}; it was the space in the numerator. Both sides now
        // count non-whitespace characters, so 0.45 means what the threshold says it means.
        $letters = @preg_match_all('/[\p{L}\p{M}\p{N}]/u', $text);
        if ($letters === false) {
            // Malformed UTF-8 — genuinely garbled output from a broken extractor, which
            // is the case this heuristic exists to reject. Fall back to the byte-wise
            // test rather than guessing.
            \debugging(
                'DocGuard: extracted text is not valid UTF-8 — using ASCII readability test.',
                DEBUG_DEVELOPER
            );
            $letters = preg_match_all('/[a-zA-Z0-9]/', $text);
            $total   = max(1, strlen(preg_replace('/\s+/', '', $text)));
            return ($letters / $total) > 0.45;
        }

        $stripped = preg_replace('/\s+/u', '', $text);
        $total    = max(1, \core_text::strlen((string)$stripped));
        return ($letters / $total) > 0.45;
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       Pure-PHP PDF extraction — two-phase architecture (FIX-DG-PDF-ARCH v1.0.54)
       ═══════════════════════════════════════════════════════════════════════════ */

    /**
     * Extract the text of a PDF using the built-in pure-PHP parser.
     *
     * Two-phase: index every object body from the raw bytes first, then decode the
     * content streams using the font CMaps those objects refer to. Used when neither
     * pdftotext nor Ghostscript is available.
     *
     * @param string $filepath Absolute path to a readable copy of the file.
     * @return string The extracted text.
     * @throws \Exception If the file cannot be read.
     */
    public static function extract_pdf_php(string $filepath): string {
        $raw = file_get_contents($filepath);
        if ($raw === false) {
            throw new \Exception('Cannot read PDF file.');
        }

        /* ── Phase A: Index object bodies from the RAW, unmodified PDF ───────── */
        // CRITICAL: do NOT inflate anything here. Inflated bytes can look like
        // PDF keywords (endobj, stream, etc.) and corrupt subsequent parsing.
        $objbodies = [];
        if (
            preg_match_all(
                '/\b(\d+)\s+\d+\s+obj\b([\s\S]*?)\bendobj\b/',
                $raw,
                $m,
                PREG_SET_ORDER
            )
        ) {
            foreach ($m as $o) {
                $objbodies[(int)$o[1]] = $o[2];
            }
        }

        if (empty($objbodies)) {
            // Fallback: try without word boundaries (some PDFs omit whitespace).
            if (
                preg_match_all(
                    '/(\d+)\s+\d+\s+obj([\s\S]*?)endobj/',
                    $raw,
                    $m,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($m as $o) {
                    $objbodies[(int)$o[1]] = $o[2];
                }
            }
        }

        /* ── Phase B: Inflate each object's stream independently ─────────────── */
        $objstreams = []; // Obj_num => inflated (or raw) stream bytes.
        foreach ($objbodies as $num => $body) {
            $stream = self::extract_stream($body);
            if ($stream !== null) {
                $objstreams[$num] = $stream;
            }
        }

        /* ── Phase C: Find all ToUnicode CMap tables ─────────────────────────── */
        $tounicodebyobj = []; // Obj_num => [int_code => utf8_string].
        foreach ($objstreams as $num => $stream) {
            if (
                strpos($stream, 'beginbfchar') !== false ||
                strpos($stream, 'beginbfrange') !== false
            ) {
                $table = self::parse_cmap_stream($stream);
                if (!empty($table)) {
                    $tounicodebyobj[$num] = $table;
                }
            }
        }

        /* ── Phase D: Map font resource names to their CMaps ─────────────────── */
        $fontcmaps = []; // Font_resource_name => cmap_table.

        // D-1: Scan every object for /Type /Font with a /ToUnicode reference.
        $fontobjtocmap = []; // Font_obj_num => cmap_table.
        foreach ($objbodies as $num => $body) {
            if (strpos($body, '/ToUnicode') === false) {
                continue;
            }
            // Direct ToUnicode on this font object.
            if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $tm)) {
                $tu = (int)$tm[1];
                if (isset($tounicodebyobj[$tu])) {
                    $fontobjtocmap[$num] = $tounicodebyobj[$tu];
                }
            }
        }

        // D-2: Walk /Font resource dicts to find name → font-obj-num mapping.
        // Search both the raw PDF and every inflated content stream for /Font dicts,
        // because in some PDFs the Resources dict lives inside a content stream.
        $searchsources = [$raw];
        foreach ($objstreams as $s) {
            $searchsources[] = $s;
        }

        foreach ($searchsources as $src) {
            // Match /Font << ... >> — allow up to 4 KB for the dict contents.
            if (!preg_match_all('/\/Font\s*<<([\s\S]{1,4096}?)>>/', $src, $fdm)) {
                continue;
            }
            foreach ($fdm[1] as $fontdict) {
                // Each entry: /ResourceName N 0 R.
                if (
                    !preg_match_all(
                        '/\/(\w+)\s+(\d+)\s+\d+\s+R/',
                        $fontdict,
                        $refs,
                        PREG_SET_ORDER
                    )
                ) {
                    continue;
                }
                foreach ($refs as $ref) {
                    $rname = $ref[1];
                    $fnum  = (int)$ref[2];
                    // First resource dict to name a font wins; later ones must not clobber it.
                    if (isset($fontcmaps[$rname])) {
                        continue;
                    }
                    $resolved = self::resolve_font_cmap($fnum, $fontobjtocmap, $objbodies, $tounicodebyobj);
                    if ($resolved !== null) {
                        $fontcmaps[$rname] = $resolved;
                    }
                }
            }
        }

        /* ── Phase E: Build union fallback CMap ──────────────────────────────── */
        // Used when the active font name is unknown or has no individual CMap.
        $unioncmap = [];
        foreach ($tounicodebyobj as $cmap) {
            $unioncmap += $cmap; // First mapping wins (array union).
        }

        /* ── Phase F: Extract text from content streams ──────────────────────── */
        $text = '';
        foreach ($objstreams as $num => $stream) {
            // Skip streams that are clearly not page content (CMap, image data, etc.)
            if (
                strpos($stream, 'BT') === false && strpos($stream, 'Tj') === false &&
                strpos($stream, 'TJ') === false
            ) {
                continue;
            }
            $text .= self::extract_from_stream($stream, $fontcmaps, $unioncmap);
        }

        /* ── Phase G: Last-resort scan if nothing found ──────────────────────── */
        if (strlen(trim($text)) < 30) {
            foreach ($objstreams as $stream) {
                // Hex-encoded string shown with a Tj operator.
                if (preg_match_all('/<([0-9a-fA-F\s]{2,})>\s*Tj/', $stream, $hm)) {
                    foreach ($hm[1] as $h) {
                        $text .= self::decode_hex($h, $unioncmap) . ' ';
                    }
                }
                // Literal string shown with a Tj operator.
                if (preg_match_all('/\(([^)]{2,})\)\s*Tj/s', $stream, $lm)) {
                    foreach ($lm[1] as $l) {
                        $text .= self::decode_literal($l, $unioncmap) . ' ';
                    }
                }
            }
        }

        // Normalise whitespace.
        //
        // FIX-DG-PDF-NEWLINES (v1.0.85): the order here matters now that
        // extract_from_stream() emits real line breaks. Line endings are folded to \n
        // FIRST so the run-collapsing rule below can actually see consecutive breaks —
        // the old '/(\r\n|\r|\n){3,}/' only collapsed runs that were already adjacent,
        // and a Tj always appends a space, so every break arrived as " \n" and no run
        // was ever adjacent. Spaces either side of a break are then removed, which is
        // what keeps a marker line starting with "Question 3:" rather than " Question 3:"
        // — question_parser::try_labelled() anchors its pattern with ^ on each line, so a
        // single leading space would defeat the whole fix.
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Find the ToUnicode CMap that applies to one font object referenced by a /Font dict.
     *
     * Tries, in the order the PDF spec makes them authoritative: the CMap already indexed
     * against that font object; a /ToUnicode reference in the font object's own body; and
     * finally, for Type0 composite fonts, a /ToUnicode reference on the first descendant
     * font. Each step is only reached when the previous one produced nothing usable, so a
     * font object with a dangling /ToUnicode reference still gets a chance at its
     * descendant's table.
     *
     * @param int $fontobjnum Object number of the font the resource name points at.
     * @param array $fontobjtocmap CMap tables already resolved per font object number.
     * @param array $objbodies Raw object bodies, keyed by object number.
     * @param array $tounicodebyobj Parsed ToUnicode tables, keyed by the object number that
     *                              carried them.
     * @return array|null The character-code to UTF-8 mapping table for this font, or null
     *                    when no ToUnicode table can be found for it.
     */
    private static function resolve_font_cmap(
        int $fontobjnum,
        array $fontobjtocmap,
        array $objbodies,
        array $tounicodebyobj
    ): ?array {
        if (isset($fontobjtocmap[$fontobjnum])) {
            return $fontobjtocmap[$fontobjnum];
        }
        if (!isset($objbodies[$fontobjnum])) {
            return null;
        }
        $fbody = $objbodies[$fontobjnum];

        // Direct /ToUnicode on the font object itself.
        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fbody, $tum)) {
            $tu = (int)$tum[1];
            if (isset($tounicodebyobj[$tu])) {
                return $tounicodebyobj[$tu];
            }
        }

        // Type0: check the DescendantFonts array for /ToUnicode.
        if (preg_match('/\/DescendantFonts\s*\[\s*(\d+)\s+\d+\s+R/', $fbody, $dm)) {
            $dnum = (int)$dm[1];
            if (
                isset($objbodies[$dnum]) &&
                preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $objbodies[$dnum], $tum2)
            ) {
                $tu2 = (int)$tum2[1];
                if (isset($tounicodebyobj[$tu2])) {
                    return $tounicodebyobj[$tu2];
                }
            }
        }

        return null;
    }

    /* ── Object / stream helpers ─────────────────────────────────────────────── */

    /**
     * Extract and inflate the stream from a PDF object body string.
     * Returns null if the object has no stream.
     *
     * @param string $objbody The raw bytes of one "N 0 obj ... endobj" body.
     * @return string|null The decoded stream contents, or null if there is none or it
     *                     could not be decoded.
     */
    private static function extract_stream(string $objbody): ?string {
        if (!preg_match('/\bstream\b([\s\S]*?)\bendstream\b/', $objbody, $m)) {
            return null;
        }
        // Ltrim ONE leading \r\n or \n (PDF spec: stream keyword followed by single EOL).
        $data = $m[1];
        if (substr($data, 0, 2) === "\r\n") {
            $data = substr($data, 2);
        } else if ($data[0] === "\n" || $data[0] === "\r") {
            $data = substr($data, 1);
        }

        // Inflate FlateDecode (including when Filter is an array).
        if (
            preg_match(
                '/\/Filter\s*(?:\/FlateDecode|\[[\s\S]*?\/FlateDecode[\s\S]*?\])/',
                $objbody
            )
        ) {
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

    /* ── Content stream text extraction ──────────────────────────────────────── */

    /**
     * Extract all text from a single content stream, respecting font changes.
     *
     * FIX-DG-PDF-NEWLINES (v1.0.85): this method used to recognise the text-SHOWING
     * operators (Tj, TJ, ' and ") and nothing else. A PDF has no newline character: a
     * line break is a text-POSITIONING operator — Td, TD, T*, Tm — which moves the text
     * matrix down the page. Those operators fell through every branch below to the
     * `$pos++` catch-all and were skipped one byte at a time, so the entire page came
     * back as a single space-joined run with not one "\n" in it.
     *
     * The observable consequence was on any host with neither pdftotext nor Ghostscript
     * installed — which is most managed/shared Moodle hosting, and the case this parser
     * exists to serve. question_parser::try_labelled() and try_numbered() both split on
     * explode("\n") and anchor their marker patterns with ^, so with zero newlines they
     * could never match a single marker, parse() fell through to its last resort, and
     * every submission was reported as "1 section(s) analysed — Full Document" no matter
     * how many questions it contained. Per-question analysis was silently dead on those
     * hosts. It was invisible in the report because the display path collapses
     * whitespace, so the rendered text looked the same either way.
     *
     * The fix tracks the position of the current text line and emits a break whenever
     * that position moves, which is the definition of a new line in the PDF imaging
     * model and therefore works regardless of which operator a producer chose:
     *   - Tm sets the text matrix absolutely; its e and f operands are the new line
     *     origin. Word, LaTeX and most library-generated PDFs position every line this way.
     *   - Td moves the line origin by (tx, ty) relative to the START of the current line.
     *   - TD is Td plus "set the leading to -ty".
     *   - TL sets the leading; T* moves down by exactly one leading, and is always a break.
     *   - ' and " carry an implicit T*; they already emitted "\n" but left the tracked
     *     position stale, so a following Tm or Td compared against the wrong y.
     * BT resets the text matrix to the identity, so the tracked position resets with it.
     *
     * @param string $stream     The decoded content stream bytes.
     * @param array  $fontcmaps CMap tables keyed by PDF font resource name.
     * @param array  $unioncmap Fallback CMap merged from every font in the document.
     * @return string The text shown by this content stream.
     */
    private static function extract_from_stream(
        string $stream,
        array $fontcmaps,
        array $unioncmap
    ): string {
        $text         = '';
        $currentfont = null;

        // One PDF number, as it may appear as an operand: optional sign, digits and/or a
        // decimal point. Exponent notation is not legal in a PDF content stream.
        $num = '(-?[\d.]+)';

        // Process BT...ET blocks.
        if (preg_match_all('/BT([\s\S]*?)ET/', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                $pos = 0;
                $len = strlen($block);

                // BT resets the text matrix and the text line matrix to the identity,
                // so the current line origin starts at (0, 0) for every block. The
                // leading defaults to 0 until a TL or TD sets it; a T* before either is
                // still treated as a break below, because it always is one.
                $linex   = 0.0;
                $liney   = 0.0;
                $leading = 0.0;

                while ($pos < $len) {
                    /* /FontName size Tf */
                    if (preg_match('/\G\s*\/(\w+)\s+[\d.]+\s+Tf/', $block, $m, 0, $pos)) {
                        $currentfont = $m[1];
                        $pos += strlen($m[0]);
                        continue;
                    }

                    $cmap = (!empty($currentfont) && isset($fontcmaps[$currentfont]))
                        ? $fontcmaps[$currentfont]
                        : $unioncmap;

                    // PDF operator syntax, not commented-out PHP: "[" opens a PDF
                    // array of string/number pairs and TJ shows it with kerning.
                    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                    /* [...] TJ */
                    if (preg_match('/\G\s*\[([\s\S]*?)\]\s*TJ/', $block, $m, 0, $pos)) {
                        $text .= self::decode_tj_array($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // Hex-encoded string shown with a Tj operator.
                    if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>\s*Tj/', $block, $m, 0, $pos)) {
                        $text .= self::decode_hex($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // Literal string shown with a Tj operator.
                    if (
                        preg_match(
                            '/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s',
                            $block,
                            $m,
                            0,
                            $pos
                        )
                    ) {
                        $text .= self::decode_literal($m[1], $cmap) . ' ';
                        $pos += strlen($m[0]);
                        continue;
                    }

                    /* <hex> newline operators ' "
                       Both carry an implicit T*, so the tracked line origin moves down by
                       one leading as well as the break being emitted. */
                    if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>\s*[\'"]/s', $block, $m, 0, $pos)) {
                        $text .= self::decode_hex($m[1], $cmap) . "\n";
                        $liney -= $leading;
                        $pos += strlen($m[0]);
                        continue;
                    }

                    /* (lit) newline operators ' " */
                    if (
                        preg_match(
                            '/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*[\'"]/s',
                            $block,
                            $m,
                            0,
                            $pos
                        )
                    ) {
                        $text .= self::decode_literal($m[1], $cmap) . "\n";
                        $liney -= $leading;
                        $pos += strlen($m[0]);
                        continue;
                    }

                    /* ── Text positioning operators ──────────────────────────── */
                    // These must be matched here, ahead of the $pos++ catch-all, because
                    // they are the ONLY record in a content stream of where one line ends
                    // and the next begins. Tm is tried first: it is the longest operand
                    // list, and a 6-number sequence would otherwise be walked into
                    // byte-by-byte. Nothing here is matched unless the operator token
                    // itself is present, so the other 6-number operator (cm) and the
                    // numeric operands of Tc/Tw/Tz/Ts/Tf are left alone.

                    // A b c d e f Tm — set the text matrix absolutely; e and f are the
                    // new line origin, so f changing is a new line.
                    if (
                        preg_match(
                            '/\G\s*' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num
                            . '\s+' . $num . '\s+' . $num . '\s+Tm\b/',
                            $block,
                            $m,
                            0,
                            $pos
                        )
                    ) {
                        $text .= self::line_break_for_move($linex, $liney, (float)$m[5], (float)$m[6]);
                        $linex = (float)$m[5];
                        $liney = (float)$m[6];
                        $pos  += strlen($m[0]);
                        continue;
                    }

                    // Tx ty Td, and tx ty TD (which also sets the leading to -ty).
                    // The offset is relative to the start of the CURRENT line, not to
                    // wherever the last glyph was drawn, which is why $linex/$liney track
                    // the line origin rather than the drawing position.
                    if (
                        preg_match(
                            '/\G\s*' . $num . '\s+' . $num . '\s+(TD|Td)\b/',
                            $block,
                            $m,
                            0,
                            $pos
                        )
                    ) {
                        $newx = $linex + (float)$m[1];
                        $newy = $liney + (float)$m[2];
                        if ($m[3] === 'TD') {
                            $leading = -(float)$m[2];
                        }
                        $text .= self::line_break_for_move($linex, $liney, $newx, $newy);
                        $linex = $newx;
                        $liney = $newy;
                        $pos  += strlen($m[0]);
                        continue;
                    }

                    // Leading TL — no movement of its own, but T* depends on it.
                    if (preg_match('/\G\s*' . $num . '\s+TL\b/', $block, $m, 0, $pos)) {
                        $leading = (float)$m[1];
                        $pos    += strlen($m[0]);
                        continue;
                    }

                    // T* — move to the start of the next line. Always a break, and
                    // emitted unconditionally rather than through the y comparison so
                    // that a producer which never set a leading (leading 0) still gets
                    // its line structure.
                    if (preg_match('/\G\s*T\*/', $block, $m, 0, $pos)) {
                        $text .= "\n";
                        $liney -= $leading;
                        $pos   += strlen($m[0]);
                        continue;
                    }

                    $pos++;
                }
            }
        }

        return $text;
    }

    /**
     * Decide what whitespace a move of the text line origin represents.
     *
     * FIX-DG-PDF-NEWLINES (v1.0.85): the single place that turns PDF geometry into
     * characters, so the rule is stated once for every operator that moves the line.
     *
     * The epsilon is deliberately small and the two thresholds are deliberately
     * asymmetric, because the two errors are not equally costly. A break that should not
     * have been emitted — a Td-positioned superscript, say — puts a line break inside a
     * sentence: question_parser only ever splits on a marker, and
     * normalise_for_similarity() collapses all whitespace, so nothing downstream can see
     * it. A break that should have been emitted and was not merges two lines, and if one
     * of them began "Question 4:" that marker is lost and the section with it. So when in
     * doubt, break: 1.0 unscaled text-space unit is far below any real line leading
     * (which is at least the font size, ~8 units and up) and far above the rounding
     * noise in a producer's coordinates.
     *
     * A horizontal-only move yields a space rather than nothing so that text laid out in
     * columns, or a line assembled from several Td-positioned runs, does not have its
     * words concatenated.
     *
     * @param float $oldx X of the line origin before the move.
     * @param float $oldy Y of the line origin before the move.
     * @param float $newx X of the line origin after the move.
     * @param float $newy Y of the line origin after the move.
     * @return string "\n" for a new line, " " for a horizontal gap, "" for neither.
     */
    private static function line_break_for_move(float $oldx, float $oldy, float $newx, float $newy): string {
        if (abs($newy - $oldy) > 1.0) {
            return "\n";
        }
        if (abs($newx - $oldx) > 1.0) {
            return ' ';
        }
        return '';
    }

    /* ── String decoders ─────────────────────────────────────────────────────── */

    /**
     * Decode a TJ array: interleaved (lit) / <hex> strings and numeric kerning.
     *
     * @param string $arr  The raw bytes between the brackets of the TJ array.
     * @param array  $cmap The CMap of the font currently selected.
     * @return string The decoded text.
     */
    private static function decode_tj_array(string $arr, array $cmap): string {
        $result = '';
        $pos    = 0;
        $len    = strlen($arr);

        while ($pos < $len) {
            // Hex-encoded string operand, delimited by angle brackets.
            if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>/', $arr, $m, 0, $pos)) {
                $result .= self::decode_hex($m[1], $cmap);
                $pos += strlen($m[0]);
                continue;
            }
            // Literal string operand, delimited by parentheses.
            if (preg_match('/\G\s*\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $arr, $m, 0, $pos)) {
                $result .= self::decode_literal($m[1], $cmap);
                $pos += strlen($m[0]);
                continue;
            }
            // Numeric kerning adjustment: carries no text, so step over it.
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
     *
     * @param string $s    The raw bytes between the parentheses of the literal string.
     * @param array  $cmap The CMap of the font currently selected.
     * @return string The decoded text.
     */
    private static function decode_literal(string $s, array $cmap): string {
        return self::apply_cmap(self::pdf_unescape($s), $cmap);
    }

    /**
     * Decode a PDF hex string <XXXX...> and apply CMap.
     * Auto-detects UTF-16BE when no CMap is present (common in Word exports).
     *
     * @param string $hex  The raw hexadecimal digits between the angle brackets.
     * @param array  $cmap The CMap of the font currently selected.
     * @return string The decoded text.
     */
    private static function decode_hex(string $hex, array $cmap): string {
        $hex = preg_replace('/\s+/', '', $hex);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0'; // Pad odd-length per PDF spec.
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

    /* ── CMap application ────────────────────────────────────────────────────── */

    /**
     * Apply a CMap table to a decoded byte string, producing UTF-8.
     * Supports 1-byte and 2-byte character code keys.
     * Bytes that have no mapping in a non-empty CMap are dropped.
     *
     * @param string $s    The bytes to translate.
     * @param array  $cmap Character code to Unicode code point map.
     * @return string The translated text.
     */
    private static function apply_cmap(string $s, array $cmap): string {
        if (empty($cmap)) {
            return $s;
        }

        // Detect if any key needs 2-byte lookup.
        $twobyte = false;
        foreach ($cmap as $key => $ignored) {
            if ($key > 0xFF) {
                $twobyte = true;
                break;
            }
        }

        $result = '';
        $len    = strlen($s);
        $i      = 0;

        while ($i < $len) {
            // 2-byte lookup.
            if ($twobyte && $i + 1 < $len) {
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

    /* ── CMap parsing ────────────────────────────────────────────────────────── */

    /**
     * Parse a ToUnicode CMap stream into an int → UTF-8 string table.
     * Keys may be 1-byte (0x00–0xFF) or 2-byte (0x0000–0xFFFF).
     *
     * @param string $cmap The decoded ToUnicode CMap stream.
     * @return array Character code to Unicode code point map.
     */
    private static function parse_cmap_stream(string $cmap): array {
        $table = [];

        // Beginbfrange: <from> <to> <start>.
        if (preg_match_all('/beginbfrange([\s\S]*?)endbfrange/', $cmap, $secs, PREG_SET_ORDER)) {
            foreach ($secs as $sec) {
                if (
                    preg_match_all(
                        '/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/',
                        $sec[1],
                        $triples,
                        PREG_SET_ORDER
                    )
                ) {
                    foreach ($triples as $t) {
                        $from = hexdec($t[1]);
                        $to   = hexdec($t[2]);
                        $uni  = hexdec($t[3]);

                        /*
                         * V1.0.87 FIX-DG-BFRANGE-UNBOUNDED: $from and $to came straight from
                         * hexdec() of arbitrary-length hex in the PDF, with no bound on the
                         * distance between them. A crafted or corrupt CMap containing
                         * <00000000> <FFFFFFFF> <0041> asked this loop for 4.29 BILLION
                         * iterations, each allocating an array entry - the cron worker
                         * exhausts memory or hangs indefinitely.
                         *
                         * This is the one PDF path with no timeout guard. Both CLI branches
                         * are wrapped in `timeout 60` precisely because "corrupt/malformed
                         * PDFs hang the cron worker indefinitely", but the pure-PHP fallback
                         * that runs when those tools are absent had nothing equivalent - and
                         * since v1.0.86 we know that fallback is the path most managed hosts
                         * actually take.
                         *
                         * A CMap range is a contiguous run of character codes. Real ones are
                         * small; a single range covering more than the entire Basic
                         * Multilingual Plane is not a font, it is malformed input. Clamp to
                         * 65,536 entries per range, which is every code point a 2-byte CID
                         * font can address, and record the truncation rather than failing
                         * silently.
                         */
                        $maxrange = 0xFFFF;
                        if ($to < $from) {
                            // Reversed range: not a range at all. Skip it.
                            continue;
                        }
                        if (($to - $from) > $maxrange) {
                            debugging(
                                'DocGuard: CMap bfrange of ' . ($to - $from + 1)
                                    . ' entries truncated to ' . ($maxrange + 1)
                                    . ' - the PDF is malformed or hostile.',
                                DEBUG_DEVELOPER
                            );
                            $to = $from + $maxrange;
                        }

                        for ($i = $from; $i <= $to; $i++) {
                            $table[$i] = self::cp_to_utf8($uni + ($i - $from));
                        }
                    }
                }
            }
        }

        // Beginbfchar: <from> <unicode>.
        if (preg_match_all('/beginbfchar([\s\S]*?)endbfchar/', $cmap, $secs, PREG_SET_ORDER)) {
            foreach ($secs as $sec) {
                if (
                    preg_match_all(
                        '/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/',
                        $sec[1],
                        $pairs,
                        PREG_SET_ORDER
                    )
                ) {
                    foreach ($pairs as $p) {
                        $table[hexdec($p[1])] = self::cp_to_utf8(hexdec($p[2]));
                    }
                }
            }
        }

        return $table;
    }

    /* ── PDF literal string unescaping ───────────────────────────────────────── */

    /**
     * Unescape a raw PDF literal string (content between parentheses).
     * Handles octal escapes, named escapes, UTF-16BE BOM, and WinAnsi fallback.
     *
     * @param string $s The raw literal string bytes.
     * @return string The unescaped bytes.
     */
    private static function pdf_unescape(string $s): string {
        // Octal escapes: \nnn.
        $s = preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            function ($m) {
                return chr(octdec($m[1]));
                },
            $s
        );

        // Named escapes.
        $s = str_replace(
            ['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'],
            ["\n", "\r", "\t", '\\', '(', ')'],
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

    /* ── Unicode utilities ───────────────────────────────────────────────────── */

    /**
     * Encode one Unicode code point as UTF-8.
     *
     * @param int $cp The Unicode code point.
     * @return string The UTF-8 encoding of that code point.
     */
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
