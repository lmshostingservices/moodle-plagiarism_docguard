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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Called when a student submits (or resubmits) an assignment.
     * Locates the submitted PDF/DOCX files and triggers analysis.
     *
     * @param \mod_assign\event\assessable_submitted $event The submission event.
     * @return void
     */
    public static function on_assessable_submitted(\mod_assign\event\assessable_submitted $event): void {
        $cmid = (int)$event->contextinstanceid;

        /*
         * V1.0.88 FIX-DG-OBSERVER-NEVER-FIRED: this read the submission id from
         * $event->other['submissionid'], a key mod_assign has never set.
         *
         * assessable_submitted::create_from_submission() (mod/assign/classes/event/
         * assessable_submitted.php) builds its payload as:
         *     'objectid' => $submission->id,
         *     'other'    => ['submission_editable' => $editable],
         * and nothing else. So ['submissionid'] was always null, $submissionid was always
         * 0, and get_area_files() below searched itemid 0 - which holds no submission
         * files. Every student submission therefore stored NOTHING and sat on the grey
         * "Plagiarism Check Pending" badge for ever.
         *
         * Nor did anything recover it: process_pending Phase 1 only retries records that
         * already exist with status 'pending', and no record was ever created. The only
         * paths that worked were the opt-in historical backfill and the teacher pressing
         * Scan or Re-analyse by hand - which is exactly the behaviour observed on a live
         * site, where submissions stayed Pending until re-analysed manually.
         *
         * The submission id is in objectid, where core puts it.
         */
        $submissionid = (int)$event->objectid;

        /*
         * V1.0.88 FIX-DG-OBSERVER-WRONG-USER: $event->userid is the person who performed
         * the action, not necessarily the author. When a teacher submits on a student's
         * behalf core sets userid = teacher and relateduserid = student (see the
         * create_from_submission() branch that adds relateduserid when
         * $submission->userid !== $USER->id).
         *
         * Filing the work under the submitter meant the student's extracted document text
         * was stored against the teacher's user id: the student's badge stayed Pending,
         * the teacher acquired a record for work they did not write, and - because
         * privacy\provider keys on userid - the student's GDPR export and erasure request
         * both missed the record entirely.
         *
         * relateduserid is set only when the two differ, so falling back to userid is
         * correct for an ordinary self-submission.
         */
        $userid = (int)($event->relateduserid ?: $event->userid);

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
        /*
         * V1.0.92: the licence check is NOT made here. check_unlock() is an outbound HTTP
         * call — CURLOPT_CONNECTTIMEOUT 5 plus CURLOPT_TIMEOUT 10 — and its own
         * write_close() guard fires only for AJAX and CLI, so on an ordinary submit it
         * held the Moodle session write lock for up to fifteen seconds and serialised
         * every other request from that browser. Taking the file work out of this callback
         * while leaving a blocking network call in it would not have closed the finding.
         *
         * analyse_submission::execute() re-checks the licence before it analyses anything,
         * which is the correct place for it: the task runs under cron with nothing waiting.
         */

        /*
         * V1.0.92 PERF-DG-OBSERVER-ADHOC: queue the work, do not do it here.
         *
         * This callback used to call analyse_and_store() once per submitted file, inline,
         * inside the student's own submit request. Each file costs an external process —
         * pdftotext or Ghostscript, each with a 60-second timeout — plus signal scoring and
         * several DB writes. A student submitting two PDFs to a host without poppler could
         * therefore watch a spinning submit button for two minutes, and a submission that
         * ran past max_execution_time died half-written: the record created, the analysis
         * not, and the student shown a server error for work Moodle had in fact accepted.
         *
         * What remains here is deliberately cheap: the activity gate above, one
         * file-storage read plus one insert per supported file (mark_pending(), below), and
         * queueing the task. No extraction, no scoring, no outbound call. analyse_submission
         * re-checks every gate at execution time, because the task may run long after the
         * submission.
         *
         * The badge reads "Pending" until cron picks the task up, which is how every other
         * asynchronous path in this plugin already behaves.
         */
        $context = \context_module::instance($cmid);

        /*
         * V1.0.92 FIX-DG-ADHOC-SAFETY-NET: write the pending rows here, before queueing.
         *
         * This is not bookkeeping — it is the plugin's only retry mechanism, and moving
         * analysis to an adhoc task would otherwise have destroyed it.
         *
         * Until now the observer called analyse_and_store() inline, and the first thing
         * that function does is insert the row with status='pending'. That row is what
         * process_pending Phase 1 looks for ("status='pending' AND timecreated < now-300")
         * and retries indefinitely. An adhoc task has no such guarantee: core discards it
         * after $attemptsavailable failures, an administrator clearing a stuck adhoc queue
         * deletes it, and execute() has several legitimate early returns. In every one of
         * those cases, with no row written, there would be nothing left to retry and
         * nothing to show — the submission would sit on a grey Pending badge for ever,
         * which is precisely the class of failure this plugin has spent five releases
         * closing.
         *
         * Writing the row first costs one file-storage read and one insert per file. No
         * extraction, no scoring, no network: none of the work the reviewer objected to.
         *
         * The 5-minute grace window in Phase 1 is what makes the two mechanisms cooperate
         * rather than collide — the adhoc task normally finishes well inside it, and Phase 1
         * only picks the row up if it did not.
         */
        /*
         * V1.0.92: gated on has_credentials(), which is a config read, not the network call
         * check_unlock() makes.
         *
         * Without it an unlicensed site would start recording student submissions it has
         * said nothing about: check_unlock() fails closed with no Site ID or API Key, so
         * plagiarism_docguard_print_disclosure() returns '' and the student is never told
         * their document is processed — while this method wrote a row holding their file
         * name, content hash, user id and context. In 1.0.91 the observer's check_unlock()
         * gate stopped that row existing at all. Those rows would also never drain, because
         * process_pending Phase 1 returns on the same licence check before its loop.
         */
        if (!\plagiarism_docguard_has_credentials()) {
            return;
        }

        // Nothing analysable means nothing to queue. assessable_submitted also fires for
        // online-text-only submissions and for uploads of types DocGuard cannot read;
        // queueing for those left adhoc rows for cron to pick up and discard.
        if (self::mark_pending($cmid, $userid, $submissionid, $context->id) === 0) {
            return;
        }

        \plagiarism_docguard\task\analyse_submission::queue(
            $cmid,
            $userid,
            $submissionid,
            $context->id
        );
    }

    /**
     * Record every supported file in a submission as pending, without analysing it.
     *
     * Deliberately does the minimum: no text extraction, no scoring, no outbound calls.
     * An existing row for the same (userid, cmid, contenthash) is left exactly as it is —
     * re-queueing must never reset an already-analysed result, and must never reset
     * timecreated, which Phase 1's grace window depends on.
     *
     * @param int $cmid Course module id.
     * @param int $userid The author of the work.
     * @param int $submissionid The assign_submission id holding the files.
     * @param int $contextid Module context id.
     * @return int The number of supported files in the submission, whether or not this call
     *             was the one that recorded them.
     */
    protected static function mark_pending(int $cmid, int $userid, int $submissionid, int $contextid): int {
        global $DB;

        $fs    = \get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'filename',
            false
        );

        $supported = 0;

        foreach ($files as $file) {
            if ($file->is_directory() || !extractor::is_supported($file)) {
                continue;
            }
            $supported++;

            $contenthash = $file->get_contenthash();
            if ($DB->record_exists('plagiarism_docguard_sub', [
                'userid'      => $userid,
                'cmid'        => $cmid,
                'contenthash' => $contenthash,
            ])) {
                continue;
            }

            $now = time();
            $sub = new \stdClass();
            $sub->userid            = $userid;
            $sub->cmid              = $cmid;
            $sub->contextid         = $contextid;
            $sub->submissionid      = $submissionid;
            $sub->filename          = $file->get_filename();
            $sub->filetype          = extractor::filetype($file);
            $sub->contenthash       = $contenthash;
            $sub->status            = 'pending';
            $sub->section_count     = 0;
            $sub->overall_riskscore = 0;
            $sub->overall_risklevel = 'low';
            $sub->timecreated       = $now;
            $sub->timemodified      = $now;

            try {
                $DB->insert_record('plagiarism_docguard_sub', $sub);
            } catch (\Throwable $e) {
                // Never let bookkeeping break a student's submission.
                //
                // Note this is NOT the duplicate-row guard: db/install.xml declares
                // userid_cmid_ix and contenthash_ix as ordinary non-unique indexes, so a
                // concurrent writer does not make this insert throw — it makes it succeed
                // twice. The record_exists() check above narrows that window; the duplicate
                // handling in analyse_and_store() (newest row wins, extras reported) is what
                // copes when it is lost.
                \debugging(
                    'DocGuard: could not pre-record pending row for user ' . $userid
                        . ' cm ' . $cmid . ' — ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $supported;
    }

    /**
     * Called when an activity is deleted: remove the DocGuard records that belonged to it.
     *
     * V1.0.88 FIX-DG-ORPHAN-ON-DELETE.
     *
     * DocGuard observed no deletion event at all, so deleting an assignment left every
     * plagiarism_docguard_sub row — including normtext, the complete extracted text of the
     * student's document — and every plagiarism_docguard_sec row, holding up to 65,000
     * characters of the student's verbatim answer per section, in the database for ever.
     *
     * That is not merely untidy, it is unreachable. Both privacy paths key on contextid,
     * and course/lib.php::course_delete_module() calls
     * context_helper::delete_instance(CONTEXT_MODULE, …) BEFORE it triggers this event, so
     * the stored contextid names a {context} row that no longer exists.
     * core_privacy's contextlist_base::get_contexts() wraps context::instance_by_id() in a
     * try/catch and silently DROPS any context it cannot instantiate, so from the moment
     * the activity is deleted:
     *   - get_contexts_for_userid() returns an id that resolves to nothing, therefore the
     *     data never appears in a subject access export;
     *   - the approved contextlist built from it is empty, therefore an erasure request
     *     deletes nothing and reports success;
     *   - delete_data_for_all_users_in_context() is never called for a context that has
     *     ceased to exist.
     * The one deletion route that stays open is the site administrator running SQL by
     * hand, which is not a GDPR compliance position.
     *
     * Reading the record's own cmid rather than its contextid deliberately: the context is
     * already gone by the time this runs, and cmid is what both tables actually store.
     *
     * Course deletion does NOT come through here — lib/moodlelib.php::remove_course_contents()
     * deletes each module context and course_modules row directly and triggers no
     * course_module_deleted event — so the cleanup task additionally sweeps rows whose cmid
     * no longer exists. See task/cleanup.php::purge_orphans().
     *
     * @param \core\event\course_module_deleted $event The deletion event.
     * @return void
     */
    public static function on_course_module_deleted(\core\event\course_module_deleted $event): void {
        self::purge_records_for_cm((int)$event->objectid);
    }

    /**
     * Delete every DocGuard record belonging to one course module, and its config key.
     *
     * Shared by the deletion observer and the cleanup task's orphan sweep so the two can
     * never disagree about what "belongs to" an activity.
     *
     * The per-activity enabled_cm_<cmid> value goes too. Course module ids are never
     * reused by Moodle, so a surviving key can only ever be dead weight in {config_plugins};
     * on a long-lived site that is one abandoned row per assignment ever deleted, and it is
     * the key the backfill phase enumerates to build its candidate activity list.
     *
     * @param int $cmid The course module whose records should be removed.
     * @return int The number of plagiarism_docguard_sub records deleted.
     */
    public static function purge_records_for_cm(int $cmid): int {
        global $DB;

        if ($cmid <= 0) {
            return 0;
        }

        $subids = $DB->get_fieldset_select('plagiarism_docguard_sub', 'id', 'cmid = :cmid', ['cmid' => $cmid]);
        if ($subids) {
            [$in, $params] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
        }
        // Section rows are also keyed on cmid in their own right, so a row whose parent
        // record has already gone (an interrupted earlier delete, a hand-run cleanup) is
        // still removed rather than being left behind holding the student's answer text.
        $DB->delete_records('plagiarism_docguard_sec', ['cmid' => $cmid]);
        $DB->delete_records('plagiarism_docguard_sub', ['cmid' => $cmid]);

        if (get_config('plagiarism_docguard', 'enabled_cm_' . $cmid) !== false) {
            unset_config('enabled_cm_' . $cmid, 'plagiarism_docguard');
        }

        return count($subids);
    }

    /**
     * Run analysis on one file and persist results.
     *
     * @param \stored_file $file The submitted document to analyse.
     * @param int $cmid The course module the submission belongs to.
     * @param int $userid The student who submitted it.
     * @param int $submissionid The assign_submission id, or 0 when unknown.
     * @param int $contextid The module context id.
     * @return void
     * @throws \Throwable Propagated from extraction or scoring, so callers can record
     *                    an "error" status rather than a false "analysed" one.
     */
    public static function analyse_and_store(
        \stored_file $file,
        int $cmid,
        int $userid,
        int $submissionid,
        int $contextid
    ): void {
        global $DB;

        // V1.0.80: last line of defence for the site-wide and per-activity switches.
        // Every caller checks them, but this is the one function that writes extracted
        // document text to the database, so it verifies for itself rather than trusting
        // five separate call sites to have got it right — including any added later.
        if (!\plagiarism_docguard_is_enabled() || !\plagiarism_docguard_is_cm_active($cmid)) {
            \debugging(
                'DocGuard: analyse_and_store() called for cmid ' . $cmid
                    . ' while DocGuard is disabled — refusing to process.',
                DEBUG_DEVELOPER
            );
            return;
        }

        $contenthash = $file->get_contenthash();
        $filetype    = extractor::filetype($file);

        // Check for existing record by contenthash + cmid + userid.
        //
        // v1.0.80: get_recordS, not get_record.
        //
        // get_record() throws dml_multiple_records_exception the moment two rows match,
        // and NOTHING guaranteed uniqueness on (userid, cmid, contenthash): db/install.xml
        // declares userid_cmid_ix and contenthash_ix as ordinary non-unique indexes, and
        // the insert path below has no locking. Duplicates are not hypothetical — two
        // teachers pressing Analyse at once, cron running while a teacher presses it, or
        // the observer firing on a resubmission of the same file, all race between this
        // read and the insert. From then on EVERY future call for that student threw, so
        // the submission could never be analysed again by any route: badge, report,
        // re-analyse, or cron.
        //
        // A UNIQUE index would be the tidier fix, and it was considered. It is rejected
        // for this release because adding one to a live client site whose table may
        // ALREADY contain duplicate rows aborts the upgrade half-way — the plugin would be
        // left un-upgradeable, which is a worse failure than the one being fixed, and it
        // is not something to discover on a paying customer's site during a point release.
        // Handling the set is correct regardless of what the schema does, so it is done
        // here: newest row wins, older duplicates are reported at developer level so a
        // site that has them can be cleaned up before any future index is introduced.
        $matches = $DB->get_records(
            'plagiarism_docguard_sub',
            [
                'userid'      => $userid,
                'cmid'        => $cmid,
                'contenthash' => $contenthash,
                ],
            'timemodified DESC, id DESC'
        );
        $existing = $matches ? reset($matches) : null;
        if (count($matches) > 1) {
            \debugging(
                'DocGuard: ' . count($matches) . ' duplicate submission records for user ' . $userid
                    . ' cm ' . $cmid . ' contenthash ' . $contenthash . ' — using the most recent (id '
                    . $existing->id . '). The extras are stale and can be deleted.',
                DEBUG_DEVELOPER
            );
        }
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

        /* ── Persist per-section records BEFORE flipping the parent status ───── */
        //
        // FIX-DG-SECTION-ORDER (v1.0.78): the parent record used to be updated to
        // status='analysed' first and the section rows inserted afterwards. Any
        // failure in the insert loop therefore stranded the record as 'analysed'
        // with a missing or partial breakdown — and analyse_and_store() returns
        // early on status==='analysed' (see above), so nothing ever retried it.
        // The teacher saw a score on the badge and "No section data available" on
        // the report, permanently. Sections are now written first and the parent
        // status reflects what was actually stored.
        //
        // FIX-DG-SECTION-NUM (v1.0.78): section_num is now a sequential counter
        // rather than the number parsed out of the document. db/install.xml puts a
        // UNIQUE index on (subid, section_num), but question_parser treats
        // "Question N", "Answer N", "Task N" etc. as the same integer, so an
        // ordinary template laid out as
        // Question 1 / Answer 1 / Question 2 / Answer 2
        // produced the sequence 1,1,2,2. The second insert threw
        // dml_write_exception and aborted the whole loop. The parsed number is
        // still visible to the teacher — it is part of section_label ("Answer 1").
        // Sequential numbering also preserves document order for the
        // "ORDER BY section_num ASC" the report uses.
        $sectime         = time();
        $sectionstored  = 0;
        $sectionfailed  = 0;
        $sectionnum     = 0;
        $firstfailure   = '';

        // Outer guard: nothing in this block may escape before the parent record is
        // updated below. If it did, the record would be left at status='pending'
        // with its original timecreated, which process_pending Phase 1 re-selects
        // first on every run (ORDER BY timecreated ASC) — re-running full extraction
        // and scoring forever while occupying one of its 15 slots per run. That is
        // the same poison-pill shape this release fixes in the task itself.
        try {
            foreach ((array)($result['sections'] ?? []) as $sec) {
                $sectionnum++;

                $rec = new \stdClass();
                $rec->subid         = $subid;
                $rec->userid        = $userid;
                $rec->cmid          = $cmid;
                $rec->section_num   = $sectionnum;
                // FIX-DG-MB-TRUNCATE (v1.0.78): byte-wise substr() could sever a
                // multi-byte character, and MySQL/utf8mb4 rejects the resulting
                // invalid string with "Incorrect string value" — another way the
                // insert loop aborted mid-breakdown. core_text is multi-byte safe.
                $rec->section_label = \core_text::substr((string)($sec['label'] ?? ''), 0, 128);
                $rec->section_text  = \core_text::substr((string)($sec['text'] ?? ''), 0, 65000);
                $rec->wordcount     = (int)($sec['wordcount'] ?? 0);
                $rec->riskscore     = (float)($sec['riskscore'] ?? 0);
                $rec->risklevel     = (string)($sec['risklevel'] ?? 'low');
                $rec->signalsjson   = json_encode($sec['signals'] ?? []);
                $rec->timemodified  = $sectime;

                // Per-row guard: one unstorable section must not cost the teacher the
                // rest of the breakdown.
                try {
                    $DB->insert_record('plagiarism_docguard_sec', $rec);
                    $sectionstored++;
                } catch (\Throwable $e) {
                    $sectionfailed++;
                    if ($firstfailure === '') {
                        $firstfailure = $e->getMessage();
                    }
                    \debugging(
                        'DocGuard: failed to store section ' . $sectionnum . ' of submission '
                            . $subid . ' — ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
        } catch (\Throwable $e) {
            $sectionfailed++;
            if ($firstfailure === '') {
                $firstfailure = $e->getMessage();
            }
            // V1.0.80: the outer guard swallowed its exception entirely. The parent record
            // still ends up marked 'error' below, but nothing said what went wrong.
            \debugging(
                'DocGuard: section loop aborted for submission ' . $subid . ' — '
                    . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }

        /* ── Persist the parent record ──────────────────────────────────────── */
        // Count what is actually in the table rather than trusting this request's
        // tally: under the concurrent-re-analysis race described below, another
        // process may have stored the rows this one failed to insert, and reporting
        // "0 section(s) analysed" above a full breakdown would be worse than the bug
        // being fixed.
        try {
            $sectionactual = (int)$DB->count_records('plagiarism_docguard_sec', ['subid' => $subid]);
        } catch (\Throwable $e) {
            // V1.0.80: was a silent fallback. If this count fails the reported
            // section_count may not match what is actually stored, which is exactly the
            // kind of quiet inconsistency the surrounding fix exists to prevent.
            \debugging(
                'DocGuard: could not count stored sections for submission ' . $subid
                    . ' — falling back to this run\'s tally. ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            $sectionactual = $sectionstored;
        }

        $update = new \stdClass();
        $update->id                 = $subid;
        $update->status             = $result['status'];
        $update->overall_riskscore  = $result['overall_riskscore'];
        $update->overall_risklevel  = $result['overall_risklevel'];
        // Report what is actually viewable, not what was theoretically produced.
        $update->section_count      = $sectionactual;
        $update->analysisjson       = $result['analysisjson'];
        $update->normtext           = $result['normtext'];
        $update->errormsg           = $result['error'] ?? null;
        $update->timemodified       = time();

        // If analysis produced sections but none could be stored, the report would
        // show a score with an empty breakdown and no explanation. Surface it as a
        // visible error instead — the teacher gets an actionable message and the
        // Re-analyse path stays open.
        //
        // Guarded by a re-count first. Two teachers clicking Analyse at once (or one
        // clicking while cron runs) both delete and re-insert sections 1..N; the
        // loser's inserts all collide on subid_secnum_ix. Without this check the
        // loser would overwrite the winner's perfectly good result with an error
        // status, hiding a breakdown that is sitting right there in the table.
        if ($sectionactual === 0 && !empty($result['sections'] ?? [])) {
            $update->status = 'error';
            // Kept under 120 characters — lib.php truncates badge error text there.
            $detail = $firstfailure !== ''
                ? ': ' . \core_text::substr(preg_replace('/\s+/', ' ', $firstfailure), 0, 60)
                : '.';
            $update->errormsg = 'Section breakdown could not be stored' . $detail . ' Use Re-analyse to retry.';
        } else if ($sectionfailed > 0) {
            // Partial failure. Deliberately NOT written to errormsg: that field is
            // only ever rendered for status='error' (lib.php badge, student_report
            // notification, report.php row title), so a note stored here would be
            // invisible to the teacher while making the record look faulty to anyone
            // reading the table. section_count above already reflects reality, and
            // the developer-level detail went to debugging() in the loop.
            \debugging(
                'DocGuard: submission ' . $subid . ' stored ' . $sectionactual
                    . ' of ' . ($sectionstored + $sectionfailed) . ' sections.',
                DEBUG_DEVELOPER
            );
        }

        $DB->update_record('plagiarism_docguard_sub', $update);
    }
}
