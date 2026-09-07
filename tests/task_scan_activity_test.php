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
 * Integration tests for the adhoc "Scan & Analyse Unprocessed Submissions" task.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\task\scan_activity
 */
final class task_scan_activity_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Execute a scan_activity task with the given custom data and return its output.
     *
     * @param array $data Custom data for the task.
     * @return string The captured mtrace output.
     */
    private function run_with(array $data): string {
        $task = new scan_activity();
        $task->set_custom_data((object)$data);
        ob_start();
        $task->execute();
        return (string)ob_get_clean();
    }

    /**
     * Queueing is idempotent per activity: a second click while one is pending does not
     * add a second full pass.
     *
     * @return void
     */
    public function test_queue_for_cm_is_deduplicated(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act  = $this->create_docguard_assign();
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(
            scan_activity::queue_for_cm(
                (int)$act['cm']->id,
                (int)$act['context']->id,
                (int)$user->id
                )
        );
        $this->assertFalse(
            scan_activity::queue_for_cm(
                (int)$act['cm']->id,
                (int)$act['context']->id,
                (int)$user->id
                )
        );
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(scan_activity::class));

        // A different activity is a different job.
        $other = $this->create_docguard_assign();
        $this->assertTrue(
            scan_activity::queue_for_cm(
                (int)$other['cm']->id,
                (int)$other['context']->id,
                (int)$user->id
                )
        );
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(scan_activity::class));
    }

    /**
     * The queued task carries the activity, the context and the pass number, and the
     * user who asked for it.
     *
     * @return void
     */
    public function test_queued_task_custom_data(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act  = $this->create_docguard_assign();
        $user = $this->getDataGenerator()->create_user();
        scan_activity::queue_for_cm((int)$act['cm']->id, (int)$act['context']->id, (int)$user->id);

        $tasks = \core\task\manager::get_adhoc_tasks(scan_activity::class);
        $task  = reset($tasks);
        $data  = $task->get_custom_data();
        $this->assertEquals($act['cm']->id, $data->cmid);
        $this->assertEquals($act['context']->id, $data->contextid);
        $this->assertEquals(1, $data->pass);
        $this->assertEquals($user->id, $task->get_userid());
    }

    /**
     * A run analyses every submitted file that has no record yet, and skips the ones
     * that already have one.
     *
     * @return void
     */
    public function test_execute_analyses_untracked_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act = $this->create_docguard_assign();
        $alice = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $bob   = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $asub = $this->create_assign_submission($act['assign'], (int)$alice->id);
        $this->store_submission_file(
            $act['context'],
            $asub,
            'alice.docx',
            $this->two_question_docx('alice')
        );
        $bsub = $this->create_assign_submission($act['assign'], (int)$bob->id);
        $bfile = $this->store_submission_file(
            $act['context'],
            $bsub,
            'bob.docx',
            $this->two_question_docx('bob')
        );

        // Bob is already tracked and must be skipped.
        $this->create_sub(['userid' => $bob->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'filename' => 'bob.docx',
            'contenthash' => $bfile->get_contenthash()]);

        $output = $this->run_with([
            'cmid' => (int)$act['cm']->id,
            'contextid' => (int)$act['context']->id,
            'pass' => 1,
        ]);

        $this->assertStringContainsString('analysed 1 file(s), 0 error(s)', $output);
        $this->assertStringContainsString('activity complete', $output);
        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sub'));
        $this->assertSame(
            'analysed',
            $DB->get_field('plagiarism_docguard_sub', 'status', ['userid' => $alice->id])
        );
    }

    /**
     * Unsubmitted drafts are not scanned.
     *
     * @return void
     */
    public function test_execute_ignores_draft_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $sub = $this->create_assign_submission($act['assign'], (int)$student->id);
        $DB->set_field('assign_submission', 'status', 'draft', ['id' => $sub]);
        $this->store_submission_file(
            $act['context'],
            $sub,
            'draft.docx',
            $this->two_question_docx('draft')
        );

        $this->run_with(['cmid' => (int)$act['cm']->id, 'contextid' => (int)$act['context']->id]);

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Files DocGuard cannot read are skipped without leaving a record.
     *
     * @return void
     */
    public function test_execute_skips_unsupported_files(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $sub = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->store_submission_file($act['context'], $sub, 'notes.txt', 'plain text');

        $output = $this->run_with([
            'cmid' => (int)$act['cm']->id,
            'contextid' => (int)$act['context']->id,
        ]);

        $this->assertStringContainsString('analysed 0 file(s)', $output);
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * The gates are re-checked at execution time, not only when the button was pressed.
     *
     * @return void
     */
    public function test_execute_abandons_when_gates_are_closed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $sub = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->store_submission_file(
            $act['context'],
            $sub,
            'late.docx',
            $this->two_question_docx('late')
        );
        $data = ['cmid' => (int)$act['cm']->id, 'contextid' => (int)$act['context']->id];

        set_config('enabled', 0, 'plagiarism_docguard');
        $this->assertStringContainsString('disabled site-wide', $this->run_with($data));

        set_config('enabled', 1, 'plagiarism_docguard');
        set_config('enabled_cm_' . $act['cm']->id, 0, 'plagiarism_docguard');
        $this->assertStringContainsString('not enabled for this activity', $this->run_with($data));

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Malformed or missing task data is reported and does nothing.
     *
     * @return void
     */
    public function test_execute_with_malformed_data(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $this->assertStringContainsString('malformed task data', $this->run_with([]));
        $this->assertStringContainsString('malformed task data', $this->run_with(['cmid' => 5]));
    }

    /**
     * An activity deleted between the click and the run is reported, not fatal.
     *
     * @return void
     */
    public function test_execute_when_activity_has_gone(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act = $this->create_docguard_assign();
        $cmid = (int)$act['cm']->id;
        $contextid = (int)$act['context']->id;
        course_delete_module($cmid);

        $output = $this->run_with(['cmid' => $cmid, 'contextid' => $contextid]);
        $this->assertStringContainsString('no longer exists', $output);
    }

    /**
     * The run stops at BATCH_SIZE files, queues a continuation with the next pass
     * number, and that continuation finishes the remainder.
     *
     * @return void
     */
    public function test_batch_limit_defers_the_remainder(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $sub = $this->create_assign_submission($act['assign'], (int)$student->id);
        $total = scan_activity::BATCH_SIZE + 2;
        for ($i = 0; $i < $total; $i++) {
            $this->store_submission_file(
                $act['context'],
                $sub,
                'file' . $i . '.docx',
                $this->two_question_docx('doc' . $i)
            );
        }

        $data = ['cmid' => (int)$act['cm']->id, 'contextid' => (int)$act['context']->id, 'pass' => 1];
        $output = $this->run_with($data);

        $this->assertStringContainsString('budget reached — queuing continuation', $output);
        $this->assertSame(scan_activity::BATCH_SIZE, $DB->count_records('plagiarism_docguard_sub'));

        $queued = \core\task\manager::get_adhoc_tasks(scan_activity::class);
        $this->assertCount(1, $queued);
        $continuation = reset($queued);
        $this->assertEquals(2, $continuation->get_custom_data()->pass);

        ob_start();
        $continuation->execute();
        $second = (string)ob_get_clean();
        $this->assertStringContainsString('activity complete', $second);
        $this->assertSame($total, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * The task reports a name from the language pack.
     *
     * @return void
     */
    public function test_get_name(): void {
        $this->resetAfterTest();
        $name = (new scan_activity())->get_name();
        $this->assertNotEmpty($name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
