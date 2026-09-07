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
 * Unit tests for the document text extractor.
 *
 * These drive the two file-path entry points, extract_docx() and extract_pdf(), with
 * fixtures built in the test itself. The readability heuristic that decides whether an
 * extraction is usable, looks_readable(), is private and has no public entry point of
 * its own, so it is exercised through extract_pdf() rather than by reflection.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\extractor
 */
final class extractor_test extends \advanced_testcase {
    /**
     * Write a .docx fixture containing the given wordprocessingml body.
     *
     * @param string $bodyxml The contents of the w:body element.
     * @param bool $includedocument Whether to include the word/document.xml part at all.
     * @return string Absolute path to the file written.
     */
    private function make_docx(string $bodyxml, bool $includedocument = true): string {
        $path = make_request_directory() . '/fixture.docx';
        $zip  = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE) === true);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        if ($includedocument) {
            $zip->addFromString(
                'word/document.xml',
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>' . $bodyxml . '</w:body></w:document>'
            );
        }
        $zip->close();
        return $path;
    }

    /**
     * Escape one line of text for use as a PDF literal string operand.
     *
     * @param string $line The text to escape.
     * @return string The text with backslashes and parentheses escaped.
     */
    private function escape_pdf_literal(string $line): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
    }

    /**
     * Build a minimal uncompressed single-page PDF containing the given lines.
     *
     * Lines are positioned with T*, after a TL sets the leading — the style produced by
     * the simplest PDF writers.
     *
     * @param array $lines The lines of text to place on the page.
     * @return string Absolute path to the file written.
     */
    private function make_pdf(array $lines): string {
        $content = "BT\n/F1 12 Tf\n14 TL\n72 720 Td\n";
        foreach ($lines as $line) {
            $content .= '(' . $this->escape_pdf_literal($line) . ") Tj T*\n";
        }
        return $this->wrap_pdf($content . "ET\n");
    }

    /**
     * Build a single-page PDF that positions every line with an absolute Tm and shows it
     * with a TJ array — the shape Word, LaTeX and most PDF libraries emit.
     *
     * @param array $lines The lines of text to place on the page.
     * @return string Absolute path to the file written.
     */
    private function make_pdf_tm(array $lines): string {
        $content = "BT\n/F1 11 Tf\n";
        $y       = 760.0;
        foreach ($lines as $line) {
            $content .= '1 0 0 1 72 ' . number_format($y, 2, '.', '') . " Tm\n";
            $content .= '[(' . $this->escape_pdf_literal($line) . ")] TJ\n";
            $y -= 15.0;
        }
        return $this->wrap_pdf($content . "ET\n");
    }

    /**
     * Build a single-page PDF that positions every line with a relative TD and shows it
     * with a literal Tj, using no T* and no TL at all.
     *
     * @param array $lines The lines of text to place on the page.
     * @return string Absolute path to the file written.
     */
    private function make_pdf_td(array $lines): string {
        $content = "BT\n/F1 11 Tf\n72 760 Td\n";
        $first   = true;
        foreach ($lines as $line) {
            if (!$first) {
                $content .= "0 -13 TD\n";
            }
            $first    = false;
            $content .= '(' . $this->escape_pdf_literal($line) . ") Tj\n";
        }
        return $this->wrap_pdf($content . "ET\n");
    }

    /**
     * Wrap a content stream in the object graph, xref table and trailer of a one-page PDF.
     *
     * @param string $content The page content stream.
     * @return string Absolute path to the file written.
     */
    private function wrap_pdf(string $content): string {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefpos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
            . "startxref\n" . $xrefpos . "\n%%EOF\n";

        $path = make_request_directory() . '/fixture.pdf';
        file_put_contents($path, $pdf);
        return $path;
    }

    /**
     * A well-formed .docx yields one line per non-empty paragraph, separated by blank
     * lines, with runs inside a paragraph joined and empty paragraphs dropped.
     *
     * @return void
     */
    public function test_extract_docx_returns_paragraph_text(): void {
        $path = $this->make_docx(
            '<w:p><w:r><w:t>Question 1: Describe the hazard.</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>The hazard </w:t></w:r><w:r><w:t>is chemical.</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>   </w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Question 2: Café Müller 这是测试</w:t></w:r></w:p>'
        );

        $text = extractor::extract_docx($path);

        $this->assertSame(
            "Question 1: Describe the hazard.\n\n"
            . "The hazard is chemical.\n\n"
            . 'Question 2: Café Müller 这是测试',
            $text
        );
    }

    /**
     * A ZIP container that is not a Word document names the missing part rather than
     * failing opaquely.
     *
     * @return void
     */
    public function test_extract_docx_rejects_zip_without_document_part(): void {
        $path = $this->make_docx('', false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Not a valid DOCX file (word/document.xml not found).');
        extractor::extract_docx($path);
    }

    /**
     * A legacy OLE2 binary .doc is detected by its magic number and reported with an
     * instruction the teacher can pass on, not with "Cannot open DOCX file".
     *
     * The message is also checked to be short enough to survive lib.php rendering
     * badge errors as substr($errmsg, 0, 120).
     *
     * @return void
     */
    public function test_extract_docx_detects_legacy_binary_doc(): void {
        $path = make_request_directory() . '/legacy.doc';
        file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 200));

        try {
            extractor::extract_docx($path);
            $this->fail('Expected an exception for a legacy binary .doc file.');
        } catch (\Exception $e) {
            $this->assertSame(
                'Legacy .doc format is not supported. Ask the student to resubmit as .docx or PDF.',
                $e->getMessage()
            );
            $this->assertLessThanOrEqual(120, strlen($e->getMessage()));
        }
    }

    /**
     * A file that is not a container of any kind reports the filename it could not open.
     *
     * @return void
     */
    public function test_extract_docx_rejects_non_container(): void {
        $path = make_request_directory() . '/junk.docx';
        file_put_contents($path, 'this is not a zip file at all, just text');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot open DOCX file: junk.docx');
        extractor::extract_docx($path);
    }

    /**
     * A path that does not exist fails the same way as an unopenable file rather than
     * emitting a PHP warning.
     *
     * @return void
     */
    public function test_extract_docx_rejects_missing_file(): void {
        $path = make_request_directory() . '/absent.docx';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot open DOCX file: absent.docx');
        extractor::extract_docx($path);
    }

    /**
     * Regression test for the readability heuristic in looks_readable().
     *
     * A maths worksheet is mostly digits and operators. Counting only \p{L} and \p{M}
     * this fixture's extracted text scores 0.4267 and is rejected by the 0.45 cut-off;
     * counting \p{N} as well it scores 0.9400 and is accepted. The release that dropped
     * \p{N} therefore threw away a perfect pdftotext extraction of every maths worksheet
     * and lab results table and fell through to the pure-PHP parser.
     *
     * The two paths are distinguishable in their output: pdftotext lays the page out in
     * columns, padding each line to the horizontal position the glyphs were drawn at,
     * which the pure-PHP fallback does not attempt. The column padding on the digit rows
     * is what the string assertions below turn on.
     *
     * The line-count assertion used to carry that job on its own, on the grounds that the
     * pure-PHP fallback returned no newlines at all. Since FIX-DG-PDF-NEWLINES it returns
     * line breaks too — correctly — so that assertion no longer discriminates between the
     * two paths and is kept only as a sanity check on the line structure.
     *
     * @return void
     */
    public function test_extract_pdf_keeps_digit_heavy_extraction(): void {
        if (!self::pdftotext_available()) {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed on this host.');
        }

        $path = $this->make_pdf([
            'Mathematics Worksheet 7B',
            'Question 1  Solve for x',
            '3x + 7 = 22',
            'x = 5',
            'Question 2  Evaluate',
            '12 * 8 = 96',
            '144 / 12 = 12',
            'Question 3  2^10 = 1024',
            '1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16',
            '17 18 19 20 21 22 23 24 25 26 27 28 29',
        ]);

        $text = extractor::extract_pdf($path);

        // Column padding varies between poppler releases, so runs of spaces are
        // collapsed before the content is compared. Line breaks are left alone,
        // because they are what the last two assertions turn on.
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = trim(preg_replace('/ +/', ' ', $line));
        }
        $normalised = implode("\n", $lines);

        // The numeric content survived rather than being scored as garbage.
        $this->assertStringContainsString("3x + 7 = 22\n", $normalised);
        $this->assertStringContainsString("144 / 12 = 12\n", $normalised);
        $this->assertStringContainsString("17 18 19 20 21 22 23 24 25 26 27 28 29", $normalised);

        // The page's line structure survived intact.
        $this->assertStringContainsString("Mathematics Worksheet 7B\n", $normalised);
        $this->assertGreaterThan(5, substr_count($normalised, "\n"));
    }

    /**
     * The digit-heavy fixture is the case the heuristic has to get right: measured the
     * way the current code measures it the text is plainly readable, and measured the
     * way the withdrawn release measured it, it is not. This pins the arithmetic that
     * distinguishes the two, independently of whether a PDF toolchain is installed.
     *
     * @return void
     */
    public function test_digit_heavy_text_is_only_readable_when_digits_are_counted(): void {
        $extracted = "Mathematics Worksheet 7B\n"
            . "Question 1 Solve for x\n"
            . "3x + 7 = 22\n"
            . "x=5\n"
            . "Question 2 Evaluate\n"
            . "12 * 8 = 96\n"
            . "144 / 12 = 12\n"
            . "Question 3 2^10 = 1024\n"
            . "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16\n"
            . "17 18 19 20 21 22 23 24 25 26 27 28 29\n";

        $total = \core_text::strlen(preg_replace('/\s+/u', '', trim($extracted)));
        $this->assertSame(150, $total);

        $withdigits = preg_match_all('/[\p{L}\p{M}\p{N}]/u', trim($extracted));
        $lettersonly = preg_match_all('/[\p{L}\p{M}]/u', trim($extracted));

        $this->assertSame(141, $withdigits);
        $this->assertSame(64, $lettersonly);
        $this->assertGreaterThan(0.45, $withdigits / $total);
        $this->assertLessThan(0.45, $lettersonly / $total);
    }

    /**
     * The counterpart to the maths worksheet: a genuinely garbled extraction, the case
     * the heuristic exists to reject, scores far below the cut-off even with digits
     * counted, so widening the numerator did not make the test toothless.
     *
     * @return array<string, array{0: string, 1: float}>
     */
    public static function garbled_text_provider(): array {
        return [
            'symbol soup from a failed extraction' => [
                // The backtick is deliberate fixture content — this string is the
                // punctuation soup a failed PDF extraction emits, and the assertion
                // below depends on its exact character mix.
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found
                '@#$%^&*()_+{}|:"<>?~`-=[]\\;\',./!@#$%^&*()_+{}|:"<>?a~`-=[]\\;\',./',
                0.0156,
            ],
            'block drawing noise from a broken font map' => [
                str_repeat('░▒▓█▄▀■□▪▫', 8),
                0.0,
            ],
        ];
    }

    /**
     * Garbled extractions score below the 0.45 readability cut-off.
     *
     * @dataProvider garbled_text_provider
     * @param string $text The garbled extraction.
     * @param float $expectedratio The readable-character ratio observed for it.
     * @return void
     */
    public function test_garbled_text_scores_below_the_readability_cutoff(string $text, float $expectedratio): void {
        $letters = preg_match_all('/[\p{L}\p{M}\p{N}]/u', trim($text));
        $total   = max(1, \core_text::strlen(preg_replace('/\s+/u', '', trim($text))));

        $this->assertSame($expectedratio, round($letters / $total, 4));
        $this->assertLessThan(0.45, $letters / $total);
    }

    /**
     * The three-question assessment text the pure-PHP line-break tests are built from.
     *
     * Deliberately shaped like a real RTO assessment: a header before the first marker,
     * blank lines between blocks, and answers long enough to clear
     * question_parser::MIN_SECTION_CHARS.
     *
     * @return string[] The lines of the page, in order.
     */
    private static function three_question_lines(): array {
        return [
            'Assessment Task 2 - Infection Control',
            '',
            'Question 1: Name one standard precaution and when it applies.',
            'Answer: Hand hygiene before and after every episode of patient',
            'contact, because hands move organisms between patients.',
            '',
            'Question 2: Explain why protective equipment comes off in order.',
            'Answer: The order exists so the most contaminated item is removed',
            'first and never touches skin or clothing on the way off.',
            '',
            'Question 3: Describe what to do after a sharps injury.',
            'Answer: Wash the wound with soap and running water, report it',
            'immediately and follow the local exposure protocol.',
        ];
    }

    /**
     * The same page laid out with three different positioning operators.
     *
     * T* is the case that was already handled through the ' operator's sibling path;
     * Tm and TD are the two that were not, and between them they cover essentially every
     * real-world producer — Tm for Word, LaTeX, TCPDF and wkhtmltopdf, TD for writers
     * that lay out relatively.
     *
     * @return array<string, array{0: string}>
     */
    public static function line_positioning_style_provider(): array {
        return [
            'lines positioned with T* after TL' => ['make_pdf'],
            'lines positioned with an absolute Tm' => ['make_pdf_tm'],
            'lines positioned with a relative TD' => ['make_pdf_td'],
        ];
    }

    /**
     * Regression test for FIX-DG-PDF-NEWLINES: the pure-PHP PDF parser emits a line break
     * wherever the PDF moves to a new line.
     *
     * A PDF stores no newline character. A line break is a text-positioning operator —
     * Td, TD, T* or Tm — and extract_from_stream() used to recognise only the
     * text-showing operators, skipping the positioning ones one byte at a time. The whole
     * page therefore came back as a single space-joined run containing no "\n", on every
     * host without pdftotext or Ghostscript installed, which is most shared and managed
     * Moodle hosting. question_parser splits on explode("\n") and anchors its marker
     * patterns with ^, so it could not match one marker and every submission was reported
     * as one "Full Document" section however many questions it held.
     *
     * The assertion is deliberately on the exact text: it pins both that the breaks are
     * present and that they land between the right lines rather than mid-sentence. The
     * three fixtures are byte-different PDFs that draw the identical page, so the same
     * expected string across all three is the evidence that the fix is a property of the
     * geometry and not of one operator.
     *
     * @dataProvider line_positioning_style_provider
     * @param string $builder Name of the fixture builder that lays the page out.
     * @return void
     */
    public function test_extract_pdf_php_breaks_lines_for_positioning_operators(string $builder): void {
        $path = $this->$builder(self::three_question_lines());

        $text = extractor::extract_pdf_php($path);

        $this->assertSame(
            "Assessment Task 2 - Infection Control\n"
            . "\n"
            . "Question 1: Name one standard precaution and when it applies.\n"
            . "Answer: Hand hygiene before and after every episode of patient\n"
            . "contact, because hands move organisms between patients.\n"
            . "\n"
            . "Question 2: Explain why protective equipment comes off in order.\n"
            . "Answer: The order exists so the most contaminated item is removed\n"
            . "first and never touches skin or clothing on the way off.\n"
            . "\n"
            . "Question 3: Describe what to do after a sharps injury.\n"
            . "Answer: Wash the wound with soap and running water, report it\n"
            . 'immediately and follow the local exposure protocol.',
            $text
        );
    }

    /**
     * The end-to-end consequence of the fix, and the shape of the defect the site owner
     * actually saw: the extracted text now parses into the three questions on the page
     * instead of one "Full Document" section.
     *
     * The header above the first marker belongs to no question and is dropped by
     * try_labelled(), which is why the first section starts at "Question 1:".
     *
     * @dataProvider line_positioning_style_provider
     * @param string $builder Name of the fixture builder that lays the page out.
     * @return void
     */
    public function test_pure_php_extraction_parses_into_its_questions(string $builder): void {
        $path = $this->$builder(self::three_question_lines());

        $sections = question_parser::parse(extractor::extract_pdf_php($path));

        $this->assertCount(3, $sections);
        $this->assertSame(['Question 1', 'Question 2', 'Question 3'], array_column($sections, 'label'));
        $this->assertSame([1, 2, 3], array_column($sections, 'num'));
        $this->assertStringStartsWith('Question 1: Name one standard precaution', $sections[0]['text']);
        $this->assertStringStartsWith('Question 3: Describe what to do after', $sections[2]['text']);
    }

    /**
     * Whether the poppler-utils pdftotext binary is on the PATH of this host.
     *
     * @return bool True when pdftotext can be run.
     */
    private static function pdftotext_available(): bool {
        $output = [];
        $retval = 0;
        exec('which pdftotext 2>/dev/null', $output, $retval);
        return $retval === 0 && !empty($output);
    }
}
