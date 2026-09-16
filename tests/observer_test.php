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

use plagiarism_docguard\task\analyse_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the DocGuard event observer.
 *
 * These drive the real submission entry point: a stored file in a real
 * assignsubmission_file area, a real mod_assign event, and the records the plugin
 * actually writes to the database. Every expectation was read back from an observed
 * run; where the observed behaviour is wrong the docblock says so and the assertion
 * pins the current behaviour.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\observer
 */
final class observer_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Build the assign object, the student's submission row and a stored file for it.
     *
     * @param array $act The activity as returned by create_docguard_assign().
     * @param \stdClass $student The submitting student.
     * @param string $filename Name of the file to store.
     * @param string $content Raw bytes of the file.
     * @return array{assign: \assign, submission: \stdClass, file: \stored_file}
     */
    private function submit_file(array $act, \stdClass $student, string $filename, string $content): array {
        $assignobj  = new \assign($act['context'], $act['cm'], $act['course']);
        $submission = $assignobj->get_user_submission($student->id, true);
        $file = $this->store_submission_file($act['context'], (int)$submission->id, $filename, $content);
        return ['assign' => $assignobj, 'submission' => $submission, 'file' => $file];
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-OBSERVER-NEVER-FIRED): the submission id must be read
     * from $event->objectid. Until 1.0.88 the observer read it from
     * $event->other['submissionid'], a key mod_assign has never set;
     * assessable_submitted::create_from_submission() puts the submission id in objectid
     * and populates other with nothing but submission_editable. The observer therefore
     * searched for the submitted files with itemid = 0, found none, and analysed
     * nothing, so no student submission was ever analysed by the event pipeline.
     *
     * This test builds the event through core's own factory — no hand-made payload —
     * so it fails again the moment the observer stops reading the key core really sends.
     *
     * @return void
     */
    public function test_real_submission_event_is_analysed_and_stored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);
        $parts = $this->submit_file($act, $student, 'report.docx', $this->two_question_docx());

        $event = \mod_assign\event\assessable_submitted::create_from_submission(
            $parts['assign'],
            $parts['submission'],
            true
        );
        // The payload core actually sends: submission id in objectid, nothing useful in other.
        $this->assertArrayNotHasKey('submissionid', $event->other);
        $this->assertEquals($parts['submission']->id, $event->objectid);

        // Handed straight to the observer, exactly as core's event dispatcher would.
        // (trigger() cannot be used here: Moodle's PHPUnit runs each test inside a
        // database transaction and core defers non-internal observers until it commits,
        // so a triggered event would prove nothing either way.)
        observer::on_assessable_submitted($event);

        /*
         * V1.0.92 PERF-DG-OBSERVER-ADHOC: the observer queues, it does not analyse. The
         * event payload assertions above still matter — they are what the 1.0.88 fix was
         * about — so they are now checked on the queued task's custom data, and the task
         * is then executed so the end-to-end result is still asserted below.
         */
        // The observer records the file as pending and queues the work. It must NOT have
        // analysed anything inline — but it must leave the retryable pending row behind,
        // because that row is what process_pending Phase 1 recovers if the task is lost.
        $pending = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertNotFalse($pending, 'The observer must pre-record the submission as pending.');
        $this->assertSame('pending', $pending->status);
        $this->assertSame('report.docx', $pending->filename);
        $this->assertGreaterThan(0, (int)$pending->timecreated);
        $this->assertSame(
            0,
            $DB->count_records('plagiarism_docguard_sec'),
            'The observer must not analyse inline; that work belongs to the adhoc task.'
        );

        $tasks = \core\task\manager::get_adhoc_tasks(analyse_submission::class);
        $this->assertCount(1, $tasks, 'The observer must queue exactly one analysis task.');
        $queued = reset($tasks);
        $data   = $queued->get_custom_data();
        $this->assertEquals($parts['submission']->id, (int)$data->submissionid);
        $this->assertEquals($student->id, (int)$data->userid);
        $this->assertEquals($act['cm']->id, (int)$data->cmid);
        $this->assertEquals($act['context']->id, (int)$data->contextid);

        $queued->execute();

        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertNotFalse($record, 'The core submission event must produce a record.');
        $this->assertSame('analysed', $record->status);
        $this->assertSame('report.docx', $record->filename);
        $this->assertSame('docx', $record->filetype);
        $this->assertEquals($parts['submission']->id, $record->submissionid);
        $this->assertEquals($act['context']->id, $record->contextid);
        $this->assertEquals($act['cm']->id, $record->cmid);
        $this->assertSame(2, (int)$record->section_count);
        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sec', ['subid' => $record->id]));

        // The file really was found under the submission's own itemid, not itemid 0:
        // nothing was ever stored at itemid 0 in this test.
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Build the event exactly as core's assessable_submitted::create_from_submission()
     * builds it: submission id in objectid, submission_editable the only other key.
     *
     * @param array $act The activity as returned by create_docguard_assign().
     * @param array $parts The assign, submission and file as returned by submit_file().
     * @param \stdClass $student The submitting student.
     * @return \mod_assign\event\assessable_submitted The event to hand to the observer.
     */
    private function make_event(array $act, array $parts, \stdClass $student) {
        return \mod_assign\event\assessable_submitted::create([
            'context'  => $act['context'],
            'objectid' => $parts['submission']->id,
            'userid'   => $student->id,
            'other'    => ['submission_editable' => true],
        ]);
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-OBSERVER-WRONG-USER): the analysis must be attributed
     * to the author of the work, not to whoever pressed the button. mod_assign lets a
     * teacher submit on a student's behalf (locallib.php save_submission /
     * submit_for_grading), and core then puts the teacher in userid and the student in
     * relateduserid. Until 1.0.88 the observer used userid unconditionally, so the
     * student's extracted document text was filed under the teacher: the student's badge
     * stayed Pending and the student's GDPR export and erasure both missed the record,
     * because the privacy provider keys on userid.
     *
     * @return void
     */
    public function test_submission_made_on_behalf_of_a_student_is_filed_under_the_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        $this->setUser($student);
        $parts = $this->submit_file($act, $student, 'report.docx', $this->two_question_docx());

        $this->setUser($teacher);
        $event = \mod_assign\event\assessable_submitted::create_from_submission(
            $parts['assign'],
            $parts['submission'],
            true
        );
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($student->id, $event->relateduserid);

        observer::on_assessable_submitted($event);

        // V1.0.92: attribution is now carried on the queued task, so assert it there as
        // well as on the stored record — the task's userid is what files the work.
        $tasks = \core\task\manager::get_adhoc_tasks(analyse_submission::class);
        $this->assertCount(1, $tasks);
        $queued = reset($tasks);
        $this->assertEquals(
            $student->id,
            (int)$queued->get_custom_data()->userid,
            'The task must carry the author, not the submitter.'
        );
        $queued->execute();

        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub', ['userid' => $student->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub', ['userid' => $teacher->id]));
    }

    /**
     * A file type DocGuard cannot read leaves no record behind, so the badge never
     * claims anything about it.
     *
     * V1.0.92: the observer pre-records supported files as pending and queues the adhoc
     * task; unsupported files are filtered out of both, so neither the pending row nor the
     * analysis ever happens for them.
     *
     * @return void
     */
    public function test_unsupported_file_type_leaves_no_record(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);
        $parts = $this->submit_file($act, $student, 'notes.txt', str_repeat('plain text content. ', 20));

        observer::on_assessable_submitted($this->make_event($act, $parts, $student));

        // V1.0.92: the observer filters on file type before it records or queues anything,
        // so an unsupported upload produces neither a pending row nor a task. Draining any
        // task that did get queued must still leave nothing behind.
        foreach (\core\task\manager::get_adhoc_tasks(analyse_submission::class) as $queued) {
            $queued->execute();
        }

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Passing an unreadable file straight to analyse_and_store() — the route the cron
     * tasks and the re-analyse pages use — does leave a record, in error state.
     *
     * @return void
     */
    public function test_analyse_and_store_records_unsupported_type_as_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file($act['context'], $subid, 'notes.txt', 'hello world');

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('error', $record->status);
        $this->assertSame('unsupported', $record->filetype);
        $this->assertStringContainsString('Unsupported file type', $record->errormsg);
        $this->assertNull($record->normtext);
    }

    /**
     * A document that yields almost no text is recorded as an error the teacher can
     * see, with no section rows and no stored text.
     *
     * @return void
     */
    public function test_document_with_no_extractable_text_is_an_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'scan.docx',
            $this->docx_bytes([''])
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('error', $record->status);
        $this->assertStringContainsString('too short or empty', $record->errormsg);
        $this->assertSame(0, (int)$record->section_count);
        $this->assertNull($record->normtext);
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec'));
    }

    /**
     * Re-analysing a record updates the existing row in place, replaces its section
     * rows, and preserves the original timecreated so the cron grace window still
     * measures from the first attempt.
     *
     * @return void
     */
    public function test_reanalysis_updates_in_place_and_replaces_sections(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx()
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );
        $first = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $firstsecids = array_keys($DB->get_records('plagiarism_docguard_sec', ['subid' => $first->id]));

        // Mimic what report.php does before asking for a re-analysis.
        $DB->set_field('plagiarism_docguard_sub', 'status', 'pending', ['id' => $first->id]);
        $DB->set_field('plagiarism_docguard_sub', 'timecreated', 111, ['id' => $first->id]);

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));
        $second = $DB->get_record('plagiarism_docguard_sub', ['id' => $first->id]);
        $this->assertSame('analysed', $second->status);
        $this->assertEquals(111, $second->timecreated);
        $this->assertSame(2, (int)$second->section_count);

        $secondsecids = array_keys($DB->get_records('plagiarism_docguard_sec', ['subid' => $first->id]));
        $this->assertCount(2, $secondsecids);
        $this->assertSame([], array_intersect($firstsecids, $secondsecids));
    }

    /**
     * A record already marked analysed is left completely alone, so an unchanged file
     * is never re-extracted.
     *
     * @return void
     */
    public function test_already_analysed_record_is_not_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx()
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );
        $before = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $DB->set_field('plagiarism_docguard_sub', 'overall_riskscore', 99, ['id' => $before->id]);

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $after = $DB->get_record('plagiarism_docguard_sub', ['id' => $before->id]);
        $this->assertEquals(99, $after->overall_riskscore);
        $this->assertEquals($before->timemodified, $after->timemodified);
    }

    /**
     * The per-activity switch is honoured by analyse_and_store() itself, not only by
     * its callers: nothing is stored for an activity where DocGuard is off.
     *
     * @return void
     */
    public function test_is_cm_active_gate_blocks_storage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx()
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );
        $this->assertDebuggingCalled();

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * The site-wide switch is honoured by analyse_and_store() too.
     *
     * @return void
     */
    public function test_site_wide_disable_blocks_storage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx()
        );

        set_config('enabled', 0, 'plagiarism_docguard');
        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );
        $this->assertDebuggingCalled();

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * When duplicate rows already exist for the same student, activity and file, the
     * newest is updated, the older ones are left alone, and the duplication is
     * reported at developer level.
     *
     * @return void
     */
    public function test_duplicate_records_use_the_newest(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx()
        );
        $hash    = $file->get_contenthash();

        $old = $this->create_sub(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'contenthash' => $hash, 'status' => 'pending',
            'timemodified' => 100, 'timecreated' => 100]);
        $new = $this->create_sub(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'contenthash' => $hash, 'status' => 'pending',
            'timemodified' => 200, 'timecreated' => 200]);

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );
        $this->assertDebuggingCalled();

        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sub'));
        $this->assertSame('analysed', $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $new]));
        $this->assertSame('pending', $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $old]));
    }

    /**
     * Section rows are numbered sequentially in document order regardless of the
     * numbers printed in the document, and carry the parsed label, the text, the word
     * count and the score.
     *
     * @return void
     */
    public function test_section_rows_are_numbered_sequentially(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $answer  = 'The identified workplace hazard was recorded in the register during the '
            . 'inspection of the loading dock and reported to the supervisor that morning.';
        // Question/Answer pairs: the parser reads both markers as the same number, so a
        // naive implementation would collide on the unique (subid, section_num) index.
        $file = $this->store_submission_file(
            $act['context'],
            $subid,
            'qa.docx',
            $this->docx_bytes([
                'Question 1: Describe the hazard.',
                $answer,
                'Answer 1: ' . $answer,
                'Question 2: Describe the control.',
                $answer,
                'Answer 2: ' . $answer,
                ])
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('analysed', $record->status);
        $sections = $DB->get_records(
            'plagiarism_docguard_sec',
            ['subid' => $record->id],
            'section_num ASC'
        );
        $nums = array_column($sections, 'section_num');
        $this->assertEquals(range(1, count($sections)), array_map('intval', $nums));
        $this->assertSame(count($sections), (int)$record->section_count);
        foreach ($sections as $section) {
            $this->assertEquals($student->id, $section->userid);
            $this->assertEquals($act['cm']->id, $section->cmid);
            $this->assertNotEmpty($section->section_label);
            $this->assertNotEmpty($section->section_text);
            $this->assertGreaterThan(0, (int)$section->wordcount);
            $this->assertNotEmpty($section->signalsjson);
        }
    }

    /**
     * A document with no question markers is stored as a single "Full Document"
     * section.
     *
     * @return void
     */
    public function test_unstructured_document_becomes_one_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'essay.docx',
            $this->docx_bytes([
                'The inspection of the loading dock identified a hazard that had not been '
                . 'recorded in the register, and the supervisor was informed the same morning.',
            ])
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('analysed', $record->status);
        $this->assertSame(1, (int)$record->section_count);
        $section = $DB->get_record('plagiarism_docguard_sec', ['subid' => $record->id]);
        $this->assertSame('Full Document', $section->section_label);
    }
}
