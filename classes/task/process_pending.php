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
 * Capped at MAX_PER_RUN files per cron execution to avoid PHP timeouts. The task is
 * scheduled hourly (db/tasks.php), so that is 15 files per hour by default.
 *
 * v1.0.78: Phase 2 is now opt-in and off by default — see the FIX-DG-BACKFILL-OPTIN
 * note in execute(). Phase 1 is unconditional.
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
                // FIX-DG-PENDING-POISON-PILL (v1.0.78): this used to `continue`
                // without changing the record, unlike the !$filerecord branch above
                // which marks it error. Phase 1 selects status='pending' ordered by
                // timecreated ASC and caps at MAX_PER_RUN, so an unretrievable file
                // was re-selected first on every run forever, permanently consuming
                // one of the 15 slots. Fifteen such records and no other student's
                // submission is ever analysed again — the whole class sits on
                // Pending with no breakdown. Mark it error so the queue drains and
                // the teacher sees an actionable badge.
                //
                // Grace window first: get_file_by_id() can also return false
                // transiently on sites using an alternative file system (S3/Azure),
                // or while a file is still in the trashdir. Erroring on a five-second
                // blip would be the opposite mistake, since nothing retries 'error'
                // records automatically. Only records older than an hour are flipped.
                if ($sub->timecreated > 0 && (time() - $sub->timecreated) < 3600) {
                    mtrace("DocGuard process_pending [P1]: sub {$sub->id} — file not retrievable, within 1h grace, will retry.");
                    continue;
                }
                $upd               = new \stdClass();
                $upd->id           = $sub->id;
                $upd->status       = 'error';
                $upd->errormsg     = 'Stored file could not be retrieved from Moodle file storage.';
                $upd->timemodified = time();
                $DB->update_record('plagiarism_docguard_sub', $upd);
                mtrace("DocGuard process_pending [P1]: sub {$sub->id} — file id {$filerecord->id} not retrievable, marked error.");
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
        //
        // FIX-DG-BACKFILL-OPTIN (v1.0.78): Phase 2 is OFF by default.
        //
        // This phase was dead code in every prior release (see
        // scan_untracked_files()), so no site has ever had it run. Repairing it
        // without a gate would silently turn an inert task into a site-wide sweep of
        // every historical assignment submission on the install — shelling out to
        // pdftotext/gs per file and storing extracted text for every student who has
        // ever submitted — on the strength of a point upgrade. That is not a change
        // an administrator should discover after the fact.
        //
        // The reported fault (teachers unable to open the breakdown) does not depend
        // on this phase: new submissions are handled by the event observer, stuck
        // records by Phase 1 above, and a specific activity's backlog by the
        // "Scan & Analyse Unprocessed Submissions" button on the class report.
        if (!get_config('plagiarism_docguard', 'enablebackfill')) {
            mtrace('DocGuard process_pending [P2]: historical backfill is disabled '
                . '(Site administration → Plugins → DocGuard → "Analyse historical submissions"). Skipping.');
        } else if ($processed < self::MAX_PER_RUN) {
            // Isolated so it can never take Phase 1 down with it. An uncaught
            // exception here fails the whole scheduled task, and Moodle then applies
            // exponential fail-delay — backing the task off towards once a day and
            // throttling Phase 1, which is the part that recovers stuck submissions.
            try {
                $this->scan_untracked_files(self::MAX_PER_RUN - $processed, $processed);
            } catch (\Throwable $e) {
                mtrace('DocGuard process_pending [P2]: ABORTED — ' . $e->getMessage());
            }
        }

        mtrace("DocGuard process_pending: finished. Files processed this run: {$processed}.");
    }

    /**
     * Scan DocGuard-enabled assign CMs for submitted files not yet in the DB.
     */
    private function scan_untracked_files(int $limit, int &$processed): void {
        global $DB, $CFG;

        // FIX-DG-BACKFILL-ENABLEMENT (v1.0.78): this whole phase was dead code, and
        // making it live required three separate corrections.
        //
        // (1) Enablement source. It looked for enabled activities in
        //     {plagiarism_config} (plugin='docguard', name='plagiarism_docguard_enable'),
        //     a table DocGuard never writes to — enablement lives in
        //     set_config('enabled_cm_<cmid>', …, 'plagiarism_docguard'). The site-wide
        //     fallback checked 'sitewide_assign_enable', another key nothing ever
        //     sets. $enabled_cms was therefore always empty and the task logged "no
        //     DocGuard-enabled assign CMs found" and returned on every single run.
        //
        //     Rather than rebuild that list — which also diverges from rendering,
        //     because plagiarism_docguard_is_cm_active() treats an ABSENT config key
        //     as enabled, and would produce an unbounded IN clause on a site with
        //     site-wide enablement on — each candidate row is now filtered through
        //     plagiarism_docguard_is_cm_active() itself. Cron and the badge cannot
        //     disagree, because they now ask the same function.
        //
        // (2) Ordering. The query fetched the NEWEST submissions site-wide
        //     (ORDER BY timemodified DESC, limit 75). Those are precisely the ones
        //     the observer already tracked, so every row was discarded by the
        //     record_exists() check below and the phase made no progress, ever.
        //     Untracked rows are now excluded in SQL via NOT EXISTS, so the window is
        //     filled only with work that actually needs doing.
        //
        // (3) Table alias. {modules} was aliased as "mod", a reserved word on
        //     MySQL/MariaDB (the modulo operator). This never surfaced because the
        //     query was unreachable; the moment the phase went live it would have
        //     thrown dml_read_exception on the majority of Moodle installs. Renamed
        //     to "md". PostgreSQL would not have reproduced it.
        // (4) Same gates the observer honours. classes/observer.php checks
        //     is_cm_active() and check_unlock() before analysing anything; a cron
        //     path that ignored them would analyse submissions on sites where an
        //     administrator has switched plagiarism off, or that are not licensed.
        if (empty($CFG->enableplagiarism)) {
            mtrace('DocGuard process_pending [P2]: plagiarism is disabled site-wide — skipping.');
            return;
        }
        if (!\plagiarism_docguard_check_unlock()) {
            mtrace('DocGuard process_pending [P2]: plugin is not unlocked — skipping.');
            return;
        }

        $fs = get_file_storage();

        // (5) Exclude activities where DocGuard is EXPLICITLY switched off. This has
        //     to be an exclusion list, not an inclusion list: is_cm_active() treats an
        //     absent config key as enabled, so the enabled set is "all assigns" and
        //     only the disabled set is enumerable. It is also normally tiny, so the
        //     NOT IN clause stays small — the inclusion list this replaces would have
        //     produced one placeholder per assign on the site.
        $disabled_cms = [];
        foreach ((array)get_config('plagiarism_docguard') as $key => $value) {
            if (strpos($key, 'enabled_cm_') !== 0 || !empty($value)) {
                continue;
            }
            $disabled_cmid = (int)substr($key, strlen('enabled_cm_'));
            if ($disabled_cmid > 0) {
                $disabled_cms[] = $disabled_cmid;
            }
        }
        $notin_sql    = '';
        $notin_params = [];
        if (!empty($disabled_cms)) {
            [$notin, $notin_params] = $DB->get_in_or_equal($disabled_cms, SQL_PARAMS_NAMED, 'dis', false);
            $notin_sql = " AND cm.id $notin ";
        }

        // Oldest first: the case this phase exists for is a backlog of submissions
        // made before the plugin was installed. New submissions are handled by the
        // observer as they happen and do not need this path.
        //
        // asub.userid > 0 excludes group (team) submissions. Their assign_submission
        // rows carry userid = 0 with the group in groupid, so the NOT EXISTS below —
        // which keys on (cmid, userid) — would treat the first group processed as
        // covering the whole activity and silently exclude every other group forever.
        // The resulting records would also never be displayed, since get_links() looks
        // rows up by the real student's user id. Group assignments are therefore left
        // to the observer rather than half-handled here.
        $rows = $DB->get_records_sql(
            "SELECT af.id, af.submission, asub.userid, asub.assignment,
                    cm.id AS cmid, ctx.id AS contextid
               FROM {assignsubmission_file} af
               JOIN {assign_submission} asub ON asub.id = af.submission
                    AND asub.latest = 1
                    AND asub.status = 'submitted'
               JOIN {assign} a ON a.id = asub.assignment
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} md ON md.id = cm.module AND md.name = 'assign'
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = " . CONTEXT_MODULE . "
              WHERE cm.deletioninprogress = 0
                    $notin_sql
                AND asub.userid > 0
                AND NOT EXISTS (
                        SELECT 1
                          FROM {plagiarism_docguard_sub} dg
                         WHERE dg.cmid = cm.id
                           AND dg.userid = asub.userid
                    )
              ORDER BY asub.timemodified ASC",
            $notin_params,
            0,
            $limit * 5  // Head-room: some rows are still skipped by is_supported().
        );

        if (empty($rows)) {
            mtrace("DocGuard process_pending [P2]: no untracked submitted assign files found.");
            return;
        }

        mtrace('DocGuard process_pending [P2]: ' . count($rows) . ' candidate untracked submission(s).');

        foreach ($rows as $row) {
            if ($processed >= self::MAX_PER_RUN) {
                break;
            }

            $cmid    = (int)$row->cmid;
            $userid  = (int)$row->userid;
            $subid   = (int)$row->submission;
            $ctx     = (int)$row->contextid;

            // FIX-DG-BACKFILL-ENABLEMENT (v1.0.78): ask the same function the badge
            // asks, so cron never analyses an activity where DocGuard is switched
            // off, and never skips one where the badge says it is on.
            if (!\plagiarism_docguard_is_cm_active($cmid)) {
                continue;
            }

            $files = $fs->get_area_files(
                $ctx,
                'assignsubmission_file',
                'submission_files',
                $subid,
                'timemodified DESC',
                false
            );

            $row_complete   = true;  // False if the per-run cap cut this row short.
            $row_queued     = 0;
            $row_supported  = 0;     // Supported files seen, regardless of outcome.

            foreach ($files as $file) {
                if ($processed >= self::MAX_PER_RUN) {
                    $row_complete = false;
                    break;
                }
                if ($file->is_directory()) {
                    continue;
                }
                if (!\plagiarism_docguard\extractor::is_supported($file)) {
                    continue;
                }

                $row_supported++;
                $contenthash = $file->get_contenthash();

                // Defence in depth. Unreachable in normal operation: the NOT EXISTS
                // above already excluded this (cmid, userid) pair. Kept because it is
                // the finer-grained check (it also keys on contenthash) and costs one
                // indexed lookup on a row we are about to spend seconds analysing.
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
                    $row_queued++;
                } catch (\Throwable $e) {
                    mtrace("DocGuard process_pending [P2]: ERROR user {$userid} cm {$cmid} file '{$file->get_filename()}' — " . $e->getMessage());
                }
            }

            // FIX-DG-BACKFILL-TERMINATION (v1.0.78): make a permanent skip durable.
            //
            // Without this the phase cannot converge. The candidate query excludes
            // rows that have a plagiarism_docguard_sub record, but a submission whose
            // files are all unsupported (.odt, .txt, images) never gets one — so it
            // stays a candidate forever, and because the ordering is oldest-first it
            // deterministically occupies the head of the window on every run. A site
            // with enough old unsupported submissions would log "N candidate
            // untracked submission(s)" and process nothing, indefinitely.
            //
            // Writing a terminal 'unsupported' marker drops the row from the query
            // permanently. render_badge() returns '' for this status, so nothing is
            // shown to teachers or students. Only written when the row was examined
            // in full — if the per-run cap cut it short, it is retried next run.
            // Gated on $row_supported, not $row_queued: if a supported file was found
            // but analyse_and_store() threw (a DB blip, a transient storage failure),
            // writing "no supported files" would be false, permanent — nothing retries
            // this status — and invisible, since render_badge() renders nothing for it.
            // Those rows are left as candidates so the next run retries them.
            if ($row_complete && $row_queued === 0 && $row_supported === 0) {
                try {
                    if (!$DB->record_exists('plagiarism_docguard_sub', ['cmid' => $cmid, 'userid' => $userid])) {
                        $marker = new \stdClass();
                        $marker->userid            = $userid;
                        $marker->cmid              = $cmid;
                        $marker->contextid         = $ctx;
                        $marker->submissionid      = $subid;
                        $marker->filename          = '';
                        $marker->filetype          = 'unsupported';
                        $marker->contenthash       = '';
                        $marker->status            = 'unsupported';
                        $marker->section_count     = 0;
                        $marker->overall_riskscore = 0;
                        $marker->overall_risklevel = 'low';
                        $marker->errormsg          = 'No DocGuard-supported files (PDF/DOCX) in this submission.';
                        $marker->timecreated       = time();
                        $marker->timemodified      = time();
                        $DB->insert_record('plagiarism_docguard_sub', $marker);
                        mtrace("DocGuard process_pending [P2]: user {$userid} cm {$cmid} — no supported files, marked unsupported.");
                    }
                } catch (\Throwable $e) {
                    mtrace("DocGuard process_pending [P2]: could not mark user {$userid} cm {$cmid} unsupported — " . $e->getMessage());
                }
            }
        }
    }
}
