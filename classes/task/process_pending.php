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
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_pending extends \core\task\scheduled_task {
    /** Maximum files analysed per single cron run. */
    const MAX_PER_RUN = 15;

    /**
     * Name shown for this task on the scheduled tasks admin page.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('process_pending_task', 'plagiarism_docguard');
    }

    /**
     * Analyse submissions still marked pending, then optionally backfill older ones.
     *
     * Phase 1 retries records with status "pending"; phase 2 scans for submitted files
     * that have no DocGuard record at all, and runs only when the backfill setting is on.
     *
     * @return void
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
        require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

        // V1.0.80: honour the site-wide switch. This task re-runs extraction and scoring
        // and stores document text, so an administrator who has switched DocGuard off
        // must not find it still working through a backlog every hour. Phase 1 and
        // Phase 2 are both covered, and each candidate is additionally filtered through
        // plagiarism_docguard_is_cm_active() below so a per-activity opt-out is honoured
        // by cron exactly as it is by the badge.
        if (!\plagiarism_docguard_is_enabled()) {
            mtrace(
                'DocGuard process_pending: DocGuard is disabled site-wide '
                    . '(Site administration → Plugins → Plagiarism → DocGuard). Nothing to do.'
            );
            return;
        }

        /*
         * V1.0.88 FIX-DG-PHASE1-NO-LICENCE-GATE: the licence gate was applied to Phase 2
         * (see scan_untracked_files()), to classes/observer.php and to
         * classes/task/scan_activity.php — but not here, so Phase 1 was the one processing
         * path on the whole site that never asked whether the site was licensed.
         *
         * Since v1.0.85 (FIX-DG-UNLICENSED-FAILS-OPEN in lib.php) a site with no Site ID
         * and no API Key is deliberately treated as unlicensed: check_unlock() returns
         * false, print_disclosure() stops telling students their work is being checked, and
         * settings.php reports "Credentials not configured". Phase 1 carried on regardless
         * — every hour, for every stuck record, extracting and storing the full text of a
         * student's document on a site that had just told that student it was not doing so,
         * and told its administrator it could not. Those two statements cannot both be
         * allowed to stand.
         *
         * Placed before Phase 1 rather than inside the loop: this is a site-level property,
         * one cached lookup answers it for the whole run, and the mtrace line tells an
         * administrator reading the cron log exactly why nothing moved.
         *
         * check_unlock() rather than the credentials-only test the three web endpoints use:
         * this is CLI, so its write_close() guard fires and its network timeout costs a
         * cron run nothing, which makes the stricter test the right one here. It still
         * fails OPEN on a vendor outage, so a licensed site whose network is down keeps
         * working.
         */
        if (!\plagiarism_docguard_check_unlock()) {
            mtrace(
                'DocGuard process_pending: this site is not licensed for DocGuard '
                    . '(check the Site ID and API Key at Site administration → Plugins → Plagiarism '
                    . '→ DocGuard). Nothing to do.'
            );
            return;
        }

        $processed = 0;

        /* ── Phase 1: Re-analyse existing status='pending' DB records ───────── */
        // Give a 5-minute grace window so a freshly-submitted file's observer
        // pipeline has time to complete before we interfere.
        $grace = time() - 300;
        $pendingsubs = $DB->get_records_select(
            'plagiarism_docguard_sub',
            "status = 'pending' AND timecreated < :grace",
            ['grace' => $grace],
            'timecreated ASC',
            '*',
            0,
            self::MAX_PER_RUN
        );

        foreach ($pendingsubs as $sub) {
            if ($processed >= self::MAX_PER_RUN) {
                break;
            }

            // V1.0.80: per-activity opt-out is honoured here too. A record can be left
            // 'pending' from before a teacher unticked DocGuard on the activity, and this
            // loop would happily analyse it hours later.
            if (!\plagiarism_docguard_is_cm_active((int)$sub->cmid)) {
                mtrace("DocGuard process_pending [P1]: sub {$sub->id} — DocGuard is off for cm {$sub->cmid}, skipping.");
                continue;
            }

            // V1.0.80: shared helper instead of a raw "LIMIT 1" — see lib.php.
            $fileid = \plagiarism_docguard_find_file_id_by_hash((string)$sub->contenthash);

            if (!$fileid) {
                // Original file was deleted from Moodle file storage.
                $upd             = new \stdClass();
                $upd->id         = $sub->id;
                $upd->status     = 'error';
                $upd->errormsg   = 'File no longer exists in Moodle file storage.';
                $upd->timemodified = time();
                $DB->update_record('plagiarism_docguard_sub', $upd);
                mtrace(
                    "DocGuard process_pending [P1]: sub {$sub->id} — contenthash {$sub->contenthash} "
                        . "not found in {files}, marked error."
                );
                continue;
            }

            $fs   = get_file_storage();
            $file = $fs->get_file_by_id($fileid);
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
                mtrace("DocGuard process_pending [P1]: sub {$sub->id} — file id {$fileid} not retrievable, marked error.");
                continue;
            }

            mtrace(
                "DocGuard process_pending [P1]: re-analysing sub {$sub->id} user {$sub->userid} "
                    . "cmid {$sub->cmid} file {$sub->filename}"
            );

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

        /* ── Phase 2: Scan for untracked submitted files ─────────────────────── */
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
            mtrace(
                'DocGuard process_pending [P2]: historical backfill is disabled '
                    . '(Site administration → Plugins → DocGuard → "Analyse historical submissions"). Skipping.'
            );
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
     *
     * @param int $limit     Maximum number of files to analyse in this phase.
     * @param int $processed Running total of files analysed this run, updated by reference
     *                           so the caller's budget stays accurate.
     * @return void
     */
    private function scan_untracked_files(int $limit, int &$processed): void {
        global $DB, $CFG;

        // FIX-DG-BACKFILL-ENABLEMENT (v1.0.78): this whole phase was dead code, and
        // making it live required three separate corrections.
        //
        // (1) Enablement source. It looked for enabled activities in
        // {plagiarism_config} (plugin='docguard', name='plagiarism_docguard_enable'),
        // a table DocGuard never writes to — enablement lives in
        // set_config('enabled_cm_<cmid>', …, 'plagiarism_docguard'). The site-wide
        // fallback checked 'sitewide_assign_enable', another key nothing ever
        // sets. $enabledcms was therefore always empty and the task logged "no
        // DocGuard-enabled assign CMs found" and returned on every single run.
        //
        // Rather than rebuild that list — which also diverges from rendering,
        // because plagiarism_docguard_is_cm_active() treats an ABSENT config key
        // as enabled, and would produce an unbounded IN clause on a site with
        // site-wide enablement on — each candidate row is now filtered through
        // plagiarism_docguard_is_cm_active() itself. Cron and the badge cannot
        // disagree, because they now ask the same function.
        //
        // (2) Ordering. The query fetched the NEWEST submissions site-wide
        // (ORDER BY timemodified DESC, limit 75). Those are precisely the ones
        // the observer already tracked, so every row was discarded by the
        // record_exists() check below and the phase made no progress, ever.
        // Untracked rows are now excluded in SQL via NOT EXISTS, so the window is
        // filled only with work that actually needs doing.
        //
        // (3) Table alias. {modules} was aliased as "mod", a reserved word on
        // MySQL/MariaDB (the modulo operator). This never surfaced because the
        // query was unreachable; the moment the phase went live it would have
        // thrown dml_read_exception on the majority of Moodle installs. Renamed
        // to "md". PostgreSQL would not have reproduced it.
        // (4) Same gates the observer honours. classes/observer.php checks
        // is_cm_active() and check_unlock() before analysing anything; a cron
        // path that ignored them would analyse submissions on sites where an
        // administrator has switched plagiarism off, or that are not licensed.
        if (empty($CFG->enableplagiarism)) {
            mtrace('DocGuard process_pending [P2]: plagiarism is disabled site-wide — skipping.');
            return;
        }
        if (!\plagiarism_docguard_check_unlock()) {
            mtrace('DocGuard process_pending [P2]: plugin is not unlocked — skipping.');
            return;
        }

        $fs = get_file_storage();

        // V1.0.80: INCLUSION list, not an exclusion list.
        //
        // This used to build a NOT IN list of explicitly-disabled activities, because
        // is_cm_active() treated an absent config key as ENABLED — so "enabled" meant
        // "every assign on the site" and only the disabled set was enumerable. That
        // default is now inverted (see the note in plagiarism_docguard_is_cm_active()):
        // an activity with no saved value is OFF, so the enabled set is exactly the set
        // of explicit enabled_cm_<cmid> = 1 keys and can be listed directly. That is both
        // correct and much narrower work for cron, since a site's backfill sweep now only
        // touches activities a teacher actually opted in.
        //
        // Platform-wide flags are deliberately NOT expanded into this list: they can
        // enable an unbounded number of activities, which would put one placeholder per
        // assign into the IN clause. Rows are still filtered through is_cm_active() below,
        // which consults those flags — the SQL list is a cheap narrowing, not the
        // authority.
        $enabledcms = [];
        foreach ((array)get_config('plagiarism_docguard') as $key => $value) {
            if (strpos($key, 'enabled_cm_') !== 0 || empty($value)) {
                continue;
            }
            $enabledcmid = (int)substr($key, strlen('enabled_cm_'));
            if ($enabledcmid > 0) {
                $enabledcms[] = $enabledcmid;
            }
        }

        $platform      = \plagiarism_docguard_get_platform_settings();
        $platformwide = !empty($platform['docguard_assignments']);

        if (empty($enabledcms) && !$platformwide) {
            mtrace('DocGuard process_pending [P2]: no activities have DocGuard enabled — skipping.');
            return;
        }

        $insql    = '';
        $inparams = [];
        if (!$platformwide) {
            [$in, $inparams] = $DB->get_in_or_equal($enabledcms, SQL_PARAMS_NAMED, 'en', true);
            $insql = " AND cm.id $in ";
        }

        // Oldest first: the case this phase exists for is a backlog of submissions
        // made before the plugin was installed. New submissions are handled by the
        // observer as they happen and do not need this path.
        //
        // asub.userid > 0 excludes group (team) submissions. Their assign_submission
        // rows carry userid = 0 with the group in groupid, so a key of (cmid, userid)
        // would treat the first group processed as covering the whole activity and
        // silently exclude every other group forever. The resulting records would also
        // never be displayed, since get_links() looks rows up by the real student's user
        // id. Group assignments are therefore left to the observer rather than
        // half-handled here.
        //
        // V1.0.88 FIX-DG-BACKFILL-FILE-GRANULARITY: the candidate window is now built
        // from FILES, and excluded per file.
        //
        // What was wrong: the query selected {assignsubmission_file} rows — one row per
        // SUBMISSION, not per file, whatever the plural name suggests; the column that
        // varies is numfiles — and excluded a row as soon as
        // `NOT EXISTS (… dg.cmid = cm.id AND dg.userid = asub.userid)`
        // failed, i.e. as soon as the student had ANY DocGuard record in that activity.
        // mod_assign lets a student attach several documents to one submission, and
        // assignsubmission_file's own maxfilesubmissions setting defaults to 20.
        //
        // So a student who submitted two documents of which only one had been analysed —
        // by the observer, by a teacher pressing Re-analyse, or by an earlier run of this
        // very phase that hit its per-run cap between the two files — was excluded from
        // the window entirely, and the finer-grained contenthash check inside the loop
        // (which the old code described as "unreachable in normal operation", correctly,
        // and which was the only thing that keyed on the file) could never run for them.
        // Their second document was never analysed by cron, its badge said "Plagiarism
        // Check Pending" for ever, and nothing anywhere said why. Partial coverage of a
        // student's work is the worst outcome for a plagiarism tool: the teacher sees a
        // score for the class and no indication that a document is missing from it.
        //
        // The exclusion is now keyed on (cmid, userid, contenthash) — exactly the key
        // analyse_and_store() uses to decide whether a record already exists — by joining
        // {files} instead of {assignsubmission_file}. {files} is where the contenthash
        // lives, so the SQL and the PHP now agree on what "already tracked" means.
        // filename <> '.' drops the directory rows the file API stores alongside real
        // files. Rows are ordered oldest-first on the submission, as before.
        $rows = $DB->get_records_sql(
            "SELECT f.id AS fileid, f.contenthash, f.filename,
                    asub.id AS submissionid, asub.userid,
                    cm.id AS cmid, ctx.id AS contextid
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = " . CONTEXT_MODULE . "
               JOIN {course_modules} cm ON cm.id = ctx.instanceid
               JOIN {modules} md ON md.id = cm.module AND md.name = 'assign'
               JOIN {assign} a ON a.id = cm.instance
               JOIN {assign_submission} asub ON asub.id = f.itemid
                    AND asub.assignment = a.id
                    AND asub.latest = 1
                    AND asub.status = 'submitted'
              WHERE f.component = 'assignsubmission_file'
                AND f.filearea = 'submission_files'
                AND f.filename <> '.'
                AND cm.deletioninprogress = 0
                    $insql
                AND asub.userid > 0
                AND NOT EXISTS (
                        SELECT 1
                          FROM {plagiarism_docguard_sub} dg
                         WHERE dg.cmid = cm.id
                           AND dg.userid = asub.userid
                           AND dg.contenthash = f.contenthash
                    )
              ORDER BY asub.timemodified ASC, f.id ASC",
            $inparams,
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

            $cmid   = (int)$row->cmid;
            $userid = (int)$row->userid;
            $subid  = (int)$row->submissionid;
            $ctx    = (int)$row->contextid;

            // FIX-DG-BACKFILL-ENABLEMENT (v1.0.78): ask the same function the badge
            // asks, so cron never analyses an activity where DocGuard is switched
            // off, and never skips one where the badge says it is on.
            if (!\plagiarism_docguard_is_cm_active($cmid)) {
                continue;
            }

            $file = $fs->get_file_by_id((int)$row->fileid);
            if (!$file || $file->is_directory()) {
                // Transient: the row was in {files} a moment ago. Left as a candidate so
                // the next run retries it rather than writing a permanent marker on the
                // strength of a storage blip.
                continue;
            }

            if (!\plagiarism_docguard\extractor::is_supported($file)) {
                // FIX-DG-BACKFILL-TERMINATION (v1.0.78), re-keyed per file in v1.0.88.
                //
                // Without a durable marker the phase cannot converge: a file DocGuard
                // cannot read (.odt, .txt, an image) never acquires a record, so it stays
                // a candidate for ever, and because the ordering is oldest-first it
                // deterministically occupies the head of the window on every run. A site
                // with enough old unsupported submissions would log "N candidate
                // untracked submission(s)" and process nothing, indefinitely.
                //
                // v1.0.88: the marker now carries the FILE's own filename and
                // contenthash. It used to be written once per submission with both fields
                // empty, which was the only thing that could satisfy the old
                // (cmid, userid) exclusion; against the contenthash-keyed exclusion above
                // an empty hash would match nothing and the poison pill would be back.
                // Writing the real hash also makes the row self-describing: a teacher or
                // administrator looking at the table can see WHICH file was skipped, and
                // a student who submits an unreadable file and then a PDF now gets the
                // PDF analysed while the marker still suppresses re-examination of the
                // first, which the old submission-wide marker did not.
                //
                // render_badge() returns '' for this status and report.php filters it
                // out, so nothing is shown to teachers or students.
                try {
                    $marker = new \stdClass();
                    $marker->userid            = $userid;
                    $marker->cmid              = $cmid;
                    $marker->contextid         = $ctx;
                    $marker->submissionid      = $subid;
                    $marker->filename          = \core_text::substr($file->get_filename(), 0, 512);
                    $marker->filetype          = 'unsupported';
                    $marker->contenthash       = (string)$row->contenthash;
                    $marker->status            = 'unsupported';
                    $marker->section_count     = 0;
                    $marker->overall_riskscore = 0;
                    $marker->overall_risklevel = 'low';
                    $marker->errormsg          = 'Not a DocGuard-supported file type (PDF/DOCX).';
                    $marker->timecreated       = time();
                    $marker->timemodified      = time();
                    $DB->insert_record('plagiarism_docguard_sub', $marker);
                    mtrace(
                        "DocGuard process_pending [P2]: user {$userid} cm {$cmid} file '"
                            . $file->get_filename() . "' — no supported files, marked unsupported."
                    );
                } catch (\Throwable $e) {
                    mtrace(
                        "DocGuard process_pending [P2]: could not mark user {$userid} cm {$cmid} "
                            . "file '" . $file->get_filename() . "' unsupported — " . $e->getMessage()
                    );
                }
                continue;
            }

            // Defence in depth. Unreachable in normal operation: the NOT EXISTS above
            // already excluded this (cmid, userid, contenthash) triple. Kept because it
            // costs one indexed lookup on a row we are about to spend seconds analysing,
            // and because it closes the window between building the candidate list and
            // reaching this file — the observer may have analysed it in between.
            $alreadytracked = $DB->record_exists(
                'plagiarism_docguard_sub',
                [
                    'cmid'        => $cmid,
                    'userid'      => $userid,
                    'contenthash' => (string)$row->contenthash,
                    ]
            );
            if ($alreadytracked) {
                continue;
            }

            mtrace(
                "DocGuard process_pending [P2]: untracked file '{$file->get_filename()}' "
                    . "user {$userid} cm {$cmid} — queuing."
            );

            try {
                // Analyse_and_store will insert the pending record then run analysis.
                \plagiarism_docguard\observer::analyse_and_store(
                    $file,
                    $cmid,
                    $userid,
                    $subid,
                    $ctx
                );
                $processed++;
            } catch (\Throwable $e) {
                mtrace(
                    "DocGuard process_pending [P2]: ERROR user {$userid} cm {$cmid} "
                        . "file '{$file->get_filename()}' — " . $e->getMessage()
                );
            }
        }
    }
}
