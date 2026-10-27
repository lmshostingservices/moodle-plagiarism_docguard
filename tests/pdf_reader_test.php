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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/docguard_pdf_fixture.php');

/**
 * Regression tests for DocGuard's own PDF reader, one per defect fixed in 1.2.5.
 *
 * Context, because it explains why there are so many of these in one release. A customer
 * asked whether they needed to install poppler-utils, which their server lacked. Measuring
 * the answer meant comparing all three extraction tiers on the same documents, and that
 * comparison showed the built-in reader was not merely lower quality - it was returning
 * SCRAMBLED TEXT and the plugin was accepting it. "Student name" arrived as
 * "Asemnts tCon". Seven separate defects were behind it.
 *
 * Every test here drives extract_pdf_php() - the shipped method - on a PDF built by
 * docguard_pdf_fixture, and asserts the exact text. Exact rather than "contains", because
 * each of these defects produced output that still looked plausible: letters in the right
 * proportion, words of the right length, nothing that would fail a loose assertion. That
 * is what let them survive so long, and a loose assertion here would let them back.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\extractor
 */
final class pdf_reader_test extends \advanced_testcase {

    /**
     * The glyph order of a subset font, as a producer builds it: codes from 1 upwards in
     * order of first use.
     *
     * @param string $text The text the font must be able to draw.
     * @param string[] $extra Extra characters or ligature pairs to place after those.
     * @return array<int, string> Code => the character or pair it means.
     */
    private static function subset(string $text, array $extra = []): array {
        $map  = [];
        $code = 1;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (!in_array($char, $map, true)) {
                $map[$code++] = $char;
            }
        }
        foreach ($extra as $piece) {
            $map[$code++] = $piece;
        }
        return $map;
    }

    /**
     * The heading every fixture draws in its FIRST font.
     *
     * Its presence is not decoration. See make_pdf().
     *
     * @var string
     */
    const HEADING = 'Assessment';

    /**
     * Build a one-page PDF that draws a heading in one subset font and body text in a
     * second, each with its own independent glyph numbering.
     *
     * TWO FONTS, ALWAYS, and this is the single most important property of these fixtures.
     *
     * A first version of this helper used one font, and mutation testing showed that seven
     * of these tests could not fail. With one font, the union-of-all-fonts fallback CMap is
     * identical to the correct per-font CMap, so every defect in resolving which font a
     * string belongs to - the indirect /Font reference, the object streams, the nested
     * dictionary, the dropped stream - produced perfectly correct text anyway. The tests
     * passed against the very code they were written to condemn.
     *
     * That is also why the real defect went unnoticed for so long: it is invisible in any
     * document with a single font, and visible in every document with two. A subset font
     * numbers its glyphs from 1 in order of first use, so two subset fonts both use codes
     * 1, 2, 3 for entirely different letters. Decode the body with the heading's table and
     * every letter is wrong - which is exactly what "Student name" arriving as
     * "Asemnts tCon" was.
     *
     * The heading is short and shares no letters it does not need with the body, so the
     * two subsets collide on the low codes that matter. Any test here that stopped using
     * two fonts would stop testing anything.
     *
     * Every other variation is a flag, so each test differs from the baseline in one
     * property and its assertion can only be about that property.
     *
     * @param string $text The body text the page draws, after the heading.
     * @param array $options Any of: map, codebytes, indirectfont, indirectlength,
     *                       eolbeforeendstream, ascii85, objstm, nestedresources,
     *                       perglyph, encrypt, fontsize.
     * @return string Absolute path to the PDF written.
     */
    private function make_pdf(string $text, array $options = []): string {
        $map       = $options['map'] ?? self::subset($text);
        $codebytes = (int)($options['codebytes'] ?? 1);
        $fontsize  = (int)($options['fontsize'] ?? 12);
        $headmap   = self::subset(self::HEADING);

        $pdf = new \docguard_pdf_fixture();

        // F1: the heading's font. Its codes 1, 2, 3... mean different letters from F2's.
        $headtounicode = $pdf->add_stream(
            '',
            \docguard_pdf_fixture::tounicode($headmap, $codebytes)
        );
        $headfont = $pdf->add(
            '<</Type /Font /Subtype /TrueType /BaseFont /AAAAAA+FixtureBold'
            . ' /ToUnicode ' . $headtounicode . ' 0 R>>'
        );

        // F2: the body's font.
        $tounicode = $pdf->add_stream(
            '',
            \docguard_pdf_fixture::tounicode($map, $codebytes)
        );
        $font = $pdf->add(
            '<</Type /Font /Subtype /TrueType /BaseFont /BBBBBB+Fixture'
            . ' /ToUnicode ' . $tounicode . ' 0 R>>'
        );

        // The /Font value: inline, or an indirect reference as Word and LibreOffice write
        // it. The difference is the whole of FIX-DG-PDF-INDIRECT-FONT.
        $fontentries = '/F1 ' . $headfont . ' 0 R /F2 ' . $font . ' 0 R';
        if (!empty($options['indirectfont'])) {
            $fontdictobj = $pdf->add('<<' . $fontentries . '>>');
            $fontvalue   = '/Font ' . $fontdictobj . ' 0 R';
        } else {
            $fontvalue = '/Font <<' . $fontentries . '>>';
        }

        // Resources with another dictionary BEFORE the fonts, which is what defeats a
        // non-greedy "<<([\s\S]*?)>>" match for the /Font dictionary.
        $resources = !empty($options['nestedresources'])
            ? '<</ColorSpace <</CS0 /DeviceRGB>> /ExtGState <</GS0 <</ca 1>>>> '
                . $fontvalue . ' /ProcSet [/PDF /Text]>>'
            : '<<' . $fontvalue . ' /ProcSet [/PDF /Text]>>';
        $resourcesobj = $pdf->add($resources);

        $content = $this->content_stream($text, $map, $headmap, $codebytes, $fontsize, $options);

        $streamdata = $content;
        $streamdict = '';
        if (!empty($options['ascii85'])) {
            // The chain ReportLab writes: deflate, then ASCII85-armour. Decoding has to
            // happen in the declared order or the inflate is attempted on armoured text.
            $streamdata = \docguard_pdf_fixture::ascii85(gzcompress($content));
            $streamdict = '/Filter [/ASCII85Decode /FlateDecode]';
        }

        $lengthoverride = null;
        if (!empty($options['indirectlength'])) {
            // "/Length 42 0 R". LibreOffice writes content-stream lengths this way.
            //
            // The object number must have TWO OR MORE DIGITS, and that is not a detail.
            // The defect being pinned down is a regex backtracking into its own guard: on
            // "/Length 66 0 R" the digits match "66", the indirect-reference lookahead is
            // satisfied by " 0 R", and PCRE then shortens the capture to "6" - at which
            // point the next character is "6", the lookahead cannot match, the negative
            // lookahead succeeds, and the stream is read as six bytes.
            //
            // With a single-digit object number there is nothing to backtrack TO: "6"
            // cannot be shortened, so the match simply fails and the correct fallback
            // runs. A fixture numbered 6 therefore passes against the defect. Verified:
            // it did, until this line was added.
            $pdf->pad_to(20);
            $lengthobj      = $pdf->reserve();
            $lengthoverride = $lengthobj . ' 0 R';
            $pdf->put($lengthobj, (string)strlen($streamdata));
        }

        $contentobj = $pdf->add_stream(
            $streamdict,
            $streamdata,
            true,
            $lengthoverride,
            !isset($options['eolbeforeendstream']) || $options['eolbeforeendstream']
        );

        $pagesobj = $pdf->reserve();
        $pageobj  = $pdf->add(
            '<</Type /Page /Parent ' . $pagesobj . ' 0 R /Resources ' . $resourcesobj
            . ' 0 R /Contents ' . $contentobj . ' 0 R /MediaBox [0 0 612 792]>>'
        );
        $pdf->put(
            $pagesobj,
            '<</Type /Pages /Kids [' . $pageobj . ' 0 R] /Count 1>>'
        );

        $raw = $pdf->build();

        if (!empty($options['objstm'])) {
            $raw = $this->pack_into_object_stream($raw);
        }
        if (!empty($options['encrypt'])) {
            // Only the marker matters: extract_pdf_php() refuses on seeing /Encrypt and
            // never attempts to decode. Building a genuinely encrypted file would test
            // qpdf, not this plugin.
            $raw = str_replace('trailer\n<</Size', 'trailer' . "\n" . '<</Size', $raw);
            $raw = preg_replace('/trailer\s*<</', "trailer\n<</Encrypt 99 0 R ", $raw, 1);
        }

        $path = make_request_directory() . '/fixture.pdf';
        file_put_contents($path, $raw);
        return $path;
    }

    /**
     * The content stream: the heading in F1, then the body in F2 on the next line.
     *
     * @param string $text The body text to draw.
     * @param array<int, string> $map The body font's code map.
     * @param array<int, string> $headmap The heading font's code map.
     * @param int $codebytes Bytes per character code.
     * @param int $fontsize The Tf size.
     * @param array $options Honours 'perglyph' and 'eolbeforeendstream'.
     * @return string The content stream.
     */
    private function content_stream(
        string $text,
        array $map,
        array $headmap,
        int $codebytes,
        int $fontsize,
        array $options
    ): string {
        $headhex = \docguard_pdf_fixture::encode(self::HEADING, $headmap, $codebytes);
        $stream  = "BT\n/F1 " . $fontsize . " Tf\n1 0 0 1 72 720 Tm\n<" . $headhex . "> Tj\n";

        if (empty($options['perglyph'])) {
            $hex = \docguard_pdf_fixture::encode($text, $map, $codebytes);
            $stream .= '/F2 ' . $fontsize . " Tf\n1 0 0 1 72 700 Tm\n<" . $hex . "> Tj\nET";
        } else {
            // One operator per glyph, each positioned by its own Td with that glyph's
            // advance - how a PDF printed through WebKit is written. The advances are a
            // little UNDER the font size, as a real glyph's is, and the gap between words
            // is the space glyph rather than a wider move, which is also what WebKit does.
            // Same line origin as the whole-string path, so the only difference between
            // the two is how the glyphs are shown and positioned.
            $stream .= '/F2 ' . $fontsize . " Tf\n1 0 0 1 72 700 Tm\n";
            foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $index => $char) {
                $hex = \docguard_pdf_fixture::encode($char, $map, $codebytes);
                if ($hex === '') {
                    continue;
                }
                if ($index > 0) {
                    $stream .= sprintf("%.4f 0 Td\n", $fontsize * 0.62);
                }
                $stream .= '<' . $hex . "> Tj\n";
            }
            $stream .= 'ET';
        }

        // Deliberately NO trailing newline. The stream's last byte matters for
        // FIX-DG-PDF-ENDSTREAM-WORDBOUNDARY - the old pattern needed a non-word character
        // before the "endstream" keyword - and add_stream() decides whether to put an
        // end-of-line there. "ET" ends in a word character, so with that end-of-line
        // omitted a letter sits immediately before the keyword, which is the shape that
        // used to make the entire stream vanish.
        return $stream;
    }

    /**
     * The text a fixture is expected to yield: the heading, then the body on its own line.
     *
     * The heading comes from the second font and is drawn 20 units higher, so the reader
     * emits a line break between them.
     *
     * @param string $body The body text the fixture was built with.
     * @return string The whole expected extraction.
     */
    private static function expected(string $body): string {
        return self::HEADING . "\n" . $body;
    }

    /**
     * Move every non-stream object of a PDF into one compressed /ObjStm.
     *
     * This is what Microsoft Word does by default from PDF 1.5 onwards, and what made the
     * font dictionaries unreachable to an object index built by scanning the raw bytes.
     * Stream objects stay at the top level because the specification forbids a stream
     * inside an object stream.
     *
     * @param string $raw A PDF built by the fixture builder.
     * @return string The same document with its plain objects packed away.
     */
    private function pack_into_object_stream(string $raw): string {
        preg_match_all('/\b(\d+)\s+0\s+obj\b([\s\S]*?)\bendobj\b/', $raw, $matches, PREG_SET_ORDER);

        $packed  = [];
        $kept    = [];
        foreach ($matches as $match) {
            $num  = (int)$match[1];
            $body = trim($match[2]);
            if (strpos($body, 'stream') !== false) {
                $kept[$num] = $match[2];
            } else {
                $packed[$num] = $body;
            }
        }

        $offsets = '';
        $bodies  = '';
        foreach ($packed as $num => $body) {
            $offsets .= $num . ' ' . strlen($bodies) . ' ';
            $bodies  .= $body . ' ';
        }
        $payload = $offsets . $bodies;
        $first   = strlen($offsets);

        $out = "%PDF-1.7\n";
        $compressed = gzcompress($payload);
        $out .= "1000 0 obj\n<</Type /ObjStm /N " . count($packed) . ' /First ' . $first
             . ' /Length ' . strlen($compressed) . " /Filter /FlateDecode>>\nstream\n"
             . $compressed . "\nendstream\nendobj\n";
        foreach ($kept as $num => $body) {
            $out .= $num . " 0 obj" . $body . "endobj\n";
        }
        return $out . "trailer\n<</Size 1001>>\n%%EOF\n";
    }

    /* ── The defects ─────────────────────────────────────────────────────────── */

    /**
     * The baseline: the fixture builder itself reads back correctly.
     *
     * Every other test in this file differs from this one in a single property, so if this
     * fails they all do and none of their failures mean what they say.
     *
     * @return void
     */
    public function test_a_plain_pdf_reads_back_exactly(): void {
        $path = $this->make_pdf('Student name Jordan Ellis');

        $this->assertSame(self::expected('Student name Jordan Ellis'), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-ENDSTREAM-WORDBOUNDARY. A stream whose last byte is a word character,
     * with no end-of-line before the "endstream" keyword, must still be read.
     *
     * The old pattern was `/\bstream\b([\s\S]*?)\bendstream\b/`. The `\b` before
     * "endstream" needs a non-word character on one side; stream data is arbitrary bytes
     * and the specification requires no EOL before the keyword, so whenever the last byte
     * was a letter, digit or underscore the entire match failed and the object was treated
     * as having NO STREAM AT ALL.
     *
     * Roughly a quarter of bytes are word characters, so this discarded about one stream in
     * four in every PDF - deterministic per file, which is why it never looked like a race.
     * It is the defect behind the scrambled text: a font's /ToUnicode object ended in "M",
     * its table was dropped, and every word drawn in that font was decoded with another
     * font's table.
     *
     * The fixture ends its content stream in "A" deliberately. Note that this test would
     * pass against the old code if the text happened to end in punctuation, which is
     * exactly how the defect hid.
     *
     * @return void
     */
    public function test_a_stream_ending_in_a_letter_is_still_read(): void {
        $path = $this->make_pdf('Student answer A', ['eolbeforeendstream' => false]);

        $this->assertSame(self::expected('Student answer A'), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-INDIRECT-FONT. A /Font value written as an indirect reference must be
     * followed.
     *
     * A dictionary value may be inline - "/Font << /F1 5 0 R >>" - or a reference to
     * another object - "/Font 78 0 R". Word and LibreOffice both write the second form.
     * The old code searched only for the first, so on those files no font mapping was built
     * and every string was decoded with the union of all fonts' tables.
     *
     * The union is not a degraded mapping, it is a wrong one: a subset font numbers its
     * glyphs from 1 in order of first use, so two subset fonts both use codes 1, 2, 3 for
     * different letters, and first-wins union decodes the second font with the first
     * font's table.
     *
     * @return void
     */
    public function test_an_indirect_font_reference_is_followed(): void {
        $path = $this->make_pdf('Student name Jordan Ellis', ['indirectfont' => true]);

        $this->assertSame(self::expected('Student name Jordan Ellis'), extractor::extract_pdf_php($path));
    }

    /**
     * The /Length guard must not be defeated by backtracking.
     *
     * extract_stream() prefers /Length when it is a direct integer, and must ignore an
     * indirect "/Length 66 0 R". The guard was written `(\d+)(?!\s+\d+\s+R)`, and on
     * "/Length 66 0 R" PCRE matched "66", found " 0 R" satisfied the lookahead, then
     * BACKTRACKED the digits to "6" - at which point the next character is "6", the
     * lookahead's \s+ cannot match, the negative lookahead succeeds, and the stream was
     * read as SIX BYTES.
     *
     * That truncated page content to nothing on every LibreOffice document, because
     * LibreOffice writes content-stream lengths as indirect references. The text here is
     * well over six bytes so a truncating read cannot produce it.
     *
     * @return void
     */
    public function test_an_indirect_length_does_not_truncate_the_stream(): void {
        $path = $this->make_pdf(
            'Standard 3.2.2 requires five degrees or below',
            ['indirectlength' => true]
        );

        $this->assertSame(
            self::expected('Standard 3.2.2 requires five degrees or below'),
            extractor::extract_pdf_php($path)
        );
    }

    /**
     * FIX-DG-PDF-OBJSTM. Objects packed inside a compressed /ObjStm must be indexed.
     *
     * From PDF 1.5 a producer may put most of a document's objects, font dictionaries
     * included, inside a compressed object stream. Word does this by default. The object
     * index is a regex over the raw bytes, which by construction cannot see inside a
     * compressed stream, so the font dictionaries were unreachable and the union fallback
     * decoded everything with the wrong table - the same scrambled output as an
     * unfollowed indirect reference, from a different cause.
     *
     * @return void
     */
    public function test_objects_inside_an_object_stream_are_found(): void {
        $path = $this->make_pdf(
            'Student name Jordan Ellis',
            ['indirectfont' => true, 'objstm' => true]
        );

        $this->assertSame(self::expected('Student name Jordan Ellis'), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-ASCII85. A /Filter chain must be applied in the order it is declared.
     *
     * "/Filter [/ASCII85Decode /FlateDecode]" means the bytes were deflated and then
     * ASCII85-armoured, so they must be un-armoured first. The old code looked for the
     * substring "/FlateDecode" anywhere and inflated, which on a chain is attempted
     * against armoured text and fails. What it returned was the armoured text, which
     * contains no BT, Tj or TJ, so the content stream was skipped as "clearly not page
     * content" and the document extracted as COMPLETELY EMPTY - reported to the trainer as
     * "the file may be scanned/image-based" on a text PDF. ReportLab writes this chain.
     *
     * @return void
     */
    public function test_an_ascii85_and_flate_filter_chain_is_decoded_in_order(): void {
        $path = $this->make_pdf('Hand hygiene before every episode', ['ascii85' => true]);

        $this->assertSame(
            self::expected('Hand hygiene before every episode'),
            extractor::extract_pdf_php($path)
        );
    }

    /**
     * FIX-DG-PDF-CODESPACE. The code width comes from the CMap's codespacerange, not from
     * guessing at the keys.
     *
     * A Type0 font with /Encoding /Identity-H always uses two-byte codes, but its codes
     * are glyph indices in a subset, and a subset of fewer than 256 glyphs has every index
     * below 0x100. The old inference - "two-byte if any key exceeds 0xFF" - therefore said
     * one byte, and each two-byte code was read as two one-byte codes: the high byte 0x00
     * decoded to a NUL and the real code was dropped as unmapped.
     *
     * Measured on a WebKit PDF: "Assessment Submission" came out as
     * "A\0s\0s\0e\0s\0s\0m\0e\0n\0t\0", which is also why the result was not valid UTF-8.
     *
     * This fixture declares "<0000> <FFFF>" with every code under 256, so it fails against
     * the old guess and passes against the declaration.
     *
     * @return void
     */
    public function test_two_byte_codes_are_read_two_bytes_at_a_time(): void {
        $text = 'Assessment Submission';
        $path = $this->make_pdf($text, [
            'codebytes' => 2,
            'map'       => self::subset($text),
        ]);

        $this->assertSame(self::expected($text), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-LIGATURE. A ToUnicode destination is a UTF-16BE STRING, which may be more
     * than one code unit long.
     *
     * Modern text fonts put "fi" and "ti" on single glyphs whose destination is
     * <00660069> - "f" then "i". The old code did `cp_to_utf8(hexdec($hex))`, treating the
     * whole destination as one number: 6,684,777, far outside Unicode. Every ligature in
     * the document became U+FFFD.
     *
     * Measured on a LibreOffice cover sheet: "Qualification" extracted as
     * "Quali<?><?>ca<?><?>on" and "Written Questions" as "Wri<?><?>en Ques<?><?>ons", and
     * the text was then not valid UTF-8 - which is why str_word_count() returned zero
     * words on a document that had otherwise extracted almost perfectly.
     *
     * @return void
     */
    public function test_a_ligature_glyph_decodes_to_both_its_characters(): void {
        $text = 'Qualification Certificate Written Questions';
        // The font carries "fi" and "ti" as single glyphs, as Carlito and Calibri do.
        $map  = self::subset($text, ['fi', 'ti']);
        $path = $this->make_pdf($text, ['map' => $map]);

        $this->assertSame(self::expected($text), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-PERGLYPH-SPACES. A producer that shows one glyph at a time must not have
     * a space inserted between every letter.
     *
     * Two things used to do this. Every show operator had a space appended
     * unconditionally, and any horizontal move over ONE PDF UNIT emitted one - a PDF unit
     * being 1/72 inch, which is true between every pair of adjacent letters at any real
     * font size.
     *
     * Both are right for a producer that shows a whole line per operator and only moves
     * between table cells. For one that positions each glyph with its own Td - which is
     * what a PDF printed through WebKit does, the "print to PDF" route from a browser -
     * "Assessment Submission" became "A s s e s s m e n t  S u b m i s s i o n". Every
     * word in the document was destroyed: not visibly corrupt, just unusable, so the
     * chat-assistant phrases, the legislation citations and the copy comparison all found
     * nothing. It is the quietest kind of failure, because the text still looks almost
     * right to a human reading the report.
     *
     * @return void
     */
    public function test_per_glyph_positioning_does_not_space_out_every_letter(): void {
        $text = 'Assessment Submission';
        $path = $this->make_pdf($text, ['perglyph' => true, 'fontsize' => 32]);

        $this->assertSame(self::expected($text), extractor::extract_pdf_php($path));
    }

    /**
     * A nested dictionary before the fonts must not truncate the resources.
     *
     * A PDF dictionary nests, so the non-greedy "<<([\s\S]*?)>>" this file used stopped at
     * the FIRST ">>", which is the inner one. On a page whose /Resources carry a
     * /ColorSpace or /ExtGState before the fonts - ordinary, and what WebKit writes - the
     * /Font entry was cut off entirely and the union fallback took over.
     *
     * @return void
     */
    public function test_resources_with_a_nested_dictionary_first_still_yield_the_fonts(): void {
        $path = $this->make_pdf('Student name Jordan Ellis', [
            'indirectfont'    => true,
            'nestedresources' => true,
        ]);

        $this->assertSame(self::expected('Student name Jordan Ellis'), extractor::extract_pdf_php($path));
    }

    /**
     * FIX-DG-PDF-ENCRYPTED. A password-protected PDF must say so.
     *
     * Every string and stream in an encrypted PDF is encrypted, usually RC4 or AES.
     * Decoding one needs the standard security handler, which this parser does not
     * implement. What it used to do was worse than not supporting them: it inflated the
     * encrypted streams anyway, found no content, and returned an empty string - so the
     * caller reported "Extracted text is too short or empty. The file may be
     * scanned/image-based", sending the trainer to look for a scanner when the real answer
     * was that the file has a password.
     *
     * Most of these are not malicious. Students password-protect documents, and some
     * "compress PDF" web tools hand back an encrypted file.
     *
     * @return void
     */
    public function test_an_encrypted_pdf_is_reported_as_password_protected(): void {
        $path = $this->make_pdf('Student name Jordan Ellis', ['encrypt' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/password-protected/i');
        extractor::extract_pdf_php($path);
    }

    /**
     * FIX-DG-PDF-LASTRESORT-DUPLICATE. A short document must not come back twice.
     *
     * The last-resort salvage scan ran whenever the text so far was under 30 characters and
     * APPENDED to it. "Under 30 characters" is not "nothing found": a short but perfectly
     * extracted document - a one-line answer, a cover sheet, the one-question submissions
     * in this plugin's own sample pack - tripped it, and because the scan re-reads the same
     * Tj operators the proper pass already read, the result was the document's text twice.
     *
     * Measured on a 25-character fixture: "Student name Jordan Ellis Student name Jordan
     * Ellis". Duplicated text doubles the word count the trainer is shown and feeds the
     * similarity comparison a document the student did not submit.
     *
     * @return void
     */
    public function test_a_short_document_is_not_returned_twice(): void {
        // Deliberately under the old 30-character trigger.
        $path = $this->make_pdf('Yes I washed');

        $this->assertSame(self::expected('Yes I washed'), extractor::extract_pdf_php($path));
    }

    /**
     * Extraction never returns invalid UTF-8.
     *
     * FIX-DG-EXTRACT-UTF8. Invalid UTF-8 in a text column is not cosmetic: preg functions
     * with /u silently return false on it, which is how authenticity checks come back empty
     * on a document full of findings, and json_encode() of the analysis fails outright,
     * which is how a submission ends up stored with no result at all.
     *
     * @return void
     */
    public function test_ensure_utf8_cleans_invalid_sequences(): void {
        $broken = "Student name: Jord" . "\xC3" . "an Ellis" . "\xFF\xFE" . ' submitted';

        $clean = extractor::ensure_utf8($broken);

        $this->assertTrue(
            mb_check_encoding($clean, 'UTF-8'),
            'Whatever the extractors produced, what leaves extract() must be valid UTF-8.'
        );
        $this->assertStringContainsString('Student name', $clean);
        $this->assertStringContainsString('submitted', $clean);
    }

    /**
     * Valid UTF-8 is returned untouched, byte for byte.
     *
     * The guard above must not be a filter that quietly rewrites good text. Offsets
     * matter: authenticity::evidence() quotes by byte offset, so a substitution that
     * shifted the string would move every quoted excerpt in the report.
     *
     * @return void
     */
    public function test_ensure_utf8_leaves_valid_text_alone(): void {
        $text = "Linh Nguy\u{1ec5}n wrote: the temperature was 5\u{b0}C \u{2014} within range.";

        $this->assertSame($text, extractor::ensure_utf8($text));
    }

    /**
     * Font resource names are scoped to the PAGE, not to the document.
     *
     * "/F1" on page 1 and "/F1" on page 2 routinely name different font objects - every
     * multi-page Word or LibreOffice document does this. The old code kept one
     * document-wide table with "first resource dictionary wins", so in a multi-page
     * document every later page was decoded with the FIRST page's fonts.
     *
     * This fixture is the minimal reproduction: two pages, each with its own /F1 naming a
     * different subset font, drawing text that shares the low glyph codes. If the mapping
     * is not per-page, page 2 comes out as the letters of page 1's alphabet.
     *
     * It is also the test that proves the per-page path is load-bearing rather than
     * decorative: the document-wide table is a deliberate fallback for files whose page
     * tree cannot be walked, and it is capable of masking a per-page failure on a
     * single-page document.
     *
     * @return void
     */
    public function test_each_page_is_decoded_with_its_own_fonts(): void {
        $firstpage  = 'Hygiene before handling food';
        $secondpage = 'Temperature must stay below five';

        $firstmap  = self::subset($firstpage);
        $secondmap = self::subset($secondpage);

        $pdf = new \docguard_pdf_fixture();

        $tu1   = $pdf->add_stream('', \docguard_pdf_fixture::tounicode($firstmap));
        $font1 = $pdf->add('<</Type /Font /Subtype /TrueType /BaseFont /AAAAAA+One'
            . ' /ToUnicode ' . $tu1 . ' 0 R>>');
        $tu2   = $pdf->add_stream('', \docguard_pdf_fixture::tounicode($secondmap));
        $font2 = $pdf->add('<</Type /Font /Subtype /TrueType /BaseFont /BBBBBB+Two'
            . ' /ToUnicode ' . $tu2 . ' 0 R>>');

        // Both pages call their own font "/F1". That is the whole point.
        $res1 = $pdf->add('<</Font <</F1 ' . $font1 . ' 0 R>> /ProcSet [/PDF /Text]>>');
        $res2 = $pdf->add('<</Font <</F1 ' . $font2 . ' 0 R>> /ProcSet [/PDF /Text]>>');

        $content1 = $pdf->add_stream('', "BT\n/F1 12 Tf\n1 0 0 1 72 700 Tm\n<"
            . \docguard_pdf_fixture::encode($firstpage, $firstmap) . "> Tj\nET");
        $content2 = $pdf->add_stream('', "BT\n/F1 12 Tf\n1 0 0 1 72 700 Tm\n<"
            . \docguard_pdf_fixture::encode($secondpage, $secondmap) . "> Tj\nET");

        $pages = $pdf->reserve();
        $page1 = $pdf->add('<</Type /Page /Parent ' . $pages . ' 0 R /Resources ' . $res1
            . ' 0 R /Contents ' . $content1 . ' 0 R /MediaBox [0 0 612 792]>>');
        $page2 = $pdf->add('<</Type /Page /Parent ' . $pages . ' 0 R /Resources ' . $res2
            . ' 0 R /Contents ' . $content2 . ' 0 R /MediaBox [0 0 612 792]>>');
        $pdf->put($pages, '<</Type /Pages /Kids [' . $page1 . ' 0 R ' . $page2
            . ' 0 R] /Count 2>>');

        $path = make_request_directory() . '/twopage.pdf';
        file_put_contents($path, $pdf->build());

        $text = extractor::extract_pdf_php($path);

        $this->assertStringContainsString($firstpage, $text);
        $this->assertStringContainsString(
            $secondpage,
            $text,
            'Page 2 must be decoded with page 2 fonts, not page 1 fonts.'
        );
    }

    /**
     * Text that is not valid UTF-8 is never accepted as a usable extraction.
     *
     * FIX-DG-READABLE-INVALID-UTF8. This branch used to fall back to an ASCII ratio test
     * and RETURN TRUE for text that was not valid UTF-8 at all. Scrambled output is mostly
     * letters, so it scored about 0.9 and was accepted every time - which is how the
     * garbled built-in extraction reached the database instead of falling through.
     *
     * Invalid UTF-8 is not a language this plugin does not recognise; it is not text. It
     * cannot be stored, compared with /u patterns, or shown to a trainer.
     *
     * @return void
     */
    public function test_invalid_utf8_is_not_readable(): void {
        $text = 'Student name Jordan Ellis submitted this assessment on time and in full'
            . "\xFF\xFE\xFF" . 'with all the required evidence attached as asked.';

        $this->assertFalse(
            extractor::looks_readable($text),
            'Invalid UTF-8 must send the cascade to the next method, not be accepted.'
        );

        // The rejection is REPORTED, not silent. This is DocGuard's own diagnostic, not a
        // warning from Moodle: asserting it here pins that an administrator with developer
        // debugging on is told why an extraction was discarded. Discarding a student's
        // submission without saying so is how a site runs for months on the wrong tier
        // with nothing on any page to show it - which is the defect V1.1.3 existed to fix.
        $this->assertDebuggingCalled();
    }

    /**
     * A dense run of unmappable characters is not a usable extraction.
     *
     * FIX-DG-READABLE-REPLACEMENT-CHARS. U+FFFD means the decoder met codes it could not
     * map - a missing or wrong character table - so what came out is not the document's
     * text. Real text does not contain these: across the 73-document corpus the highest
     * count in a good extraction is zero. The ligature defect this release also fixes
     * measured 3.6%.
     *
     * @return void
     */
    public function test_text_full_of_replacement_characters_is_not_readable(): void {
        $good = 'The student wrote that the temperature must be kept below five degrees '
            . 'at all times and that the log is checked at the start of every shift.';

        $this->assertTrue(extractor::looks_readable($good));

        $broken = str_replace(['ti', 'fi'], "\u{FFFD}\u{FFFD}", $good . ' Qualification '
            . 'Certificate Written Questions identification notification');

        $this->assertFalse(
            extractor::looks_readable($broken),
            'A document peppered with unmappable characters has not been read.'
        );
        $this->assertDebuggingCalled();
    }

    /**
     * There is no language-plausibility check, and there must not be one.
     *
     * A function-word check was written for this release and removed before it shipped.
     * Against English it separated cleanly - correct extractions 0.288 to 0.576 of tokens,
     * scrambled ones 0.097 to 0.149 - and on that basis it looked finished.
     *
     * Then it was measured against the other languages an Australian VET cohort writes in.
     * French 0.024, Spanish 0.000, Vietnamese 0.025, Indonesian 0.000, German 0.056,
     * Tagalog 0.050, Somali 0.041, Swahili 0.000. Every one BELOW the scrambled text: the
     * check would have thrown out genuine submissions from those students while accepting
     * the garbage it existed to catch. Widening the word list moves the hole to whichever
     * language is not on the list, and there is always one.
     *
     * This test is the guard on that decision, in the same spirit as
     * authenticity_test::test_no_style_measurement_is_reintroduced(). The constants and the
     * helper are gone; a future reader who reaches for this idea should find this test
     * first, because the idea is appealing and the measurements are not obvious.
     *
     * @return void
     */
    public function test_no_language_plausibility_check_is_reintroduced(): void {
        $source = file_get_contents(__DIR__ . '/../classes/extractor.php');

        // Needles assembled at runtime so this test cannot match its own source, which is
        // a trap this project has fallen into six times.
        $banned = [
            'FUNCTION' . '_WORDS',
            'MIN_FUNCTION' . '_WORD_SHARE',
            'function_word' . '_share_is_plausible',
            'LATIN_LETTER' . '_SHARE_FOR_ENGLISH',
        ];
        foreach ($banned as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                'A language-plausibility check rejects real work from students who do not '
                . 'write in English. See the measurements in looks_readable().'
            );
        }

        // And the behaviour, not just the absence of the names: prose in a language the
        // plugin knows nothing about must be accepted as readable.
        $tagalog = 'Ang mag-aaral ay kailangang maghugas ng kamay gamit ang mainit na tubig '
            . 'at sabon nang hindi bababa sa dalawampung segundo bago humawak ng pagkain. '
            . 'Kailangan ding patuyuin ang kamay gamit ang papel at hindi sa apron. Ito ang '
            . 'pinakamahalagang kontrol sa kusina at sinusuri ito sa simula ng bawat shift '
            . 'ng superbisor na naka-duty sa araw na iyon. Kung may hiwa sa kamay kailangan '
            . 'itong takpan ng asul na benda at susuotan ng guwantes sa ibabaw nito.';

        $this->assertTrue(
            extractor::looks_readable($tagalog),
            'A submission written in Tagalog has been read perfectly well.'
        );
    }

    /**
     * Correctly extracted text in any language is readable.
     *
     * The companion to the test above: these are the documents a language-shaped guard
     * would have thrown away. They are kept as a data provider rather than prose so that
     * adding a cohort language is a one-line change.
     *
     * @dataProvider real_prose_provider
     * @param string $text Correctly extracted prose.
     * @param string $language What it is.
     * @return void
     */
    public function test_correctly_extracted_prose_is_readable_in_any_language(
        string $text,
        string $language
    ): void {
        $this->assertTrue(
            extractor::looks_readable($text),
            'A submission in ' . $language . ' has been read correctly and must be accepted.'
        );
    }

    /**
     * Real prose in the languages an Australian VET cohort writes in.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function real_prose_provider(): array {
        return [
            'English' => [
                'The student must wash their hands with warm water and soap for at least '
                    . 'twenty seconds before handling any food, and dry them on paper towel '
                    . 'rather than on an apron. This is the control that matters most.',
                'English',
            ],
            'French' => [
                'L\'etudiant doit se laver les mains avec de l\'eau chaude et du savon '
                    . 'pendant au moins vingt secondes avant de manipuler des aliments. '
                    . 'C\'est le controle le plus important dans une cuisine.',
                'French',
            ],
            'Spanish' => [
                'El estudiante debe lavarse las manos con agua tibia y jabon durante al '
                    . 'menos veinte segundos antes de manipular cualquier alimento. Es el '
                    . 'control mas importante en una cocina.',
                'Spanish',
            ],
            'Vietnamese' => [
                'Sinh vien phai rua tay bang nuoc am va xa phong trong it nhat hai muoi '
                    . 'giay truoc khi tiep xuc voi thuc pham. Day la bien phap quan trong '
                    . 'nhat trong bep va duoc kiem tra vao dau moi ca lam viec.',
                'Vietnamese',
            ],
            'Tagalog' => [
                'Ang mag-aaral ay kailangang maghugas ng kamay gamit ang mainit na tubig at '
                    . 'sabon nang hindi bababa sa dalawampung segundo bago humawak ng '
                    . 'pagkain. Ito ang pinakamahalagang kontrol sa kusina.',
                'Tagalog',
            ],
            'Swahili' => [
                'Mwanafunzi anapaswa kunawa mikono kwa maji ya moto na sabuni kwa muda wa '
                    . 'sekunde ishirini kabla ya kushika chakula chochote. Hii ni udhibiti '
                    . 'muhimu zaidi jikoni na inakaguliwa mwanzoni wa kila zamu.',
                'Swahili',
            ],
            'Chinese' => [
                '这是一个关于食品安全的评估。学生必须洗手二十秒钟。温度必须保持在五度以下或六十度以上。'
                    . '如果手上有伤口必须用蓝色防水敷料盖住并戴上手套。',
                'Chinese',
            ],
            'Arabic' => [
                'هذا تقييم حول سلامة الأغذية ويجب على الطالب غسل يديه لمدة عشرين ثانية قبل '
                    . 'لمس الطعام. يجب أيضا تجفيف اليدين بالورق وليس بالمريلة.',
                'Arabic',
            ],
        ];
    }

    /**
     * Build a PDF whose font table cannot produce any character.
     *
     * Every code maps to a lone surrogate - a code point that cannot be encoded - so every
     * glyph decodes to U+FFFD. This is the shape of a font whose character information is
     * missing or broken, and it is the only way to reach the readability guard
     * deterministically from a public entry point.
     *
     * @param string $text The text the page draws.
     * @return string Absolute path to the PDF written.
     */
    private function make_undecodable_pdf(string $text): string {
        $pdf   = new \docguard_pdf_fixture();
        $codes = [];
        $next  = 1;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (!isset($codes[$char])) {
                $codes[$char] = $next++;
            }
        }

        $entries = '';
        foreach ($codes as $code) {
            $entries .= sprintf("<%02X> <D800>\n", $code);
        }
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CMapName /Broken def\n/CMapType 2 def\n1 begincodespacerange\n<00> <FF>\n"
            . "endcodespacerange\n" . count($codes) . " beginbfchar\n" . $entries
            . "endbfchar\nendcmap\nend\nend\n";

        $tounicode = $pdf->add_stream('', $cmap);
        $font      = $pdf->add('<</Type /Font /Subtype /TrueType /BaseFont /AAAAAA+Broken'
            . ' /ToUnicode ' . $tounicode . ' 0 R>>');
        $resources = $pdf->add('<</Font <</F1 ' . $font . ' 0 R>> /ProcSet [/PDF /Text]>>');

        $hex = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $hex .= sprintf('%02X', $codes[$char]);
        }
        $content = $pdf->add_stream('', "BT\n/F1 12 Tf\n1 0 0 1 72 700 Tm\n<" . $hex . "> Tj\nET");

        $pages = $pdf->reserve();
        $page  = $pdf->add('<</Type /Page /Parent ' . $pages . ' 0 R /Resources ' . $resources
            . ' 0 R /Contents ' . $content . ' 0 R /MediaBox [0 0 612 792]>>');
        $pdf->put($pages, '<</Type /Pages /Kids [' . $page . ' 0 R] /Count 1>>');

        $path = make_request_directory() . '/undecodable.pdf';
        file_put_contents($path, $pdf->build());
        return $path;
    }

    /**
     * A PDF nothing can decode is refused, not returned as replacement characters.
     *
     * This is the readability guard doing the job it exists for, at the one tier that had
     * never been checked. The two CLI tiers were always tested for readability; the
     * built-in reader - the one that was actually producing scrambled text - was accepted
     * unconditionally, so nothing in the plugin could notice.
     *
     * Adding the guard was not enough on its own. In the first draft of extract_pdf() the
     * guard was evaluated, the cascade fell through it correctly, and the final return
     * handed back the unreadable text anyway: measured at 252 characters of U+FFFD
     * returned by a tier that had just been told its text was no good. Those characters
     * would have been stored, compared against the cohort as though they were the
     * student's writing, and shown to a trainer as the document's text.
     *
     * The message names the fault and what to do about it, because an error somebody can
     * act on is the whole difference between this and a page of question marks.
     *
     * @return void
     */
    public function test_an_undecodable_pdf_is_refused_rather_than_returned(): void {
        $path = $this->make_undecodable_pdf(
            'Student name Jordan Ellis submitted this assessment with all of the evidence '
            . 'attached today as the assessor asked for in the original task instructions.'
        );

        // Not expectException(): the debugging() call this path makes has to be asserted
        // too, and an expected exception ends the test before any assertion after it runs.
        try {
            extractor::extract_pdf($path);
            $this->fail('A PDF that cannot be decoded must be refused, not returned.');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression('/could not be decoded/i', $e->getMessage());
        }

        $this->assertDebuggingCalled();
    }

    /**
     * The same document, read by the built-in reader alone, is what the guard rejects.
     *
     * Asserted separately so that a failure of the test above says which half broke: the
     * reader producing something different, or the cascade handling it differently.
     *
     * @return void
     */
    public function test_an_undecodable_pdf_fails_the_readability_guard(): void {
        $path = $this->make_undecodable_pdf(
            'Student name Jordan Ellis submitted this assessment with all of the evidence '
            . 'attached today as the assessor asked for in the original task instructions.'
        );

        $text = extractor::extract_pdf_php($path);

        $this->assertNotEmpty($text, 'The reader does produce output; it is just not text.');
        $this->assertFalse(
            extractor::looks_readable($text),
            'A document that is entirely replacement characters has not been decoded.'
        );
        $this->assertDebuggingCalled();
    }
}
