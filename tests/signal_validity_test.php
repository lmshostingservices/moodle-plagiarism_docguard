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
     *
     * The stamp must rise whenever the meaning of the number changes, so that rows already
     * in the database are labelled rather than silently reinterpreted. It went to 3 in
     * 1.1.5, when the activity's shared template text stopped counting towards similarity:
     * a model 2 score on a templated activity is mostly the template, and comparing it with
     * a model 3 score would be comparing two different measurements.
     */
    public function test_stored_scores_are_stamped_with_the_score_model(): void {
        $this->assertSame(3, analyser::SCORE_MODEL);

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

    /**
     * The user field list must cover every name field fullname() requires.
     *
     * V1.1.4. cross_student_similarity() selected only id, username, firstname and
     * lastname, then called fullname() on the result. fullname() wants every name field
     * Moodle defines and emits a developer warning without them, so on a site with
     * developer debugging on, every flagged pair produced a warning.
     *
     * Asserted against \core_user\fields rather than a transcribed list, because a
     * transcribed list is exactly what drifted: report.php and student_report.php each
     * carried their own hardcoded copy of eight names and the analyser's copy had four.
     */
    public function test_user_field_list_covers_every_name_field(): void {
        $fields = analyser::user_fields_for_fullname();
        $listed = array_map('trim', explode(',', $fields));

        foreach (\core_user\fields::get_name_fields() as $required) {
            $this->assertContains($required, $listed,
                "fullname() requires {$required}, which this field list does not select");
        }

        // id is needed to key the records; username is read by the caller.
        $this->assertContains('id', $listed, 'records are keyed by id');
        $this->assertContains('username', $listed, 'the caller reads ->username');
    }

    /**
     * The helper must not be bypassed by a hardcoded list reappearing.
     */
    public function test_no_call_site_hardcodes_the_name_fields(): void {
        foreach (['report.php', 'student_report.php', 'classes/analyser.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../' . $file);
            // Strip comments so the explanatory notes do not count as code. This check has
            // matched a comment rather than code four times in this release series.
            $code = implode("\n", array_filter(
                explode("\n", $src),
                fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
            ));
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"]id\s*,\s*(username\s*,\s*)?firstname\s*,\s*lastname/i',
                $code,
                $file . ' must take its user field list from user_fields_for_fullname()'
            );
        }
    }

    /* ── V1.1.5: the activity's own template is not evidence about a student ──── */

    /**
     * An ordinary RTO assessment cover sheet and student declaration.
     *
     * Every student in the activity submits this text because the assessment tool told them
     * to. It is 185 words - shorter than many real ones, which also carry mapping tables,
     * version footers and a unit-of-competency extract.
     */
    private function cover_sheet(string $unit, string $question): string {
        return 'ASSESSMENT COVER SHEET. Registered Training Organisation code 41234. '
            . 'Unit of competency ' . $unit . '. Assessment task 1 of 3, written questions. '
            . 'Instructions to the student. Answer all questions in your own words in the '
            . 'spaces provided. You must complete every question to be assessed as '
            . 'satisfactory. If you need more space, attach additional pages and label them '
            . 'clearly. Your assessor will provide feedback within ten working days. If you '
            . 'are assessed as not yet satisfactory you are entitled to two further attempts. '
            . 'Reasonable adjustment is available on request, please speak to your trainer '
            . 'before you begin. Student declaration. I declare that this assessment is my own '
            . 'work, that I have not copied from any other student, and that I have not '
            . 'allowed any other student to copy my work. I understand that plagiarism and '
            . 'cheating are serious breaches of this organisation\'s academic misconduct '
            . 'policy and may result in my enrolment being cancelled. I confirm that I have '
            . 'retained a copy of this assessment for my own records. ' . $question . ' My answer. ';
    }

    /**
     * The scoring pipeline exactly as cross_student_similarity() runs it, minus the database.
     *
     * @param array $docs Map of name => raw submitted text.
     * @return array Map of name => bigram set with the activity's template subtracted.
     */
    private function compare_cohort(array $docs): array {
        $strip = new \ReflectionMethod(analyser::class, 'prepare_for_scoring');
        $strip->setAccessible(true);

        $sets = [];
        foreach ($docs as $name => $text) {
            $sets[$name] = question_parser::bigram_set(
                question_parser::normalise_for_similarity($strip->invoke(null, $text)['text'])
            );
        }
        $docfreq = [];
        foreach ($sets as $set) {
            foreach ($set as $bigram => $ignored) {
                $docfreq[$bigram] = ($docfreq[$bigram] ?? 0) + 1;
            }
        }
        $template = analyser::template_bigrams($docfreq, count($sets));

        $clean = [];
        foreach ($sets as $name => $set) {
            $clean[$name] = array_diff_key($set, $template);
        }
        return $clean;
    }

    /**
     * Two students who submit the same assessment template are not copying each other.
     *
     * The defect this guards: before 1.1.5 the comparison ran over the whole extracted
     * document, template included. On a 10-student cohort sharing one cover sheet, all 44
     * innocent pairs cleared the reporting threshold. This test asserts the shipped
     * behaviour on the pair that was worst affected - two students who answered DIFFERENT
     * UNITS and still measured 48.0% on the strength of the cover sheet alone.
     */
    public function test_shared_template_text_is_not_reported_as_copying(): void {
        $q = 'Question 4. Explain the temperature danger zone and describe how you monitor it.';
        $sheet = $this->cover_sheet('SITXFSA005 Use hygienic practices for food safety', $q);

        $foodsafety = 'I always check the temperature of the cool room at the start of my shift '
            . 'and write it on the chart near the door. The danger zone is between 5 and 60 '
            . 'degrees because that is where bacteria grow fastest. If food sits in that range '
            . 'for more than four hours we have to throw it out. I check with the probe '
            . 'thermometer and I wipe it with an alcohol swab before and after.';
        $personalcare = 'When I am supporting a client with a shower I knock first and wait to be '
            . 'invited in, even in their own room, because that is their private space. I ask how '
            . 'they would like to be helped rather than assuming. If a client says no I stop, and '
            . 'I record that they declined in the progress notes and tell the registered nurse.';
        $different = 'At the beginning of every shift my job is to record the fridge and cool room '
            . 'temperatures in the log book. Under the two hour four hour rule, anything left in '
            . 'the danger zone beyond four hours must be discarded. I sanitise the probe between '
            . 'different foods to avoid cross contamination and report any fault to my supervisor.';

        // Raw, template included: the defect. Establish it, or the test proves nothing.
        $raw = fn($a, $b) => question_parser::jaccard_sets(
            question_parser::bigram_set(question_parser::normalise_for_similarity($a)),
            question_parser::bigram_set(question_parser::normalise_for_similarity($b))
        );
        $this->assertGreaterThan(
            analyser::S12_REPORT_THRESHOLD,
            $raw($sheet . $foodsafety, $sheet . $personalcare),
            'Fixture must reproduce the defect: the shared cover sheet alone must push two '
            . 'answers to different units above the reporting threshold.'
        );

        $clean = $this->compare_cohort([
            'a' => $sheet . $foodsafety,
            'b' => $sheet . $personalcare,
            'c' => $sheet . $different,
        ]);
        foreach ([['a', 'b'], ['a', 'c'], ['b', 'c']] as [$x, $y]) {
            $this->assertLessThan(
                analyser::S12_REPORT_THRESHOLD,
                question_parser::jaccard_sets($clean[$x], $clean[$y]),
                "Independent answers $x/$y must not be reported once the template is removed."
            );
        }
    }

    /**
     * Removing the template must not remove the copying.
     */
    public function test_a_real_copy_still_scores_high_through_the_template(): void {
        $q = 'Question 4. Explain the temperature danger zone and describe how you monitor it.';
        $sheet = $this->cover_sheet('SITXFSA005 Use hygienic practices for food safety', $q);

        $dana = 'I always check the temperature of the cool room at the start of my shift and '
            . 'write it on the chart near the door. The danger zone is between 5 and 60 degrees '
            . 'because that is where bacteria grow fastest. If food sits in that range for more '
            . 'than four hours we have to throw it out. Last month the cool room read 8 degrees '
            . 'so I told the chef straight away and we moved the dairy into the other fridge.';
        // Luke's copy of Dana, with the handful of word swaps a copier actually makes.
        $luke = str_replace(
            ['start of my shift', 'write it', 'grow fastest', 'throw it out', 'told the chef', 'moved the dairy'],
            ['beginning of my shift', 'record it', 'grow quickest', 'discard it', 'informed the chef', 'shifted the dairy'],
            $dana
        );
        $priya = 'At the beginning of every shift my job is to record the fridge and cool room '
            . 'temperatures in the log book. Under the two hour four hour rule, anything left in '
            . 'the danger zone beyond four hours must be discarded. I sanitise the probe between '
            . 'different foods to avoid cross contamination and report any fault to my supervisor.';

        $clean = $this->compare_cohort([
            'dana' => $sheet . $dana, 'luke' => $sheet . $luke, 'priya' => $sheet . $priya,
        ]);
        $this->assertGreaterThan(
            analyser::BAND_HIGH / 100,
            question_parser::jaccard_sets($clean['dana'], $clean['luke']),
            'A copy with light word swaps must still reach HIGH with the template removed.'
        );
        $this->assertLessThan(
            analyser::S12_REPORT_THRESHOLD,
            question_parser::jaccard_sets($clean['dana'], $clean['priya']),
            'The student who wrote her own answer must not be dragged in with them.'
        );
    }

    /**
     * The template threshold is a fraction of the cohort, never a fixed count.
     *
     * A fixed count of three subtracts the shared text of any three students who copied one
     * another, which is the evidence. Measured: fixed-3 found 1 of 3 ring pairs at n=30 and
     * 0 of 15 for a ring of six. This asserts the scaling rule, and
     * test_a_copy_ring_is_not_hidden_by_the_template_rule asserts what it buys.
     */
    public function test_template_threshold_scales_with_the_cohort(): void {
        // Never below the floor, however small the activity.
        foreach ([1, 2, 3, 4, 6, 9] as $n) {
            $this->assertSame(3, analyser::template_threshold($n),
                "A cohort of $n cannot support a threshold above the floor.");
        }
        $this->assertSame(4, analyser::template_threshold(12));
        $this->assertSame(10, analyser::template_threshold(30));
        $this->assertSame(14, analyser::template_threshold(40));
        $this->assertSame(34, analyser::template_threshold(100));

        foreach ([12, 30, 100, 400] as $n) {
            $this->assertGreaterThan(
                3,
                analyser::template_threshold($n),
                "A fixed threshold of 3 would subtract a three-student copy ring at n=$n."
            );
        }
    }

    /**
     * Three students copying one another must not erase their own evidence.
     *
     * This is the attack that killed the first version of the rule. With a fixed threshold
     * of three, the ring's shared text appears in three submissions, so it is classified as
     * template and subtracted - the rule hides exactly what it exists to find.
     */
    public function test_a_copy_ring_is_not_hidden_by_the_template_rule(): void {
        $q = 'Question 4. Explain the temperature danger zone and describe how you monitor it.';
        $sheet = $this->cover_sheet('SITXFSA005 Use hygienic practices for food safety', $q);

        $source = 'I always check the temperature of the cool room at the start of my shift and '
            . 'write it on the chart near the door. The danger zone is between 5 and 60 degrees '
            . 'because that is where bacteria grow fastest. If food sits in that range for more '
            . 'than four hours we have to throw it out. Last month the cool room read 8 degrees '
            . 'so I told the chef straight away and we moved the dairy into the other fridge.';
        $swaps = [
            ['start of my shift', 'beginning of my shift'], ['write it', 'record it'],
            ['grow fastest', 'grow quickest'], ['throw it out', 'discard it'],
            ['told the chef', 'informed the chef'], ['moved the dairy', 'shifted the dairy'],
        ];
        $docs = [];
        for ($i = 0; $i < 3; $i++) {
            $text = $source;
            foreach ($swaps as $j => [$from, $to]) {
                if (($i + $j) % 2 === 0) {
                    $text = str_replace($from, $to, $text);
                }
            }
            $docs["ring$i"] = $sheet . $text;
        }
        // Nine students who wrote their own answers, so the ring is 3 of a cohort of 12.
        $own = [
            'I record the fridge temperatures in the log book at the beginning of every shift.',
            'My supervisor showed me how to use the probe thermometer and sanitise it between foods.',
            'Food held in the danger zone for over four hours has to be thrown away under the rule.',
            'I knock before entering a client room and wait to be invited in before I help them.',
            'We use a slide sheet for transfers when the care plan says two workers are required.',
            'I wash my hands before handling ready to eat food and after touching raw chicken.',
            'The cool room alarm sounded on Tuesday so I moved the dairy and called maintenance.',
            'I check the use by dates on the delivery and reject anything that is out of date.',
            'When a client declines personal care I record the refusal in the progress notes.',
        ];
        foreach ($own as $i => $sentence) {
            $docs["own$i"] = $sheet . str_repeat($sentence . ' ', 4);
        }

        $clean = $this->compare_cohort($docs);
        for ($i = 0; $i < 3; $i++) {
            for ($j = $i + 1; $j < 3; $j++) {
                $this->assertGreaterThan(
                    analyser::S12_REPORT_THRESHOLD,
                    question_parser::jaccard_sets($clean["ring$i"], $clean["ring$j"]),
                    "Ring pair $i/$j must still be reported: a cohort-fraction threshold "
                    . 'exists precisely so that collusion does not classify itself as template.'
                );
            }
        }
        foreach (array_keys($docs) as $x) {
            foreach (array_keys($docs) as $y) {
                if ($x >= $y || (str_starts_with($x, 'ring') && str_starts_with($y, 'ring'))) {
                    continue;
                }
                $this->assertLessThan(
                    analyser::S12_REPORT_THRESHOLD,
                    question_parser::jaccard_sets($clean[$x], $clean[$y]),
                    "Innocent pair $x/$y must not be reported."
                );
            }
        }
    }

    /**
     * Below three submissions there is no way to tell a template from a copy, so no pair
     * is reported.
     *
     * Two independent answers carrying an ordinary cover sheet measure 50.5% - above the
     * threshold, MEDIUM, and put to a trainer as a match. The figure is wrong rather than
     * imprecise, and the activity corrects itself when the third student submits.
     */
    public function test_no_pair_is_reported_below_three_submissions(): void {
        $this->assertSame(3, analyser::COHORT_MIN_FOR_COMPARISON);

        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');
        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));
        $this->assertMatchesRegularExpression(
            '/\$cohortsize\s*<\s*self::COHORT_MIN_FOR_COMPARISON/',
            $code,
            'cross_student_similarity() must return nothing below the cohort floor. '
            . '(Comments are stripped here: this guard has matched a comment four times '
            . 'in this release series.)'
        );
    }

    /**
     * The template is derived from a bounded sample, and the sample does not change the answer.
     *
     * V1.2.0 FIX-DG-TEMPLATE-MEMORY. Deriving the template from every submission in a large
     * activity peaked at 172 MB on a cohort of 100 documents at the normtext storage cap, which
     * exhausts a 256 MB cron and leaves the submission retrying forever. The template is a
     * PROPORTION - text carried by a third or more of the submissions - and a sample estimates
     * a proportion, so it is derived from at most TEMPLATE_SAMPLE_MAX documents.
     *
     * Three things have to hold: the template is the same one, the sample is the same every
     * time, and a copy ring that is a small share of a large cohort is still not swallowed.
     *
     * @return void
     */
    public function test_the_template_sample_is_bounded_deterministic_and_equivalent(): void {
        $this->assertSame(60, analyser::TEMPLATE_SAMPLE_MAX);

        $sheet = $this->cover_sheet('SITXFSA005 Use hygienic practices for food safety',
            'Question 4. Explain the temperature danger zone.');

        // 200 submissions, every one with its own wording under one shared cover sheet.
        $texts = [];
        for ($i = 0; $i < 200; $i++) {
            $texts[] = question_parser::normalise_for_similarity(
                $sheet . 'On shift number ' . $i . ' I checked the cool room and recorded reading '
                . $i . ' in the log book before service started for the evening sitting.'
            );
        }

        $sampled = analyser::template_bigrams_for_cohort($texts);

        // Same answer as the full cohort would give. Computed here by taking a slice small
        // enough that no sampling occurs, which is the comparison that matters: boilerplate
        // appears in every submission, so any honest subset finds it.
        $unsampled = analyser::template_bigrams_for_cohort(array_slice($texts, 0, 45));
        $this->assertNotEmpty($sampled, 'The cover sheet must still be identified.');
        $this->assertSame([], array_diff_key($unsampled, $sampled),
            'Sampling must not lose template text that a smaller cohort finds.');

        /*
         * An activity whose assessment tool was REISSUED partway through the cohort: the first
         * half carries one cover sheet, the second half another. This is the fixture that makes
         * the sampling strategy testable at all, and it exists because mutation testing showed
         * the single-template cohort above cannot distinguish any strategy from any other -
         * when the boilerplate is in every submission, the first N, a stride, and a random
         * shuffle all find exactly the same template.
         *
         * Two properties follow from it:
         *  - STRIDE, not the first N. Taking the first 60 of 200 sees only the original cover
         *    sheet and never identifies the reissued one, so every pair in the second half
         *    keeps its shared boilerplate and reads as a match.
         *  - DETERMINISTIC, not random. With two templates present, different samples find
         *    different amounts of each, so a shuffled sample gives the same submission a
         *    different score on re-analysis. A figure a trainer cannot reproduce is not
         *    evidence, whatever its value.
         */
        /*
         * The reissued tool must share almost NOTHING with the original, or this fixture cannot
         * distinguish one sampling strategy from another. The first version reused
         * cover_sheet() and changed only the question line, which left the two sheets sharing
         * 71% of their word pairs - so the shared 71% was found by any strategy and the test
         * passed even when the sampling was mutated. Mutation testing caught that.
         */
        $sheettwo = 'VALIDATED ASSESSMENT TOOL v3. Issued under the organisation quality '
            . 'framework following moderation. Candidate guidance: responses are marked against '
            . 'the performance criteria listed in the mapping matrix at the rear of this '
            . 'booklet. Where a response is judged insufficient, your trainer will arrange a '
            . 'supplementary oral questioning session rather than a full resubmission. Keep your '
            . 'own copy. Integrity undertaking: by submitting this booklet I confirm the '
            . 'responses are mine alone, produced without prohibited assistance, and I accept '
            . 'that breaches are managed under the disciplinary schedule. '
            . 'Task 4 of 9. Set out what the temperature danger zone is and how you monitor it. ';
        $reissued = [];
        for ($i = 0; $i < 200; $i++) {
            $reissued[] = question_parser::normalise_for_similarity(
                ($i < 100 ? $sheet : $sheettwo)
                . ' On shift number ' . $i . ' I checked the cool room and recorded reading '
                . $i . ' in the log book before service started for the evening sitting.'
            );
        }

        $both = analyser::template_bigrams_for_cohort($reissued);
        $firstsheetonly = analyser::template_bigrams_for_cohort(array_slice($reissued, 0, 45));
        $secondsheetonly = analyser::template_bigrams_for_cohort(array_slice($reissued, 155, 45));

        $this->assertNotEmpty($firstsheetonly);
        $this->assertNotEmpty($secondsheetonly);

        // A stride across the whole cohort must see BOTH cover sheets. Taking the first N
        // would see only the first, and the second half's pairs would stay inflated.
        $missedsecond = array_diff_key($secondsheetonly, $both);
        $this->assertLessThan(
            count($secondsheetonly) / 2,
            count($missedsecond),
            'The sample must span the cohort: a template introduced partway through was missed, '
            . 'which is what taking the first N submissions does.'
        );

        // Deterministic: the same submission must score the same on re-analysis.
        for ($run = 0; $run < 4; $run++) {
            $this->assertSame($sampled, analyser::template_bigrams_for_cohort($texts),
                'The template must be identical on every run over the same cohort.');
            $this->assertSame($both, analyser::template_bigrams_for_cohort($reissued),
                'With two templates in one activity, a random sample finds different amounts '
                . 'of each and the score stops being reproducible.');
        }

        // And a copy ring that is a small share of a large cohort must survive the sampling.
        $source = $sheet . 'I always check the cool room at the start of my shift and write the '
            . 'reading on the chart near the door because the bacteria grow fastest in the '
            . 'danger zone between five and sixty degrees so we discard anything left too long.';
        $ring = [];
        for ($i = 0; $i < 10; $i++) {
            $ring[] = question_parser::normalise_for_similarity(
                str_replace(
                    ['always check', 'write the reading', 'grow fastest', 'discard'],
                    $i % 2 === 0
                        ? ['check', 'record the reading', 'multiply quickest', 'throw out']
                        : ['always inspect', 'note the reading', 'grow quickest', 'bin'],
                    $source
                )
            );
        }
        $cohort = array_merge($ring, array_slice($texts, 0, 190));
        $template = analyser::template_bigrams_for_cohort($cohort);

        $a = array_diff_key(question_parser::bigram_set($ring[0]), $template);
        $b = array_diff_key(question_parser::bigram_set($ring[1]), $template);
        $this->assertGreaterThan(
            analyser::S12_REPORT_THRESHOLD,
            question_parser::jaccard_sets($a, $b),
            'A ring of 10 in a cohort of 200 is 5% of the activity and must still be reported. '
            . 'If sampling swallowed it, the sample is too small or the threshold is wrong.'
        );
    }

    /**
     * The shipped comparison must subtract the template from BOTH documents in a pair.
     *
     * This guard exists because mutation testing proved it had to. The behavioural tests
     * for the rule above run on a reimplementation of the pipeline, since this harness has
     * no database - so deleting the subtraction from cross_student_similarity() itself
     * passed every one of them. Two mutations went uncaught: dropping it from this
     * submission's set, and dropping it from the other submission's set.
     *
     * analyser_test.php now covers the real function against a real database, which is the
     * authoritative test. This one is the cheap check that also fires in a bare PHP
     * environment, and it is deliberately asymmetric-aware: the subtraction must appear on
     * both sides, because removing it from either one silently restores the defect for every
     * pair while leaving the other call site looking correct.
     *
     * @return void
     */
    public function test_the_shipped_comparison_subtracts_the_template_from_both_documents(): void {
        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');

        // Isolate cross_student_similarity() and strip comments, so neither a neighbouring
        // method nor an explanatory note can satisfy the assertions below.
        $from = strpos($src, 'public static function cross_student_similarity');
        $this->assertNotFalse($from, 'cross_student_similarity() must exist');
        $to = strpos($src, "\n    public static function", $from + 10);
        $body = substr($src, $from, $to === false ? null : $to - $from);
        $code = implode("\n", array_filter(
            explode("\n", $body),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));

        $this->assertSame(
            2,
            preg_match_all('/array_diff_key\s*\(\s*\n?\s*question_parser::bigram_set/', $code),
            'Both documents in a pair must have the template subtracted before they are '
            . 'compared. Exactly two call sites: this submission, and the one it is being '
            . 'compared against.'
        );
        $this->assertStringContainsString('self::template_bigrams_for_cohort(', $code,
            'The template set must come from the shared helper, not be rebuilt inline. '
            . 'report.php must call the same one: it carried its own copy of this pairwise '
            . 'comparison, so the first version of this fix corrected the per-submission '
            . 'badge and left the class report listing every innocent pair.');
        $this->assertMatchesRegularExpression(
            '/jaccard_sets\s*\(\s*\$bga\s*,\s*\$bgb\s*\)/',
            $code,
            'The comparison must run on the two subtracted sets.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/jaccard_sets\s*\(\s*question_parser::bigram_set/',
            $code,
            'Nothing may be compared straight from bigram_set() without subtraction.'
        );
    }

    /**
     * The unlock price is stated identically everywhere, and nothing advertises AI detection.
     *
     * V1.2.1. The settings page carried "Unlock (5,000 credits)" where the real price is 50
     * credits (US$5) - a hundredfold overstatement on the first screen a prospective buyer
     * sees - and the figure appeared in three places that had to be changed together.
     *
     * The same string also read "scores it across 12 plagiarism and AI-detection signals".
     * Those twelve signals were removed in 1.0.99 after flagging 0 of 48 answers generated by
     * ChatGPT, Gemini and Claude, so the settings page was advertising a capability the plugin
     * had not contained for several releases - and advertising it as AI detection, the one
     * claim this product must not make.
     *
     * @return void
     */
    public function test_the_unlock_price_and_product_claims_are_consistent(): void {
        $root = __DIR__ . '/..';
        $sources = [
            'lang/en/plagiarism_docguard.php' => file_get_contents($root . '/lang/en/plagiarism_docguard.php'),
            'README.md'                       => file_get_contents($root . '/README.md'),
        ];

        $figures = [];
        foreach ($sources as $name => $text) {
            if (preg_match_all('/([0-9][0-9,]*)\s*credits?/i', $text, $m)) {
                foreach ($m[1] as $found) {
                    $figures[str_replace(',', '', $found)][] = $name;
                }
            }
        }
        // array_keys() casts numeric string keys to integers, so compare as strings.
        $found = array_map('strval', array_keys($figures));
        $this->assertSame(['50'], $found,
            'The unlock price must be stated as the same number everywhere. Found: '
            . implode(', ', $found));

        /*
         * EVERY occurrence must carry the currency, not just one of them. Checking that the
         * phrase appears somewhere in the file passes while a second mention says a bare
         * "50 credits" - which a reader can take for fifty dollars. Mutation testing found
         * exactly that hole.
         */
        foreach ($sources as $name => $text) {
            $mentions = preg_match_all('/50\s*credits?/i', $text);
            if ($mentions === 0) {
                continue;
            }
            $this->assertSame(
                $mentions,
                preg_match_all('/50 credits, US\$5/', $text),
                $name . ': every mention of the price must read "50 credits, US$5". '
                . $mentions . ' mention(s) found, not all of them complete.'
            );
        }

        // And no customer-facing string may advertise AI detection. Comments are stripped,
        // since the explanatory notes legitimately quote the wording being removed.
        $lang = implode("\n", array_filter(
            explode("\n", $sources['lang/en/plagiarism_docguard.php']),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));
        foreach (["detects it across", 'AI-detection signal', 'AI detection signal',
                  'plagiarism and AI'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $lang,
                'No string may advertise AI detection: ' . $claim);
        }

        // The settings blurb must still say plainly what the product does not do.
        $this->assertStringContainsString('does not detect AI writing', $lang,
            'about_desc must state the limit, on the first screen an administrator sees.');
    }

    /**
     * The student-facing disclosure must describe everything DocGuard actually stores.
     *
     * V1.2.2. This is the notice shown to the student before they submit, and it is the
     * strongest claim the plugin makes to the person with the least power in the exchange. It
     * said their text was extracted and compared, and that quotations and the reference list
     * were removed - all true, and all incomplete since 1.2.0, which also stores authenticity
     * findings quoting short passages of their writing verbatim and reads the author name,
     * save count and editing time out of their file.
     *
     * The privacy registry had the identical failure and was fixed in 1.2.1; this is the same
     * omission in the place a student actually reads. Both disclosure variants are checked,
     * because they differ only in their closing sentence about retention and it would be easy
     * to update one.
     *
     * @return void
     */
    public function test_the_student_disclosure_covers_what_is_stored(): void {
        $lang = file_get_contents(__DIR__ . '/../lang/en/plagiarism_docguard.php');

        foreach (['disclosure', 'disclosure_noretention'] as $key) {
            $this->assertSame(1, preg_match(
                '/\$string\[\'' . $key . '\'\]\s*=\s*(.*?);\s*\n/s', $lang, $m
            ), "the $key string must exist");
            $text = $m[1];

            // What is compared, and what is taken out before comparing.
            foreach (['assessment template', 'reference list', 'same activity'] as $needed) {
                $this->assertStringContainsStringIgnoringCase($needed, $text,
                    "$key must say that the $needed affects the comparison.");
            }

            // What the authenticity checks store about them. Added in 1.2.0, undisclosed
            // until 1.2.2.
            foreach (['chat assistant', 'legislation', 'author name', 'editing',
                      'passage of your own writing'] as $needed) {
                $this->assertStringContainsStringIgnoringCase($needed, $text,
                    "$key must disclose that DocGuard records \"$needed\".");
            }

            // The limits, stated to the student and not only to the trainer.
            $this->assertStringContainsStringIgnoringCase('does not judge whether your work was written with AI', $text,
                "$key must tell the student DocGuard does not judge AI use.");
            $this->assertStringContainsStringIgnoringCase('never sent to any third party', $text,
                "$key must state that the document itself does not leave the site.");
            // Phrasing is free; the statement is not. Either "not a finding of misconduct"
            // or "nothing ... is a finding of misconduct" satisfies this.
            $this->assertStringContainsStringIgnoringCase('finding of misconduct on its own', $text,
                "$key must state that nothing here is a finding of misconduct on its own.");
        }

        /*
         * And the two variants must stay in step.
         *
         * They differ only in their closing sentence about retention, so the body is one text
         * maintained in two places - which is how the privacy registry and the analyser's user
         * field list came to disagree in earlier releases. Checking each one against a list of
         * required phrases does not catch divergence: a sentence added to one and not the other
         * passes both. Comparing the bodies does.
         */
        $body = function (string $key) use ($lang): string {
            preg_match('/\$string\[\'' . $key . '\'\]\s*=\s*(.*?);\s*\n/s', $lang, $m);
            // Everything up to the sentence about retention, which is the only intended difference.
            $text = (string)($m[1] ?? '');
            $cut = stripos($text, 'The extracted text is deleted');
            if ($cut === false) {
                $cut = stripos($text, 'This site has set the extracted text');
            }
            return $cut === false ? $text : substr($text, 0, $cut);
        };
        $this->assertSame(
            $body('disclosure'),
            $body('disclosure_noretention'),
            'The two student disclosures must be identical apart from their closing sentence '
            . 'about retention. They have diverged, which means one was updated and the other '
            . 'was not, and half the students on the site are being told something different.'
        );
    }

    /**
     * No customer-facing string may quote a measurement that the current code cannot produce.
     *
     * V1.2.2. Five strings quoted "13%" for two independent answers and "92%" for a copy.
     * Both were measured before 1.1.5 excluded the shared assessment template, so neither
     * reproduces on the shipped code - a trainer checking the plugin's own stated benchmark
     * against the plugin's own output would have found they disagreed.
     *
     * @return void
     */
    public function test_no_string_quotes_a_superseded_measurement(): void {
        $strip = fn(string $text): string => implode("\n", array_filter(
            explode("\n", $text),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));

        /*
         * The README too, not only the language file. The first version of this test checked
         * the strings alone and passed while the README's own band table still quoted 13% and
         * 92% - the same superseded numbers, in the document a buyer reads first.
         */
        $sources = [
            'lang/en/plagiarism_docguard.php' => $strip(
                file_get_contents(__DIR__ . '/../lang/en/plagiarism_docguard.php')),
            // The line that names the superseded figures is the one place they may appear.
            'README.md' => preg_replace(
                '/^Earlier releases of this README(.|\n)*?reproduce\.$/m', '',
                file_get_contents(__DIR__ . '/../README.md')),
        ];

        foreach ($sources as $name => $text) {
            foreach (['13%', '92%', '47.5%', '0.7%'] as $stale) {
                $this->assertStringNotContainsString($stale, $text,
                    $name . ' quotes ' . $stale . ', which was measured before the assessment '
                    . 'template was excluded in 1.1.5 and no longer reproduces. Quote a figure '
                    . 'the shipped sample pack produces.');
            }
        }
    }

    /**
     * The class report must compare the same way the analyser does.
     *
     * report.php does not call cross_student_similarity(). It carries its own pairwise loop,
     * because it compares every pair in the activity rather than one submission against the
     * rest. So the first version of the 1.1.5 fix corrected the badge on each submission and
     * left THIS table - the one a trainer reads before opening a misconduct file - still
     * listing all 44 innocent pairs of a ten-student cohort.
     *
     * Both paths now take the template from analyser::template_bigrams_for_cohort(). This
     * asserts that report.php still does, and that it honours the cohort floor, because a
     * page that compares unconditionally will happily print the 50.5% that two independent
     * answers measure when no template can yet be identified.
     *
     * @return void
     */
    public function test_the_class_report_subtracts_the_template_and_honours_the_floor(): void {
        $src  = file_get_contents(__DIR__ . '/../report.php');
        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));

        $this->assertStringContainsString('template_bigrams_for_cohort(', $code,
            'report.php must take the activity template from the shared helper.');
        /*
         * Both gates, counted. The page checks the floor twice - once to explain itself
         * instead of comparing, and once to stay silent rather than claim "no similarities
         * found" from a comparison it never ran. Asserting only that the constant appears
         * somewhere in the file is not enough: a mutation that disabled the first gate left
         * the second one's mention of the constant behind, and the guard passed.
         */
        $this->assertSame(
            2,
            preg_match_all(
                '/\$count\s*<\s*\\\\?plagiarism_docguard\\\\analyser::COHORT_MIN_FOR_COMPARISON/',
                $code
            ),
            'report.php must gate on the cohort floor in both places: before comparing, and '
            . 'before reporting that nothing was found.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/jaccard_sets\s*\([^)]*question_parser::bigram_set/',
            $code,
            'report.php must compare the subtracted sets, never raw bigram sets.'
        );
        $this->assertMatchesRegularExpression(
            '/array_diff_key\s*\(\s*\n?\s*\\\\?plagiarism_docguard\\\\question_parser::bigram_set/',
            $code,
            'Each submission\'s set must have the template subtracted before comparison.'
        );
    }
}
