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

// FIX-DG-OBSERVER-LIB-INCLUDE (v1.0.20): Moodle autoloads classes/observer.php
// via PSR-4 when the event fires. lib.php (which defines the global helper
// functions plagiarism_docguard_is_cm_active() and
// plagiarism_docguard_check_unlock()) is NOT automatically included before
// this class file is loaded, causing "Call to undefined function" even when
// the \ global-namespace prefix is correct. Fix: explicitly require lib.php
// before any code that calls those helpers.
require_once(__DIR__ . '/../lib.php');

/**
 * Event observer for DocGuard.
 * Fires when a student submits an assignment and triggers document analysis.
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class observer {
    /**
     * Called when a student submits (or resubmits) an assignment.
     * Locates the submitted PDF/DOCX files and triggers analysis.
     */
    public static function on_assessable_submitted(\mod_assign\event\assessable_submitted $event): void {
        global $DB;

        $cmid         = (int)$event->contextinstanceid;
        $userid       = (int)$event->userid;
        $submissionid = (int)($event->other['submissionid'] ?? 0);

        // FIX-DG-OBSERVER-NAMESPACE (v1.0.11): observer.php is in namespace
        // plagiarism_docguard. PHP does NOT fall back to the global namespace for
        // user-defined functions — only for PHP built-ins. Bare calls to
        // plagiarism_docguard_is_cm_active(), plagiarism_docguard_check_unlock(),
        // and get_file_storage() were resolved as
        // plagiarism_docguard\plagiarism_docguard_is_cm_active() etc., which do not
        // exist, causing "Call to undefined function" on every student submission.
        // Fix: prefix all three with \ to force global namespace resolution.
        if (!\plagiarism_docguard_is_cm_active($cmid)) {
            return;
        }
        if (!\plagiarism_docguard_check_unlock()) {
            return;
        }

        // Write close to prevent session locking during API/heavy work.
        \core\session\manager::write_close();

        // Retrieve all files submitted in this assignment submission.
        $context = \context_module::instance($cmid);
        $fs      = \get_file_storage();
        $files   = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'filename',
            false
        );

        foreach ($files as $file) {
            if (!extractor::is_supported($file)) {
                continue;
            }
            self::analyse_and_store($file, $cmid, $userid, $submissionid, $context->id);
        }
    }

    /**
     * Run analysis on one file and persist results.
     */
    public static function analyse_and_store(
        \stored_file $file,
        int $cmid,
        int $userid,
        int $submissionid,
        int $contextid
    ): void {
        global $DB;

        $contenthash = $file->get_contenthash();
        $filetype    = extractor::filetype($file);

        // Check for existing record by contenthash + cmid + userid.
        $existing = $DB->get_record('plagiarism_docguard_sub', [
            'userid'      => $userid,
            'cmid'        => $cmid,
            'contenthash' => $contenthash,
        ]);
        if ($existing && $existing->status === 'analysed') {
            return; // Already analysed — do not re-analyse unchanged file.
        }

        $now = time();

        // Upsert the submission record in pending state.
        $sub = new \stdClass();
        $sub->userid        = $userid;
        $sub->cmid          = $cmid;
        $sub->contextid     = $contextid;
        $sub->submissionid  = $submissionid;
        $sub->filename      = $file->get_filename();
        $sub->filetype      = $filetype;
        $sub->contenthash   = $contenthash;
        $sub->status        = 'pending';
        $sub->timemodified  = $now;
        $sub->section_count     = 0;
        $sub->overall_riskscore = 0;
        $sub->overall_risklevel = 'low';

        if ($existing) {
            $sub->id = $existing->id;
            // FIX-DG-TIMECREATED-RESET (v1.0.70): Do NOT reset timecreated on retry.
            // Pre-fix: $sub->timecreated = $now was always set, even on updates. Every
            // time process_pending retried a stuck-pending record, it reset timecreated
            // to the retry time. The task's 5-min grace window
            // ("status='pending' AND timecreated < now-300") was then perpetually reset,
            // excluding the record from the next run and causing infinite retry loops
            // with no progress and no error. Fix: preserve the original timecreated by
            // omitting it from the update stdClass — Moodle update_record() only updates
            // columns present on the object.
            $DB->update_record('plagiarism_docguard_sub', $sub);
            $subid = $existing->id;
            // Remove old section records before re-analysing.
            $DB->delete_records('plagiarism_docguard_sec', ['subid' => $subid]);
        } else {
            $sub->timecreated = $now; // Only set timecreated on fresh insert.
            $subid = $DB->insert_record('plagiarism_docguard_sub', $sub);
        }

        // Run full analysis — wrapped in try/catch(\Throwable) so any uncaught
        // PHP \Error (e.g. \TypeError from scoring math on unusual PDF content)
        // that escapes analyser::analyse_file() is recorded as status='error'
        // rather than leaving the record permanently at status='pending'.
        // FIX-DG-ANALYSE-CATCH (v1.0.70).
        try {
            $result = analyser::analyse_file($file, $cmid, $userid, $submissionid);
        } catch (\Throwable $e) {
            $err = new \stdClass();
            $err->id          = $subid;
            $err->status      = 'error';
            $err->errormsg    = $e->getMessage();
            $err->timemodified = time();
            $DB->update_record('plagiarism_docguard_sub', $err);
            return;
        }

        // Persist results.
        $update = new \stdClass();
        $update->id                 = $subid;
        $update->status             = $result['status'];
        $update->overall_riskscore  = $result['overall_riskscore'];
        $update->overall_risklevel  = $result['overall_risklevel'];
        $update->section_count      = count($result['sections']);
        $update->analysisjson       = $result['analysisjson'];
        $update->normtext           = $result['normtext'];
        $update->errormsg           = $result['error'];
        $update->timemodified       = time();
        $DB->update_record('plagiarism_docguard_sub', $update);

        // Persist per-section records.
        foreach ($result['sections'] as $sec) {
            $rec = new \stdClass();
            $rec->subid         = $subid;
            $rec->userid        = $userid;
            $rec->cmid          = $cmid;
            $rec->section_num   = (int)($sec['num'] ?? 0);
            $rec->section_label = substr((string)($sec['label'] ?? ''), 0, 128);
            $rec->section_text  = substr((string)($sec['text']  ?? ''), 0, 65000);
            $rec->wordcount     = (int)($sec['wordcount'] ?? 0);
            $rec->riskscore     = (float)($sec['riskscore'] ?? 0);
            $rec->risklevel     = (string)($sec['risklevel'] ?? 'low');
            $rec->signalsjson   = json_encode($sec['signals'] ?? []);
            $rec->timemodified  = time();
            $DB->insert_record('plagiarism_docguard_sec', $rec);
        }
    }
}
