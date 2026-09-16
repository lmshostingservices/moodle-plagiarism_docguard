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

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Tests for the analyse_submission adhoc task.
 *
 * V1.0.92. This task holds the work the assessable_submitted observer used to do inline.
 * The observer tests already cover the end-to-end path; these cover the things that are
 * specific to being a queued task — deduplication, and re-evaluating the gates at
 * execution time rather than trusting the state that existed when it was queued.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\task\analyse_submission
 */
final class task_analyse_submission_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Queueing the same submission twice leaves one task.
     *
     * A student who double-clicks submit, or a teacher who re-saves a submission, must not
     * cost two full extractions of the same files.
     *
     * @return void
     */
    public function test_queueing_the_same_submission_twice_is_deduplicated(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act = $this->create_docguard_assign();

        analyse_submission::queue((int)$act['cm']->id, 7, 42, (int)$act['context']->id);
        analyse_submission::queue((int)$act['cm']->id, 7, 42, (int)$act['context']->id);

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(analyse_submission::class));

        // A different submission is different work.
        analyse_submission::queue((int)$act['cm']->id, 7, 43, (int)$act['context']->id);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(analyse_submission::class));
    }

    /**
     * The queued task carries the activity, author, submission and context.
     *
     * @return void
     */
    public function test_queued_task_carries_its_payload(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act = $this->create_docguard_assign();
        analyse_submission::queue((int)$act['cm']->id, 7, 42, (int)$act['context']->id);

        $tasks = \core\task\manager::get_adhoc_tasks(analyse_submission::class);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();

        $this->assertEquals($act['cm']->id, (int)$data->cmid);
        $this->assertEquals(7, (int)$data->userid);
        $this->assertEquals(42, (int)$data->submissionid);
        $this->assertEquals($act['context']->id, (int)$data->contextid);
    }

    /**
     * Malformed task data is reported and ignored rather than throwing.
     *
     * A task that throws is retried by core with backoff, so a permanently broken payload
     * would keep a dead job in the queue indefinitely.
     *
     * @return void
     */
    public function test_malformed_payload_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();

        $task = new analyse_submission();
        $task->set_custom_data((object)['cmid' => 0, 'userid' => 0, 'submissionid' => 0, 'contextid' => 0]);

        ob_start();
        $task->execute();
        $output = (string)ob_get_clean();

        $this->assertStringContainsString('malformed task data', $output);

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * Switching DocGuard off between queueing and execution stops the analysis.
     *
     * The task can run minutes or hours after the submission. Analysis that was authorised
     * when it was queued is not authorised now, so the gates are re-read at execution time
     * — the same rule scan_activity follows.
     *
     * @return void
     */
    public function test_site_wide_disable_between_queue_and_run_stops_analysis(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        // No real file is needed: the gate is read before the task touches file storage,
        // which is the whole point of the assertion.
        analyse_submission::queue(
            (int)$act['cm']->id,
            (int)$student->id,
            42,
            (int)$act['context']->id
        );

        $tasks  = \core\task\manager::get_adhoc_tasks(analyse_submission::class);
        $queued = reset($tasks);

        // The administrator switches the plugin off after the submission was made.
        set_config('enabled', 0, 'plagiarism_docguard');

        ob_start();
        $queued->execute();
        $output = (string)ob_get_clean();

        $this->assertStringContainsString('disabled site-wide', $output);

        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
    }
}
