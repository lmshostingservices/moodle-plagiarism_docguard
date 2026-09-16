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
 * Adhoc task: analyse the files of one assignment submission.
 *
 * V1.0.92. Takes the extraction and scoring work out of the assessable_submitted event
 * observer.
 *
 * Why: event observers run inline, inside the student's own submit request. The work this
 * task now owns is a per-file external process — pdftotext or Ghostscript, each with a
 * 60-second timeout — plus signal scoring and several DB writes. A student submitting two
 * PDFs on a host without poppler could therefore sit on a spinning submit button for two
 * minutes, and a submission that exceeded max_execution_time left the record half-written
 * with the student seeing a server error for work that had actually been accepted.
 *
 * The observer now does only what an observer should: read the event payload, check the
 * gates, and queue this task. Analysis happens under cron with no browser waiting, is
 * retried by core if the worker dies, and the badge shows "Pending" in the meantime —
 * exactly as it already does for every other asynchronous path in this plugin.
 *
 * Deliberately not bounded like scan_activity: the unit of work here is one submission,
 * which is a handful of files at most, not a whole class.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyse_submission extends \core\task\adhoc_task {
    /**
     * Name shown for this task in the adhoc task queue and task logs.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('analyse_submission_task', 'plagiarism_docguard');
    }

    /**
     * Queue analysis of one submission.
     *
     * @param int $cmid Course module id of the assignment.
     * @param int $userid The author of the work (not necessarily the submitter).
     * @param int $submissionid The assign_submission id holding the files.
     * @param int $contextid Module context id.
     * @return void
     */
    public static function queue(int $cmid, int $userid, int $submissionid, int $contextid): void {
        $task = new self();
        $task->set_custom_data(
            (object)[
                'cmid'         => $cmid,
                'userid'       => $userid,
                'submissionid' => $submissionid,
                'contextid'    => $contextid,
            ]
        );
        /* $checkforexisting = true: core compares the whole custom-data blob, and for this
           task the blob IS the identity of the work — same cm, same user, same submission.
           A student who clicks submit twice, or an editing teacher who re-saves a
           submission, must not queue the same extraction twice. */
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Analyse every supported file in the queued submission.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;

        require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');

        $data         = $this->get_custom_data();
        $cmid         = (int)($data->cmid ?? 0);
        $userid       = (int)($data->userid ?? 0);
        $submissionid = (int)($data->submissionid ?? 0);
        $contextid    = (int)($data->contextid ?? 0);

        if (!$cmid || !$userid || !$submissionid || !$contextid) {
            mtrace('DocGuard analyse_submission: malformed task data — nothing to do.');
            return;
        }

        // Re-check the gates at execution time, not only at queue time. This task may run
        // minutes after the submission, and an administrator may have switched DocGuard
        // off in between; analysis that was authorised then is not authorised now. Mirrors
        // the same re-check in scan_activity.
        if (!\plagiarism_docguard_is_enabled()) {
            mtrace("DocGuard analyse_submission: cm {$cmid} — plugin disabled site-wide, skipping.");
            return;
        }
        if (!\plagiarism_docguard_is_cm_active($cmid)) {
            mtrace("DocGuard analyse_submission: cm {$cmid} — DocGuard not active for this activity, skipping.");
            return;
        }
        if (!\plagiarism_docguard_check_unlock()) {
            mtrace("DocGuard analyse_submission: cm {$cmid} — plugin not unlocked, skipping.");
            return;
        }

        $fs    = get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'filename',
            false
        );

        if (empty($files)) {
            mtrace("DocGuard analyse_submission: submission {$submissionid} — no files found.");
            return;
        }

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if (!\plagiarism_docguard\extractor::is_supported($file)) {
                continue;
            }
            try {
                \plagiarism_docguard\observer::analyse_and_store(
                    $file,
                    $cmid,
                    $userid,
                    $submissionid,
                    $contextid
                );
            } catch (\Throwable $e) {
                // One unreadable file must not fail the task and trigger core's retry
                // backoff for the whole submission. analyse_and_store() already records
                // the failure against the record as status='error'.
                mtrace(
                    "DocGuard analyse_submission: cm {$cmid} user {$userid} file '"
                        . $file->get_filename() . "' — " . $e->getMessage()
                );
            }
        }
    }
}
