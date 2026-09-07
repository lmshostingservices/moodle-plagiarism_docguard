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

namespace plagiarism_docguard\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the hourly process_pending scheduled task.
 *
 * Both phases are driven against real submission files, real assign rows and the real
 * database. The task writes to output through mtrace(), so every run is wrapped in an
 * output buffer and the trace is asserted on where it is the only visible evidence.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\task\process_pending
 */
final class task_process_pending_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Run the task and return everything it wrote to output.
     *
     * @return string The captured mtrace output.
     */
    private function run_task(): string {
        ob_start();
        (new process_pending())->execute();
        return (string)ob_get_clean();
    }

    /**
     * Store a file for a student and register the pending DocGuard record the observer
     * would have left behind, aged past the grace window.
     *
     * @param array $act Activity as returned by create_docguard_assign().
     * @param \stdClass $student The student.
     * @param string $flavour Distinguishing word so each document has its own hash.
     * @param int $age How many seconds ago the record was created.
     * @return array{subid: int, file: \stored_file, submissionid: int}
     */
    private function make_pending(array $act, \stdClass $student, string $flavour, int $age = 600): array {
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $file = $this->store_submission_file(
            $act['context'],
            $submissionid,
            $flavour . '.docx',
            $this->two_question_docx($flavour)
        );
        $subid = $this->create_sub([
            'userid'       => $student->id,
            'cmid'         => $act['cm']->id,
            'contextid'    => $act['context']->id,
            'submissionid' => $submissionid,
            'filename'     => $flavour . '.docx',
            'contenthash'  => $file->get_contenthash(),
            'status'       => 'pending',
            'section_count' => 0,
            'normtext'     => null,
            'analysisjson' => null,
            'timecreated'  => time() - $age,
            'timemodified' => time() - $age,
        ]);
        return ['subid' => $subid, 'file' => $file, 'submissionid' => $submissionid];
    }

    /**
     * The task refuses to do anything while DocGuard is switched off site-wide.
     *
     * @return void
     */
    public function test_site_wide_disable_stops_the_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $pending = $this->make_pending($act, $student, 'disabled');

        set_config('enabled', 0, 'plagiarism_docguard');
        $output = $this->run_task();

        $this->assertStringContainsString('disabled site-wide', $output);
        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $pending['subid']])
        );
    }

    /**
     * Phase 1 analyses a pending record once it is older than the five minute grace
     * window, and leaves a fresher one alone.
     *
     * @return void
     */
    public function test_phase1_grace_window(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $fresh   = $this->make_pending($act, $student, 'fresh', 60);
        $stale   = $this->make_pending($act, $student, 'stale', 600);

        $output = $this->run_task();

        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $fresh['subid']])
        );
        $this->assertSame(
            'analysed',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $stale['subid']])
        );
        $this->assertStringContainsString('Files processed this run: 1', $output);
        $this->assertGreaterThan(
            0,
            $DB->count_records(
                'plagiarism_docguard_sec',
                ['subid' => $stale['subid']]
                )
        );
    }

    /**
     * The grace window is exclusive: a record exactly 300 seconds old is still inside
     * it and is only picked up once it is older than that.
     *
     * @return void
     */
    public function test_phase1_grace_window_boundary(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $onboundary = $this->make_pending($act, $student, 'boundary', 300);
        $justpast   = $this->make_pending($act, $student, 'justpast', 301);

        $this->run_task();

        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $onboundary['subid']])
        );
        $this->assertSame(
            'analysed',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $justpast['subid']])
        );
    }

    /**
     * A pending record for an activity where DocGuard has since been switched off is
     * skipped and stays pending.
     *
     * @return void
     */
    public function test_phase1_skips_inactive_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $pending = $this->make_pending($act, $student, 'optedout');

        set_config('enabled_cm_' . $act['cm']->id, 0, 'plagiarism_docguard');
        $output = $this->run_task();

        $this->assertStringContainsString('DocGuard is off for cm', $output);
        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $pending['subid']])
        );
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-PHASE1-NO-LICENCE-GATE): an unlicensed site analyses
     * nothing, from this task either.
     *
     * The observer, Phase 2 and the scan_activity adhoc task all called
     * plagiarism_docguard_check_unlock() before analysing anything; Phase 1 never asked, so
     * it was the one processing path on the whole site with no licence gate. Since v1.0.85
     * a site with no Site ID and no API Key is deliberately treated as unlicensed:
     * print_disclosure() stops telling students their work is being checked and
     * settings.php reports "Credentials not configured". Phase 1 carried on regardless,
     * every hour, extracting and storing the full text of student documents on a site that
     * had just told those students it was not doing so.
     *
     * @return void
     */
    public function test_phase1_refuses_to_analyse_on_an_unlicensed_site(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $pending = $this->make_pending($act, $student, 'unlicensed');

        // The exact state settings.php reports as "Credentials not configured". A negative
        // licence verdict is cached so nothing here reaches the network.
        unset_config('siteid', 'plagiarism_docguard');
        unset_config('apikey', 'plagiarism_docguard');
        set_config('unlock_cache_result', 0, 'plagiarism_docguard');
        set_config('unlock_cache_time', time(), 'plagiarism_docguard');

        $output = $this->run_task();

        $this->assertStringContainsString('not licensed for DocGuard', $output);
        $record = $DB->get_record('plagiarism_docguard_sub', ['id' => $pending['subid']]);
        $this->assertSame('pending', $record->status);
        $this->assertNull($record->normtext);
    }

    /**
     * A pending record whose file has been removed from storage is marked as an error
     * so the queue drains and the teacher sees something actionable.
     *
     * @return void
     */
    public function test_phase1_marks_missing_file_as_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid = $this->create_sub([
            'userid'      => $student->id,
            'cmid'        => $act['cm']->id,
            'contextid'   => $act['context']->id,
            'contenthash' => sha1('a file that was deleted'),
            'status'      => 'pending',
            'timecreated' => time() - 600,
        ]);

        $output = $this->run_task();

        $record = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid]);
        $this->assertSame('error', $record->status);
        $this->assertSame('File no longer exists in Moodle file storage.', $record->errormsg);
        $this->assertStringContainsString('marked error', $output);
        $this->assertStringContainsString('Files processed this run: 0', $output);
    }

    /**
     * Phase 1 stops at MAX_PER_RUN records per run and picks up the oldest first, so
     * one busy activity cannot exhaust a cron worker.
     *
     * @return void
     */
    public function test_phase1_batch_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $subids = [];
        for ($i = 0; $i < process_pending::MAX_PER_RUN + 2; $i++) {
            // Oldest first, so the last two created are the ones left behind.
            $pending = $this->make_pending($act, $student, 'batch' . $i, 100000 - $i);
            $subids[$i] = $pending['subid'];
        }

        $this->run_task();

        $analysed = $DB->count_records('plagiarism_docguard_sub', ['status' => 'analysed']);
        $this->assertSame(process_pending::MAX_PER_RUN, $analysed);
        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sub', ['status' => 'pending']));
        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => end($subids)])
        );
    }

    /**
     * Phase 2 does nothing at all unless the administrator has turned the historical
     * backfill on.
     *
     * @return void
     */
    public function test_phase2_is_off_by_default(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'old.docx',
            $this->two_question_docx('backfill')
        );

        $output = $this->run_task();

        $this->assertStringContainsString('historical backfill is disabled', $output);
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * With the backfill on, Phase 2 finds a submission the observer never saw and
     * analyses it.
     *
     * @return void
     */
    public function test_phase2_backfills_untracked_submission(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'old.docx',
            $this->two_question_docx('backfill')
        );

        $output = $this->run_task();

        $this->assertStringContainsString('candidate untracked submission', $output);
        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertNotFalse($record);
        $this->assertSame('analysed', $record->status);
        $this->assertEquals($submissionid, $record->submissionid);
        $this->assertEquals($act['context']->id, $record->contextid);
    }

    /**
     * Phase 2 only looks at activities with an explicit per-activity opt-in, so a
     * submission in an activity that has never been configured is left alone.
     *
     * @return void
     */
    public function test_phase2_skips_activities_that_never_opted_in(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'old.docx',
            $this->two_question_docx('nooptin')
        );

        $output = $this->run_task();

        $this->assertStringContainsString('no activities have DocGuard enabled', $output);
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * A submission that contains nothing DocGuard can read gets a terminal
     * "unsupported" marker so the backfill converges instead of re-examining it hourly.
     *
     * @return void
     */
    public function test_phase2_marks_submission_with_no_supported_files(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file($act['context'], $submissionid, 'notes.txt', 'plain text');

        $output = $this->run_task();

        $this->assertStringContainsString('no supported files, marked unsupported', $output);
        $record = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('unsupported', $record->status);
        // V1.0.88 FIX-DG-BACKFILL-FILE-GRANULARITY: the marker names the file it stands
        // for. It used to be written once per submission with both of these empty, which
        // was the only thing that could satisfy the old (cmid, userid) exclusion; against
        // the contenthash-keyed exclusion an empty hash would match nothing and the
        // poison pill this marker exists to prevent would be back.
        $this->assertSame('notes.txt', $record->filename);
        $this->assertNotSame('', $record->contenthash);

        // Second run: the marker keeps it out of the candidate window.
        $second = $this->run_task();
        $this->assertStringContainsString('no untracked submitted assign files found', $second);
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Group submissions, whose assign_submission rows carry userid = 0, are left to the
     * observer rather than half-handled by the backfill.
     *
     * @return void
     */
    public function test_phase2_ignores_group_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act = $this->create_docguard_assign();
        $submissionid = $this->create_assign_submission($act['assign'], 0);
        $DB->set_field('assign_submission', 'groupid', 7, ['id' => $submissionid]);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'group.docx',
            $this->two_question_docx('group')
        );

        $output = $this->run_task();

        $this->assertStringContainsString('no untracked submitted assign files found', $output);
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-BACKFILL-FILE-GRANULARITY): an unreadable file no longer
     * hides a readable one submitted alongside it.
     *
     * The old marker was written once per submission with an empty content hash, so a
     * student who attached a .txt and a .docx had the whole submission marked
     * "unsupported" the moment the .txt was examined, and the .docx was excluded from the
     * candidate window for ever by the (cmid, userid) key. Per-file markers end that: the
     * .txt is marked and the .docx is analysed, in the same run.
     *
     * @return void
     */
    public function test_phase2_marks_one_file_unsupported_and_still_analyses_the_other(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid, 2);
        $this->store_submission_file($act['context'], $submissionid, 'notes.txt', 'plain text');
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'real.docx',
            $this->two_question_docx('mixed')
        );

        $this->run_task();

        $marker = $DB->get_record('plagiarism_docguard_sub', ['filename' => 'notes.txt']);
        $this->assertSame('unsupported', $marker->status);

        $analysed = $DB->get_record('plagiarism_docguard_sub', ['filename' => 'real.docx']);
        $this->assertNotFalse($analysed, 'The readable document must still be analysed.');
        $this->assertSame('analysed', $analysed->status);

        // Converges: nothing is left as a candidate.
        $this->assertStringContainsString(
            'no untracked submitted assign files found',
            $this->run_task()
        );
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-BACKFILL-FILE-GRANULARITY): a student's second document
     * is backfilled even when their first one is already tracked.
     *
     * The Phase 2 candidate query used to select {assignsubmission_file} rows — one row per
     * SUBMISSION, not per file — and exclude a row as soon as its NOT EXISTS on
     * (cmid, userid) failed, i.e. as soon as the student had ANY DocGuard record in that
     * activity. mod_assign lets a student attach several documents to one submission
     * (assignsubmission_file's maxfilesubmissions defaults to 20), so a student whose first
     * document had been analysed — by the observer, by a teacher, or by an earlier run of
     * this phase that hit its per-run cap between the two files — was excluded from the
     * window entirely and their second document was never analysed. Its badge said
     * "Plagiarism Check Pending" indefinitely, with nothing anywhere saying why. The
     * exclusion is now keyed on (cmid, userid, contenthash), which is the key
     * analyse_and_store() itself uses.
     *
     * @return void
     */
    public function test_phase2_backfills_a_second_file_for_the_same_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid, 2);
        $tracked = $this->store_submission_file(
            $act['context'],
            $submissionid,
            'one.docx',
            $this->two_question_docx('one')
        );
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'two.docx',
            $this->two_question_docx('two')
        );

        // Only the first file has a record.
        $this->create_sub([
            'userid'      => $student->id,
            'cmid'        => $act['cm']->id,
            'contextid'   => $act['context']->id,
            'filename'    => 'one.docx',
            'contenthash' => $tracked->get_contenthash(),
        ]);

        $output = $this->run_task();

        $this->assertStringContainsString("untracked file 'two.docx'", $output);
        $second = $DB->get_record('plagiarism_docguard_sub', ['filename' => 'two.docx']);
        $this->assertNotFalse($second);
        $this->assertSame('analysed', $second->status);
        $this->assertEquals($student->id, $second->userid);
        $this->assertEquals($submissionid, $second->submissionid);

        // The already-tracked file is not re-analysed, and no third record appears.
        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sub'));

        // And the phase converges: a second run finds nothing left to do.
        $this->assertStringContainsString(
            'no untracked submitted assign files found',
            $this->run_task()
        );
    }

    /**
     * Phase 2 respects the budget Phase 1 has already spent: with the per-run cap
     * already consumed, no backfill work is attempted at all.
     *
     * @return void
     */
    public function test_phase2_is_skipped_when_phase1_used_the_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();
        set_config('enablebackfill', 1, 'plagiarism_docguard');

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        for ($i = 0; $i < process_pending::MAX_PER_RUN; $i++) {
            $this->make_pending($act, $student, 'p1x' . $i, 100000 - $i);
        }
        $backfilled = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $submissionid = $this->create_assign_submission($act['assign'], (int)$backfilled->id);
        $this->create_assignsubmission_file($act['assign'], $submissionid);
        $this->store_submission_file(
            $act['context'],
            $submissionid,
            'late.docx',
            $this->two_question_docx('late')
        );

        $output = $this->run_task();

        $this->assertStringContainsString(
            'Files processed this run: '
                . process_pending::MAX_PER_RUN,
            $output
        );
        $this->assertStringNotContainsString('candidate untracked submission', $output);
        $this->assertSame(
            0,
            $DB->count_records(
                'plagiarism_docguard_sub',
                ['userid' => $backfilled->id]
                )
        );
    }

    /**
     * Phase 2 does nothing when Moodle's own plagiarism subsystem is off, even though
     * DocGuard's own switch is on.
     *
     * @return void
     */
    public function test_task_stops_when_core_plagiarism_is_off(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $pending = $this->make_pending($act, $student, 'coreoff');

        set_config('enableplagiarism', 0);
        $output = $this->run_task();

        $this->assertStringContainsString('disabled site-wide', $output);
        $this->assertSame(
            'pending',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['id' => $pending['subid']])
        );
    }

    /**
     * The task reports a name from the language pack rather than a missing-string
     * placeholder.
     *
     * @return void
     */
    public function test_get_name(): void {
        $this->resetAfterTest();
        $name = (new process_pending())->get_name();
        $this->assertNotEmpty($name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
