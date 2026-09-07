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
 * Unit tests for the pure scoring surface of the DocGuard analyser.
 *
 * Only the methods that touch neither the database nor plugin configuration are
 * exercised here: score_section(), compute_s12_score() and band(). The individual
 * signal functions are private, so each one is driven through score_section() with
 * text constructed to fire it and text constructed not to. Every expected number was
 * observed from a run of the real method against the exact input given.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\analyser
 */
final class analyser_test extends \advanced_testcase {
    /**
     * Neutral vocabulary used to build sections of an exact word count. None of these
     * words is an AI marker, a transition word, a contraction or a template phrase.
     */
    const VOCAB = [
        'orange', 'window', 'garden', 'bicycle', 'lantern', 'harbour', 'meadow', 'cabinet',
        'trumpet', 'blanket', 'compass', 'pebble', 'thicket', 'saddle', 'quarry', 'kettle',
        'ribbon', 'furnace', 'lagoon', 'anchor', 'pasture', 'marble', 'cinder', 'plateau',
        'satchel', 'burrow', 'chimney', 'gravel', 'hollow', 'trellis', 'wagon', 'crate',
        'copper', 'bramble', 'tunnel', 'ledger', 'basket', 'stirrup', 'hedge', 'canvas',
    ];

    /**
     * Build neutral prose of an exact sentence count and words-per-sentence.
     *
     * @param int $sentences How many sentences to produce.
     * @param int $wordspersentence How many words each sentence should contain.
     * @return string The generated text.
     */
    private static function neutral_text(int $sentences, int $wordspersentence): string {
        $out = [];
        $i = 0;
        for ($s = 0; $s < $sentences; $s++) {
            $words = [];
            for ($k = 0; $k < $wordspersentence; $k++) {
                $words[] = self::VOCAB[$i % count(self::VOCAB)];
                $i++;
            }
            $out[] = ucfirst(implode(' ', $words)) . '.';
        }
        return implode(' ', $out);
    }

    /**
     * Risk scores and the band each one falls into.
     *
     * @return array<string, array{0: float, 1: string}>
     */
    public static function band_provider(): array {
        return [
            'zero is low' => [0.0, 'low'],
            'thirty four is low' => [34.0, 'low'],
            'just under thirty five is low' => [34.9, 'low'],
            'thirty five is medium' => [35.0, 'medium'],
            'sixty four is medium' => [64.0, 'medium'],
            'just under sixty five is medium' => [64.9, 'medium'],
            'sixty five is high' => [65.0, 'high'],
            'one hundred is high' => [100.0, 'high'],
        ];
    }

    /**
     * band() maps a score onto low, medium or high at the documented boundaries.
     *
     * @dataProvider band_provider
     * @param float $score The risk score to band.
     * @param string $expected The band observed from the real method.
     * @return void
     */
    public function test_band(float $score, string $expected): void {
        $this->assertSame($expected, analyser::band($score));
    }

    /**
     * Cross-student similarity values and the S12 points they earn.
     *
     * @return array<string, array{0: float, 1: int, 2: bool}>
     */
    public static function s12_provider(): array {
        return [
            'no similarity scores nothing' => [0.0, 0, false],
            'just below the first threshold' => [0.34, 0, false],
            'at the first threshold' => [0.35, 3, true],
            'just below the second threshold' => [0.54, 3, true],
            'at the second threshold' => [0.55, 8, true],
            'just below the third threshold' => [0.74, 8, true],
            'at the third threshold' => [0.75, 15, true],
            'identical submissions' => [1.0, 15, true],
        ];
    }

    /**
     * compute_s12_score() converts the highest similarity found into points at the
     * 0.35 / 0.55 / 0.75 boundaries.
     *
     * @dataProvider s12_provider
     * @param float $similarity The highest similarity found against another submission.
     * @param int $expectedpoints The points observed from the real method.
     * @param bool $expectedfired Whether the signal is expected to report as fired.
     * @return void
     */
    public function test_compute_s12_score(float $similarity, int $expectedpoints, bool $expectedfired): void {
        $result = analyser::compute_s12_score($similarity);

        $this->assertSame($expectedpoints, $result['points']);
        $this->assertSame(15, $result['max']);
        $this->assertSame($expectedfired, $result['fired']);
        $this->assertSame($similarity, $result['max_similarity']);
    }

    /**
     * A section of fewer than eight words is not scored at all: it reports zero risk
     * with an explicit note and no signals, so a one-line answer cannot be flagged.
     *
     * @return void
     */
    public function test_score_section_refuses_sections_under_eight_words(): void {
        $result = analyser::score_section('One two three four five six seven.');

        $this->assertSame(7, $result['wordcount']);
        $this->assertSame(0, $result['riskscore']);
        $this->assertSame('low', $result['risklevel']);
        $this->assertSame('insufficient_text', $result['note']);
        $this->assertSame([], $result['signals']);
    }

    /**
     * Eight words is the first length that is scored, and all ten signals are reported.
     *
     * @return void
     */
    public function test_score_section_scores_from_eight_words(): void {
        $result = analyser::score_section('One two three four five six seven eight.');

        $this->assertSame(8, $result['wordcount']);
        $this->assertArrayNotHasKey('note', $result);
        $this->assertSame([
            's1_ai_markers',
            's2_sentence_uniformity',
            's3_ttr_uniformity',
            's4_transitions',
            's5_contractions',
            's6_passive_voice',
            's7_template',
            's8_trigrams',
            's9_sentence_starts',
            's10_vocab_richness',
        ], array_keys($result['signals']));
        $this->assertSame(0, $result['riskscore']);
    }

    /**
     * S5 fires only above 100 words, at three points from 150 words and six from 300.
     * The 110-word case proves the threshold is real rather than "any text with no
     * contractions in it".
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function contraction_absence_provider(): array {
        return [
            '110 words is below the 150 word threshold' => [11, 110, 0],
            '160 words earns three points' => [16, 160, 3],
            '320 words earns six points' => [32, 320, 6],
        ];
    }

    /**
     * S5 scores the complete absence of contractions, but only in long enough sections.
     *
     * @dataProvider contraction_absence_provider
     * @param int $sentences Number of ten-word sentences to generate.
     * @param int $expectedwords The word count observed for that text.
     * @param int $expectedpoints The S5 points observed from the real method.
     * @return void
     */
    public function test_s5_contraction_absence(int $sentences, int $expectedwords, int $expectedpoints): void {
        $result = analyser::score_section(self::neutral_text($sentences, 10));

        $this->assertSame($expectedwords, $result['wordcount']);
        $this->assertSame($expectedpoints, $result['signals']['s5_contractions']['points']);
        $this->assertSame([], $result['signals']['s5_contractions']['found']);
        $this->assertSame($expectedpoints > 0, $result['signals']['s5_contractions']['fired']);
    }

    /**
     * A single contraction anywhere in a 300-word section silences S5 completely,
     * taking it from six points to zero and naming the contraction it found.
     *
     * @return void
     */
    public function test_s5_is_silenced_by_one_contraction(): void {
        $body = self::neutral_text(32, 10);

        $without = analyser::score_section($body);
        $with    = analyser::score_section($body . " I don't think that matters here at all.");

        $this->assertSame(6, $without['signals']['s5_contractions']['points']);
        $this->assertSame(0, $with['signals']['s5_contractions']['points']);
        $this->assertSame(["don't"], $with['signals']['s5_contractions']['found']);
        $this->assertFalse($with['signals']['s5_contractions']['fired']);
    }

    /**
     * S2 gives the full ten points to sentences of identical length, and nothing to
     * text whose sentence lengths vary the way human writing does.
     *
     * @return void
     */
    public function test_s2_sentence_length_uniformity(): void {
        $uniform = analyser::score_section(self::neutral_text(8, 12));

        $s2 = $uniform['signals']['s2_sentence_uniformity'];
        $this->assertSame(8, $s2['sentence_count']);
        $this->assertSame(12.0, $s2['mean_words']);
        $this->assertSame(0.0, $s2['std_dev']);
        $this->assertSame(10, $s2['points']);
        $this->assertTrue($s2['fired']);
    }

    /**
     * Sentences of 1, 24, 3, 26, 4, 20 and 5 words in turn produce a standard
     * deviation of 9.64 over the six sentences long enough to count, which is well
     * clear of the 4.0 cut-off, so S2 stays silent.
     *
     * @return void
     */
    public function test_s2_stays_silent_on_varied_sentence_lengths(): void {
        $text = 'Yes. '
            . 'Orange window garden bicycle lantern harbour meadow cabinet trumpet blanket compass pebble '
            . 'thicket saddle quarry kettle ribbon furnace lagoon anchor pasture marble cinder plateau. '
            . 'Satchel burrow chimney. '
            . 'Gravel hollow trellis wagon crate copper bramble tunnel ledger basket stirrup hedge canvas '
            . 'orange window garden bicycle lantern harbour meadow cabinet trumpet blanket compass pebble. '
            . 'Thicket saddle quarry kettle. '
            . 'Ribbon furnace lagoon anchor pasture marble cinder plateau satchel burrow chimney gravel '
            . 'hollow trellis wagon crate copper bramble tunnel ledger. '
            . 'Basket stirrup hedge canvas orange.';

        $s2 = analyser::score_section($text)['signals']['s2_sentence_uniformity'];

        $this->assertSame(6, $s2['sentence_count']);
        $this->assertSame(9.64, $s2['std_dev']);
        $this->assertSame(0, $s2['points']);
        $this->assertFalse($s2['fired']);
    }

    /**
     * S2 needs at least four sentences of more than two words; below that it reports
     * a null standard deviation rather than guessing.
     *
     * @return void
     */
    public function test_s2_needs_four_sentences(): void {
        $s2 = analyser::score_section('One two three four five six seven eight.')['signals']['s2_sentence_uniformity'];

        $this->assertSame(1, $s2['sentence_count']);
        $this->assertNull($s2['std_dev']);
        $this->assertSame(0, $s2['points']);
    }

    /**
     * Distinct AI marker phrases present in a section and the S1 points they earn.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function ai_marker_provider(): array {
        return [
            'no markers at all' => [
                'Orange window garden bicycle lantern harbour meadow cabinet trumpet blanket.',
                0,
                0,
            ],
            'one marker earns three points' => [
                'We delve into the orange window garden bicycle lantern harbour meadow cabinet.',
                1,
                3,
            ],
            'two markers earn eight points' => [
                'We delve into a robust orange window garden bicycle lantern harbour meadow.',
                2,
                8,
            ],
            'three markers earn twelve points' => [
                'We delve into a robust seamless orange window garden bicycle lantern harbour meadow.',
                3,
                12,
            ],
            'five markers earn seventeen points' => [
                'We delve into a robust seamless proactive synergy orange window garden bicycle lantern harbour.',
                5,
                17,
            ],
            'seven markers earn the maximum' => [
                'We delve into a robust seamless proactive synergy paradigm journey orange window garden bicycle.',
                7,
                22,
            ],
        ];
    }

    /**
     * S1 counts the distinct AI marker phrases present and scores them in bands.
     *
     * @dataProvider ai_marker_provider
     * @param string $text The section text.
     * @param int $expectedcount The marker count observed from the real method.
     * @param int $expectedpoints The S1 points observed from the real method.
     * @return void
     */
    public function test_s1_ai_markers(string $text, int $expectedcount, int $expectedpoints): void {
        $s1 = analyser::score_section($text)['signals']['s1_ai_markers'];

        $this->assertSame($expectedcount, $s1['marker_count']);
        $this->assertSame($expectedpoints, $s1['points']);
        $this->assertSame(22, $s1['max']);
        $this->assertSame($expectedpoints > 0, $s1['fired']);
    }

    /**
     * Regression test for FIX-DG-MARKER-DUPLICATE (v1.0.84).
     *
     * "landscape" appeared twice in AI_MARKERS. signal_ai_markers() walks the list with
     * strpos(), so ONE occurrence of the word in a student's text was counted as two
     * distinct markers and shown to the teacher twice - and that single duplicate was
     * enough on its own to move S1 from the one-marker band (3 points) to the two-marker
     * band (8). The list must stay free of duplicates for the count to mean anything.
     *
     * @return void
     */
    public function test_ai_markers_has_no_duplicates(): void {
        $text = 'The landscape orange window garden bicycle lantern harbour meadow cabinet trumpet blanket.';

        $s1 = analyser::score_section($text)['signals']['s1_ai_markers'];

        $this->assertSame(1, $s1['marker_count']);
        $this->assertSame(['landscape'], $s1['matches']);
        $this->assertSame(3, $s1['points']);
        // The general guarantee, so any future duplicate fails here rather than as a
        // mysterious score change on one word.
        $this->assertSame(
            count(analyser::AI_MARKERS),
            count(array_unique(analyser::AI_MARKERS)),
            'AI_MARKERS contains a duplicate entry: '
                . implode(', ', array_diff_assoc(analyser::AI_MARKERS, array_unique(analyser::AI_MARKERS)))
        );
    }

    /**
     * S4 stays silent on prose with no academic connectors in it at all.
     *
     * @return void
     */
    public function test_s4_stays_silent_without_transitions(): void {
        $s4 = analyser::score_section(self::neutral_text(5, 10))['signals']['s4_transitions'];

        $this->assertSame(0, $s4['count']);
        $this->assertSame(0.0, $s4['per_100']);
        $this->assertSame([], $s4['hits']);
        $this->assertSame(0, $s4['points']);
    }

    /**
     * Regression test for FIX-DG-TRANSITION-DUPLICATE (v1.0.84).
     *
     * "meanwhile" appeared twice in TRANSITION_WORDS. signal_transitions() sums
     * substr_count() per entry, so a single use of the word counted as two connectors
     * and was listed twice in the hits shown to the teacher. In this 51-word section
     * that doubled the rate from 1.96 to 3.92 per hundred words, crossing the 3.0
     * threshold and awarding five points one connector should not earn.
     *
     * @return void
     */
    public function test_transition_words_has_no_duplicates(): void {
        $text = 'Meanwhile ' . self::neutral_text(5, 10);

        $s4 = analyser::score_section($text)['signals']['s4_transitions'];

        $this->assertSame(1, $s4['count']);
        $this->assertSame(1.96, $s4['per_100']);
        $this->assertSame(['meanwhile ×1'], $s4['hits']);
        $this->assertSame(0, $s4['points']);
        $this->assertSame(
            count(analyser::TRANSITION_WORDS),
            count(array_unique(analyser::TRANSITION_WORDS)),
            'TRANSITION_WORDS contains a duplicate entry: '
                . implode(
                    ', ',
                    array_diff_assoc(
                        analyser::TRANSITION_WORDS,
                        array_unique(analyser::TRANSITION_WORDS)
                        )
                )
        );
    }

    /**
     * Template openers and closers, and the S7 points their presence earns.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: int}>
     */
    public static function template_pattern_provider(): array {
        return [
            'neither opener nor closer' => ['', '', false, false, 0],
            'opener only' => ['This essay will discuss the topic. ', '', true, false, 5],
            'closer only' => ['', ' In conclusion the matter is settled here today.', false, true, 5],
            'both opener and closer' => [
                'This essay will discuss the topic. ',
                ' In conclusion the matter is settled here today.',
                true,
                true,
                10,
            ],
        ];
    }

    /**
     * S7 awards five points for a stock opener or a stock closer and ten for both.
     *
     * @dataProvider template_pattern_provider
     * @param string $prefix Text placed before the neutral body.
     * @param string $suffix Text placed after the neutral body.
     * @param bool $expectedopener Whether an opener is expected to be found.
     * @param bool $expectedcloser Whether a closer is expected to be found.
     * @param int $expectedpoints The S7 points observed from the real method.
     * @return void
     */
    public function test_s7_template_pattern(
        string $prefix,
        string $suffix,
        bool $expectedopener,
        bool $expectedcloser,
        int $expectedpoints
    ): void {
        $s7 = analyser::score_section($prefix . self::neutral_text(3, 10) . $suffix)['signals']['s7_template'];

        $this->assertSame($expectedopener, $s7['opener_found']);
        $this->assertSame($expectedcloser, $s7['closer_found']);
        $this->assertSame($expectedpoints, $s7['points']);
    }

    /**
     * S9 gives six points when every sentence opens with one of the stock openers,
     * and nothing when none of them does.
     *
     * @return void
     */
    public function test_s9_sentence_start_uniformity(): void {
        $uniform = 'The orange window garden bicycle lantern harbour meadow cabinet. '
            . 'The trumpet blanket compass pebble thicket saddle quarry kettle. '
            . 'This ribbon furnace lagoon anchor pasture marble cinder plateau. '
            . 'It satchel burrow chimney gravel hollow trellis wagon crate. '
            . 'In copper bramble tunnel ledger basket stirrup hedge canvas. '
            . 'There orange window garden bicycle lantern harbour meadow cabinet.';

        $s9 = analyser::score_section($uniform)['signals']['s9_sentence_starts'];

        $this->assertSame(6, $s9['total']);
        $this->assertSame(6, $s9['uniform_count']);
        $this->assertSame(1.0, $s9['uniform_ratio']);
        $this->assertSame(6, $s9['points']);
    }

    /**
     * The same six sentences with content-word openings score nothing on S9, proving
     * the signal responds to the opener and not to the sentence count.
     *
     * @return void
     */
    public function test_s9_stays_silent_on_varied_sentence_starts(): void {
        $varied = 'Orange window garden bicycle lantern harbour meadow cabinet trumpet. '
            . 'Blanket compass pebble thicket saddle quarry kettle ribbon furnace. '
            . 'Lagoon anchor pasture marble cinder plateau satchel burrow chimney. '
            . 'Gravel hollow trellis wagon crate copper bramble tunnel ledger. '
            . 'Basket stirrup hedge canvas orange window garden bicycle lantern. '
            . 'Harbour meadow cabinet trumpet blanket compass pebble thicket saddle.';

        $s9 = analyser::score_section($varied)['signals']['s9_sentence_starts'];

        $this->assertSame(6, $s9['total']);
        $this->assertSame(0, $s9['uniform_count']);
        $this->assertSame(0.0, $s9['uniform_ratio']);
        $this->assertSame(0, $s9['points']);
    }

    /**
     * S3 needs two paragraphs of twenty or more words. Two paragraphs of entirely
     * distinct vocabulary both score a type-token ratio of 1.0, so the deviation
     * between them is zero and the signal awards its full eight points.
     *
     * @return void
     */
    public function test_s3_ttr_uniformity_across_paragraphs(): void {
        $para1 = 'orange window garden bicycle lantern harbour meadow cabinet trumpet blanket compass pebble '
            . 'thicket saddle quarry kettle ribbon furnace lagoon anchor pasture marble cinder plateau';
        $para2 = 'satchel burrow chimney gravel hollow trellis wagon crate copper bramble tunnel ledger '
            . 'basket stirrup hedge canvas mantle rafter cobble jetty willow bracken cistern gable';

        $s3 = analyser::score_section($para1 . "\n\n" . $para2)['signals']['s3_ttr_uniformity'];

        $this->assertSame(2, $s3['para_count']);
        $this->assertSame([1.0, 1.0], $s3['ttr_values']);
        $this->assertSame(0.0, $s3['ttr_std_dev']);
        $this->assertSame(8, $s3['points']);
    }

    /**
     * A single paragraph cannot be compared with anything, so S3 reports a null
     * deviation and no points instead of scoring it.
     *
     * @return void
     */
    public function test_s3_needs_two_paragraphs(): void {
        $para = 'orange window garden bicycle lantern harbour meadow cabinet trumpet blanket compass pebble '
            . 'thicket saddle quarry kettle ribbon furnace lagoon anchor pasture marble cinder plateau';

        $s3 = analyser::score_section($para)['signals']['s3_ttr_uniformity'];

        $this->assertSame(1, $s3['para_count']);
        $this->assertNull($s3['ttr_std_dev']);
        $this->assertSame(0, $s3['points']);
    }

    /**
     * The overall risk score is the sum of the individual signal points, banded by
     * band(). This pins the aggregation itself, not any one signal.
     *
     * @return void
     */
    public function test_score_section_total_is_the_sum_of_its_signals(): void {
        $result = analyser::score_section(self::neutral_text(32, 10));

        $sum = 0;
        foreach ($result['signals'] as $signal) {
            $sum += $signal['points'];
        }

        $this->assertSame(28, $sum);
        $this->assertSame(28, $result['riskscore']);
        $this->assertSame('low', $result['risklevel']);
        $this->assertSame(analyser::band(28), $result['risklevel']);
    }
}
