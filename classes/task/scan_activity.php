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

/**
 * Adhoc task: analyse one activity's unprocessed submissions, in bounded batches.
 *
 * v1.0.80. Replaces the synchronous loop that used to run inside report.php's
 * "Scan & Analyse Unprocessed Submissions" button.
 *
 * Why an adhoc task and not "just make the loop faster": the work is unbounded by nature
 * — it is however many submissions the activity has — and each file costs an external
 * process (pdftotext or Ghostscript, each with a 60-second timeout) plus scoring. There
 * is no version of that which reliably finishes inside max_execution_time for a real
 * class, and a web request that is killed mid-loop leaves half the class analysed with no
 * record of where it stopped. Moodle's adhoc queue is built for precisely this shape of
 * job: it runs under cron with no browser waiting, it is retried by core if the process
 * dies, and a task can re-queue itself to continue.
 *
 * Bounding strategy, belt and braces:
 *   - at most BATCH_SIZE files per run, and
 *   - at most TIME_BUDGET seconds of wall clock per run,
 * whichever comes first; if work remains, the task queues a continuation of itself and
 * returns cleanly. Progress is therefore never lost, and one enormous activity cannot
 * monopolise the cron worker.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_activity extends \core\task\adhoc_task {
    /** Maximum files analysed in a single run of this task. */
    const BATCH_SIZE = 20;

    /** Maximum wall-clock seconds spent in a single run before deferring the rest. */
    const TIME_BUDGET = 240;

    /**
     * Name shown for this task in the adhoc task queue and task logs.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('scan_activity_task', 'plagiarism_docguard');
    }

    /**
     * Queue a scan for one course module, unless one is already queued for it.
     *
     * The duplicate check matters: the button is a plain link with a confirm dialog, and a
     * teacher watching a slow backlog will click it again. Without this, each click would
     * add another full pass over the same activity.
     *
     * @param int $cmid Course module id of the activity to scan.
     * @param int $contextid Module context id.
     * @param int $userid Who asked for it (for the task's audit trail).
     * @return bool True if a new task was queued, false if one was already pending.
     */
    public static function queue_for_cm(int $cmid, int $contextid, int $userid): bool {
        $existing = \core\task\manager::get_adhoc_tasks(self::class);
        foreach ($existing as $task) {
            $data = $task->get_custom_data();
            if (!empty($data->cmid) && (int)$data->cmid === $cmid) {
                return false;
            }
        }

        $task = new self();
        $task->set_custom_data(
            (object)[
                'cmid'      => $cmid,
                'contextid' => $contextid,
                'pass'      => 1,
                ]
        );
        $task->set_userid($userid);
        /* $checkforexisting = false: the duplicate check above is by cmid, which is what
           "already queued for this activity" actually means. Core's own check compares
           the whole custom-data blob, so it would not catch a second click on an activity
           whose queued task is a later pass. */
        \core\task\manager::queue_adhoc_task($task, false);
        return true;
    }

    /**
     * Analyse the queued activity's unprocessed submissions in one bounded batch.
     *
     * Processes at most BATCH_SIZE files and stops after TIME_BUDGET seconds, re-queueing
     * itself for the next pass if work remains, so a large class cannot exhaust the
     * cron worker's execution time.
     *
     * @return void
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
        // V1.0.92: only lib.php is required explicitly. It holds global functions and is
        // not autoloadable; observer, analyser and extractor are classes under classes/,
        // which Moodle's autoloader resolves on first use. Requiring them by hand was
        // redundant and breaks if a class is ever moved or renamed.

        $data      = $this->get_custom_data();
        $cmid      = (int)($data->cmid ?? 0);
        $contextid = (int)($data->contextid ?? 0);
        if (!$cmid || !$contextid) {
            mtrace('DocGuard scan_activity: malformed task data — nothing to do.');
            return;
        }

        // Re-check the gates at execution time, not just at queue time: the task may run
        // minutes or hours after the click, and an administrator or teacher may have
        // switched DocGuard off in between. Analysis that was authorised then is not
        // authorised now.
        if (!\plagiarism_docguard_is_enabled()) {
            mtrace("DocGuard scan_activity: cm {$cmid} — plugin is disabled site-wide, abandoning scan.");
            return;
        }
        if (!\plagiarism_docguard_is_cm_active($cmid)) {
            mtrace("DocGuard scan_activity: cm {$cmid} — DocGuard is not enabled for this activity, abandoning scan.");
            return;
        }
        if (!\plagiarism_docguard_check_unlock()) {
            mtrace("DocGuard scan_activity: cm {$cmid} — plugin is not unlocked, abandoning scan.");
            return;
        }

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            mtrace("DocGuard scan_activity: cm {$cmid} no longer exists.");
            return;
        }
        $assign = $DB->get_record('assign', ['id' => $cm->instance]);
        if (!$assign) {
            mtrace("DocGuard scan_activity: assign instance for cm {$cmid} no longer exists.");
            return;
        }

        $fs        = get_file_storage();
        $started   = time();
        $processed = 0;
        $errors    = 0;
        $deferred  = false;

        // Oldest first and keyed on id, so the ordering is stable across runs even as
        // records are created underneath us.
        $submissions = $DB->get_records(
            'assign_submission',
            [
                'assignment' => $assign->id,
                'latest'     => 1,
                'status'     => 'submitted',
                ],
            'id ASC'
        );

        foreach ($submissions as $submission) {
            if ($processed >= self::BATCH_SIZE || (time() - $started) >= self::TIME_BUDGET) {
                $deferred = true;
                break;
            }

            $files = $fs->get_area_files(
                $contextid,
                'assignsubmission_file',
                'submission_files',
                (int)$submission->id,
                'timemodified DESC',
                false
            );

            foreach ($files as $file) {
                if ($processed >= self::BATCH_SIZE || (time() - $started) >= self::TIME_BUDGET) {
                    $deferred = true;
                    break 2;
                }
                if ($file->is_directory()) {
                    continue;
                }
                if (!\plagiarism_docguard\extractor::is_supported($file)) {
                    continue;
                }

                // Already tracked? Nothing to do — this is what makes the task safely
                // resumable and safely repeatable: each run simply skips what is done.
                if (
                    $DB->record_exists(
                        'plagiarism_docguard_sub',
                        [
                            'cmid'        => $cmid,
                            'userid'      => (int)$submission->userid,
                            'contenthash' => $file->get_contenthash(),
                            ]
                    )
                ) {
                    continue;
                }

                try {
                    \plagiarism_docguard\observer::analyse_and_store(
                        $file,
                        $cmid,
                        (int)$submission->userid,
                        (int)$submission->id,
                        $contextid
                    );
                    $processed++;
                } catch (\Throwable $e) {
                    // One bad file must not abandon the rest of the class. The record is
                    // left for process_pending Phase 1 to retry or for the teacher to
                    // re-analyse from the report.
                    $errors++;
                    mtrace(
                        "DocGuard scan_activity: cm {$cmid} user {$submission->userid} file '"
                            . $file->get_filename() . "' — " . $e->getMessage()
                    );
                }
            }
        }

        mtrace(
            "DocGuard scan_activity: cm {$cmid} — analysed {$processed} file(s), {$errors} error(s)"
                . ($deferred ? ', budget reached — queuing continuation.' : ', activity complete.')
        );

        if ($deferred) {
            // Re-queue rather than pushing on. queue_for_cm() would refuse (this task is
            // still "running" from the queue's point of view), so build the continuation
            // directly.
            $next = new self();
            $next->set_custom_data((object)[
                'cmid'      => $cmid,
                'contextid' => $contextid,
                // Distinct 'pass' number, and $checkforexisting = false below: this task's
                // own row is still in the queue while it executes, so an identical
                // custom-data blob with the dedupe flag on would silently discard the
                // continuation and strand the backlog half-done. Progress itself is
                // derived from the database (records that exist are skipped), not from
                // this counter.
                'pass'      => (int)($data->pass ?? 1) + 1,
            ]);
            $next->set_userid($this->get_userid());
            \core\task\manager::queue_adhoc_task($next, false);
        }
    }
}
