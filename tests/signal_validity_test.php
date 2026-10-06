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

/**
 * Validity tests for the detection signals.
 *
 * V1.0.93. These differ from the rest of the suite: they state what each signal is
 * REQUIRED to do, rather than recording what it currently does. Every one of them
 * corresponds to a defect found by measuring the signals against texts of known origin,
 * and each would have failed before this release.
 *
 * The scores these signals produce are shown to teachers deciding whether to question a
 * student about academic misconduct, so a signal that measures something other than what
 * its label claims is a defect of the most serious kind, not a tuning issue.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\analyser
 */
final class signal_validity_test extends \advanced_testcase {














    /**
     * The S12 threshold is a constant, not a literal repeated across files.
     *
     * report.php carried its own 0.30 while the scoring function used 0.35. Reading the
     * constant from the source is the only way a test can catch that drift returning.
     *
     * @return void
     */
    public function test_report_uses_the_shared_similarity_constant(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/plagiarism/docguard/report.php');

        $this->assertStringContainsString(
            'S12_REPORT_THRESHOLD',
            $source,
            'report.php must use the shared constant rather than its own similarity literal.'
        );
        /*
         * Only the collection threshold is guarded. report.php also compares $sim against
         * 0.50 and 0.70 to colour the concern column, which are presentation bands rather
         * than the decision about whether a pair is shown at all, so the pattern is
         * limited to the range a collection threshold would occupy.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/\$sim\s*>=\s*0\.[234]/',
            $source,
            'report.php must take the similarity cut-off from S12_REPORT_THRESHOLD, not a literal.'
        );
    }

    /**
     * One threshold governs both scoring and reporting of cross-submission similarity.
     *
     * The class report listed pairs from 0.30 under a heading about academic misconduct
     * while the scoring function awarded nothing below 0.35, so a pair at 0.31 was put in
     * front of a teacher as a concern the engine scored at zero.
     *
     * V1.0.99: compute_s12_score() is gone - it was dead code, never called anywhere in
     * the plugin, which is how copy evidence came to contribute nothing to the score it
     * should have been. submission_score() replaces it and is wired into analysis.
     *
     * @return void
     */
    public function test_similarity_reporting_and_scoring_share_one_threshold(): void {
        $threshold = analyser::S12_REPORT_THRESHOLD;

        $below = analyser::submission_score($threshold - 0.01);
        $at    = analyser::submission_score($threshold);

        $this->assertSame('low', $below['risklevel'],
            'Below the threshold a pair is not reported, so it must not be banded above low.');
        $this->assertSame('medium', $at['risklevel'],
            'At the threshold the pair is both scored and reported.');
        $this->assertSame(
            (float)analyser::BAND_MEDIUM,
            (float)$at['riskscore'],
            'The reporting threshold and the MEDIUM band boundary must be the same number.'
        );
    }

    /**
     * Filler prose with no marker vocabulary in it, long enough to clear the ramp.
     *
     * @param int $words Approximate number of words wanted.
     * @return string
     */
    private function plain_filler(int $words): string {
        $line = 'The student described the workplace task and gave an example from the site. ';
        return trim(str_repeat($line, (int)ceil($words / 13)));
    }

    /**
     * A reference list must not be scored as the student's writing.
     *
     * This is the measured false positive that prompted the change: eight ordinary VET
     * titles scored 11 of 22 on S1, because academic titles are written in precisely the
     * register the marker list describes. The student wrote none of those words.
     */
    public function test_reference_list_is_not_scored_as_student_prose(): void {
        $refs = "References\n\n"
            . "Boud, D. (2015). Navigating the complexities of feedback in higher education. "
            . "Journal of Assessment, 40(3), 12-30.\n"
            . "Clark, R. (2018). A holistic approach to delving into workplace learning. "
            . "Vocational Review, 12(1), 55-71.\n"
            . "Dunne, M. (2019). Best practices in fostering a robust compliance landscape. "
            . "RTO Quarterly, 8(2), 3-19.\n"
            . "Ellis, P. (2020). Key takeaways from the evolving assessment paradigm. "
            . "Training Journal, 15(4), 44-60.\n"
            . "Finch, S. (2021). Unlocking the potential of underpinning knowledge. "
            . "Skills Review, 3(2), 88-99.\n";

        $result = analyser::score_section($refs);

        $this->assertSame(0, (int)$result['riskscore'],
            'A bibliography carries no student prose and must score zero.');
        $this->assertSame('mostly_not_own_prose', $result['note'] ?? '',
            'The teacher must be told why nothing was measured, not shown a LOW result.');
        $this->assertGreaterThan(0, (int)$result['excluded_words'],
            'The excluded word count is what makes the exclusion auditable.');
    }

    /**
     * A student who quotes a source accurately must not be charged for the source's style.
     */
    public function test_quoted_material_is_excluded_from_scoring(): void {
        $quote = '"It is important to note that reflection is not merely a cognitive act; '
            . 'it is a deeply embedded practice that serves to underscore the complexities '
            . 'inherent in professional learning, and it is worth noting that practitioners '
            . 'must delve into their own assumptions in order to foster genuine insight."';

        $own  = 'I agree with this. On my placement I wrote notes after each shift and '
            . 'changed how I handed over to the next nurse. ';
        $own .= $this->plain_filler(140);

        $withquote = $own . ' ' . $quote;

        $plain  = analyser::score_section($own);
        $quoted = analyser::score_section($withquote);

        $this->assertGreaterThan(0, (int)$quoted['excluded_words'],
            'The quotation must be recognised and set aside.');
        $this->assertSame(
            (int)($plain['signals']['s1_ai_markers']['points'] ?? 0),
            (int)($quoted['signals']['s1_ai_markers']['points'] ?? 0),
            'Adding a block quotation must not change what the student scores on S1.'
        );
    }




    /**
     * Band thresholds live in one place.
     *
     * The S12 threshold was duplicated between the report and the engine and they drifted,
     * so the report named pairs to a teacher that the engine scored at zero. The band
     * boundaries are the same kind of number and get the same treatment.
     */
    public function test_band_thresholds_come_from_the_shared_constants(): void {
        $this->assertSame('low', analyser::band(analyser::BAND_MEDIUM - 1));
        $this->assertSame('medium', analyser::band(analyser::BAND_MEDIUM));
        $this->assertSame('medium', analyser::band(analyser::BAND_HIGH - 1));
        $this->assertSame('high', analyser::band(analyser::BAND_HIGH));

        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\$score\s*>=\s*\d+/', $src,
            'band() must compare against the named constants, not a repeated literal.'
        );
    }






    /**
     * Quotation marks must be paired by position, not by proximity.
     *
     * The first implementation used /"(?:[^"]{15,})"/u, and a regex engine scanning left
     * to right pairs a closing mark with the next opening one. On an answer using three
     * short scare-quotes it kept "restructure", "right-sized" and "opportunity" and
     * stripped the student's narration between them — 20 words of a 74-word answer, and
     * precisely the reverse of the intent.
     */
    public function test_short_quotations_do_not_strip_the_surrounding_prose(): void {
        $scare = 'The manager called it a "restructure" but everyone knew what that meant. '
            . 'She said the team was being "right-sized" and that we should see it as an '
            . '"opportunity". I did not see it that way and nor did anyone else on my '
            . 'shift. We had been told the same thing the year before and nothing good '
            . 'came of it then either.';

        $result = analyser::score_section($scare);

        $this->assertSame(0, (int)$result['excluded_words'],
            'Three short quoted terms must cost the student nothing.');
        $this->assertStringContainsString('restructure', $scare,
            'Sanity: the fixture must actually contain the quoted terms.');
    }

    /**
     * Dialogue is the student's own writing.
     */
    public function test_dialogue_is_not_stripped_as_quotation(): void {
        $dialogue = '"You need to check the plan first," she said, turning back to the '
            . 'trolley. "I did check it," I told her, though I knew I had only skimmed the '
            . 'top sheet. "Then you would know she cannot bear weight on the left leg." '
            . 'I said nothing to that. There was nothing I could say that would have '
            . 'helped. "Go and read it properly and come back and tell me what you will do."';

        $this->assertSame(0, (int)analyser::score_section($dialogue)['excluded_words'],
            'Dialogue in a narrative answer is the student writing, not a source.');
    }

    /**
     * One stray quotation mark must not swallow the rest of the answer.
     */
    public function test_unbalanced_quotation_mark_strips_nothing(): void {
        $stray = 'The policy states that "all incidents must be reported within 24 hours '
            . 'and the supervisor notified immediately. I did report it on the same day '
            . 'and I told the supervisor before I went home. The form was filed in the '
            . 'incident folder which is kept in the office near the medication room.';

        $this->assertSame(0, (int)analyser::score_section($stray)['excluded_words'],
            'An unclosed quote from OCR or a typo must not cost a student their answer.');
    }

    /**
     * A long block quotation is still removed — the point of the exclusion.
     */
    public function test_long_block_quotation_is_still_stripped(): void {
        $quote = '"It is important to note that reflection is not merely a cognitive act; '
            . 'it is a deeply embedded practice that serves to underscore the complexities '
            . 'inherent in professional learning, and practitioners must delve into their '
            . 'own assumptions in order to foster genuine insight into their practice."';

        $own = 'I agree with this. On my placement I wrote notes after each shift. '
            . $this->plain_filler(140);

        $this->assertGreaterThan(
            0,
            (int)analyser::score_section($own . ' ' . $quote)['excluded_words'],
            'A block quotation long enough to carry its author style must be removed.'
        );
    }

    /**
     * score_section() must return the same keys whichever branch it takes.
     */
    public function test_result_shape_is_the_same_on_every_path(): void {
        $tiny   = analyser::score_section('Three words only.');
        $normal = analyser::score_section($this->plain_filler(300));

        $this->assertSame([], array_diff(array_keys($normal), array_keys($tiny)),
            'Every key present on the normal path must also be present on the short path.');
        $this->assertSame('insufficient_text', $tiny['note'],
            'A section too short to describe says so, rather than reporting a clean result.');
    }

    /**
     * The style signals are gone, and must not come back by accident.
     *
     * Measured against 48 answers to real VET prompts written by three current language
     * models - Claude, ChatGPT and Gemini, answering naturally and again rewritten to
     * sound like a struggling student - the signals flagged 0 of 48, while the
     * highest-scoring document in the test set was a human second-language student essay.
     * A signal set that cannot separate its two classes must not be computed, stored or
     * displayed beside a copying verdict, where it reads as corroborating it.
     */
    public function test_no_style_signals_are_computed(): void {
        $text = 'It is important to note that this essay will delve into the complexities '
            . 'of the multifaceted landscape. Furthermore, it is worth noting that a '
            . 'holistic approach serves to underscore the pivotal role of stakeholders. '
            . 'In conclusion, these actionable insights shed light on best practices. '
            . str_repeat('The student described the workplace task in detail. ', 20);

        $result = analyser::score_section($text);

        $this->assertSame([], $result['signals'],
            'Marker-dense prose must still produce no signals.');
        $this->assertSame(0, $result['riskscore'],
            'Nothing about how a document is written contributes to its score.');

        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');
        foreach (['signal_ai_markers', 'signal_contraction_absence', 'signal_passive_voice',
                  'signal_sentence_uniformity', 'signal_ttr_uniformity', 'signal_transitions',
                  'signal_template_pattern', 'signal_trigram_repetition',
                  'signal_sentence_starts', 'signal_vocab_richness'] as $fn) {
            $this->assertStringNotContainsString('function ' . $fn, $src,
                $fn . '() was removed in 1.0.99 and must not be reintroduced.');
        }
        $this->assertStringNotContainsString('const AI_MARKERS', $src,
            'The marker list was removed; it had a useful life of 12-18 months and expired.');
    }

    /**
     * The score is a similarity percentage, and the bands sit where the measurements put them.
     */
    public function test_submission_score_is_the_similarity_percentage(): void {
        $this->assertSame(0.0, analyser::submission_score(0.0)['riskscore']);
        $this->assertSame(13.3, analyser::submission_score(0.133)['riskscore']);
        $this->assertSame(91.8, analyser::submission_score(0.918)['riskscore']);

        // Two students answering the same closed question independently measured 13%.
        $this->assertSame('low', analyser::submission_score(0.133)['risklevel'],
            'Independent answers to the same question must not be flagged.');
        // A genuine copy with light paraphrase measured 92%.
        $this->assertSame('high', analyser::submission_score(0.918)['risklevel'],
            'A genuine copy must reach HIGH.');
        // The band boundary and the pair-listing threshold are the same number.
        $this->assertSame(
            'medium',
            analyser::submission_score(analyser::S12_REPORT_THRESHOLD)['risklevel'],
            'A pair at the reporting threshold must be scored, not listed at zero.'
        );
        $this->assertSame(1.0, analyser::submission_score(5.0)['max_similarity'],
            'Similarity is clamped: a score above 100% is not meaningful.');
    }

    /**
     * A shared quotation must not read as copying.
     *
     * This is the stripping machinery's real job now. Two students who quote the same
     * legislation or the same textbook passage share wording that neither of them wrote.
     * Measured on two unrelated answers carrying one long shared quotation: 47.5%
     * similarity with the quotation left in, which is above the reporting threshold and
     * would have been put to a teacher as a copy match, against 0.0% with it removed.
     */
    public function test_a_shared_quotation_is_not_reported_as_copying(): void {
        $quote = ' As Boud (2015) states, "reflection is not merely a cognitive act but a '
            . 'deeply embedded practice that serves to underscore the complexities '
            . 'inherent in professional learning, and practitioners must examine their own '
            . 'assumptions in order to develop genuine insight into their own practice."';

        $a = 'I checked the care plan before the transfer and asked the client how she '
            . 'preferred to be moved. We used the slide sheet because the plan said so.' . $quote;
        $b = 'On my shift I read the notes first and then spoke to the resident about how '
            . 'he wanted to get up. I used the hoist as the plan required.' . $quote;

        $strip = new \ReflectionMethod(analyser::class, 'prepare_for_scoring');
        $strip->setAccessible(true);

        /*
         * V1.1.1: this measured the parser's old similarity helper, which wrapped PHP's
         * similar_text - a character-level measure copy detection has never used.
         * The assertions passed, against the wrong number. Now measured exactly the way
         * cross_student_similarity() measures: bigram sets, Jaccard.
         */
        $prod = fn($x, $y) => question_parser::jaccard_sets(
            question_parser::bigram_set(question_parser::normalise_for_similarity($x)),
            question_parser::bigram_set(question_parser::normalise_for_similarity($y))
        );
        $rawsim   = $prod($a, $b);
        $cleansim = $prod($strip->invoke(null, $a)['text'], $strip->invoke(null, $b)['text']);

        $this->assertGreaterThan(
            analyser::S12_REPORT_THRESHOLD,
            $rawsim,
            'Fixture must be a false match before stripping, or the test proves nothing.'
        );
        $this->assertLessThan(
            analyser::S12_REPORT_THRESHOLD,
            $cleansim,
            'Removing the shared quotation must clear the false copy match.'
        );
    }

    /**
     * Stored scores carry a stamp saying which model produced them.
     */
    public function test_stored_scores_are_stamped_with_the_score_model(): void {
        $this->assertSame(2, analyser::SCORE_MODEL);

        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');
        $this->assertStringContainsString("'score_model'      => self::SCORE_MODEL", $src,
            'Every analysed row must record what its score means.');

        $report = file_get_contents(__DIR__ . '/../student_report.php');
        $this->assertStringContainsString('legacyscore', $report,
            'Rows from before the change must be labelled, not reinterpreted.');
        $this->assertStringContainsString('SCORE_MODEL', $report,
            'The report must compare against the constant, not a transcribed number.');
    }

    /**
     * Copy evidence: this submission takes the highest similarity found against it.
     *
     * The highest match is deliberately NOT last in the list. The first version of this
     * test had it last, which meant the assertion also passed when the code took the last
     * match instead of the largest - a test that cannot fail for the reason it names.
     * Mutation testing found it.
     */
    public function test_copy_evidence_takes_the_highest_match(): void {
        $writes = analyser::plan_copy_evidence(10, [
            ['subid' => 30, 'similarity' => 0.81],
            ['subid' => 20, 'similarity' => 0.42],
        ], [20 => 0.0, 30 => 0.0]);

        $own = array_values(array_filter($writes, fn($w) => $w['subid'] === 10));
        $this->assertCount(1, $own, 'The compared submission is always written.');
        $this->assertSame(81.0, $own[0]['riskscore'], 'It takes the highest match, not the first.');
        $this->assertSame('high', $own[0]['risklevel']);
    }

    /**
     * A submission matching nobody scores zero, which is the honest value.
     */
    public function test_no_match_scores_zero(): void {
        $writes = analyser::plan_copy_evidence(10, [], []);

        $this->assertCount(1, $writes);
        $this->assertSame(0.0, $writes[0]['riskscore']);
        $this->assertSame('low', $writes[0]['risklevel']);
    }

    /**
     * Both sides of a flagged pair are recorded.
     *
     * Copying is symmetric, and the earlier submission may have been analysed before the
     * later one existed. Without this, whoever submits first keeps a clean badge however
     * much of their work turns up in somebody else's.
     */
    public function test_both_sides_of_a_pair_are_written(): void {
        $writes = analyser::plan_copy_evidence(10, [
            ['subid' => 20, 'similarity' => 0.80],
        ], [20 => 0.0]);

        $ids = array_column($writes, 'subid');
        $this->assertContains(10, $ids, 'The new submission must be scored.');
        $this->assertContains(20, $ids, 'The submission it matches must be scored too.');

        $other = array_values(array_filter($writes, fn($w) => $w['subid'] === 20));
        $this->assertSame(80.0, $other[0]['riskscore']);
    }

    /**
     * A matched submission is raised only, never lowered.
     *
     * Otherwise a student could clear an existing 90% match by submitting again and
     * matching somebody else at 40%. That is both a correctness bug and an invitation.
     */
    public function test_an_existing_higher_match_is_never_lowered(): void {
        $writes = analyser::plan_copy_evidence(10, [
            ['subid' => 20, 'similarity' => 0.40],
        ], [20 => 90.0]);

        $this->assertSame([10], array_column($writes, 'subid'),
            'A lower later match must not overwrite a higher existing one.');
    }

    /**
     * Equal is not higher: an unchanged score produces no write.
     */
    public function test_an_equal_match_produces_no_write(): void {
        $writes = analyser::plan_copy_evidence(10, [
            ['subid' => 20, 'similarity' => 0.55],
        ], [20 => 55.0]);

        $this->assertSame([10], array_column($writes, 'subid'));
    }

    /**
     * Degenerate input must not produce a write that corrupts a row.
     */
    public function test_copy_evidence_ignores_malformed_matches(): void {
        $writes = analyser::plan_copy_evidence(10, [
            ['subid' => 10, 'similarity' => 0.99],   // itself
            ['subid' => 0,  'similarity' => 0.99],   // no id
            ['subid' => 20, 'similarity' => 0.60],
            ['subid' => 20, 'similarity' => 0.75],   // duplicate, higher
        ], []);

        $ids = array_column($writes, 'subid');
        $this->assertSame([10, 20], $ids, 'One write per real submission, self excluded.');

        $other = array_values(array_filter($writes, fn($w) => $w['subid'] === 20));
        $this->assertSame(75.0, $other[0]['riskscore'],
            'A duplicated match takes the higher similarity.');
        $own = array_values(array_filter($writes, fn($w) => $w['subid'] === 10));
        $this->assertSame(99.0, $own[0]['riskscore'],
            'The self-match is excluded from writes but still counts toward its own score.');
    }
}
