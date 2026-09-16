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

/**
 * DocGuard upgrade steps.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * v1.0.80 — two classes of thing were removed from every step below.
 *
 * 1. opcache_invalidate() / opcache_reset() blocks.
 *    An upgrade step is not the place to manage the opcode cache. Moodle runs upgrades
 *    from a request (or CLI process) whose opcode cache is not necessarily the one
 *    serving the site — under php-fpm with several pools, or opcache.restrict_api, the
 *    calls either hit the wrong cache or emit a warning and do nothing. Worse,
 *    opcache_reset() flushes the ENTIRE server-wide cache, which on shared hosting is
 *    every other tenant's problem too. If an opcode cache is serving stale plugin files
 *    that is a deployment matter for the sysadmin (restart php-fpm), not something a
 *    plugin gets to reach out and fix.
 *
 * 2. assign_capability() blocks that granted plagiarism/docguard:viewreport to
 *    teacher-archetype roles.
 *    A plugin upgrade must not edit the site's role definitions. That capability carries
 *    RISK_PERSONAL — it exposes other people's document text and risk scores — and
 *    handing it out from an upgrade step means an administrator's roles change during a
 *    point release, silently, with no prompt and no record beyond the upgrade log. Role
 *    defaults belong in the 'archetypes' block of db/access.php, which is precisely what
 *    that block is for; anything beyond the defaults is the administrator's decision to
 *    make in Define Roles. Existing sites are not harmed either way, because
 *    plagiarism_docguard_can_view_reports() in lib.php falls back to mod/assign:grade —
 *    that runtime check, not the grant, is what has always made markers able to open the
 *    report.
 *
 * Rows already written by earlier runs of those blocks are left alone. Removing the code
 * stops it happening again; it does not revoke anything.
 *
 * @param int $oldversion The version currently installed, from version.php.
 * @return bool Always true; a failed step throws instead.
 */
function xmldb_plagiarism_docguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    // V1.0.85 FIX-DG-DEAD-SAVEPOINTS: this function used to carry 26 `if ($oldversion <
    // N)` blocks for 11 distinct versions - five identical blocks at 2026051200, ten at
    // 2026051300, three at 2026051400. upgrade_plugin_savepoint() records the version in
    // the database; it does not change the local $oldversion, so every duplicate block
    // after the first still evaluated true and wrote the same savepoint again. All of
    // them were savepoint-only, so nothing was executed twice and no site was harmed -
    // but repeated savepoints at one version read as a mistake to anyone auditing an
    // upgrade path, which includes the Moodle plugin reviewer, and the next person adding
    // a step here had no way to tell which of ten identical blocks was the live one.
    //
    // Consolidated to one block per version, in ascending order. Every explanatory
    // comment from the merged blocks is kept verbatim - they are the record of why each
    // release exists - and the merge was verified by comparing the complete comment text
    // of the file before and after, which is identical.

    if ($oldversion < 2026051200) {
        // V1.0.3: added scheduled cleanup task and privacy provider.
        // No DB schema changes — savepoint only.

        // V1.0.18: FIX-DG-UPDATE-STATUS-REFLECTION (12 May 2026)
        // Restored update_status() as a no-op stub on plagiarism_plugin_docguard.
        // plagiarism_plugin_docguard does not extend plagiarism_plugin, so Moodle
        // versions whose plagiarismlib.php calls new ReflectionMethod(class, method)
        // without a try/catch guard throw a ReflectionException and crash the grading
        // page with "Method plagiarism_plugin_docguard::update_status() does not exist".
        // The stub satisfies ReflectionMethod on all Moodle versions while the global
        // plagiarism_docguard_before_standard_top_of_body_html() function continues to
        // handle the 4.3+ hook path. No DB schema changes.

        upgrade_plugin_savepoint(true, 2026051200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051300) {
        // FIX-DG-PRELOAD-PLAGIARISMLIB + FIX-DG-REMOVE-LEGACY-TOP-OF-BODY (v1.0.23):
        // No DB schema changes. New before_standard_head_html_generation hook callback pre-loads
        // plagiarismlib.php; legacy global plagiarism_docguard_before_standard_top_of_body_html() removed.

        // FIX-DG-PRELOAD-INLINE + FIX-DG-REMOVE-DEBUG-NOOP (v1.0.24): No DB schema changes.
        // Guarded require_once at top of lib.php; removed noisy debugging() calls in get_links().

        // FIX-DG-SITEWIDE-SETTINGS (v1.0.25): Added plagiarism_docguard_get_platform_settings()
        // which fetches and caches site-wide enable/disable toggles from the AI Grader platform.
        // is_cm_active() now checks platform settings first — if the admin enabled DocGuard
        // site-wide for all assignments or all quizzes, that overrides the per-activity checkbox.
        // Cached 30 minutes in Moodle config. No DB schema changes.

        // ADD-DG-STUDENT-ANSWER-DISPLAY (v1.0.26): student_report.php now shows the full
        // student answer text for each document section (previously only a 280-char excerpt
        // was shown). Text is rendered in a styled "Student's Answer" box with plain-text
        // normalisation (strip_tags, entity decode, NBSP collapse). Answers >600 chars get
        // a "show more / show less" inline toggle. No DB schema changes.

        // ADD-DG-SIGNAL-STATUS-KEY (v1.0.27): student_report.php signal table now includes a
        // collapsible "<details> Signal status key" legend above each signal table explaining
        // FIRED (suspicious pattern detected, points added) and Silent (evaluated, nothing
        // suspicious found, no points added). Each status badge also has a native title=""
        // tooltip for instant hover context. No DB schema changes.

        // ADD-DG-S12-PLAIN-ENGLISH (v1.0.28): student_report.php Cross-Student Similarity
        // section now leads with a highlighted amber callout box asking "Has this student
        // copied from another student in this class?" followed by a plain-English explanation
        // of what S12 checks (scans every other student's submission, measures text overlap,
        // flags high similarity as potential copying/shared notes). The technical Jaccard
        // method note is demoted to a small grey sub-caption below the callout box. No DB
        // schema changes.

        // FIX-DG-SESSION-LOCK (v1.0.29): Added \core\session\manager::write_close() before
        // all outbound HTTP calls in plagiarism_docguard_check_unlock() and
        // plagiarism_docguard_auto_unlock(). No DB schema changes.

        // FIX-DG-PRELOAD-LIB: Hook callback now also require_once(lib.php).
        // Re-added plagiarism_docguard_before_standard_top_of_body_html() function.
        // No DB schema changes.

        // FIX-DG-BODY-PRELOAD-LIB: before_standard_top_of_body_html_generation
        // callback now also loads plagiarismlib.php + lib.php as a secondary
        // belt-and-suspenders guarantee. No DB schema changes.

        // ADD-DG-DIAG (v1.0.32): diag.php included in ZIP for the first time.
        // File existed in source but was omitted from every prior ZIP build, causing
        // /plagiarism/docguard/diag.php to return 404 on all installed sites.
        // No functional PHP changes. No DB schema changes.

        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051400) {
        // FIX-SESSION-CLOSE-GUARD (v1.0.43): Guarded all three write_close() calls in
        // lib.php to AJAX/CLI contexts only. During a normal web page render, write_close()
        // caused "Session mutated after close: $SESSION->editedpages" because the admin
        // framework writes that key at end-of-render after the function returned.
        // No DB schema changes.

        // FIX-DG-PATH-A-STUB (v1.0.46): Restored update_status() stub on the Path A
        // (standalone) class declaration in lib.php.
        //
        // Root cause of "Method plagiarism_plugin_docguard::update_status() does not exist"
        // crash on the assignment grading page:
        //
        // 1. During early Moodle bootstrap (setup.php:855 get_plugins_with_function scan),
        // lib.php is require_once()'d for the first time.
        // 2. At that moment class_exists('plagiarism_plugin', false) === false — plagiarismlib.php
        // has not loaded yet — so Path A (standalone class, no update_status()) is compiled.
        // 3. require_once() prevents lib.php from executing again; Path A class is permanent.
        // 4. Later, on the assign grading page, plagiarism_update_status() is called.
        // 5. Moodle 4.4+ plagiarismlib.php:106 calls
        // new ReflectionMethod('plagiarism_plugin_docguard', 'update_status')
        // with NO surrounding try/catch.
        // 6. The method does not exist on the Path A class → ReflectionException → page crash.
        //
        // v1.0.19 had this stub; it was accidentally removed in v1.0.21 (parent class
        // provided it) then never restored when v1.0.45 reintroduced the Path A/B conditional.
        //
        // Fix: update_status() no-op stub added to Path A class. Method now always exists
        // regardless of which path the class definition took. No DB schema changes.

        // FIX-DG-FILESYSTEM-LOAD (v1.0.47): Replaced single-strategy $CFG->libdir
        // plagiarismlib.php load with dual-strategy loader to ensure Path B
        // (extends plagiarism_plugin) is always taken regardless of $CFG state.
        //
        // Root cause of deprecation: Path A (standalone class with update_status() stub)
        // was permanently compiled during early bootstrap. Later, Moodle 4.4+
        // plagiarismlib.php:106 calls ReflectionMethod unconditionally — finds the stub,
        // getDeclaringClass() = 'plagiarism_plugin_docguard' != 'plagiarism_plugin',
        // fires debugging(). Moodle's debugging() writes HTML directly to output buffer
        // so set_error_handler() cannot suppress it.
        //
        // Fix: Added filesystem-relative fallback path using dirname(dirname(dirname(__FILE__)))
        // which resolves to Moodle root regardless of $CFG. When plagiarismlib.php loads,
        // plagiarism_plugin is defined, Path B is taken, update_status() is inherited,
        // getDeclaringClass() returns 'plagiarism_plugin', debugging() is never called.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026051400, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026052200) {
        // V1.0.56: Removed DEFAULT="" from CHAR NOT NULL columns in install.xml (filename, filetype, contenthash, section_label).
        // No DB schema changes to existing sites.

        upgrade_plugin_savepoint(true, 2026052200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026060500) {
        // V1.0.67: PERF-FIX-DG-BATCH-PRELOAD — replaced N+1 DB query pattern + removed
        // synchronous lazy analysis from get_links(). No DB schema changes.

        upgrade_plugin_savepoint(true, 2026060500, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026061000) {
        // ADD-DG-PROCESS-PENDING (v1.0.69): Added process_pending scheduled task
        // that runs every hour and processes two classes of stuck submissions:
        // Phase 1 — status='pending' DB records older than 5 minutes whose
        // analyse_and_store() failed (API error, PDF extraction failure).
        // Phase 2 — submitted files in DocGuard-enabled assign CMs that have NO
        // plagiarism_docguard_sub record at all (common when the plugin
        // is installed or upgraded after students have already submitted
        // and the assessable_submitted observer never fired).
        // Also added a teacher-facing "Scan & Analyse Unprocessed Submissions" button
        // on report.php for immediate on-demand triggering without waiting for cron.
        // No DB schema changes.

        upgrade_plugin_savepoint(true, 2026061000, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026072000) {
        // GS-TIMEOUT-FIX (v1.0.73): Corrupt PDFs could hang Ghostscript (gs) indefinitely,
        // spawning a new stuck process every cron run and pushing server load to 47+ on a
        // 32-core machine. Fix: prepend "timeout 60" to both the pdftotext and gs exec()
        // calls in classes/extractor.php so a bad PDF is killed after 60 seconds max.
        // No DB schema changes.
        //
        // v1.0.80: opcache_invalidate() block removed from this step — see the note at the
        // top of this function.

        upgrade_plugin_savepoint(true, 2026072000, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026072300) {
        // FIX-API-DOMAIN: API endpoint URLs settled on lms-labs.com, the domain that
        // actually resolves from a Moodle server. All ajax.php, api_client, unlock_verifier
        // and lib.php calls updated. No DB schema changes.
        //
        // v1.0.80: this was three consecutive blocks all guarded on the same
        // $oldversion < 2026072300 and all doing nothing but flipping the domain comment
        // and invalidating the opcode cache. They are collapsed into one, and the
        // opcache_invalidate()/opcache_reset() calls are gone — see the note at the top of
        // this function.

        upgrade_plugin_savepoint(true, 2026072300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026081500) {
        /* ── v1.0.78 — report visibility and breakdown storage ──────────────── */
        //
        // FIX-DG-CAP-ARCHETYPES: db/access.php lists the 'teacher' (non-editing teacher)
        // archetype so new installs grant plagiarism/docguard:viewreport to markers.
        //
        // FIX-DG-REPORT-ACCESS: report.php and student_report.php accept mod/assign:grade
        // OR plagiarism/docguard:viewreport — the same test lib.php uses to decide whether
        // to render the link — so the two can no longer disagree. That runtime check is
        // what actually guarantees markers can open the report on an existing site.
        //
        // FIX-DG-SECTION-NUM: per-section rows are numbered sequentially before insert.
        //
        // No schema changes.
        //
        // v1.0.80: the assign_capability() loop that used to live here has been REMOVED —
        // see the note at the top of this function. Sites that already ran this step keep
        // the permission rows it created; nothing is taken away.

        upgrade_plugin_savepoint(true, 2026081500, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026081501) {
        /* ── v1.0.79 ────────────────────────────────────────────────────────── */
        // Existed only to give sites that had registered 2026081500 without running its
        // steps something above their recorded version to run. Its assign_capability()
        // loop and opcache block are removed in v1.0.80 for the reasons given at the top
        // of this function; the savepoint itself must stay so the version sequence a site
        // may already have recorded remains valid.

        upgrade_plugin_savepoint(true, 2026081501, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026082800) {
        /* ── v1.0.80 — make the "enabled" setting real without switching working
           sites off underneath their administrators ───────────────── */
        //
        // Plagiarism_docguard_is_enabled() now gates every entry point on the plugin's
        // 'enabled' config value, which until this release was written by settings.php
        // and read by nothing. That value is absent on every existing site, for a reason
        // that matters here: settings.php could never be opened at all (it called
        // admin_externalpage_setup() for a page nothing registered), so no administrator
        // has ever been able to save it. Treating "absent" as off would therefore stop
        // analysis on every site that is currently working, on a point upgrade, with no
        // warning — a regression dressed up as a security fix.
        //
        // So: a site that already has DocGuard credentials configured is treated as
        // having consented to it running, and gets an explicit enabled=1 written once.
        // Everyone else — including every fresh install — starts off and is switched on
        // deliberately by an administrator on the settings page, which now opens.
        //
        // Idempotent: only writes when the key is genuinely absent.
        if (get_config('plagiarism_docguard', 'enabled') === false) {
            $dgsiteid = (string)(get_config('plagiarism_docguard', 'siteid') ?: '');
            $dgapikey = (string)(get_config('plagiarism_docguard', 'apikey') ?: '');
            $configured = ($dgsiteid !== '' && $dgapikey !== '')
                || (!empty(
                    get_config('local_aiconfig', 'siteid'))
                        && !empty(get_config('local_aiconfig', 'apikey'))
                );
            set_config('enabled', $configured ? 1 : 0, 'plagiarism_docguard');
            upgrade_log(
                UPGRADE_LOG_NORMAL,
                'plagiarism_docguard',
                $configured
                    ? 'Existing DocGuard credentials found — site-wide "enabled" setting written as ON to preserve '
                        . 'current behaviour.'
                : 'No DocGuard credentials configured — site-wide "enabled" setting written as OFF (opt-in default).'
            );
        }

        // Per-activity default also changes in this release: an activity with NO saved
        // DocGuard value now counts as OFF, where it used to count as ON. Deliberately
        // NOT backfilled with enabled_cm_<cmid> = 1 rows. The whole point of the change
        // is that those activities never opted in — their teachers were shown an unticked
        // box — and writing a row for each would both cement a choice nobody made and
        // create one config row per assignment on the site. Teachers switch DocGuard on
        // per activity, or an administrator uses the platform-wide flag.

        upgrade_plugin_savepoint(true, 2026082800, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026082906) {
        /* ── v1.0.88 — repair what the two data defects fixed in this release left
           behind. Both steps correct previously-stored rows that are simply wrong;
           neither touches a row that is right. ───────────────────────────────── */

        // Step 1 - FIX-DG-ORPHAN-ON-DELETE.
        //
        // Until this release DocGuard observed no deletion event, so every activity or
        // course ever deleted left its submission and section records behind — including
        // normtext and section_text, the full extracted text of the student's document.
        // Those rows are not merely stale, they are UNREACHABLE: both privacy paths key on
        // contextid, core deletes the module context before it announces the deletion, and
        // core_privacy's contextlist silently drops any context it cannot instantiate. So
        // the data could not be exported to the student who asked for it, could not be
        // erased when they asked for it, and would sit there for the life of the database.
        //
        // Deleting it is the only defensible answer. There is nothing to preserve: the
        // activity, its submissions and its files are gone, no report can render, no badge
        // can be drawn, and the only thing the rows can still do is hold personal data
        // nobody can reach.
        //
        // Keyed on cmid, not contextid, because the context row is exactly what is missing.
        // cmid = 0 rows are excluded: those are malformed rather than orphaned, and an
        // upgrade step should not delete data on the strength of a zero.
        //
        // classes/task/cleanup.php runs the same sweep nightly from now on, which is what
        // covers course deletion (lib/moodlelib.php::remove_course_contents() triggers no
        // per-module event) and anything a future core change routes around the observer.
        $orphansub = 'cmid > 0 AND NOT EXISTS ('
            . 'SELECT 1 FROM {course_modules} cm WHERE cm.id = {plagiarism_docguard_sub}.cmid)';
        $orphanids = $DB->get_fieldset_select('plagiarism_docguard_sub', 'id', $orphansub, []);
        if ($orphanids) {
            [$dgin, $dgparams] = $DB->get_in_or_equal($orphanids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $dgin", $dgparams);
            $DB->delete_records_select('plagiarism_docguard_sub', $orphansub, []);
        }
        $orphansec = 'cmid > 0 AND NOT EXISTS ('
            . 'SELECT 1 FROM {course_modules} cm WHERE cm.id = {plagiarism_docguard_sec}.cmid)';
        $orphansecs = $DB->count_records_select('plagiarism_docguard_sec', $orphansec, []);
        if ($orphansecs > 0) {
            $DB->delete_records_select('plagiarism_docguard_sec', $orphansec, []);
        }
        upgrade_log(
            UPGRADE_LOG_NORMAL,
            'plagiarism_docguard',
            'Removed ' . count($orphanids) . ' submission record(s) and ' . $orphansecs
                . ' further section record(s) belonging to activities that no longer exist. '
                . 'These held student document text and were unreachable by the privacy provider.'
        );

        // Step 2 - FIX-DG-BACKFILL-FILE-GRANULARITY.
        //
        // The historical backfill used to write one terminal "unsupported" marker per
        // SUBMISSION, with filename and contenthash both empty, because that was the only
        // thing that could satisfy its old (cmid, userid) exclusion. The exclusion is now
        // keyed on contenthash, against which an empty hash matches nothing — so those rows
        // would suppress nothing while remaining permanently in the table, and worse, a
        // submission covered only by such a marker would never be re-examined correctly.
        //
        // Delete them and let the backfill re-derive one marker per file with the file's
        // real name and hash. That is safe and mechanical: these rows carry no analysis, no
        // score and no text — filename '', contenthash '', section_count 0 — they are pure
        // bookkeeping, they render nothing (render_badge() returns '' for this status and
        // report.php filters it out), and the phase that wrote them is off by default.
        //
        // Markers written by the new code are matched by neither condition, so re-running
        // this step would be a no-op.
        $legacymarkers = $DB->count_records_select(
            'plagiarism_docguard_sub',
            "status = :status AND contenthash = :emptyhash",
            ['status' => 'unsupported', 'emptyhash' => '']
        );
        if ($legacymarkers > 0) {
            $DB->delete_records_select(
                'plagiarism_docguard_sub',
                "status = :status AND contenthash = :emptyhash",
                ['status' => 'unsupported', 'emptyhash' => '']
            );
            upgrade_log(
                UPGRADE_LOG_NORMAL,
                'plagiarism_docguard',
                'Removed ' . $legacymarkers . ' submission-wide "unsupported" marker row(s). The historical '
                    . 'backfill now records one marker per file, keyed on the file content hash.'
            );
        }

        upgrade_plugin_savepoint(true, 2026082906, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026091600) {
        /*
         * V1.0.92 — Moodle Marketplace review MMRT-179.
         *
         * No schema changes. Every item in this release is code or packaging:
         *
         *   SEC-DG-GS-SAFER      -dSAFER added to the Ghostscript command line in
         *                        classes/extractor.php. Approval blocker: every PDF
         *                        reaching the extractor is a student upload, and without
         *                        the flag Ghostscript honours PostScript operators that
         *                        read and write arbitrary paths, and on affected versions
         *                        execute commands through %pipe% device names.
         *
         *   SEC-DG-APIKEY-HEADER The licence API key now travels in a request header
         *                        instead of the query string. curl::get($url, $params)
         *                        appends parameters to the URL, so the credential was
         *                        written into the vendor's access logs, every proxy log on
         *                        the path, and any reporting that records full URLs.
         *
         *   PERF-DG-OBSERVER-ADHOC  The assessable_submitted observer queues the new
         *                        analyse_submission adhoc task instead of running
         *                        extraction and scoring inline in the student's submit
         *                        request.
         *
         *   Backup/restore       backup/moodle2/ now carries the per-activity enablement
         *                        flag through course backup, restore and duplicate. It is
         *                        stored as config key enabled_cm_<cmid>, so the restore
         *                        class remaps it onto the new course module id.
         *
         *   Autoloading, CSS     Redundant require_once of autoloaded classes removed from
         *                        both scheduled tasks; manual $PAGE->requires->css() calls
         *                        removed from the two report pages, since Moodle already
         *                        aggregates every plugin's styles.css.
         */
        upgrade_plugin_savepoint(true, 2026091600, 'plagiarism', 'docguard');
    }

    return true;
}
