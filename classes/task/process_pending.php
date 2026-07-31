<?php

namespace plagiarism_docguard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: process pending and untracked DocGuard submissions.
 *
 * ADD-DG-PROCESS-PENDING (v1.0.69):
 *
 * The v1.0.67 performance fix removed synchronous analyse_and_store() from
 * get_links(), relying on the event observer and "scheduled task" to process
 * files. However the only existing scheduled task (cleanup) merely prunes old
 * normtext — it does not run analysis. This left two classes of submissions
 * permanently stuck at "Plagiarism Check Pending":
 *
 *   Case A — No DB record: plugin installed/upgraded after students already
 *   submitted. The assessable_submitted observer never fired, so no record
 *   exists in plagiarism_docguard_sub. get_links() renders the grey Pending
 *   badge (subid=false) and there is no teacher-reachable re-analyse path.
 *
 *   Case B — DB record with status='pending': observer fired and inserted a
 *   pending record but analyse_and_store() failed (PDF extraction error, API
 *   timeout, etc.). Status is never updated.
 *
 * This task addresses both cases:
 *   Phase 1 — re-analyse existing status='pending' records older than 5 min
 *              (grace window for freshly-submitted files to complete normally).
 *   Phase 2 — scan all DocGuard-enabled assign CMs for student-submitted files
 *              that have no plagiarism_docguard_sub record at all, insert a
 *              pending record and run analysis.
 *
 * Capped at MAX_PER_RUN files per cron execution to avoid PHP timeouts.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_pending extends \core\task\scheduled_task {

    /** Maximum files analysed per single cron run. */
    const MAX_PER_RUN = 15;

    public function get_name(): string {
        return get_string('process_pending_task', 'plagiarism_docguard');
    }

    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

        $processed = 0;

        // ── Phase 1: Re-analyse existing status='pending' DB records ─────────
        // Give a 5-minute grace window so a freshly-submitted file's observer
        // pipeline has time to complete before we interfere.
        $grace = time() - 300;
        $pending_subs = $DB->get_records_select(
            'plagiarism_docguard_sub',
            "status = 'pending' AND timecreated < :grace",
            ['grace' => $grace],
            'timecreated ASC',
            '*',
            0,
            self::MAX_PER_RUN
        );

        foreach ($pending_subs as $sub) {
            if ($processed >= self::MAX_PER_RUN) {
                break;
            }

            $filerecord = $DB->get_record_sql(
                'SELECT id FROM {files} WHERE contenthash = ? AND filename != ? ORDER BY id DESC LIMIT 1',
                [$sub->contenthash, '.']
            );

            if (!$filerecord) {
                // Original file was deleted from Moodle file storage.
                $upd             = new \stdClass();
                $upd->id         = $sub->id;
                $upd->status     = 'error';
                $upd->errormsg   = 'File no longer exists in Moodle file storage.';
                $upd->timemodified = time();
                $DB->update_record('plagiarism_docguard_sub', $upd);
                mtrace("DocGuard process_pending [P1]: sub {$sub->id} — contenthash {$sub->contenthash} not found in {files}, marked error.");
                continue;
            }

            $fs   = get_file_storage();
            $file = $fs->get_file_by_id($filerecord->id);
            if (!$file || $file->is_directory()) {
                continue;
            }

            mtrace("DocGuard process_pending [P1]: re-analysing sub {$sub->id} user {$sub->userid} cmid {$sub->cmid} file {$sub->filename}");

            try {
                \plagiarism_docguard\observer::analyse_and_store(
                    $file,
                    (int)$sub->cmid,
                    (int)$sub->userid,
                    (int)$sub->submissionid,
                    (int)$sub->contextid
                );
                $processed++;
            } catch (\Throwable $e) {
                mtrace("DocGuard process_pending [P1]: ERROR sub {$sub->id} — " . $e->getMessage());
            }
        }

        // ── Phase 2: Scan for untracked submitted files ───────────────────────
        // When DocGuard is installed/upgraded after students have already
        // submitted, the observer never fired and there is no DB record at all.
        // Detect these by cross-referencing assignsubmission_file submissions
        // against plagiarism_docguard_sub.
        if ($processed < self::MAX_PER_RUN) {
            $this->scan_untracked_files(self::MAX_PER_RUN - $processed, $processed);
        }

        mtrace("DocGuard process_pending: finished. Files processed this run: {$processed}.");
    }

    /**
     * Scan DocGuard-enabled assign CMs for submitted files not yet in the DB.
     */
    private function scan_untracked_files(int $limit, int &$processed): void {
        global $DB, $CFG;

        // Find CMs with per-activity DocGuard enabled.
        $enabled_cms = $DB->get_fieldset_select(
            'plagiarism_config',
            'cm',
            "plugin = 'docguard' AND name = 'plagiarism_docguard_enable' AND value = '1'"
        );

        // Also respect site-wide enable flags from plugin config.
        $cfg = (array)get_config('plagiarism_docguard');
        if (!empty($cfg['sitewide_assign_enable'])) {
            // Site-wide on for assign — find all assign CMs not already in the list.
            $all_assign_cms = $DB->get_fieldset_sql(
                "SELECT cm.id
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  WHERE cm.deletioninprogress = 0"
            );
            $enabled_cms = array_unique(array_merge((array)$enabled_cms, $all_assign_cms));
        }

        if (empty($enabled_cms)) {
            mtrace("DocGuard process_pending [P2]: no DocGuard-enabled assign CMs found — skipping untracked scan.");
            return;
        }

        $fs = get_file_storage();
        [$in_sql, $in_params] = $DB->get_in_or_equal($enabled_cms, SQL_PARAMS_NAMED, 'cm');

        // Fetch recently-modified submitted files across enabled CMs.
        // Only look at the "latest" submission per student (latest=1).
        $rows = $DB->get_records_sql(
            "SELECT af.id, af.submission, asub.userid, asub.assignment,
                    cm.id AS cmid, ctx.id AS contextid
               FROM {assignsubmission_file} af
               JOIN {assign_submission} asub ON asub.id = af.submission
                    AND asub.latest = 1
                    AND asub.status = 'submitted'
               JOIN {assign} a ON a.id = asub.assignment
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} mod ON mod.id = cm.module AND mod.name = 'assign'
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = " . CONTEXT_MODULE . "
              WHERE cm.id $in_sql
              ORDER BY asub.timemodified DESC",
            $in_params,
            0,
            $limit * 5  // Fetch extra — most rows may already have DB records.
        );

        if (empty($rows)) {
            mtrace("DocGuard process_pending [P2]: no submitted assign files found in enabled CMs.");
            return;
        }

        foreach ($rows as $row) {
            if ($processed >= self::MAX_PER_RUN) {
                break;
            }

            $cmid    = (int)$row->cmid;
            $userid  = (int)$row->userid;
            $subid   = (int)$row->submission;
            $ctx     = (int)$row->contextid;

            $files = $fs->get_area_files(
                $ctx,
                'assignsubmission_file',
                'submission_files',
                $subid,
                'timemodified DESC',
                false
            );

            foreach ($files as $file) {
                if ($processed >= self::MAX_PER_RUN) {
                    break;
                }
                if ($file->is_directory()) {
                    continue;
                }
                if (!\plagiarism_docguard\extractor::is_supported($file)) {
                    continue;
                }

                $contenthash = $file->get_contenthash();

                // Skip if a record (any status) already exists.
                $existing = $DB->record_exists('plagiarism_docguard_sub', [
                    'cmid'        => $cmid,
                    'userid'      => $userid,
                    'contenthash' => $contenthash,
                ]);
                if ($existing) {
                    continue;
                }

                mtrace("DocGuard process_pending [P2]: untracked file '{$file->get_filename()}' user {$userid} cm {$cmid} — queuing.");

                try {
                    // analyse_and_store will insert the pending record then run analysis.
                    \plagiarism_docguard\observer::analyse_and_store(
                        $file,
                        $cmid,
                        $userid,
                        $subid,
                        $ctx
                    );
                    $processed++;
                } catch (\Throwable $e) {
                    mtrace("DocGuard process_pending [P2]: ERROR user {$userid} cm {$cmid} file '{$file->get_filename()}' — " . $e->getMessage());
                }
            }
        }
    }
}
