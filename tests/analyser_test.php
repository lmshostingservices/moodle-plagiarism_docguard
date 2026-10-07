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
 * exercised here: score_section(), submission_score() and band(). The individual
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
        $this->assertSame('', $result['note'], 'A section long enough to describe carries no note.');
        /*
         * V1.0.99. This used to assert the ten style signals were present and keyed in
         * order. They were removed: measured against 48 answers written by three current
         * language models they flagged none of them, while the highest-scoring document in
         * the test set was a human second-language student. The key is retained and always
         * empty so stored rows and report code keep one shape.
         */
        $this->assertSame([], $result['signals'],
            'No style signals are computed; the key exists only to keep one row shape.');
        $this->assertSame(0, $result['riskscore'],
            'A section carries no score. The submission score is copy evidence.');
    }

    /**
     * cross_student_similarity() must not make fullname() complain.
     *
     * V1.1.4. This is the authoritative check and it needs a real Moodle: it builds two
     * genuinely similar submissions from real users, runs the comparison, and asserts that
     * Moodle emitted no developer warning. A field-list assertion can confirm the list
     * looks right; only fullname() itself can confirm it is satisfied.
     *
     * The defect it guards: the method selected id, username, firstname and lastname, then
     * called fullname(), which wants every name field Moodle defines. With developer
     * debugging on, every flagged pair produced a warning.
     *
     * @return void
     */
    public function test_cross_student_similarity_emits_no_debugging(): void {
        global $DB;
        $this->resetAfterTest();

        $shared = 'To wash your hands correctly you first wet your hands with clean running '
            . 'water. Then you apply enough soap to cover all the surfaces of your hands. '
            . 'You rub your palms together and then rub the back of each hand with the palm '
            . 'of the other hand. You keep rubbing for at least twenty seconds and then '
            . 'rinse your hands well under clean running water before drying them.';

        $course = $this->getDataGenerator()->create_course();
        $one    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $two    = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $norm = question_parser::normalise_for_similarity($shared);
        $mk = function (int $userid) use ($DB, $norm): int {
            return (int)$DB->insert_record('plagiarism_docguard_sub', (object)[
                'cmid'              => 424242,
                'userid'            => $userid,
                'submissionid'      => 0,
                'filename'          => 'answer.pdf',
                'status'            => 'analysed',
                'overall_riskscore' => 0,
                'overall_risklevel' => 'low',
                'section_count'     => 1,
                'normtext'          => $norm,
                'timecreated'       => time(),
                'timemodified'      => time(),
            ]);
        };
        $first  = $mk((int)$one->id);
        $second = $mk((int)$two->id);

        /*
         * V1.1.5: a third submission, carrying a different answer.
         *
         * cross_student_similarity() reports nothing below COHORT_MIN_FOR_COMPARISON,
         * because with two documents there is no way to tell the assessment template apart
         * from one student's copy of the other. Two identical submissions used to be enough
         * to make this test's point; now they produce no matches and the test would fail on
         * its own assertNotEmpty. The third student writes her own answer, so the pair under
         * test remains the only match in the activity.
         */
        $three = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $third = (int)$DB->insert_record('plagiarism_docguard_sub', (object)[
            'cmid'              => 424242,
            'userid'            => (int)$three->id,
            'submissionid'      => 0,
            'filename'          => 'answer.pdf',
            'status'            => 'analysed',
            'overall_riskscore' => 0,
            'overall_risklevel' => 'low',
            'section_count'     => 1,
            'normtext'          => question_parser::normalise_for_similarity(
                'Before I start a shift I read the handover notes and check which residents '
                . 'need assistance with their meals. I ask each person what they would like '
                . 'and I write down anything they did not finish so the nurse can see it. '
                . 'If somebody is coughing while they eat I stop and report it straight away.'
            ),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        $this->assertGreaterThan(0, $third);

        $matches = analyser::cross_student_similarity($second, 424242, $norm);

        // The point of the test.
        $this->assertDebuggingNotCalled();

        // And the comparison must actually have produced a match, or nothing was proved.
        $this->assertNotEmpty($matches, 'identical text must match, or this test is vacuous');
        $this->assertSame($first, (int)$matches[0]['subid']);
        $this->assertNotSame('', trim((string)$matches[0]['fullname']),
            'fullname() must return a usable name');
        $this->assertNotSame('Unknown', $matches[0]['fullname'],
            'the user record must have been found');
    }

    /**
     * The activity's shared assessment template must not make students look like copiers.
     *
     * V1.1.5 FIX-DG-SHARED-TEMPLATE-INFLATES-EVERY-PAIR, exercised through the shipped
     * function against a real database. This is the authoritative test for the fix, and it
     * has to live here rather than in signal_validity_test.php: the offline harness has no
     * database, so there it can only test a reimplementation of the rule. Mutation testing
     * confirmed the gap - deleting the subtraction from cross_student_similarity() failed no
     * test whatsoever until this one existed.
     *
     * The defect: the comparison ran over everything the extractor returned, including the
     * assessment tool's cover sheet, instructions, misconduct declaration and question text,
     * which every student in the activity submits because the template told them to.
     * Measured on a 10-student cohort with one ordinary cover sheet, all 44 innocent pairs
     * cleared the reporting threshold, and two students answering different units scored
     * 48.0% on the strength of the cover sheet alone.
     *
     * @return void
     */
    public function test_shared_template_text_is_subtracted_before_comparing(): void {
        global $DB;
        $this->resetAfterTest();

        $sheet = 'ASSESSMENT COVER SHEET. Registered Training Organisation code 41234. '
            . 'Unit of competency SITXFSA005 Use hygienic practices for food safety. '
            . 'Assessment task 1 of 3, written questions. Instructions to the student. '
            . 'Answer all questions in your own words in the spaces provided. You must '
            . 'complete every question to be assessed as satisfactory. If you need more '
            . 'space, attach additional pages and label them clearly. Your assessor will '
            . 'provide feedback within ten working days. If you are assessed as not yet '
            . 'satisfactory you are entitled to two further attempts. Reasonable adjustment '
            . 'is available on request, please speak to your trainer before you begin. '
            . 'Student declaration. I declare that this assessment is my own work, that I '
            . 'have not copied from any other student, and that I have not allowed any other '
            . 'student to copy my work. I understand that plagiarism and cheating are serious '
            . 'breaches of this organisation academic misconduct policy and may result in my '
            . 'enrolment being cancelled. I confirm that I have retained a copy of this '
            . 'assessment for my own records. Question 4. Explain the temperature danger zone '
            . 'and describe how you monitor it in your workplace. My answer. ';

        $answers = [
            'dana'   => 'I always check the temperature of the cool room at the start of my shift '
                . 'and write it on the chart near the door. The danger zone is between 5 and 60 '
                . 'degrees because that is where bacteria grow fastest. If food sits in that '
                . 'range for more than four hours we have to throw it out. Last month the cool '
                . 'room read 8 degrees so I told the chef and we moved the dairy to another fridge.',
            'priya'  => 'At the beginning of every shift my job is to record the fridge and cool '
                . 'room temperatures in the log book. Under the two hour four hour rule anything '
                . 'left in the danger zone beyond four hours must be discarded. I sanitise the '
                . 'probe between different foods to avoid cross contamination and I report any '
                . 'fault I find to my supervisor before service starts for the evening.',
            'mariam' => 'When I am supporting a client with a shower I knock first and wait to be '
                . 'invited in, even in their own room, because that is their private space. I ask '
                . 'how they would like to be helped rather than assuming. If a client says no I '
                . 'stop and I record that they declined in the progress notes for the nurse.',
        ];

        $course = $this->getDataGenerator()->create_course();
        $cmid   = 515151;
        $mk = function (string $name, string $text) use ($DB, $course, $cmid): array {
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $norm = question_parser::normalise_for_similarity($text);
            $id   = (int)$DB->insert_record('plagiarism_docguard_sub', (object)[
                'cmid'              => $cmid,
                'userid'            => (int)$user->id,
                'submissionid'      => 0,
                'filename'          => $name . '.docx',
                'status'            => 'analysed',
                'overall_riskscore' => 0,
                'overall_risklevel' => 'low',
                'section_count'     => 1,
                'normtext'          => $norm,
                'timecreated'       => time(),
                'timemodified'      => time(),
            ]);
            return [$id, $norm];
        };

        $subs = [];
        foreach ($answers as $who => $answer) {
            $subs[$who] = $mk($who, $sheet . $answer);
        }

        /*
         * The fixture must reproduce the defect, or this test proves nothing: with the
         * template left in, two answers to entirely different units look like a copy pair.
         */
        $raw = question_parser::jaccard_sets(
            question_parser::bigram_set(
                question_parser::normalise_for_similarity($sheet . $answers['dana'])),
            question_parser::bigram_set(
                question_parser::normalise_for_similarity($sheet . $answers['mariam']))
        );
        $this->assertGreaterThan(analyser::S12_REPORT_THRESHOLD, $raw,
            'Fixture must be a false match before the template is removed.');

        foreach ($subs as $who => [$subid, $norm]) {
            $this->assertSame([], analyser::cross_student_similarity($subid, $cmid, $norm),
                $who . ' wrote their own answer and must not be reported against anyone.');
        }

        // And the real copier, arriving fourth, must still be caught through the template.
        $luke = str_replace(
            ['start of my shift', 'write it', 'grow fastest', 'throw it out', 'told the chef'],
            ['beginning of my shift', 'record it', 'grow quickest', 'discard it', 'informed the chef'],
            $answers['dana']
        );
        [$lukeid, $lukenorm] = $mk('luke', $sheet . $luke);

        $matches = analyser::cross_student_similarity($lukeid, $cmid, $lukenorm);
        $this->assertCount(1, $matches,
            'The copier must match exactly one submission: the one he copied.');
        $this->assertSame($subs['dana'][0], (int)$matches[0]['subid']);
        $this->assertGreaterThan(analyser::BAND_HIGH / 100, (float)$matches[0]['similarity'],
            'Subtracting the template must not soften a real copy.');
        $this->assertDebuggingNotCalled();
    }

    /**
     * Nothing is reported until a third submission can identify the template.
     *
     * Two independent answers carrying an ordinary cover sheet measure 50.5% - above the
     * reporting threshold, banded MEDIUM, and put to a trainer as a copy match. With two
     * documents nothing in the data separates the template from a copy, so that figure is
     * wrong rather than imprecise and no pair is reported. The activity corrects itself when
     * the third student submits, which the final assertions demonstrate.
     *
     * @return void
     */
    public function test_nothing_is_reported_until_the_cohort_floor_is_reached(): void {
        global $DB;
        $this->resetAfterTest();

        $sheet = 'ASSESSMENT COVER SHEET. Unit of competency CHCCCS031 Provide individualised '
            . 'support. Instructions to the student. Answer all questions in your own words. '
            . 'You must complete every question to be assessed as satisfactory. Student '
            . 'declaration. I declare that this assessment is my own work and that I have not '
            . 'copied from any other student. I understand that plagiarism and cheating are '
            . 'serious breaches of this organisation academic misconduct policy. Question 2. '
            . 'Describe how you protect a client dignity while providing personal care. My answer. ';

        $course = $this->getDataGenerator()->create_course();
        $cmid   = 626262;
        $add = function (string $answer) use ($DB, $course, $cmid, $sheet): array {
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $norm = question_parser::normalise_for_similarity($sheet . $answer);
            return [(int)$DB->insert_record('plagiarism_docguard_sub', (object)[
                'cmid'              => $cmid,
                'userid'            => (int)$user->id,
                'submissionid'      => 0,
                'filename'          => 'a.docx',
                'status'            => 'analysed',
                'overall_riskscore' => 0,
                'overall_risklevel' => 'low',
                'section_count'     => 1,
                'normtext'          => $norm,
                'timecreated'       => time(),
                'timemodified'      => time(),
            ]), $norm];
        };

        [$firstid, $firstnorm] = $add(
            'I knock on the door and wait to be invited in before I enter a resident room. '
            . 'I close the curtain and keep them covered with a towel while I help them wash. '
            . 'I ask what they want to wear instead of choosing something for them myself.');
        [$secondid, $secondnorm] = $add(
            'Before personal care I explain what I am about to do and check the person agrees. '
            . 'I shut the bathroom door so nobody walks in and I let them wash themselves '
            . 'wherever they are able to. I write up what assistance was needed afterwards.');

        $this->assertSame([], analyser::cross_student_similarity($secondid, $cmid, $secondnorm),
            'With two submissions the template cannot be identified, so nothing is reported.');
        $this->assertSame([], analyser::cross_student_similarity($firstid, $cmid, $firstnorm),
            'Neither direction may report a pair below the cohort floor.');

        // The third submission identifies the template, and the first two stay unreported -
        // now because they are genuinely dissimilar, rather than because counting stopped.
        $add('If a client refuses care I do not force it. I record the refusal in the notes '
            . 'and tell the registered nurse at handover so it can be followed up properly. '
            . 'Dignity of risk means the person can make a choice I would not make myself.');

        $this->assertSame([], analyser::cross_student_similarity($secondid, $cmid, $secondnorm),
            'Three independent answers on one template must still produce no matches.');
        $this->assertDebuggingNotCalled();
    }
}
