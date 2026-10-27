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
 * Unit tests for the document section parser and its similarity helpers.
 *
 * Every expected value in this file was observed from a run of the real method
 * against the exact input given, not predicted from reading the code.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\question_parser
 */
final class question_parser_test extends \advanced_testcase {
    /**
     * Filler long enough to carry a section past MIN_SECTION_CHARS (40 bytes).
     */
    const PAD = ' plus enough narrative body text to clear the forty character minimum.';

    /**
     * Documents whose labelled markers should split into one section per marker.
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, int>}>
     */
    public static function labelled_document_provider(): array {
        $pad = self::PAD;
        return [
            'Question N with colon' => [
                "Question 1: Describe the safety procedure.{$pad}\n"
                    . "Question 2: Explain the escalation path.{$pad}",
                ['Question 1', 'Question 2'],
                [1, 2],
            ],
            'Q.N with no space' => [
                "Q.1 Describe the safety procedure.{$pad}\n"
                    . "Q.2 Explain the escalation path.{$pad}",
                ['Q. 1', 'Q. 2'],
                [1, 2],
            ],
            'QN with no separator' => [
                "Q1: Describe the safety procedure.{$pad}\n"
                    . "Q2: Explain the escalation path.{$pad}",
                ['Q 1', 'Q 2'],
                [1, 2],
            ],
            'Activity N, three sections' => [
                "Activity 1: Prepare the workspace.{$pad}\n"
                    . "Activity 2: Clean the workspace.{$pad}\n"
                    . "Activity 3: Report on the workspace.{$pad}",
                ['Activity 1', 'Activity 2', 'Activity 3'],
                [1, 2, 3],
            ],
            'Task N with dash separator' => [
                "Task 1 - Prepare the workspace.{$pad}\n"
                    . "Task 2 - Clean the workspace.{$pad}",
                ['Task 1', 'Task 2'],
                [1, 2],
            ],
            'Part N with bracket separator' => [
                "Part 1) Prepare the workspace.{$pad}\n"
                    . "Part 2) Clean the workspace.{$pad}",
                ['Part 1', 'Part 2'],
                [1, 2],
            ],
            'Section N' => [
                "Section 1: Prepare the workspace.{$pad}\n"
                    . "Section 2: Clean the workspace.{$pad}",
                ['Section 1', 'Section 2'],
                [1, 2],
            ],
            'Answer N' => [
                "Answer 1: Prepare the workspace.{$pad}\n"
                    . "Answer 2: Clean the workspace.{$pad}",
                ['Answer 1', 'Answer 2'],
                [1, 2],
            ],
            'Response N' => [
                "Response 1: Prepare the workspace.{$pad}\n"
                    . "Response 2: Clean the workspace.{$pad}",
                ['Response 1', 'Response 2'],
                [1, 2],
            ],
            'markers preceded by cover-sheet preamble' => [
                "Student name: Jane Doe\nUnit code: BSBWHS411\n"
                    . "Question 1: Describe the safety procedure.{$pad}\n"
                    . "Question 2: Explain the escalation path.{$pad}",
                ['Question 1', 'Question 2'],
                [1, 2],
            ],
        ];
    }

    /**
     * Labelled markers produce one section per marker, with the marker word in the label.
     *
     * @dataProvider labelled_document_provider
     * @param string $text The document text to parse.
     * @param array $expectedlabels The label expected on each returned section, in order.
     * @param array $expectednums The section number expected on each returned section.
     * @return void
     */
    public function test_parse_labelled_sections(string $text, array $expectedlabels, array $expectednums): void {
        $sections = question_parser::parse($text);
        $this->assertSame($expectedlabels, array_column($sections, 'label'));
        $this->assertSame($expectednums, array_column($sections, 'num'));
    }

    /**
     * Documents split by a bare numbered list rather than a labelled marker.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function numbered_document_provider(): array {
        $pad = self::PAD;
        return [
            'number followed by full stop' => [
                "1. Describe the safety procedure.{$pad}\n"
                    . "2. Explain the escalation path.{$pad}",
                ['Question 1', 'Question 2'],
            ],
            'number followed by bracket' => [
                "1) Describe the safety procedure.{$pad}\n"
                    . "2) Explain the escalation path.{$pad}",
                ['Question 1', 'Question 2'],
            ],
        ];
    }

    /**
     * Standalone numbered lines are labelled "Question N" by the numbered parser.
     *
     * @dataProvider numbered_document_provider
     * @param string $text The document text to parse.
     * @param array $expectedlabels The label expected on each returned section, in order.
     * @return void
     */
    public function test_parse_numbered_sections(string $text, array $expectedlabels): void {
        $sections = question_parser::parse($text);
        $this->assertSame($expectedlabels, array_column($sections, 'label'));
    }

    /**
     * Three-digit leading numbers are not treated as list markers, so the document
     * falls all the way through to the single-section case.
     *
     * @return void
     */
    public function test_parse_three_digit_numbers_are_not_list_markers(): void {
        $pad = self::PAD;
        $text = "100. This line starts with a three digit number and should not match.{$pad}\n"
            . "200. Another three digit numbered line in the very same document.{$pad}";

        $sections = question_parser::parse($text);

        $this->assertCount(1, $sections);
        $this->assertSame('Full Document', $sections[0]['label']);
        $this->assertSame(0, $sections[0]['num']);
    }

    /**
     * A document with no recognisable markers becomes one "Full Document" section
     * carrying the trimmed original text.
     *
     * @return void
     */
    public function test_parse_falls_back_to_full_document(): void {
        $text = "  This document has no markers at all, just a long paragraph of prose that runs on.  ";

        $sections = question_parser::parse($text);

        $this->assertCount(1, $sections);
        $this->assertSame(0, $sections[0]['num']);
        $this->assertSame('Full Document', $sections[0]['label']);
        $this->assertSame(
            'This document has no markers at all, just a long paragraph of prose that runs on.',
            $sections[0]['text']
        );
    }

    /**
     * A single labelled marker is not enough to split: parse() needs two or more,
     * so one "Question 1:" heading yields one Full Document section instead.
     *
     * @return void
     */
    public function test_parse_single_marker_falls_back_to_full_document(): void {
        $text = "Question 1: Describe the safety procedure." . self::PAD . "\n"
            . "There is only one labelled marker in this whole document body.";

        $sections = question_parser::parse($text);

        $this->assertCount(1, $sections);
        $this->assertSame('Full Document', $sections[0]['label']);
        $this->assertStringStartsWith('Question 1: Describe', $sections[0]['text']);
    }

    /**
     * The single-section fallback keeps text shorter than MIN_SECTION_CHARS, because
     * filter_short() only runs on the multi-section paths.
     *
     * @return void
     */
    public function test_parse_full_document_is_not_length_filtered(): void {
        $sections = question_parser::parse('Too short.');

        $this->assertCount(1, $sections);
        $this->assertSame('Too short.', $sections[0]['text']);
        $this->assertLessThan(question_parser::MIN_SECTION_CHARS, strlen($sections[0]['text']));
    }

    /**
     * Input with no printable content returns no sections at all.
     *
     * @return array<string, array{0: string}>
     */
    public static function empty_document_provider(): array {
        return [
            'empty string' => [''],
            'whitespace only' => ["   \n\n\t  "],
        ];
    }

    /**
     * Empty or whitespace-only input yields an empty section list.
     *
     * @dataProvider empty_document_provider
     * @param string $text The document text to parse.
     * @return void
     */
    public function test_parse_empty_input(string $text): void {
        $this->assertSame([], question_parser::parse($text));
    }

    /**
     * Line endings that split sections, whatever convention produced them.
     *
     * @return array<string, array{0: string}>
     */
    public static function line_ending_provider(): array {
        return [
            'unix LF' => ["\n"],
            'windows CRLF' => ["\r\n"],
            'classic mac CR' => ["\r"],
        ];
    }

    /**
     * CRLF and bare CR are normalised to LF before splitting, so all three conventions
     * produce the identical two-section result.
     *
     * @dataProvider line_ending_provider
     * @param string $eol The line separator to build the document with.
     * @return void
     */
    public function test_parse_normalises_line_endings(string $eol): void {
        $pad = self::PAD;
        $text = "Question 1: Describe the safety procedure.{$pad}{$eol}"
            . "Question 2: Explain the escalation path.{$pad}";

        $sections = question_parser::parse($text);

        $this->assertSame(['Question 1', 'Question 2'], array_column($sections, 'label'));
        $this->assertStringNotContainsString("\r", $sections[0]['text']);
        $this->assertStringNotContainsString("\r", $sections[1]['text']);
    }

    /**
     * Sections shorter than MIN_SECTION_CHARS are dropped, and the surviving sections
     * keep their original numbering rather than being renumbered from one.
     *
     * @return void
     */
    public function test_parse_drops_sections_below_minimum_length(): void {
        $text = "Question 1: Short.\n"
            . "Question 2: This one is definitely long enough to survive the minimum length filter.\n"
            . "Question 3: Also comfortably longer than the forty character minimum threshold here.";

        $sections = question_parser::parse($text);

        $this->assertCount(2, $sections);
        $this->assertSame([2, 3], array_column($sections, 'num'));
        $this->assertSame(['Question 2', 'Question 3'], array_column($sections, 'label'));
    }

    /**
     * Continuation lines belong to the section that opened above them.
     *
     * @return void
     */
    public function test_parse_keeps_continuation_lines_with_their_section(): void {
        $pad = self::PAD;
        $text = "Question 1: First.{$pad}\nContinued body line for question one here.\n"
            . "Question 2: Second.{$pad}";

        $sections = question_parser::parse($text);

        $this->assertCount(2, $sections);
        $this->assertStringContainsString('Continued body line for question one here.', $sections[0]['text']);
        $this->assertStringNotContainsString('Continued body line', $sections[1]['text']);
    }

    /**
     * Text in a range of scripts with the normalisation each one is expected to survive.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalisation_provider(): array {
        return [
            'ascii loses punctuation and case' => [
                'Hello, World! This is a TEST.',
                'hello world this is a test',
            ],
            'accented latin keeps its diacritics' => [
                'Café Müller — naïve façade',
                'café müller naïve façade',
            ],
            'chinese survives instead of emptying' => [
                '这是一个测试文件。',
                '这是一个测试文件',
            ],
            'arabic survives instead of emptying' => [
                'هذا اختبار، نعم!',
                'هذا اختبار نعم',
            ],
            'cyrillic survives and lowercases' => [
                'Это Тест, да!',
                'это тест да',
            ],
            'greek survives and lowercases' => [
                'Αυτό Είναι Ένα Τεστ.',
                'αυτό είναι ένα τεστ',
            ],
            'digits are kept, punctuation inside them is not' => [
                'Item 42: cost $19.99 (approx).',
                'item 42 cost 1999 approx',
            ],
            'runs of whitespace collapse to one space' => [
                "  multiple\t\tspaces\n\nand   newlines  ",
                'multiple spaces and newlines',
            ],
            'combining marks are preserved' => [
                "Devana\u{0304}gari\u{0301} test",
                "devana\u{0304}gari\u{0301} test",
            ],
            'empty input stays empty' => ['', ''],
            'punctuation-only input normalises away entirely' => ['!!! ??? ...', ''],
        ];
    }

    /**
     * normalise_for_similarity() lowercases, strips punctuation and collapses
     * whitespace without destroying non-ASCII text.
     *
     * Before v1.0.80 the four non-Latin datasets here all normalised to an empty
     * string and "café" became "caf"; these are the regression cases for that.
     *
     * @dataProvider normalisation_provider
     * @param string $input The raw text.
     * @param string $expected The normalised fingerprint observed from the real method.
     * @return void
     */
    public function test_normalise_for_similarity(string $input, string $expected): void {
        $this->assertSame($expected, question_parser::normalise_for_similarity($input));
    }

    /**
     * Two identical Cyrillic documents compare as identical end to end: normalisation
     * keeps the text, bigrams are built from it, and Jaccard returns 1.0. Two unrelated
     * Cyrillic documents still return 0.0, so the 1.0 is not an artefact of both sides
     * having been emptied. This is the end-to-end regression case for v1.0.80.
     *
     * @return void
     */
    public function test_identical_cyrillic_documents_compare_as_identical(): void {
        $same  = 'Это полностью одинаковый текст, который сдали два студента.';
        $other = 'Это совершенно другой текст без общих слов вообще.';

        $a = question_parser::bigram_set(question_parser::normalise_for_similarity($same));
        $b = question_parser::bigram_set(question_parser::normalise_for_similarity($same));
        $c = question_parser::bigram_set(question_parser::normalise_for_similarity($other));

        $this->assertCount(7, $a);
        $this->assertSame(1.0, question_parser::jaccard_sets($a, $b));
        $this->assertSame(0.0, question_parser::jaccard_sets($a, $c));
    }

    /**
     * Regression test for FIX-DG-CJK-SIMILARITY (v1.0.84).
     *
     * v1.0.80 fixed normalise_for_similarity() so non-Latin text survives normalisation
     * instead of becoming an empty string. For spaced scripts - Cyrillic, Greek, Arabic -
     * that was enough. It was not enough for Chinese, Japanese or Thai: bigrams() and
     * bigram_set() both tokenised on the space character alone, so a normalised Chinese
     * document was ONE token, which yields ZERO bigrams, which makes jaccard_sets()
     * return 0.0 even comparing a document with itself. Cross-student similarity was
     * silently 0% for every CJK cohort.
     *
     * v1.0.84 splits a run of CJK/Thai characters per character, so a bigram is a
     * character pair - the standard approach for these scripts.
     *
     * @return void
     */
    public function test_space_free_scripts_produce_comparable_bigrams(): void {
        $doc   = '这是一个测试文件，学生提交的内容完全相同。';
        $other = '天气很好我们去公园散步吧朋友们一起走。';

        $norm = question_parser::normalise_for_similarity($doc);

        // The v1.0.80 fix still holds: the text is preserved, punctuation only is removed.
        $this->assertSame('这是一个测试文件学生提交的内容完全相同', $norm);

        // 19 characters give 18 adjacent character pairs, all distinct here.
        $this->assertSame(19, \core_text::strlen($norm));
        $set = question_parser::bigram_set($norm);
        $this->assertCount(18, $set);

        // An identical submission now compares as identical, which is the whole point.
        $this->assertSame(1.0, question_parser::jaccard_sets($set, $set));

        // An unrelated Chinese submission still compares as unrelated - the fix must not
        // simply make everything look similar.
        $unrelated = question_parser::bigram_set(question_parser::normalise_for_similarity($other));
        $this->assertSame(0.0, question_parser::jaccard_sets($set, $unrelated));

        // Bigrams() and jaccard() must agree with the set-based pair, as they do for
        // spaced scripts; the two implementations are documented as equivalent.
        $this->assertSame(
            question_parser::jaccard_sets($set, $unrelated),
            question_parser::jaccard(
                question_parser::bigrams($norm),
                question_parser::bigrams(question_parser::normalise_for_similarity($other))
            )
        );
    }

    /**
     * FIX-DG-CJK-SIMILARITY must not change any spaced-script result.
     *
     * Similarity scores are stored, and a tokenisation change that shifted Latin or
     * Cyrillic results would make every historical comparison incomparable with a new
     * one. Text containing no CJK or Thai characters must take exactly the whitespace
     * split it always did.
     *
     * @return void
     */
    public function test_spaced_scripts_are_unaffected_by_the_cjk_tokeniser(): void {
        $samples = [
            'a worker should report the hazard to their supervisor immediately',
            'Café Müller — naïve façade, résumé of the crème brûlée.',
            'это тестовый документ с одинаковым содержанием и словами',
            'Item 42: cost $19.99 (approx). Multiple   spaces   here.',
        ];

        foreach ($samples as $raw) {
            $norm = question_parser::normalise_for_similarity($raw);

            // The pre-v1.0.84 implementation, inline.
            $words = explode(' ', $norm);
            $expected = [];
            for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
                if ($words[$i] !== '' && $words[$i + 1] !== '') {
                    $expected[$words[$i] . '_' . $words[$i + 1]] = true;
                }
            }

            $actual = question_parser::bigram_set($norm);
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual, 'bigram set changed for: ' . $raw);
        }
    }

    /**
     * Text containing bytes that are not valid UTF-8 still normalises to usable
     * content rather than being lost.
     *
     * @return void
     */
    public function test_normalise_for_similarity_handles_invalid_utf8(): void {
        $input = 'Bad' . chr(0xFF) . chr(0xFE) . ' Bytes HERE!';

        $this->assertSame('bad bytes here', question_parser::normalise_for_similarity($input));
    }

    /**
     * Normalised strings and the distinct word bigrams they produce.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function bigram_provider(): array {
        return [
            'four words give three bigrams' => [
                'the quick brown fox',
                ['the_quick', 'quick_brown', 'brown_fox'],
            ],
            'repeated pairs are de-duplicated' => [
                'a b a b a b',
                ['a_b', 'b_a'],
            ],
            'a single word has no bigrams' => ['lonely', []],
            'empty text has no bigrams' => ['', []],
            'six words give five bigrams' => [
                'the cat sat on the mat',
                ['the_cat', 'cat_sat', 'sat_on', 'on_the', 'the_mat'],
            ],
        ];
    }

    /**
     * bigrams() returns the distinct adjacent word pairs in document order.
     *
     * @dataProvider bigram_provider
     * @param string $normtext Normalised text.
     * @param array $expected The bigrams observed from the real method.
     * @return void
     */
    public function test_bigrams(string $normtext, array $expected): void {
        $this->assertSame($expected, question_parser::bigrams($normtext));
    }

    /**
     * bigram_set() holds exactly the same bigrams as bigrams(), keyed instead of listed.
     *
     * @dataProvider bigram_provider
     * @param string $normtext Normalised text.
     * @param array $expected The bigrams observed from the real method.
     * @return void
     */
    public function test_bigram_set_matches_bigrams(string $normtext, array $expected): void {
        $set = question_parser::bigram_set($normtext);

        $this->assertSame($expected, array_keys($set));
        foreach ($set as $value) {
            $this->assertTrue($value);
        }
    }

    /**
     * Document pairs and the Jaccard similarity of their bigram sets.
     *
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function jaccard_provider(): array {
        return [
            // Both documents give {the_cat, cat_sat, sat_on, on_the, the_mat}: 5/5 = 1.0.
            'identical documents' => ['the cat sat on the mat', 'the cat sat on the mat', 1.0],
            /* {alpha_beta, beta_gamma} and {delta_epsilon, epsilon_zeta} share nothing. */
            'disjoint documents' => ['alpha beta gamma', 'delta epsilon zeta', 0.0],
            // Four shared bigrams, the_mat and the_rug unshared: 4 / 6 = 0.6667.
            'one word differs, four of six bigrams shared' => [
                'the cat sat on the mat',
                'the cat sat on the rug',
                0.6667,
            ],
            /* {the_cat, cat_sat} is a subset of the five-bigram set: 2 / 5 = 0.4. */
            'one document is a prefix of the other' => ['the cat sat', 'the cat sat on the mat', 0.4],
            'left document empty' => ['', 'the cat sat on the mat', 0.0],
            'right document empty' => ['the cat sat on the mat', '', 0.0],
            'both documents empty' => ['', '', 0.0],
            'single words produce no bigrams to compare' => ['lonely', 'lonely', 0.0],
        ];
    }

    /**
     * jaccard() scores the overlap of two bigram lists.
     *
     * @dataProvider jaccard_provider
     * @param string $a Normalised text of the first document.
     * @param string $b Normalised text of the second document.
     * @param float $expected The similarity observed from the real method.
     * @return void
     */
    public function test_jaccard(string $a, string $b, float $expected): void {
        $this->assertSame(
            $expected,
            question_parser::jaccard(question_parser::bigrams($a), question_parser::bigrams($b))
        );
    }

    /**
     * jaccard_sets() was introduced in v1.0.80 as a faster equivalent of jaccard().
     * It must return exactly the same number for the same documents, including the
     * same rounding, or the class report and the submission report will disagree.
     *
     * @dataProvider jaccard_provider
     * @param string $a Normalised text of the first document.
     * @param string $b Normalised text of the second document.
     * @param float $expected The similarity observed from the real method.
     * @return void
     */
    public function test_jaccard_sets_agrees_with_jaccard(string $a, string $b, float $expected): void {
        $listbased = question_parser::jaccard(question_parser::bigrams($a), question_parser::bigrams($b));
        $setbased  = question_parser::jaccard_sets(
            question_parser::bigram_set($a),
            question_parser::bigram_set($b)
        );

        $this->assertSame($expected, $setbased);
        $this->assertSame($listbased, $setbased);
    }

    /**
     * jaccard_sets() swaps its arguments internally when the first set is the larger.
     * Similarity is symmetric, so argument order must not change the answer.
     *
     * @return void
     */
    public function test_jaccard_sets_is_symmetric(): void {
        $small = question_parser::bigram_set('the cat sat');
        $large = question_parser::bigram_set('the cat sat on the mat');

        $this->assertSame(0.4, question_parser::jaccard_sets($small, $large));
        $this->assertSame(0.4, question_parser::jaccard_sets($large, $small));
    }

    /**
     * String pairs and their similar_text() percentage as a 0.0-1.0 ratio.
     *
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function similarity_provider(): array {
        return [
            'identical strings' => ['the cat sat on the mat', 'the cat sat on the mat', 1.0],
            'no characters in common' => ['aaaaaa', 'bbbbbb', 0.0],
            'one word differs' => ['the cat sat on the mat', 'the cat sat on the rug', 0.8636],
            'left string empty' => ['', 'abc', 0.0],
            'right string empty' => ['abc', '', 0.0],
        ];
    }

    /*
     * V1.1.1: test_similarity() removed with the function it covered. See the note in
     * question_parser.php - similar_text() is a character measure that copy detection
     * has never used, and reported 23% for two unrelated paragraphs.
     */

    /**
     * Regression tests for FIX-DG-INDENTED-MARKERS (v1.0.86).
     *
     * The marker pattern was anchored with a bare `^`, requiring the heading to start at
     * column zero. Extracted text is indented far more often than not:
     *
     *  - Ghostscript's txtwrite device prefixes every line with several spaces, so on a
     *    host with Ghostscript but no poppler per-question analysis had never worked.
     *  - `pdftotext -layout` deliberately preserves the source document's indentation, so
     *    any assessment whose questions sit in a table, under a hanging indent or in a
     *    numbered list failed on every host.
     *
     * Found on a live site: a document with three "Question N:" headings reported one
     * "Full Document" section. The same text dedented parsed into all three.
     *
     * The second half of this test is the part that must not regress. `^[ \t]*` tolerates
     * indentation but still requires the marker to START ITS OWN LINE, so a mention of
     * "Question 1" inside a sentence cannot open a section, truncate the real one and
     * attribute a student's text to the wrong question.
     *
     * @dataProvider indentation_provider
     * @param string $indent   The whitespace prefixing each line.
     * @param string $eol      The line ending to use.
     * @param int    $expected The number of sections expected.
     * @return void
     */
    public function test_markers_are_found_whatever_the_indentation(
        string $indent,
        string $eol,
        int $expected
    ): void {
        $body = "Question 1: Describe one hazard control and say why it matters in practice.\n"
              . "Answer: The worker reports it to their supervisor without any delay at all.\n"
              . "Question 2: Explain the difference between a hazard and a risk in your words.\n"
              . "Answer: A hazard can cause harm; risk is how likely that harm actually is.";
        $text = $indent . str_replace("\n", "\n" . $indent, $body);
        $text = str_replace("\n", $eol, $text);

        $sections = question_parser::parse($text);

        $this->assertCount($expected, $sections);
        $this->assertSame(['Question 1', 'Question 2'], array_column($sections, 'label'));
    }

    /**
     * The indentation styles real extractors produce.
     *
     * @return array[] Each dataset: line prefix, line ending, expected section count.
     */
    public static function indentation_provider(): array {
        return [
            'flush left, unix'        => ['', "\n", 2],
            'four spaces'             => ['    ', "\n", 2],
            'tab indented'            => ["\t", "\n", 2],
            'ghostscript five spaces' => ['     ', "\n", 2],
            'indented with CRLF'      => ['  ', "\r\n", 2],
            'indented with CR only'   => ['  ', "\r", 2],
        ];
    }

    /**
     * A marker inside a sentence must still not open a section.
     *
     * This is the protection the `^` anchor exists for, and FIX-DG-INDENTED-MARKERS must
     * not have cost it. These words are ordinary prose inside student answers; unanchored,
     * each would truncate the real section and mis-attribute the text that follows.
     *
     * @dataProvider prose_marker_provider
     * @param string $text The document text.
     * @return void
     */
    public function test_markers_inside_a_sentence_do_not_open_a_section(string $text): void {
        $sections = question_parser::parse($text);

        $this->assertCount(1, $sections);
        $this->assertSame('Full Document', $sections[0]['label']);
    }

    /**
     * Prose that mentions the marker words without heading anything.
     *
     * @return array[] Each dataset: the text.
     */
    public static function prose_marker_provider(): array {
        return [
            'refers back to a question' => [
                'The control described in Question 1 above applies here as well, and the '
                    . 'answer to Question 2 is similar in every respect that matters here.'],
            'cites legislation' => [
                'Under Part 2 of the Act a worker must report it. Section 5 of the WHS '
                    . 'Regulations says the same about Task 1 and about Question 1 too.'],
            'indented prose still does not match' => [
                '    The hazard in Question 1 is the wet floor, and Question 2 asks about '
                    . 'the risk, which is how likely somebody is to actually slip on it.'],
        ];
    }

    /**
     * Three realistic answers, long enough to survive MIN_SECTION_CHARS.
     *
     * @return string[] Answer text for questions 1, 2 and 3.
     */
    private static function three_answers(): array {
        return [
            'I wash my hands with warm water and soap for at least twenty seconds and dry '
                . 'them on paper towel rather than my apron, every time before I handle food.',
            'Standard 3.2.2 of the Food Standards Code requires potentially hazardous food '
                . 'to be kept at five degrees or below, and I check the log on the fridge door.',
            'The Act gives me a duty to take reasonable care for my own health and safety '
                . 'and for other people in the workplace, so I put the wet floor sign out first.',
        ];
    }

    /**
     * V1.2.5 FIX-DG-PAGEBREAK-MARKERS: a question at the top of a page still opens a
     * section.
     *
     * pdftotext emits a form feed at every page boundary and attaches it to the first line
     * of the next page, so a question that happened to start a page arrived as
     * "\x0cQuestion 3:" and the marker pattern - which tolerated only spaces and tabs -
     * did not match it.
     *
     * The damage was not limited to that one question. try_labelled() needs two or more
     * sections before parse() will use its result, so on a document whose page breaks
     * stranded enough markers the WHOLE submission collapsed to a single "Full Document"
     * section and per-question analysis was silently off. Multi-page submissions are the
     * normal case.
     *
     * @return void
     */
    public function test_a_question_after_a_page_break_still_opens_a_section(): void {
        [$a1, $a2] = self::three_answers();

        $sections = question_parser::parse(
            "Question 1: Hygiene\n$a1\n\n\x0cQuestion 2: Temperature\n$a2\n"
        );

        $this->assertCount(2, $sections);
        $this->assertSame(['Question 1', 'Question 2'], array_column($sections, 'label'));
        $this->assertStringContainsString('warm water and soap', $sections[0]['text']);
        $this->assertStringContainsString('Standard 3.2.2', $sections[1]['text']);
    }

    /**
     * The label must survive a page break too, not just the split.
     *
     * The label is read by a second pattern, and a mismatch between the two does not drop
     * the section - it MISLABELS it. That is V1.0.86 in another guise: a page-break
     * "Question 3" reported to the trainer as "Section 3".
     *
     * @return void
     */
    public function test_a_question_after_a_page_break_keeps_its_own_label(): void {
        [$a1, $a2, $a3] = self::three_answers();

        $sections = question_parser::parse(
            "Question 1: Hygiene\n$a1\n\n\x0cQuestion 2: Temperature\n$a2\n\n"
            . "\x0c   Question 3: WHS\n$a3\n"
        );

        $this->assertSame(
            ['Question 1', 'Question 2', 'Question 3'],
            array_column($sections, 'label')
        );
        $this->assertSame([1, 2, 3], array_column($sections, 'num'));
    }

    /**
     * V1.2.5 FIX-DG-CITED-SECTION-NUMBER: a cited Act section at the start of a wrapped
     * line must not open a section, and must not sever the answer it belongs to.
     *
     * The ^ anchor was never enough on its own, because line wrapping turns mid-sentence
     * text into start-of-line text:
     *
     *     "The duty on workers is set out in
     *      Section 28 of the Act, which is the part that applies to me."
     *
     * "Section 28" starts its own line, so it opened a section - and because a section runs
     * until the next marker, it also took the rest of the real answer with it. Measured on
     * a three-question submission: Question 3 dropped from 213 bytes to 49, the other 77%
     * being attributed to a phantom "Section 28", and its per-question similarity was then
     * computed on a quarter of what the student wrote.
     *
     * Citing a section of an Act is routine in the WHS and food-safety units this plugin
     * serves, which is why this was not a rare shape.
     *
     * The assertion is on the TEXT as well as the count. A test that only counted sections
     * would pass against a fix that dropped the phantom section and left the answer
     * truncated.
     *
     * @dataProvider cited_reference_provider
     * @param string $citation The wrapped citation line.
     * @return void
     */
    public function test_a_cited_reference_does_not_open_a_section(string $citation): void {
        [$a1, $a2, $a3] = self::three_answers();
        $tail = "The requirement is set out in\n" . $citation;

        $sections = question_parser::parse(
            "Question 1: Hygiene\n$a1\n\nQuestion 2: Temperature\n$a2\n\n"
            . "Question 3: WHS\n$a3 $tail\n"
        );

        $this->assertCount(3, $sections);
        $this->assertSame(
            ['Question 1', 'Question 2', 'Question 3'],
            array_column($sections, 'label')
        );
        $this->assertStringContainsString(
            $citation,
            $sections[2]['text'],
            'The citation belongs to the answer that contains it, not to a section of its own.'
        );
        $this->assertStringContainsString(
            'reasonable care',
            $sections[2]['text'],
            'The answer must still be whole: the phantom section used to take the rest of it.'
        );
    }

    /**
     * The citations that used to open a phantom section.
     *
     * @return array<string, array{0: string}>
     */
    public static function cited_reference_provider(): array {
        return [
            'a section of an Act'  => ['Section 28 of the Act, which applies to workers.'],
            'a part of a Code'     => ['Part 2 of the Food Standards Code, on temperature.'],
            'a numbered task'      => ['Task 1 is complete and the photographs are attached.'],
            'a numbered answer'    => ['Answer 2 is attached as a separate document.'],
            'a question reference' => ['Question 1 of 3 was answered above in full already.'],
        ];
    }

    /**
     * A real heading with no colon is still a heading.
     *
     * The guard above rejects a marker followed by a lowercase word. It must not reject a
     * marker followed by a capitalised one, which is an ordinary way to write a heading -
     * "Question 2 Explain how the Act applies". Getting this wrong would be worse than the
     * defect it fixes, because it would silently turn per-question analysis off for a whole
     * assessment template.
     *
     * @return void
     */
    public function test_a_heading_without_a_colon_still_opens_a_section(): void {
        [$a1, $a2] = self::three_answers();

        $sections = question_parser::parse(
            "Question 1 Describe your hygiene practices\n$a1\n\n"
            . "Question 2 Explain the temperature rule\n$a2\n"
        );

        $this->assertCount(2, $sections);
        $this->assertSame(['Question 1', 'Question 2'], array_column($sections, 'label'));
    }

    /**
     * V1.2.5 FIX-DG-PARSE-ALL-SHORT: a document whose every labelled section is too short
     * falls back to one Full Document section, not to nothing.
     *
     * Both branches of parse() used to return filter_short() directly, and filter_short()
     * drops sections under MIN_SECTION_CHARS. So a document with two or more markers whose
     * sections were all short returned an EMPTY ARRAY for a document that plainly had text.
     *
     * The caller treats that as a failure and gives up on the submission:
     * analyser::analyse_file() returns status "error" AND normtext => null. The null is the
     * part that matters - a submission with no stored normtext is invisible to
     * cross-student comparison, neither compared against the cohort nor available for the
     * cohort to be compared against, and contributes nothing to deriving the assessment
     * template either.
     *
     * The trigger is ordinary: a student who uploads the question sheet with the answers
     * still blank, or a cover sheet and marking grid on their own. Both are everyday
     * wrong-file submissions. The same text with NO markers was handled correctly as one
     * Full Document section, so having structure made DocGuard do LESS with the file.
     *
     * @dataProvider all_short_provider
     * @param string $text The document text.
     * @return void
     */
    public function test_a_document_of_only_short_sections_falls_back_to_full_document(
        string $text
    ): void {
        $sections = question_parser::parse($text);

        $this->assertCount(
            1,
            $sections,
            'parse() must never return nothing for a document that has text in it.'
        );
        $this->assertSame('Full Document', $sections[0]['label']);
        $this->assertNotEmpty($sections[0]['text']);
    }

    /**
     * Documents with markers but no answer long enough to keep.
     *
     * @return array<string, array{0: string}>
     */
    public static function all_short_provider(): array {
        return [
            'question sheet with the answers left blank' => [
                "Question 1: Hygiene\n\n\nQuestion 2: Temperature\n\n\nQuestion 3: WHS\n\n\n"],
            'a marking grid on its own' => [
                "Question 1 ...... /5\nQuestion 2 ...... /5\nQuestion 3 ...... /5\nTotal ...... /15\n"],
        ];
    }
}
