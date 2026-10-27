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
     * Image extensions accepted when OCR is available.
     *
     * V1.2.5. A photograph of a page is a submission a trainer has to deal with whether or
     * not it is a good idea, and until now DocGuard declined it outright. These are only
     * accepted when Tesseract is installed - see ocr_available(). Declaring support for a
     * file DocGuard cannot read would be worse than declining it, because the submission
     * would sit at "pending" instead of telling the trainer anything.
     *
     * HEIC is listed because it is what an iPhone produces by default, which makes it
     * common in a VET cohort. Whether Tesseract can read a given HEIC depends on the
     * Leptonica build on the host, so a failure here is reported as a readable message
     * rather than assumed to work.
     *
     * @var string[]
     */
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp', 'heic', 'heif'];

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
        // V1.2.5: images only when there is something on this host that can read them.
        if (in_array($ext, self::IMAGE_EXTENSIONS, true) || strpos($mime, 'image/') === 0) {
            return self::ocr_available();
        }
        foreach (self::SUPPORTED_MIMETYPES as $m) {
            if (strpos($mime, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is optical character recognition available on this host?
     *
     * V1.2.5. OCR is the one capability DocGuard cannot provide itself. Recognising
     * characters in a photograph needs a trained model and image processing; there is no
     * credible way to do it in PHP, and pretending otherwise would mean shipping something
     * that produces confident nonsense from a student's work.
     *
     * So it is OPTIONAL, and the plugin is wholly usable without it: every text PDF and
     * Word document is read exactly the same either way, and a scan is reported as a scan
     * instead of as an error. Installing Tesseract adds scanned PDFs and photographs; it
     * does not unlock anything that was being withheld.
     *
     * @return bool True when Tesseract is on the PATH.
     */
    public static function ocr_available(): bool {
        return self::cli_available('tesseract');
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
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        $mime = strtolower($file->get_mimetype());
        if (strpos($mime, 'pdf') !== false) {
            return 'pdf';
        }
        if (strpos($mime, 'wordprocessingml') !== false || strpos($mime, 'msword') !== false) {
            return 'docx';
        }
        if (strpos($mime, 'image/') === 0) {
            return 'image';
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

        self::$lastmethod = '';
        try {
            if ($type === 'pdf') {
                $text = self::extract_pdf($tmpfile);
            } else if ($type === 'image') {
                $text = self::extract_image($tmpfile);
            } else {
                $text = self::extract_docx($tmpfile);
            }
        } finally {
            @unlink($tmpfile);
        }

        // FIX-DG-EXTRACT-UTF8 (v1.2.5). One exit point, one guarantee: whatever the
        // extractors did, what leaves here is valid UTF-8.
        //
        // It was not before. The pure-PHP parser could return text containing U+FFFD runs
        // and raw invalid byte sequences, and that string went on to be stored in the
        // database, compared against other submissions and quoted back to a trainer as
        // the student's own words. Invalid UTF-8 in a text column is not a cosmetic
        // problem: preg functions with /u silently return false on it - which is how
        // authenticity checks can come back empty on a document full of findings - and
        // json_encode() of the analysis fails outright, which is how a submission ends up
        // stuck with no stored result at all.
        return self::ensure_utf8($text);
    }

    /**
     * How the text of the file most recently passed to extract() was obtained.
     *
     * Reset at the start of every extract() call, so it describes that call and no other.
     * Read it immediately afterwards.
     *
     * This exists because OCR text must be LABELLED. DocGuard quotes a student's own
     * sentences back to a trainer - in the evidence beside a finding, and in the
     * verification questions a trainer is meant to ask out loud. OCR does not reproduce
     * those sentences exactly: measured on a scanned submission, "SIT30821" came back as
     * "S1T30821" and every "I" as "|". Presenting that as the student's wording, to be
     * read aloud in a conversation about their conduct, would be putting words in their
     * mouth. The report has to say where the text came from.
     *
     * @var string One of '', 'pdftotext', 'ghostscript', 'builtin', 'ocr-pdf-image',
     *             'ocr-pdf-raster', 'ocr-image' or 'docx'.
     */
    private static $lastmethod = '';

    /**
     * How the last extraction was done.
     *
     * @return string The method name, or '' when nothing has been extracted yet.
     */
    public static function last_method(): string {
        return self::$lastmethod;
    }

    /**
     * Was the last extraction produced by OCR rather than read from the file's text?
     *
     * @return bool True when the text came from optical character recognition.
     */
    public static function last_was_ocr(): bool {
        return strpos(self::$lastmethod, 'ocr-') === 0;
    }

    /**
     * Minimum characters of real text before a PDF is treated as having a text layer.
     *
     * Below this a PDF is a scan, or near enough: a scanned page often carries a few
     * characters of stray text from a header stamp or a watermark, and treating those as
     * the document's text is how a scanned submission gets reported as a 12-word
     * assessment rather than as a scan.
     *
     * @var int
     */
    const PDF_TEXT_LAYER_MIN_CHARS = 120;

    /**
     * Seconds allowed for one OCR pass over one page.
     *
     * Tesseract on a 200 dpi A4 page takes a few seconds. The ceiling is here for the
     * same reason every other external call in this file has one: this runs in cron, and
     * an image crafted to be pathological must not be able to hold a worker forever.
     *
     * @var int
     */
    const OCR_TIMEOUT_SECONDS = 120;

    /**
     * Most pages of one PDF that will be put through OCR.
     *
     * OCR costs seconds per page where reading a text layer costs milliseconds. A cap
     * keeps one 300-page scan from occupying a cron worker for an hour; the first pages
     * carry the cover sheet and the early answers, which is what the checks need most.
     *
     * @var int
     */
    const OCR_MAX_PAGES = 12;

    /**
     * Read a submitted image by OCR.
     *
     * @param string $filepath Absolute path to a readable copy of the image.
     * @return string The recognised text.
     * @throws \Exception When OCR is unavailable or recognises nothing.
     */
    public static function extract_image(string $filepath): string {
        if (!self::ocr_available()) {
            throw new \Exception(
                'This submission is an image, and this site cannot read text from images. '
                . 'Ask the student to resubmit the original Word document or PDF.'
            );
        }

        $text = self::ocr_file($filepath);
        if ($text === '') {
            throw new \Exception(
                'No text could be recognised in this image. It may be too blurred, too '
                . 'dark, or handwritten. Ask the student for the original document.'
            );
        }

        self::$lastmethod = 'ocr-image';
        return $text;
    }

    /**
     * Run Tesseract over one image file.
     *
     * @param string $imagepath Absolute path to the image.
     * @return string The recognised text, or '' when nothing was recognised.
     */
    private static function ocr_file(string $imagepath): string {
        // Tesseract writes to <base>.txt and insists on appending the extension itself.
        $base = tempnam(sys_get_temp_dir(), 'dg_ocr_');
        if ($base === false) {
            return '';
        }
        @unlink($base);

        $cmd = 'timeout ' . self::OCR_TIMEOUT_SECONDS . ' tesseract '
             . escapeshellarg($imagepath) . ' ' . escapeshellarg($base)
             . ' --psm 1 -l eng 2>/dev/null';
        $retval = 0;
        exec($cmd, $ignored, $retval);

        $out  = $base . '.txt';
        $text = '';
        if (file_exists($out)) {
            $read = file_get_contents($out);
            if ($read !== false) {
                $text = $read;
            }
            @unlink($out);
        }

        if ($retval !== 0 && trim($text) === '') {
            return '';
        }
        return trim(self::ensure_utf8($text));
    }

    /**
     * Read a scanned PDF by OCR, preferring the route that needs no external rasteriser.
     *
     * A scanned PDF is a page image inside a PDF wrapper, and how it must be handled
     * depends only on how that image is encoded:
     *
     *  - /DCTDecode is a JPEG, stored in the PDF as a complete JPEG file. It can be copied
     *    straight out and handed to Tesseract, with NO rasteriser involved. This is the
     *    common case by a wide margin - phone scanning apps, Adobe Scan, Office Lens and
     *    most "scan to PDF" tools all produce it. Measured on a two-page scan of a pasted
     *    ChatGPT answer: 456 words recognised, identical to the rasterised route, and 9 of
     *    the 10 findings the original text layer produced.
     *  - CCITT G4, JBIG2 and raw raster images come from office copiers in
     *    black-and-white mode. Decoding those in PHP is not reasonable, so they need
     *    Ghostscript to turn the page into an image first.
     *
     * Trying the embedded-image route first is what keeps the dependency list down to
     * Tesseract alone for most submissions.
     *
     * @param string $filepath Absolute path to a readable copy of the PDF.
     * @return string The recognised text, or '' when OCR is unavailable or found nothing.
     */
    private static function ocr_pdf(string $filepath): string {
        if (!self::ocr_available()) {
            return '';
        }

        // Route 1: JPEGs already inside the PDF. No external rasteriser.
        $text = '';
        foreach (self::embedded_jpegs($filepath) as $jpegpath) {
            $text .= self::ocr_file($jpegpath) . "\n\n";
            @unlink($jpegpath);
        }
        if (trim($text) !== '') {
            self::$lastmethod = 'ocr-pdf-image';
            return trim($text);
        }

        // Route 2: rasterise with Ghostscript, for bitonal and raw-raster scans.
        if (!self::cli_available('gs')) {
            return '';
        }
        $dir = make_temp_directory('docguard') . '/' . uniqid('dg_raster_', true);
        if (!make_writable_directory($dir, false)) {
            return '';
        }
        try {
            // -dSAFER for the same reason as extract_pdf(): this is a file a student
            // uploaded, and without it Ghostscript honours PostScript operators that read
            // and write arbitrary paths.
            $cmd = 'timeout ' . (self::OCR_TIMEOUT_SECONDS * 2) . ' gs -dSAFER -dNOPAUSE -dBATCH'
                 . ' -dQUIET -sDEVICE=png16m -r200'
                 . ' -dFirstPage=1 -dLastPage=' . self::OCR_MAX_PAGES
                 . ' -sOutputFile=' . escapeshellarg($dir . '/p%03d.png')
                 . ' ' . escapeshellarg($filepath) . ' 2>/dev/null';
            $retval = 0;
            exec($cmd, $ignored, $retval);

            $pages = glob($dir . '/p*.png') ?: [];
            sort($pages);
            foreach ($pages as $page) {
                $text .= self::ocr_file($page) . "\n\n";
            }
        } finally {
            foreach ((glob($dir . '/*') ?: []) as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }

        if (trim($text) === '') {
            return '';
        }
        self::$lastmethod = 'ocr-pdf-raster';
        return trim($text);
    }

    /**
     * Write every JPEG image embedded in a PDF out to its own temporary file.
     *
     * A /DCTDecode stream IS a JPEG file, byte for byte, so this is a copy rather than a
     * conversion. Only the image XObjects are taken, and only up to OCR_MAX_PAGES of them.
     *
     * @param string $filepath Absolute path to the PDF.
     * @return string[] Paths of the extracted JPEGs, for the caller to unlink.
     */
    private static function embedded_jpegs(string $filepath): array {
        $raw = file_get_contents($filepath);
        if ($raw === false) {
            return [];
        }

        $paths = [];
        if (
            !preg_match_all(
                '/\b(\d+)\s+\d+\s+obj\b([\s\S]*?)\bendobj\b/',
                $raw,
                $objects,
                PREG_SET_ORDER
            )
        ) {
            return [];
        }

        foreach ($objects as $object) {
            $body = $object[2];
            if (
                strpos($body, '/DCTDecode') === false ||
                !preg_match('/\/Subtype\s*\/Image\b/', $body)
            ) {
                continue;
            }
            // extract_stream() stops at an unsupported filter and hands back the raw
            // bytes, which for DCTDecode is exactly the JPEG wanted.
            $jpeg = self::extract_stream($body);
            if ($jpeg === null || strlen($jpeg) < 1024) {
                continue;
            }
            // Must actually be a JPEG: SOI marker, and EOI somewhere after it.
            if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'dg_jpg_');
            if ($path === false || file_put_contents($path, $jpeg) === false) {
                if ($path !== false) {
                    @unlink($path);
                }
                continue;
            }
            $paths[] = $path;

            if (count($paths) >= self::OCR_MAX_PAGES) {
                break;
            }
        }

        return $paths;
    }

    /**
     * Force a string to valid UTF-8, dropping only what cannot be represented.
     *
     * @param string $text Text as an extractor produced it.
     * @return string The same text as valid UTF-8.
     */
    public static function ensure_utf8(string $text): string {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Substitute rather than delete, so that offsets move as little as possible -
        // authenticity::evidence() quotes by offset, and a silent shift puts the quoted
        // excerpt in the wrong place.
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $clean = (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);

        if (mb_check_encoding($clean, 'UTF-8')) {
            return $clean;
        }

        // Last resort: keep only the bytes that form valid sequences.
        return (string)preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*|[\x80-\xBF]+/', '', $text);
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

        /*
         * V1.2.0 hardening. word/document.xml comes out of a file a student uploaded, so it is
         * hostile input and the parser flags here are a security boundary.
         *
         * LIBXML_NONET forbids the parser fetching anything over the network, so a crafted
         * document cannot make the Moodle server issue an outbound request - the usual version
         * of that attack points an entity at a cloud instance-metadata endpoint.
         *
         * Entity SUBSTITUTION is off because LIBXML_NOENT is NOT passed, and that is what
         * stops both XXE file disclosure and entity-expansion denial of service. Verified on
         * PHP 8.4 / libxml 2.9.14: an entity pointing at a local file yields nothing, and a
         * ten-level billion-laughs bomb produced zero bytes in under a millisecond.
         *
         * That default is being relied on, so it is asserted rather than assumed. This plugin
         * runs on sites whose libxml version nobody here chose, and LIBXML_NOENT looks
         * innocuous enough that a future reader wanting "&amp;" handled properly might well
         * add it - which would silently turn file disclosure back on. See
         * extractor_test::test_docx_xml_parsing_is_hardened_against_entities().
         */
        $dom = new \DOMDocument();
        @$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        $ns         = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraphs = $dom->getElementsByTagNameNS($ns, 'p');
        $lines      = [];
        foreach ($paragraphs as $para) {
            $line = trim($para->textContent);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        self::$lastmethod = 'docx';
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
                if (self::looks_readable($t) && self::has_text_layer($t)) {
                    self::$lastmethod = 'pdftotext';
                    return $t;
                }
            }
        }

        // 2. Ghostscript (gs) — text device
        if (self::cli_available('gs')) {
            $tmpout = tempnam(sys_get_temp_dir(), 'dg_gs_');
            // Timeout 60: gs can hang indefinitely on corrupt PDFs (confirmed incident July 2026
            // where a single bad PDF spawned 20+ stuck gs processes at 92% CPU each, load avg 47).
            //
            // V1.0.92 SEC-DG-GS-SAFER: -dSAFER is mandatory here. Every PDF reaching this
            // method is a file a student uploaded. Without -dSAFER, Ghostscript honours
            // PostScript operators that read and write arbitrary paths the web server user
            // can reach, and on affected versions executes commands through %pipe% device
            // names — so a crafted "submission" becomes file disclosure or worse on the
            // Moodle host. Ghostscript enables SAFER by default from 9.50, but this plugin
            // declares no minimum Ghostscript version and runs whatever binary the host
            // provides, so the flag is passed explicitly rather than assumed.
            //
            // -dSAFER does not restrict reading the input file named on the command line,
            // so text extraction is unaffected.
            $cmd    = 'timeout 60 gs -dSAFER -dNOPAUSE -dBATCH -dQUIET -sDEVICE=txtwrite -dNoOutputFonts'
                    . ' -sOutputFile=' . escapeshellarg($tmpout)
                    . ' ' . escapeshellarg($filepath) . ' 2>/dev/null';
            $retval = 0;
            exec($cmd, $dummy, $retval);
            if ($retval === 0 && file_exists($tmpout)) {
                $t = file_get_contents($tmpout);
                @unlink($tmpout);
                if ($t !== false && self::looks_readable($t) && self::has_text_layer($t)) {
                    self::$lastmethod = 'ghostscript';
                    return $t;
                }
            } else {
                @unlink($tmpout);
            }
        }

        // 3. DocGuard's own reader. No external tool of any kind.
        //
        // V1.2.5: this tier used to be the one to apologise for. Six defects were found
        // and fixed in it this release - see extract_pdf_php() and the FIX-DG-PDF-*
        // comments - and on an 18-document corpus covering LibreOffice, Word-style object
        // streams, ReportLab filter chains, WebKit per-glyph positioning and nested
        // resource dictionaries it now produces text identical to pdftotext on 17 of them,
        // the eighteenth being a password-protected file that it reports as such. It is
        // still third in the cascade because the CLI tools have seen far more of the
        // world's broken PDFs than this parser has, not because its output is worse.
        $thrown = null;
        $phptext = '';
        // Evaluated ONCE and remembered. looks_readable() reports every rejection through
        // debugging(), so asking it the same question twice tells the administrator the
        // same thing twice about one document - which trains people to skim the log.
        $phpreadable = false;
        try {
            $phptext = self::extract_pdf_php($filepath);
            // V1.2.5: the readability guard is applied to THIS tier too. It never was
            // before, which is the most consequential gap of the lot: the two CLI tiers
            // were checked and the built-in reader - the one that was actually producing
            // scrambled text - was accepted unconditionally, so nothing in the plugin
            // could notice. With the guard here, a scrambled extraction falls through to
            // OCR, and failing that is reported rather than stored.
            $phpreadable = trim($phptext) !== '' && self::looks_readable($phptext);
            if (self::has_text_layer($phptext) && $phpreadable) {
                self::$lastmethod = 'builtin';
                return $phptext;
            }
        } catch (\Throwable $e) {
            // Held, not swallowed: if OCR cannot help either, this is the message the
            // trainer should see, because it says what is actually wrong with the file.
            $thrown = $e;
        }

        // 4. OCR, when this host has it. Only reached when no tier found a text layer,
        //    which is what a scanned submission looks like.
        $ocr = self::ocr_pdf($filepath);
        if ($ocr !== '') {
            return $ocr;
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        // Nothing found a text layer. Say which situation this is, because the action the
        // trainer should take differs: one needs a different file from the student, one
        // needs a package on the server, and one is a file nothing can read.
        if (self::looks_like_a_scan($filepath)) {
            throw new \Exception(self::ocr_available()
                ? 'This submission is a scan and no text could be recognised in it. It may '
                    . 'be too blurred, too dark, or handwritten.'
                : 'This submission is a scanned image with no text in it, and this site '
                    . 'cannot read text from images. Ask the student for the original file.');
        }

        /*
         * V1.2.5. This refusal is the point of the readability guard, and leaving it out
         * was a defect in the first draft of this method: the guard was evaluated, the
         * cascade fell through it correctly, and then the final return handed back the
         * unreadable text anyway. Measured on a PDF whose font table maps every glyph to an
         * unencodable code point: extract_pdf() returned 252 characters of U+FFFD, by way
         * of a tier that had just been told the text was no good.
         *
         * Refusing is better than returning it. Those 252 characters would be stored,
         * compared against the cohort as though they were the student's writing, and shown
         * to a trainer as the document's text. An error that names the problem lets
         * somebody act; a page of replacement characters does not.
         */
        if (trim($phptext) !== '' && !$phpreadable) {
            throw new \Exception(
                'The text in this PDF could not be decoded - the file uses fonts whose '
                . 'character information is missing or broken. Ask the student to resubmit '
                . 'as a Word document, or to export the PDF again.'
            );
        }

        // Readable, just shorter than the scan floor: a genuinely brief answer. Returned,
        // because the floor exists to spot scans and not to set a minimum word count.
        self::$lastmethod = 'builtin';
        return $phptext;
    }

    /**
     * Does this extracted text amount to a real text layer, or is it scan residue?
     *
     * V1.2.5. A scanned page is not always completely textless. A header stamp, a
     * watermark, a page number added by the scanning app, or a stray OCR layer from
     * whatever produced the file leaves a handful of characters behind. The cascade used to
     * accept any output that was READABLE, so those few characters were taken as the
     * document's text: the submission was analysed as a twelve-word assessment, scored,
     * and reported to the trainer as if it had been read.
     *
     * Requiring a minimum amount of text before calling it a text layer is what lets the
     * cascade fall through to OCR for a scan while leaving every genuine document alone.
     * The floor is deliberately well below any real submission - 120 characters is about
     * twenty words - so it cannot reject a short but genuine answer.
     *
     * @param string $text The text a tier produced.
     * @return bool True when there is enough text to treat the PDF as text-based.
     */
    private static function has_text_layer(string $text): bool {
        return \core_text::strlen(trim($text)) >= self::PDF_TEXT_LAYER_MIN_CHARS;
    }

    /**
     * Does this PDF look like a scan - page images and no text?
     *
     * Only reached once every tier has failed to find a text layer, so the question here
     * is narrower than it sounds: is there an image on the page that the text could have
     * been in? If so the file is a photograph of a document, whatever encoded it.
     *
     * Deliberately not limited to JPEG. The embedded-JPEG OCR route only handles
     * /DCTDecode, but a scan from an office copier is CCITT or a raw raster, and getting
     * the MESSAGE right matters most for exactly those files - they are the ones OCR
     * cannot reach without a rasteriser, so they are the ones whose trainer most needs to
     * be told what is going on.
     *
     * Used only to choose the message. It changes no analysis.
     *
     * @param string $filepath Absolute path to the PDF.
     * @return bool True when the PDF carries at least one image object.
     */
    private static function looks_like_a_scan(string $filepath): bool {
        $raw = file_get_contents($filepath);
        if ($raw === false) {
            return false;
        }
        return (bool)preg_match('/\/Subtype\s*\/Image\b/', $raw);
    }

    /**
     * Check whether a CLI command exists and is executable.
     *
     * @param string $cmd The command name to look for, e.g. "pdftotext".
     * @return bool True when the command was found on the PATH.
     */
    /**
     * Which PDF extraction tier this server will actually use.
     *
     * V1.1.3. PDF text is extracted by a three-tier cascade - pdftotext, then Ghostscript,
     * then a pure-PHP fallback - and until now nothing anywhere told an administrator
     * which tier their server was on. Found when the plugin's own test suite skipped a
     * digit-heavy extraction test on a live server because pdftotext was not installed:
     * the extraction was silently running on the lowest tier and no page said so.
     *
     * This is not cosmetic. Extraction quality decides whether copy detection works at
     * all - the comparison runs on extracted text, so a garbled extraction produces a
     * similarity figure about nothing. An administrator should not have to read the source
     * to find out which one they have.
     *
     * V1.2.5. The advice this method returns has CHANGED, and the reason is a measurement.
     *
     * It used to tell every administrator without poppler-utils to install it, and told
     * those without poppler OR Ghostscript that the built-in reader "is the least reliable
     * of the three and may produce poor text". Both statements were true when written and
     * the second was an understatement: the built-in reader was returning scrambled text.
     *
     * Six defects in it were found and fixed this release. On an 18-document corpus
     * spanning LibreOffice, Word-style object streams, ReportLab filter chains, WebKit
     * per-glyph positioning, nested resource dictionaries and a password-protected file,
     * the built-in reader now produces text identical to pdftotext on 17 of 18, and
     * reports the eighteenth accurately as password-protected.
     *
     * So the old advice now points at the wrong thing. Telling an administrator to get a
     * package installed - which on managed Moodle hosting can mean a support ticket and a
     * fortnight - in exchange for no measurable difference is not advice, it is noise, and
     * noise in an admin panel teaches people to ignore the panel.
     *
     * What IS worth asking for is Tesseract, and only if the site wants scanned
     * submissions read. That buys a capability the plugin cannot otherwise have at all.
     *
     * @return array{tier: string, quality: string, pdftotext: bool, ghostscript: bool,
     *               tesseract: bool, ocrpdf: bool, ocrimages: bool, advice: string}
     */
    public static function extraction_tools(): array {
        $pdftotext   = self::cli_available('pdftotext');
        $ghostscript = self::cli_available('gs');
        $tesseract   = self::cli_available('tesseract');

        if ($pdftotext) {
            $tier    = 'pdftotext';
            $quality = 'best';
        } else if ($ghostscript) {
            $tier    = 'ghostscript';
            $quality = 'best';
        } else {
            $tier    = 'builtin';
            $quality = 'best';
        }

        return [
            'tier'        => $tier,
            'quality'     => $quality,
            'pdftotext'   => $pdftotext,
            'ghostscript' => $ghostscript,
            'tesseract'   => $tesseract,
            // Scanned PDFs: the embedded-JPEG route needs nothing but Tesseract; the
            // bitonal-copier route needs a rasteriser as well.
            'ocrpdf'      => $tesseract,
            'ocrimages'   => $tesseract,
            'advice'      => $tesseract
                ? ''
                : 'Scanned PDFs and photographs cannot be read on this server. To enable '
                    . 'that, install Tesseract OCR: "apt-get install tesseract-ocr" on '
                    . 'Debian or Ubuntu, "yum install tesseract" on RHEL or CentOS. Text '
                    . 'PDFs and Word documents are unaffected and need nothing installed.',
        ];
    }

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
     * V1.2.5: public, where it used to be private.
     *
     * This method is the cascade's ACCEPTANCE TEST - it decides, for every tier, whether
     * an extraction is used or discarded in favour of the next one. That makes it part of
     * the extractor's behaviour rather than an implementation detail, and it needs to be
     * testable directly: mutation testing showed that with it private, reversing its
     * guards broke nothing any test could see, because no public entry point reaches the
     * branch that matters. A guard no test can fail is not a guard.
     *
     * @param string $text The candidate extracted text.
     * @return bool True when enough of the characters are letters for the text to be usable.
     */
    public static function looks_readable(string $text): bool {
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
            /*
             * FIX-DG-READABLE-INVALID-UTF8 (v1.2.5). This branch used to fall back to an
             * ASCII ratio test and RETURN TRUE for text that was not valid UTF-8 at all.
             * Scrambled output is mostly letters, so it scored about 0.9 and was accepted
             * every time - which is how the garbled pure-PHP extraction reached the
             * database rather than falling through to the next tier.
             *
             * Invalid UTF-8 is not a language this plugin does not recognise; it is not
             * text. It cannot be stored, compared with /u patterns, or shown to a trainer.
             * Rejecting it is the whole point of this method.
             */
            \debugging(
                'DocGuard: extracted text is not valid UTF-8; rejecting this extraction '
                    . 'and trying the next method.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        $stripped = preg_replace('/\s+/u', '', $text);
        $total    = max(1, \core_text::strlen((string)$stripped));
        if (($letters / $total) <= 0.45) {
            // V1.2.5: say so. Every other rejection in this method reports itself, and
            // this one - the oldest - did not, so an extraction discarded here was
            // discarded silently. That is how a site runs for months on the wrong tier
            // with nothing on any page to show it, which is the defect V1.1.3 existed to
            // fix at the other end.
            \debugging(
                'DocGuard: only ' . round(($letters / $total) * 100) . '% of the extracted '
                    . 'text is letters or digits; rejecting this extraction and trying the '
                    . 'next method.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        /*
         * FIX-DG-READABLE-REPLACEMENT-CHARS (v1.2.5). A dense run of U+FFFD means the
         * decoder met codes it could not map - a missing or wrong character table - and
         * what came out is not the document's text. Real text does not contain these:
         * across the 73-document corpus the highest count in a good extraction is zero.
         * One per cent is generous, and was measured at 3.6% on the ligature failure this
         * release also fixes.
         */
        $replacements = (int)preg_match_all('/\x{FFFD}/u', $text);
        if ($replacements > 0 && ($replacements / $total) > 0.01) {
            \debugging(
                'DocGuard: ' . $replacements . ' unmappable characters in the extracted text; '
                    . 'rejecting this extraction and trying the next method.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        /*
         * There is DELIBERATELY NO function-word or language-plausibility check here, and
         * it must not be added. One was written for this release, measured, and removed
         * before it shipped. The measurements are recorded because they are the argument.
         *
         * The idea was sound on its face. The two guards above cannot see the failure that
         * matters most: when a PDF's character tables are misapplied, every byte maps to
         * SOME letter, so the output is 100% letters, valid UTF-8, free of replacement
         * characters - and complete nonsense. "Student name" arrives as "Asemnts tCon" and
         * is accepted, stored, compared and quoted to a trainer. What distinguishes
         * scrambled English from English is function words: about a third of real English
         * prose, and essentially absent from a substitution of it.
         *
         * Measured against an English list, as a share of tokens:
         *
         *   correctly extracted English : 0.288 - 0.576
         *   scrambled extraction        : 0.097 - 0.149
         *
         * A clean separation, and on that basis the check looked ready. Then it was
         * measured against real prose in the other languages an Australian VET cohort
         * actually writes in:
         *
         *   French 0.024   Spanish 0.000   Vietnamese 0.025   Indonesian 0.000
         *   German 0.056   Tagalog 0.050   Somali 0.041   Swahili 0.000
         *
         * Every one of them scores BELOW the scrambled text. The check would have rejected
         * genuine submissions from those students while accepting the very garbage it was
         * written to catch.
         *
         * Widening the list to several languages does not fix it, it moves the hole.
         * Measured with a six-language list: real prose 0.275 - 0.576 against scrambled
         * 0.097 - 0.149, which separates - until it meets a language that was not on the
         * list, at which point Tagalog scores 0.050 and Swahili 0.000 and a real student's
         * work is declared unreadable. There will always be a language that was not on the
         * list.
         *
         * This is V1.0.80 in new clothes. That release existed because this method used to
         * count only a-z and therefore punished every document in a non-Latin script; the
         * fix was to stop asking English questions about text that is not English. A
         * function-word check asks the same question again, more politely.
         *
         * It is removed rather than tuned, on the same reasoning as the unit-code format
         * check in authenticity.php: a test that either rejects real work or, widened
         * enough to be safe, verifies nothing, is worse than not having one.
         *
         * What remains are the two guards above, and they are kept precisely because
         * neither asks what language the student wrote in. Invalid UTF-8 is not text in any
         * language. A document that is 1% U+FFFD has not been decoded in any language. Both
         * are facts about the bytes. The scrambling they do not catch is fixed at its
         * source in this release - the six FIX-DG-PDF-* defects above - which is where a
         * decoding fault belongs, rather than being guessed at after the fact from the
         * shape of the words.
         *
         * See pdf_reader_test::test_no_language_plausibility_check_is_reintroduced().
         */
        return true;
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

        /* ── Phase A-2: Refuse an encrypted PDF rather than returning its noise ── */
        //
        // FIX-DG-PDF-ENCRYPTED (v1.2.5). A PDF with an /Encrypt dictionary has every
        // string and stream encrypted, usually RC4 or AES. Decoding one needs the standard
        // security handler - the padding string, the key derivation, the per-object key -
        // which this parser does not implement and is not the right place to.
        //
        // What it did before was worse than not supporting them: it inflated the encrypted
        // streams anyway, got bytes that were not a content stream, extracted nothing, and
        // returned an empty string. The caller reported "Extracted text is too short or
        // empty. The file may be scanned/image-based." - which sent the trainer looking for
        // a scanner when the real answer was that the file is password-protected.
        //
        // Most of these are not malicious. Students password-protect documents, and some
        // "compress PDF" web tools return an encrypted file. An accurate message lets the
        // trainer ask for an unprotected copy. Note that the CLI tiers handle these
        // perfectly well when the owner password is the only one set, so this path is only
        // reached on a host with no pdftotext and no Ghostscript.
        if (preg_match('/\/Encrypt\s+(?:\d+\s+\d+\s+R|<<)/', $raw)) {
            throw new \Exception(
                'This PDF is password-protected, so its text cannot be read. '
                . 'Ask the student to resubmit without the password.'
            );
        }

        /* ── Phase A-3: Expand object streams (PDF 1.5+) ─────────────────────── */
        //
        // FIX-DG-PDF-OBJSTM (v1.2.5). From PDF 1.5 a producer may pack most of the
        // document's objects - including every font dictionary - inside a compressed
        // /Type /ObjStm stream. Microsoft Word does this by default, as do most current
        // producers. The object index above is a regex for "N 0 obj" in the raw bytes,
        // which by construction cannot see an object that is inside a compressed stream.
        //
        // The visible symptom was the same scrambled text as FIX-DG-PDF-INDIRECT-FONT:
        // the font dictionaries were unreachable, so no per-font CMap was built and the
        // union fallback decoded everything with the wrong table. Measured on the same
        // document rewritten with --object-streams=generate: "Student name" came out
        // "Asemnts tCon" again, while the identical uncompressed file extracted perfectly.
        //
        // The format is simple: /N pairs of "objnum offset" at the start of the stream,
        // then the object bodies, the first at /First. Objects inside an object stream
        // cannot themselves contain streams, so nothing here needs re-inflating.
        $objbodies = self::expand_object_streams($raw, $objbodies);

        /* ── Phase B: Inflate each object's stream independently ─────────────── */
        $objstreams = []; // Obj_num => inflated (or raw) stream bytes.
        foreach ($objbodies as $num => $body) {
            $stream = self::extract_stream($body);
            if ($stream !== null) {
                $objstreams[$num] = $stream;
            }
        }

        /* ── Phase C: Find all ToUnicode CMap tables ─────────────────────────── */
        // Obj_num => ['bytes' => 1|2|0, 'map' => [int_code => utf8_string]].
        $tounicodebyobj = [];
        foreach ($objstreams as $num => $stream) {
            if (
                strpos($stream, 'beginbfchar') !== false ||
                strpos($stream, 'beginbfrange') !== false
            ) {
                $table = self::parse_cmap_stream($stream);
                if (!empty($table['map'])) {
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

        // D-2: Resolve font resource names PER PAGE, from that page's own /Resources.
        //
        // FIX-DG-PDF-PAGE-FONTS (v1.2.5). Two problems were fixed together here, because
        // either one alone still produces wrong letters.
        //
        // 1. The old code searched for an INLINE "/Font << ... >>" dictionary only. Word
        //    and LibreOffice write "/Font 78 0 R" instead, so no font mapping was built at
        //    all and every string fell through to the union CMap. See dict_value().
        //
        // 2. Font resource names are scoped to the PAGE, not the document. "/F1" on page 1
        //    and "/F1" on page 2 routinely name different font objects. The old code kept
        //    one document-wide table with "first resource dict wins", so in a multi-page
        //    document every later page was decoded with the first page's fonts.
        //
        // Walking the page tree fixes both. Each page's content stream is decoded with the
        // fonts that page actually declares, following /Parent for a page that inherits
        // its /Resources - which is the usual shape, since LibreOffice hangs one shared
        // /Resources off the /Pages node.
        $contentfontmaps = []; // Content stream obj num => [resource name => cmap].

        foreach ($objbodies as $pagenum => $pagebody) {
            if (!preg_match('/\/Type\s*\/Page\b(?!s)/', $pagebody)) {
                continue;
            }
            $res = self::page_resources($pagenum, $objbodies);
            if ($res === null) {
                continue;
            }
            $fontdict = self::dict_value($res, 'Font', $objbodies);
            if ($fontdict === null) {
                continue;
            }
            $maps = self::cmaps_from_font_dict($fontdict, $fontobjtocmap, $objbodies, $tounicodebyobj);
            if (empty($maps)) {
                continue;
            }

            // /Contents is one reference or an array of them.
            if (preg_match('/\/Contents\s*\[([^\]]*)\]/', $pagebody, $cm)) {
                if (preg_match_all('/(\d+)\s+\d+\s+R/', $cm[1], $cl)) {
                    foreach ($cl[1] as $cnum) {
                        $contentfontmaps[(int)$cnum] = $maps;
                    }
                }
            } else if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pagebody, $cm)) {
                $contentfontmaps[(int)$cm[1]] = $maps;
            }

            // Document-wide table, for content streams the page tree does not account
            // for - an XObject appearance stream, or a page whose /Contents could not be
            // read. Still far better than the union: these are real per-font tables.
            foreach ($maps as $rname => $cmap) {
                if (!isset($fontcmaps[$rname])) {
                    $fontcmaps[$rname] = $cmap;
                }
            }
        }

        // D-3: Any /Font dictionary reachable anywhere, for PDFs whose page tree could not
        // be walked at all. Inline and indirect forms both, and nested dictionaries
        // balanced properly.
        $searchsources = [$raw];
        foreach ($objstreams as $s) {
            $searchsources[] = $s;
        }
        foreach ($searchsources as $src) {
            if (!preg_match_all('/\/Font\b/', $src, $fm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($fm[0] as $hit) {
                $fontdict = self::dict_value(
                    substr($src, (int)$hit[1], 4096),
                    'Font',
                    $objbodies
                );
                if ($fontdict === null) {
                    continue;
                }
                foreach (
                    self::cmaps_from_font_dict(
                        $fontdict,
                        $fontobjtocmap,
                        $objbodies,
                        $tounicodebyobj
                    ) as $rname => $cmap
                ) {
                    if (!isset($fontcmaps[$rname])) {
                        $fontcmaps[$rname] = $cmap;
                    }
                }
            }
        }

        /* ── Phase E: Build union fallback CMap ──────────────────────────────── */
        // Last resort only, when the active font name is unknown and has no table of its
        // own. It is known to be wrong across subset fonts - see dict_value() - so
        // anything that reduces how often it is reached is worth doing.
        $unionmap   = [];
        $unionbytes = 0;
        foreach ($tounicodebyobj as $cmap) {
            $unionmap += $cmap['map']; // First mapping wins (array union).
            // Mixing widths here is unavoidable - that is what makes the union a last
            // resort - so take the widest declared, which at least stays in step with
            // the bytes of the fonts that need two.
            if ((int)$cmap['bytes'] > $unionbytes) {
                $unionbytes = (int)$cmap['bytes'];
            }
        }
        $unioncmap = empty($unionmap) ? [] : ['bytes' => $unionbytes, 'map' => $unionmap];

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
            // This page's own fonts when the page tree named them; the document-wide
            // table otherwise.
            $streamfonts = $contentfontmaps[$num] ?? $fontcmaps;
            $text .= self::extract_from_stream($stream, $streamfonts, $unioncmap);
        }

        /* ── Phase G: Last-resort scan if nothing found ──────────────────────── */
        //
        // FIX-DG-PDF-LASTRESORT-DUPLICATE (v1.2.5). This ran whenever the text so far was
        // under 30 characters, and APPENDED to it. Two defects in one condition:
        //
        //  - "Under 30 characters" is not "nothing found". A short but perfectly extracted
        //    document - a single-line answer, a cover sheet, the one-question submissions
        //    in this plugin's own sample pack - tripped it, and since this scan finds the
        //    same Tj operators the proper pass already read, the result was the document's
        //    text TWICE. Measured on a 25-character fixture: "Student name Jordan Ellis
        //    Student name Jordan Ellis". Duplicated text doubles the word count a trainer
        //    is shown, and feeds the similarity comparison a document that is not the one
        //    the student submitted.
        //  - It decodes with the union CMap, which is known to be wrong across subset
        //    fonts. Appending union-decoded text to correctly decoded text mixes good
        //    output with bad and leaves no way to tell which is which.
        //
        // It now runs only when the proper pass found NOTHING, and replaces rather than
        // appends. As a genuine last resort that is all it was ever meant to be: better
        // than returning an empty string, worse than anything above it.
        if (trim($text) === '') {
            $salvaged = '';
            foreach ($objstreams as $stream) {
                // Hex-encoded string shown with a Tj operator.
                if (preg_match_all('/<([0-9a-fA-F\s]{2,})>\s*Tj/', $stream, $hm)) {
                    foreach ($hm[1] as $h) {
                        $salvaged .= self::decode_hex($h, $unioncmap) . ' ';
                    }
                }
                // Literal string shown with a Tj operator.
                if (preg_match_all('/\(([^)]{2,})\)\s*Tj/s', $stream, $lm)) {
                    foreach ($lm[1] as $l) {
                        $salvaged .= self::decode_literal($l, $unioncmap) . ' ';
                    }
                }
            }
            $text = $salvaged;
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
     * How many objects deep to follow /Parent when a page inherits its /Resources.
     *
     * The page tree in a real PDF is one or two levels. A crafted file can make /Parent
     * point back at its own page, and without a bound this walk would never return.
     *
     * @var int
     */
    const PAGE_TREE_MAX_DEPTH = 32;

    /**
     * Largest number of objects one /ObjStm is allowed to declare.
     *
     * A real object stream holds tens to a few hundred objects. /N comes from the file, so
     * a crafted value asks this parser for an unbounded loop - the same shape of defect as
     * V1.0.87 FIX-DG-BFRANGE-UNBOUNDED, and in the one PDF path with no `timeout 60`
     * around it.
     *
     * @var int
     */
    const OBJSTM_MAX_OBJECTS = 20000;

    /**
     * Add the objects packed inside every /Type /ObjStm to the object index.
     *
     * See FIX-DG-PDF-OBJSTM in extract_pdf_php() for why this exists.
     *
     * Objects already found in the raw bytes win: an incremental update appends a newer
     * top-level copy of an object that an older object stream still contains, and the
     * top-level one is the current version.
     *
     * @param string $raw The whole PDF file as read from disk.
     * @param array $objbodies Object bodies found in the raw bytes, keyed by object number.
     * @return array The index with each object stream's contents merged in.
     */
    private static function expand_object_streams(string $raw, array $objbodies): array {
        foreach ($objbodies as $body) {
            if (strpos($body, '/ObjStm') === false) {
                continue;
            }
            if (!preg_match('/\/N\s+(\d+)/', $body, $nm)) {
                continue;
            }
            if (!preg_match('/\/First\s+(\d+)/', $body, $fm)) {
                continue;
            }
            $count = (int)$nm[1];
            $first = (int)$fm[1];
            if ($count < 1) {
                continue;
            }
            if ($count > self::OBJSTM_MAX_OBJECTS) {
                debugging(
                    'DocGuard: object stream declares ' . $count . ' objects; reading the first '
                        . self::OBJSTM_MAX_OBJECTS . ' only - the PDF is malformed or hostile.',
                    DEBUG_DEVELOPER
                );
                $count = self::OBJSTM_MAX_OBJECTS;
            }

            $stream = self::extract_stream($body);
            if ($stream === null || $first < 0 || $first > strlen($stream)) {
                continue;
            }

            // The header is $count pairs of integers: object number, then its offset
            // from /First.
            $header = substr($stream, 0, $first);
            if (!preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER)) {
                continue;
            }

            $pairs = array_slice($pairs, 0, $count);
            foreach ($pairs as $i => $pair) {
                $objnum = (int)$pair[1];
                $offset = $first + (int)$pair[2];
                // Each object runs to the start of the next one, or to the end.
                if (isset($pairs[$i + 1])) {
                    $end = $first + (int)$pairs[$i + 1][2];
                } else {
                    $end = strlen($stream);
                }
                if ($offset < $first || $end <= $offset || $offset >= strlen($stream)) {
                    continue;
                }
                // A top-level definition of the same object is the newer one.
                if (isset($objbodies[$objnum])) {
                    continue;
                }
                $objbodies[$objnum] = substr($stream, $offset, $end - $offset);
            }
        }

        return $objbodies;
    }

    /**
     * Read one dictionary out of a PDF object body, balancing the << >> pairs.
     *
     * A PDF dictionary nests - "/Resources << /Font 78 0 R /XObject << /Im1 9 0 R >> >>"
     * is one dictionary containing another - so the non-greedy `<<([\s\S]*?)>>` this file
     * used before stops at the FIRST ">>", which is the inner one. On a page whose
     * resources carry an /XObject or /ExtGState before the fonts, that truncation loses
     * the /Font entry entirely.
     *
     * @param string $body The bytes to read from.
     * @param int $start Offset of the "<<" that opens the dictionary.
     * @return string|null The dictionary contents without the outer << >>, or null when
     *                     the braces do not balance.
     */
    private static function dict_at(string $body, int $start): ?string {
        $len = strlen($body);
        if ($start < 0 || $start + 1 >= $len || substr($body, $start, 2) !== '<<') {
            return null;
        }
        $depth = 0;
        for ($i = $start; $i < $len - 1; $i++) {
            $two = substr($body, $i, 2);
            if ($two === '<<') {
                $depth++;
                $i++;
                continue;
            }
            if ($two === '>>') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start + 2, $i - $start - 2);
                }
                $i++;
            }
        }
        return null;
    }

    /**
     * The value of one key in a PDF dictionary, following an indirect reference.
     *
     * FIX-DG-PDF-INDIRECT-FONT (v1.2.5). This is the method whose absence made the
     * built-in extractor produce scrambled text on PDFs written by Word and LibreOffice.
     *
     * A dictionary value may be written inline - "/Font << /F1 5 0 R >>" - or as an
     * indirect reference to another object - "/Font 78 0 R". Both are ordinary, and which
     * one a producer emits is its own business. Word and LibreOffice both emit the
     * indirect form. The previous code searched only for the inline form, so on those
     * files it found no font dictionary at all, built no resource-name-to-CMap table, and
     * fell through to the union of every font's CMap.
     *
     * The union is not a degraded mapping; it is a WRONG one. A subset font numbers its
     * glyphs from 1 in order of first use, so in a document with two subset fonts both
     * use codes 1, 2, 3 for entirely different letters. First-wins union means every
     * string drawn in the second font is decoded with the first font's table. Measured on
     * a two-font LibreOffice PDF: "Student name" came out "Asemnts tCon" and the unit code
     * "SITXFSA005" came out "AITXFAA005" - letters throughout, so looks_readable()
     * accepted it, and the garbage reached the database and the report.
     *
     * @param string $dictbody The dictionary contents to look in.
     * @param string $key The key name without its leading slash, e.g. "Font".
     * @param array $objbodies Raw object bodies, keyed by object number.
     * @return string|null The value's dictionary contents, or null when the key is absent
     *                     or its value is neither a dictionary nor a reference to one.
     */
    private static function dict_value(string $dictbody, string $key, array $objbodies): ?string {
        if (!preg_match('/\/' . preg_quote($key, '/') . '\b/', $dictbody, $km, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $after = (int)$km[0][1] + strlen($km[0][0]);
        $tail  = substr($dictbody, $after);

        // Indirect: "/Font 78 0 R".
        if (preg_match('/^\s*(\d+)\s+\d+\s+R/', $tail, $rm)) {
            $num = (int)$rm[1];
            if (!isset($objbodies[$num])) {
                return null;
            }
            $pos = strpos($objbodies[$num], '<<');
            return $pos === false ? null : self::dict_at($objbodies[$num], $pos);
        }

        // Inline: "/Font << ... >>".
        if (preg_match('/^\s*<</', $tail)) {
            $pos = strpos($tail, '<<');
            return $pos === false ? null : self::dict_at($tail, $pos);
        }

        return null;
    }

    /**
     * Map every font resource name in one /Font dictionary to its ToUnicode table.
     *
     * @param string $fontdict The contents of a /Font dictionary.
     * @param array $fontobjtocmap CMap tables already resolved per font object number.
     * @param array $objbodies Raw object bodies, keyed by object number.
     * @param array $tounicodebyobj Parsed ToUnicode tables, keyed by object number.
     * @return array Map of resource name => CMap table, for the fonts that have one.
     */
    private static function cmaps_from_font_dict(
        string $fontdict,
        array $fontobjtocmap,
        array $objbodies,
        array $tounicodebyobj
    ): array {
        $maps = [];
        if (!preg_match_all('/\/([^\s\/\[\]<>]+)\s+(\d+)\s+\d+\s+R/', $fontdict, $refs, PREG_SET_ORDER)) {
            return $maps;
        }
        foreach ($refs as $ref) {
            $resolved = self::resolve_font_cmap(
                (int)$ref[2],
                $fontobjtocmap,
                $objbodies,
                $tounicodebyobj
            );
            if ($resolved !== null) {
                $maps[$ref[1]] = $resolved;
            }
        }
        return $maps;
    }

    /**
     * The /Resources dictionary that applies to a page, following /Parent inheritance.
     *
     * A page without its own /Resources inherits the nearest ancestor's. Both pages of a
     * LibreOffice document commonly share one /Resources object through the /Pages node.
     *
     * @param int $pageobj Object number of the /Type /Page object.
     * @param array $objbodies Raw object bodies, keyed by object number.
     * @return string|null The resources dictionary contents, or null when none is reachable.
     */
    private static function page_resources(int $pageobj, array $objbodies): ?string {
        $seen = [];
        $num  = $pageobj;
        for ($depth = 0; $depth < self::PAGE_TREE_MAX_DEPTH; $depth++) {
            if (!isset($objbodies[$num]) || isset($seen[$num])) {
                return null;
            }
            $seen[$num] = true;
            $body       = $objbodies[$num];

            $res = self::dict_value($body, 'Resources', $objbodies);
            if ($res !== null) {
                return $res;
            }
            if (!preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $body, $pm)) {
                return null;
            }
            $num = (int)$pm[1];
        }
        return null;
    }

    /**
     * Extract and inflate the stream from a PDF object body string.
     * Returns null if the object has no stream.
     *
     * @param string $objbody The raw bytes of one "N 0 obj ... endobj" body.
     * @return string|null The decoded stream contents, or null if there is none or it
     *                     could not be decoded.
     */
    private static function extract_stream(string $objbody): ?string {
        /*
         * FIX-DG-PDF-ENDSTREAM-WORDBOUNDARY (v1.2.5). This method used to find the stream
         * data with:
         *
         *     /\bstream\b([\s\S]*?)\bendstream\b/
         *
         * The `\b` before `endstream` is the defect, and it is the most damaging single
         * character in this file's history. A word boundary needs a word character on one
         * side and a non-word character on the other. Stream data is arbitrary BYTES -
         * compressed, so effectively random - and the PDF specification does not require
         * any end-of-line before the `endstream` keyword. When the last byte of the data
         * happens to be a letter, a digit or an underscore, there is no boundary between
         * it and the `e` of `endstream`, the whole pattern fails to match, and this method
         * returns null as though the object had no stream at all.
         *
         * Roughly a quarter of all bytes are word characters, so this discarded about one
         * stream in four, AT RANDOM, in every PDF - deterministic for a given file, which
         * is why it never looked like a race. Found while investigating scrambled text
         * from a qpdf-written file: the second font's /ToUnicode object ended in the byte
         * "M", its table was silently dropped, and every word drawn in that font was
         * decoded with the other font's table. One missing word boundary was reported as
         * "Student name" extracting to "Asemnts tCon".
         *
         * The replacement does not rely on the data's last byte at all. The stream's
         * length is read from /Length when that is a direct integer, which is exact. When
         * /Length is an indirect reference - legal, and what some producers emit - the data
         * runs to the LAST `endstream` in the object body; the last rather than the first,
         * so that compressed data which happens to contain those nine bytes truncates
         * nothing.
         */
        if (
            !preg_match(
                '/(?<![A-Za-z0-9_])stream[ \t]*(?:\r\n|\n|\r)?/',
                $objbody,
                $sm,
                PREG_OFFSET_CAPTURE
            )
        ) {
            return null;
        }
        $start = (int)$sm[0][1] + strlen($sm[0][0]);
        $data  = null;

        // /Length as a direct integer. Excludes /Length1 and /Length2 (font programs)
        // via \b, and an indirect "/Length 12 0 R" via the second negative lookahead.
        //
        // (?!\d) is load-bearing and is not redundant with it. Without it, on
        // "/Length 66 0 R" PCRE matches the digits as "66", finds that " 0 R" satisfies
        // the indirect-reference lookahead, and then BACKTRACKS the digit run to "6" -
        // at which point the next character is "6", the lookahead's \s+ cannot match, the
        // negative lookahead succeeds, and the stream is read as SIX BYTES. That is not
        // hypothetical: it truncated page 2 of every LibreOffice document to nothing,
        // because LibreOffice writes content-stream lengths as indirect references.
        // Requiring the capture to end at a non-digit removes the shorter alternatives,
        // so the indirect form is rejected as intended and this falls through to the
        // endstream search below.
        if (preg_match('/\/Length\b\s+(\d+)(?!\d)(?!\s+\d+\s+R)/', $objbody, $lm)) {
            $len = (int)$lm[1];
            if ($len > 0 && $start + $len <= strlen($objbody)) {
                $data = substr($objbody, $start, $len);
            }
        }

        if ($data === null) {
            $end = strrpos($objbody, 'endstream');
            if ($end === false || $end <= $start) {
                return null;
            }
            $data = substr($objbody, $start, $end - $start);
            // Trim the EOL the producer put before the keyword, if any.
            $data = (string)preg_replace('/(?:\r\n|\n|\r)$/', '', $data);
        }

        if ($data === '') {
            return null;
        }

        /*
         * Apply the /Filter chain IN ORDER.
         *
         * FIX-DG-PDF-ASCII85 (v1.2.5). This used to look for the substring
         * "/FlateDecode" anywhere in the object and, if it was there, inflate. That
         * handles the common single-filter case and silently fails on a filter CHAIN.
         *
         * A chain is ordinary. ReportLab - and therefore a great many PDFs produced by
         * server-side report generators, including some Moodle-adjacent tooling - writes
         * "/Filter [ /ASCII85Decode /FlateDecode ]", which means the bytes are deflated
         * and THEN ASCII85-armoured. Inflating first cannot work: the data is still
         * ASCII85 text. The old code returned that text, which contains no BT, Tj or TJ,
         * so the content stream was skipped as "clearly not page content" and the document
         * extracted as completely EMPTY - reported to the trainer as "the file may be
         * scanned/image-based" on a text PDF.
         *
         * Decoding in the declared order fixes it. Filters that are not text transforms -
         * the image codecs DCTDecode, JPXDecode, CCITTFaxDecode, JBIG2Decode - are left
         * alone: an image stream is not text and must not be mangled into something that
         * looks like it.
         */
        $filters = [];
        if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $objbody, $fm)) {
            if (preg_match_all('/\/(\w+)/', $fm[1], $fl)) {
                $filters = $fl[1];
            }
        } else if (preg_match('/\/Filter\s*\/(\w+)/', $objbody, $fm)) {
            $filters = [$fm[1]];
        }

        foreach ($filters as $filter) {
            switch ($filter) {
                case 'FlateDecode':
                case 'Fl':
                    if (!function_exists('gzuncompress')) {
                        return $data;
                    }
                    $inf = @gzuncompress($data);
                    if ($inf === false) {
                        $inf = @gzinflate($data);
                    }
                    if ($inf === false) {
                        // Corrupt or encrypted: return what there is rather than nothing.
                        return $data;
                    }
                    $data = $inf;
                    break;

                case 'ASCII85Decode':
                case 'A85':
                    $dec = self::ascii85_decode($data);
                    if ($dec === null) {
                        return $data;
                    }
                    $data = $dec;
                    break;

                case 'ASCIIHexDecode':
                case 'AHx':
                    $hex  = (string)preg_replace('/[^0-9a-fA-F]/', '', explode('>', $data)[0]);
                    if (strlen($hex) % 2 === 1) {
                        $hex .= '0';
                    }
                    $bin = @hex2bin($hex);
                    if ($bin === false) {
                        return $data;
                    }
                    $data = $bin;
                    break;

                default:
                    // An image codec or an unsupported filter: stop here.
                    return $data;
            }
        }

        return $data;
    }

    /**
     * Decode an ASCII85 (base-85) stream as PDF writes it.
     *
     * PDF's variant starts optionally with "<~", ends with "~>", uses "z" as a shorthand
     * for four zero bytes, and pads a short final group. Whitespace is insignificant.
     *
     * @param string $data The armoured bytes.
     * @return string|null The decoded bytes, or null when the input is not ASCII85.
     */
    private static function ascii85_decode(string $data): ?string {
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        if (substr($data, 0, 2) === '<~') {
            $data = substr($data, 2);
        }
        $data = (string)preg_replace('/\s+/', '', $data);
        if ($data === '') {
            return null;
        }

        $out   = '';
        $group = [];
        $len   = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];
            if ($ch === 'z' && empty($group)) {
                $out .= "\0\0\0\0";
                continue;
            }
            $val = ord($ch) - 33;
            if ($val < 0 || $val > 84) {
                // Not ASCII85 at all; refuse rather than return rubbish.
                return null;
            }
            $group[] = $val;
            if (count($group) === 5) {
                $num = 0;
                foreach ($group as $g) {
                    $num = $num * 85 + $g;
                }
                $out  .= pack('N', $num);
                $group = [];
            }
        }

        // A short final group is padded with 'u' (84) and the extra bytes discarded.
        $partial = count($group);
        if ($partial > 1) {
            for ($i = $partial; $i < 5; $i++) {
                $group[] = 84;
            }
            $num = 0;
            foreach ($group as $g) {
                $num = $num * 85 + $g;
            }
            $out .= substr(pack('N', $num), 0, $partial - 1);
        }

        return $out;
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
        // Carried across BT blocks deliberately: a producer may select the font once and
        // show text in later blocks without repeating the Tf.
        $fontsize = 0.0;

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
                    if (preg_match('/\G\s*\/(\w+)\s+(-?[\d.]+)\s+Tf/', $block, $m, 0, $pos)) {
                        $currentfont = $m[1];
                        // The size matters as much as the name: line_break_for_move()
                        // judges a horizontal move against it to tell one glyph's advance
                        // from a gap between runs.
                        $fontsize = abs((float)$m[2]);
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
                        $text .= self::shown(self::decode_tj_array($m[1], $cmap));
                        $pos += strlen($m[0]);
                        continue;
                    }

                    // Hex-encoded string shown with a Tj operator.
                    if (preg_match('/\G\s*<([0-9a-fA-F\s]*)>\s*Tj/', $block, $m, 0, $pos)) {
                        $text .= self::shown(self::decode_hex($m[1], $cmap));
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
                        $text .= self::shown(self::decode_literal($m[1], $cmap));
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
                        $text .= self::line_break_for_move($linex, $liney, (float)$m[5], (float)$m[6], $fontsize);
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
                        $text .= self::line_break_for_move($linex, $liney, $newx, $newy, $fontsize);
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
    /**
     * One text-showing operator's output, with a separator only where one belongs.
     *
     * FIX-DG-PDF-PERGLYPH-SPACES (v1.2.5). Every show operator used to have a space
     * appended to it unconditionally. That is right for a producer which shows a whole
     * line or word at a time - LibreOffice, Word - and catastrophic for one which shows
     * ONE GLYPH at a time, positioning each with its own Td. Those producers exist: a PDF
     * printed through WebKit, which is the "print to PDF" route from a browser, emits
     *
     *     8 -39 Td <0001> Tj  24.83 0 Td <0002> Tj  18.02 0 Td <0002> Tj  ...
     *
     * one operator per letter. "Assessment Submission" came out as
     * "A s s e s s m e n t  S u b m i s s i o n". Every word in the document was destroyed
     * - not visibly corrupt, just unusable: no word matched anything, so the chat-assistant
     * phrases, the legislation citations and the copy comparison all found nothing. It is
     * the quietest kind of failure, because the text looks almost right to a human reading
     * the report.
     *
     * A single decoded character gets no separator: a producer showing one glyph at a time
     * is positioning precisely, and its glyphs are adjacent. Anything longer keeps the
     * separator, so nothing changes for the producers that show words or lines - which is
     * also why the word gaps in those documents are unaffected, since the space characters
     * are inside their strings.
     *
     * Note the test is on CHARACTERS, not bytes: an Identity-H code decodes two bytes to
     * one character, and counting bytes would treat every such glyph as multi-character
     * and put the spaces straight back.
     *
     * @param string $shown The decoded text of one show operator.
     * @return string That text, with a trailing separator where one is warranted.
     */
    private static function shown(string $shown): string {
        if ($shown === '') {
            return '';
        }
        if (mb_strlen($shown, 'UTF-8') <= 1) {
            return $shown;
        }
        return $shown . ' ';
    }

    private static function line_break_for_move(
        float $oldx,
        float $oldy,
        float $newx,
        float $newy,
        float $fontsize = 0.0
    ): string {
        if (abs($newy - $oldy) > 1.0) {
            return "\n";
        }

        /*
         * FIX-DG-PDF-PERGLYPH-SPACES (v1.2.5), second half. A horizontal move used to emit
         * a space whenever it exceeded ONE PDF unit. A PDF unit is 1/72 inch; at any real
         * font size a single glyph is a dozen or more of them, so "moved more than one
         * unit" is true between every pair of adjacent letters.
         *
         * That was invisible for producers which show a whole line per operator and only
         * move between table cells. It destroyed the text from producers which position
         * each glyph individually - a PDF printed through WebKit shows one glyph per Tj
         * with its own Td - turning "Assessment Submission" into
         * "A s s e s s m e n t  S u b m i s s i o n".
         *
         * The move has to be judged against the font size, because that is what sets how
         * far one glyph advances. No single glyph advances much past its own point size -
         * the widest, "m" and "W", reach roughly 1.1 times it - so a move beyond 1.3 times
         * the font size is a gap between runs rather than the advance of one letter.
         *
         * Word gaps are not lost by this. A producer that shows words or lines moves by a
         * whole run's width between operators, which clears the threshold comfortably; and
         * within a run the space characters are in the string itself.
         *
         * Falls back to the old one-unit rule when no font size has been seen, so a stream
         * that positions text before selecting a font behaves as it used to rather than
         * silently running words together.
         */
        $threshold = $fontsize > 0.0 ? ($fontsize * 1.3) : 1.0;
        if (abs($newx - $oldx) > $threshold) {
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
        /*
         * FIX-DG-PDF-CODESPACE (v1.2.5). The code width used to be GUESSED, by looking
         * for any key above 0xFF:
         *
         *     foreach ($cmap as $key => $ignored) { if ($key > 0xFF) { $twobyte = true; ... } }
         *
         * That inference is wrong for the commonest composite font there is. A Type0 font
         * with /Encoding /Identity-H always uses TWO-byte codes, but its codes are glyph
         * indices in a SUBSET - and a subset with fewer than 256 glyphs, which is most of
         * them, has every index below 0x100. The guess then said one byte, and each 2-byte
         * code was read as two separate 1-byte codes: the high byte 0x00 mapped to a NUL
         * and the real code byte was dropped as unmapped.
         *
         * Measured on a WebKit-produced PDF whose fonts are CIDFontType2/Identity-H:
         * "Assessment Submission" extracted as "A\0s\0s\0e\0s\0s\0m\0e\0n\0t\0" - the
         * letters interleaved with NULs, which is also why the result was not valid UTF-8.
         *
         * The width is not something to infer: every ToUnicode CMap DECLARES it, in its
         * codespacerange. parse_cmap_stream() now reads it and passes it here, and the old
         * guess remains only for a table that somehow arrives without one.
         */
        $map   = $cmap['map'] ?? $cmap;
        $bytes = (int)($cmap['bytes'] ?? 0);
        if (empty($map)) {
            return $s;
        }

        if ($bytes === 2) {
            $twobyte = true;
        } else if ($bytes === 1) {
            $twobyte = false;
        } else {
            // No declared width: fall back to the old inference.
            $twobyte = false;
            foreach ($map as $key => $ignored) {
                if ($key > 0xFF) {
                    $twobyte = true;
                    break;
                }
            }
        }
        $cmap = $map;

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

        // The code width, as the CMap itself declares it. "<00> <FF>" is one byte;
        // "<0000> <FFFF>" is two. See FIX-DG-PDF-CODESPACE in apply_cmap() for why this
        // must be read rather than inferred from the keys. The widest range wins, since a
        // mixed-width CMap must be read at its widest to stay in step with the bytes.
        $bytes = 0;
        if (preg_match_all('/begincodespacerange([\s\S]*?)endcodespacerange/', $cmap, $csr, PREG_SET_ORDER)) {
            foreach ($csr as $sec) {
                if (!preg_match_all('/<([0-9a-fA-F]+)>/', $sec[1], $bounds)) {
                    continue;
                }
                foreach ($bounds[1] as $hex) {
                    $w = (int)floor(strlen($hex) / 2);
                    if ($w > $bytes) {
                        $bytes = $w;
                    }
                }
            }
        }
        if ($bytes > 2) {
            // Three- and four-byte CMaps exist but no text font this plugin will meet
            // uses one. Reading it at two bytes would silently misalign every code, so
            // treat the declaration as unusable and let apply_cmap() infer instead.
            $bytes = 0;
        }

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

        // Beginbfrange with an ARRAY destination: <from> <to> [ <u1> <u2> ... ].
        // One destination string per code in the range, used by producers that map a run
        // of codes onto unrelated characters. Parsed before the triple form above has a
        // chance to misread the first two array entries as a destination.
        if (preg_match_all('/beginbfrange([\s\S]*?)endbfrange/', $cmap, $secs, PREG_SET_ORDER)) {
            foreach ($secs as $sec) {
                if (
                    !preg_match_all(
                        '/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*\[([^\]]*)\]/',
                        $sec[1],
                        $arrs,
                        PREG_SET_ORDER
                    )
                ) {
                    continue;
                }
                foreach ($arrs as $a) {
                    $from = hexdec($a[1]);
                    if (!preg_match_all('/<([0-9a-fA-F]*)>/', $a[3], $dests)) {
                        continue;
                    }
                    foreach ($dests[1] as $offset => $desthex) {
                        if ($offset > 0xFFFF) {
                            break;
                        }
                        $table[$from + $offset] = self::utf16be_hex_to_utf8($desthex);
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
                        $table[hexdec($p[1])] = self::utf16be_hex_to_utf8($p[2]);
                    }
                }
            }
        }

        if (empty($table)) {
            return [];
        }

        return ['bytes' => $bytes, 'map' => $table];
    }

    /**
     * Decode a CMap destination - a UTF-16BE string written as hex - to UTF-8.
     *
     * FIX-DG-PDF-LIGATURE (v1.2.5). A ToUnicode destination is a STRING, not a single
     * code point: the PDF specification writes it as UTF-16BE, and it is routinely more
     * than one code unit long. The previous code did `cp_to_utf8(hexdec($hex))`, which
     * treats the whole destination as one number.
     *
     * The practical consequence was ligatures. Carlito, Calibri and most other modern
     * text fonts put "fi" and "ti" on single glyphs whose destination is <00660069> -
     * "f" then "i". hexdec() turns that into 6,684,777, far outside Unicode, so every
     * ligature in the document became U+FFFD. Measured on a LibreOffice assessment cover
     * sheet: "Qualification" extracted as "Quali<?><?>ca<?><?>on", "Certificate" as
     * "Cer<?><?><?><?>cate", "Written Questions" as "Wri<?><?>en Ques<?><?>ons". The text
     * was then not valid UTF-8, which is also why str_word_count() returned zero words on
     * a document that had extracted almost perfectly otherwise.
     *
     * Surrogate pairs are combined, so a destination outside the Basic Multilingual Plane
     * - an emoji pasted into a submission, or a rarer CJK character - decodes to the one
     * character it is rather than to two halves of one.
     *
     * @param string $hex The destination's hex digits, with or without whitespace.
     * @return string The destination as UTF-8, or U+FFFD when it cannot be read at all.
     */
    private static function utf16be_hex_to_utf8(string $hex): string {
        $hex = (string)preg_replace('/[^0-9a-fA-F]/', '', $hex);
        if ($hex === '') {
            return '';
        }
        // A single byte destination (<41>) is a code point, not a UTF-16 unit.
        if (strlen($hex) <= 2) {
            return self::cp_to_utf8((int)hexdec($hex));
        }
        if (strlen($hex) % 4 !== 0) {
            $hex = str_pad($hex, (int)(ceil(strlen($hex) / 4) * 4), '0', STR_PAD_LEFT);
        }

        $out   = '';
        $units = str_split($hex, 4);
        for ($i = 0, $n = count($units); $i < $n; $i++) {
            $unit = (int)hexdec($units[$i]);
            // High surrogate followed by a low surrogate is one character.
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 1 < $n) {
                $low = (int)hexdec($units[$i + 1]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $out .= self::cp_to_utf8(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00));
                    $i++;
                    continue;
                }
            }
            $out .= self::cp_to_utf8($unit);
        }
        return $out;
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
        /*
         * V1.2.5 FIX-DG-PDF-LONE-SURROGATE. A code point outside Unicode, or inside the
         * surrogate range, was encoded ANYWAY by the arithmetic below - which produces
         * bytes that are not valid UTF-8 and cannot be made valid, because the character
         * they describe does not exist.
         *
         * Those bytes then left the extractor. Invalid UTF-8 in the extracted text is not
         * cosmetic: preg functions with /u silently return false on it, so the
         * authenticity checks come back empty on a document full of findings, and
         * json_encode() of the analysis fails outright, leaving a submission stored with
         * no result. Measured on a PDF whose font table maps every glyph into the
         * surrogate range: extract_pdf_php() returned a string of raw surrogate bytes.
         *
         * U+FFFD is the right answer, and it is honest in a way the raw bytes are not: it
         * says "there was a character here and it could not be represented", which is
         * exactly what happened. It is also what the readability guard counts, so a
         * document full of them is rejected rather than stored.
         */
        if ($cp < 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return "\u{FFFD}";
        }
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
