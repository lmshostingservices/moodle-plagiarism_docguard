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

    if ($oldversion < 2026100600) {
        /*
         * V1.0.93 — signal validity.
         *
         * No schema changes. Every change is to how the signals measure, and to what the
         * reports claim. Prompted by measuring the engine against texts of known origin,
         * where a second-language student essay and a VET policy answer both scored
         * higher than actual generated prose.
         *
         *   S1  The 125-entry marker list held 23 ordinary English words, 19 entries that
         *       were substrings of other entries, and 4 that S4 also scored. It counted
         *       distinct markers, so a long document scored higher for its length, and it
         *       computed a density it never used. Now a curated list, word-boundary
         *       matched, scored on density per 1000 words against bands fitted to texts
         *       of known origin.
         *
         *   S5  Matched contractions with ASCII apostrophes only. Word and PDF produce
         *       U+2019, so on a real submission no contraction was ever found and the
         *       signal awarded its full six points for their "absence" to text full of
         *       them. Apostrophes are normalised first.
         *
         *   S6  Treated any word ending -ed or -en as a past participle, scoring "is
         *       open", "are seven" and "was keen" as passive voice while missing
         *       "mistakes were made" and "the city was built". Rewritten with a real
         *       participle test.
         *
         *   S3  Split paragraphs on blank lines only, so it never ran on a PDF — the
         *       format most students submit, where pdftotext gives single newlines. It now
         *       reports itself as not measured in that case. A single-newline fallback was
         *       built first and removed: treating every line as a paragraph fabricated
         *       uniformity that was not there and awarded a false 8 of 8.
         *
         *   S12 report.php listed pairs from 0.30 under a misconduct heading while the
         *       scoring function awarded nothing below 0.35. One constant now governs
         *       both.
         *
         * The reports now state what was and was not checked, and each style signal
         * carries its own caveat about register. tests/signal_validity_test.php states
         * each of these as a requirement so none can return silently.
         */
        upgrade_plugin_savepoint(true, 2026100600, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026100700) {
        /*
         * V1.0.94 — signal validity, second round.
         *
         * No schema changes. This release continues measuring the engine against documents
         * of known origin and withdraws or corrects what does not survive the measurement.
         *
         *   Scoring scope. Quotations and the reference list are now removed before the
         *       style signals run. Both directions were wrong. A measured bibliography of
         *       eight ordinary VET titles scored 11 of 22 on S1, because academic titles
         *       are written in exactly the register the marker list describes — the student
         *       wrote none of those words. The same reference list also enlarged the
         *       denominator: a generated passage measuring 382 marker hits per 1000 words
         *       fell to 166 when eight citations were appended, so padding a submission
         *       with references was a working evasion. A section with almost nothing left
         *       after the exclusions is now reported as having too little of the student's
         *       own prose to measure, rather than being scored as submitted or reported as
         *       low risk. The word count set aside and the reason are stored with the
         *       signals and shown on the report: excluding part of a submission from
         *       scoring without telling the teacher would be worse than not excluding it.
         *
         *   S2, S3, S9. These three read the text with its line breaks intact and were
         *       still being handed the raw submission after the stripping was added, so a
         *       reference list S1 no longer saw was still measured for sentence uniformity
         *       and uniform openers. All signals now read one named variable.
         *
         *   S6 withdrawn from scoring. Once the counting was corrected in 1.0.93 the
         *       signal was measured, and it ran backwards. Passive ratio across fourteen
         *       documents: generated 0.000, 0.006, 0.007; human 0.000 to 0.135. The three
         *       generated essays are the least passive documents in the set. The only three
         *       human documents that cleared the old 0.025 threshold were a policy
         *       document, a lab report and a nursing clinical answer — every one a register
         *       in which the passive is the required house style. It awarded points to 3 of
         *       11 humans and 0 of 3 generated texts, so every point it ever contributed
         *       went to a person writing correctly for their profession. The premise was
         *       inherited from pre-LLM readability checkers; no threshold fixes a signal
         *       pointing the wrong way. The measurement is still displayed, as an
         *       observation worth 0, because passive density describes a piece of writing
         *       even though it says nothing about who wrote it.
         *
         *   Short sections. A section under 150 words is marked as not measurable instead
         *       of reported as a clean low result. Truncating the known-origin documents
         *       and rescoring: the same generated essays score 8, 5 and 5 at twenty-five
         *       words and 33, 34 and 38 at a hundred and fifty. Nothing in the set scored
         *       materially higher truncated than whole, so a short section can fail to flag
         *       but cannot falsely flag — which makes the reassuring reading the dangerous
         *       one.
         *
         *   Shared constants. The band thresholds followed the S12 threshold into named
         *       constants, and the attainable maximum is now recorded and tested: S1-S10
         *       total 84, not the 100 the report displays against.
         *
         * Effect on the known-origin set: human documents 0 of 11 flagged MEDIUM or above
         * (highest 27, a second-language student essay), generated 3 of 3 flagged (lowest
         * 37). The professional-register documents that S6 had been penalising fell —
         * nursing 15 to 9, lab report 12 to 6, policy 14 to 8 — with no change to any
         * generated document.
         *
         * Fourteen hand-written documents demonstrate a defect; they do not measure a
         * false-positive rate. A blind validation set of several hundred real submissions
         * of known provenance, including second-language writing, remains the prerequisite
         * for any accuracy claim about this plugin.
         */
        upgrade_plugin_savepoint(true, 2026100700, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026100800) {
        /*
         * V1.0.95 - defects found by auditing 1.0.94.
         *
         * No schema changes. Everything here is a fault introduced by 1.0.94 itself, found
         * by reviewing that release rather than by new measurement, and two of the three
         * had defeated the fix 1.0.94 existed to make.
         *
         *   S11 was applied to sections that were never scored. The distribution loop ran
         *       over every section, including ones score_section() had declined to score.
         *       A bibliography section was given 10 points on a word count of zero - and,
         *       worse, injecting the signal made that section's signal set non-empty, so
         *       the report rendered a one-row signal table instead of the explanation that
         *       there was too little of the student's own prose to measure. VET submissions
         *       are question-per-section almost by definition, so 1.0.94's main fix was
         *       unreachable on close to every real document.
         *
         *       The loop was a dozen lines inside analyse_file(), which needs a stored_file
         *       and a database and was therefore never unit tested. It is now
         *       apply_cross_section_signal(), taking and returning plain arrays, so the
         *       behaviour is a test rather than a code review.
         *
         *   Quotation marks were paired by proximity, not position. The pattern
         *           /"(?:[^"]{15,})"/u
         *       lets a regex engine scanning left to right pair a CLOSING mark with the
         *       next OPENING one. On an answer using three short scare-quotes it kept
         *       "restructure", "right-sized" and "opportunity" and removed the student's
         *       narration between them - 20 words of a 74-word answer, the exact reverse of
         *       the intent. Marks are now paired by position, the threshold is 20 words
         *       rather than 15 characters so dialogue and scare-quotes survive, and an
         *       unbalanced mark from OCR or a typo no longer swallows the rest of the
         *       answer.
         *
         *   Three tests had been failing since 1.0.93 and nothing had run them. There is no
         *       Moodle in the build environment, so tests/ had been lint-checked and never
         *       executed. One asserted a flat zero below a hard word floor that 1.0.93 had
         *       already replaced with a confidence ramp; two cases of the S1 density
         *       provider had been transcribed from the band table instead of measured, and
         *       were simply wrong about which band a given density falls in. All three now
         *       assert measured behaviour against the named constants.
         *
         *   score_section() returns the same keys on every path. 'lowconfidence' was absent
         *       from the under-eight-words branch.
         *
         * The known-origin figures are unchanged: human 0 of 11 flagged MEDIUM or above,
         * highest 27; generated 3 of 3 flagged, lowest 37. The mutation suite now
         * reintroduces sixteen fixed defects and a named test catches each one.
         */
        upgrade_plugin_savepoint(true, 2026100800, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026100900) {
        /*
         * V1.0.96 - withdrawal of a performance claim. No engine changes.
         *
         * Releases from 1.0.93 reported flagging "3 of 3 generated documents, lowest 37"
         * against "0 of 11 human, highest 27". Those three generated documents were
         * hand-written imitations of AI style, composed from the same folklore the S1
         * marker list was built from. Test set and detector shared an assumption, so the
         * measurement was circular.
         *
         * Measured against sixteen answers to real VET prompts produced by a current
         * language model - ten natural, six prompted to sound like a struggling student,
         * 129 to 196 words:
         *
         *   genuine model output, natural           0 to 9    0 of 10 flagged
         *   genuine model output, evasion-prompted  0 to 3     0 of 6 flagged
         *   human documents                         0 to 27   0 of 11 flagged
         *
         * S1 found zero markers in all sixteen, and up to three in human documents. The
         * highest-scoring document in the whole set is a second-language student essay.
         *
         * The marker effect is real at corpus scale (Kobak et al., Science Advances 2025)
         * but the words decay once known: delve, intricate, showcasing, realm and pivotal
         * have declined in published writing since March 2024 (Geng & Trotta,
         * arXiv:2502.09606), and markers differ by model generation - underscore appears
         * at 18 per million words in GPT-3.5 and 1,365 in GPT-4o-mini. A marker list is
         * good for roughly 12 to 18 months. This one is from 2024.
         *
         * S12 cross-student similarity is unaffected and remains the part of this plugin
         * with evidence behind it.
         */
        upgrade_plugin_savepoint(true, 2026100900, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101000) {
        /*
         * V1.0.97 - second vendor confirms the 1.0.96 finding, and S5 is fixed again.
         *
         * ChatGPT was given the same sixteen VET prompts Claude had been given. Combined,
         * across two vendors and 32 genuine machine-written documents:
         *
         *   genuine AI, both vendors    0 to 14    0 of 32 flagged
         *   human documents             0 to 27    0 of 11 flagged
         *
         * S1 found zero markers in all 32. The highest-scoring document in the entire
         * exercise remains a human second-language student essay.
         *
         * S5 FIX. 1.0.93 fixed the case where Word supplies U+2019 instead of an ASCII
         * apostrophe. It did not fix the writer who types no apostrophe at all, which is
         * the commoner case. Every one of the six informally-written submissions scored
         * 3 of 6 for "contraction absence" while containing up to seven contractions -
         * "dont", "shouldnt", "thats", "cant". The signal reported the opposite of what
         * was in front of it. Dropping apostrophes goes with hurried and lower-literacy
         * writing, so the fault fired hardest on the students least able to answer the
         * accusation it feeds. Forms that are also ordinary English words - its, were,
         * well, ill, id, hes, shed, wed - are deliberately not matched, because matching
         * them would silence the signal on nearly every document and that would be a
         * covert withdrawal rather than a fix.
         *
         * S3 CORRECTION. Earlier releases recorded that S3 "never fires on a real
         * document". That was an artefact of the test corpus, not a property of S3: the
         * documents had been written as single unbroken blocks, and S3 splits on blank
         * lines, so it was never measured at all. Against real ChatGPT output, which has
         * three or four paragraphs, S3 measured on 16 of 16 and fired on 6. It is the one
         * style signal showing any response to genuine model output. The human baseline
         * for it is still entirely unmeasured for the same reason, so it cannot yet be
         * compared, and no conclusion is drawn from it here.
         */
        upgrade_plugin_savepoint(true, 2026101000, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101100) {
        /*
         * V1.0.98 - third vendor. Documentation only; no code changes.
         *
         * Gemini was given the same sixteen VET prompts as Claude and ChatGPT, in a fresh
         * chat, with no mention that the output would be tested. Combined:
         *
         *   genuine AI, 3 vendors, 48 documents   0 to 14    0 of 48 flagged
         *   human documents, 11                   0 to 27    0 of 11 flagged
         *
         * S1 found ZERO markers in 48 of 48 genuine machine documents. The highest-scoring
         * document in the whole exercise is still a human second-language student essay,
         * at nearly twice the highest machine document.
         *
         * S3 remains the one style signal responding to real model output: it fired on 6
         * of 16 ChatGPT documents and 11 of 16 Gemini documents. Its human baseline is
         * still entirely unmeasured, because the human corpus has no blank-line
         * paragraphs, so no conclusion is drawn from it. Establishing that baseline needs
         * real student submissions with paragraph structure and is the next measurement
         * worth making.
         */
        upgrade_plugin_savepoint(true, 2026101100, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101200) {
        /*
         * V1.0.99 - the writing-style signals are removed, and copy evidence becomes the
         * score. No schema change: the existing columns are reused with a new meaning,
         * and every analysed row now records which meaning applies to it.
         *
         * WHY THE SIGNALS WENT. They were tested against 48 answers to real VET prompts
         * generated by three current language models - Claude, ChatGPT and Gemini, each
         * answering naturally and again rewritten to sound like a student who struggles
         * with written English, 129 to 196 words:
         *
         *   genuine machine-written   0 to 14 of 100    0 of 48 flagged
         *   human                     0 to 27 of 100    0 of 11 flagged
         *
         * S1, the largest signal at 22 of the 84 attainable points, found zero markers in
         * 48 of 48. The highest-scoring document in the exercise was a human
         * second-language student essay, at nearly twice the highest machine document.
         *
         * They were removed rather than kept on display as unscored observations.
         * Anything shown beside a copying verdict is read as corroborating it, which is
         * how institutions came to over-rely on detector output in the appeals the OIA
         * upheld in 2025. For a tool that feeds misconduct decisions that is a safety
         * problem, not an untidiness. 1,323 lines came out of analyser.php.
         *
         * WHY COPY EVIDENCE BECOMES THE SCORE. It was already the only measurement with
         * evidence behind it - 92% on a genuine copy, 13% on two students answering the
         * same closed question independently, 0.7% on unrelated work - and it contributed
         * nothing. compute_s12_score() was dead code, never called anywhere in the
         * plugin. cross_student_similarity() ran only when a teacher happened to open one
         * student's report, recomputing every pair on each page load and storing nothing.
         * The badge on the submission list came from a weighted average of style points.
         *
         * So a student who copied another student verbatim carried a LOW badge, because
         * verbatim copying says nothing about writing style, while a second-language
         * student who wrote their own answer carried 27 of 100. That is now the right way
         * round: the score is the similarity percentage, applied to both sides of a
         * flagged pair, computed once at analysis time.
         *
         * The band boundaries need no rescaling - 35% and 65% sit where the measurements
         * put them, and 35% is the same number that governs whether a pair is listed at
         * all, so the score and the pair list cannot disagree.
         *
         * QUOTATION STRIPPING NOW SERVES THE COMPARISON. It was built to stop the style
         * signals charging a student for an author they quoted. It earns its place for a
         * better reason: two students quoting the same legislation share wording neither
         * wrote. Measured on two unrelated answers carrying one shared quotation,
         * similarity was 47.5% with the quotation left in - above the reporting threshold,
         * a false copy match put to a teacher - and 0.0% with it removed.
         *
         * LEGACY ROWS. Rows written before this release carry no 'score_model' stamp in
         * analysisjson. Their score means a style-signal sum, which is a different
         * statement from a similarity percentage, so the reports label them as scored
         * under the previous model instead of silently reinterpreting them. Re-analyse
         * rescores a submission under the current model. No stored data is altered by this
         * upgrade.
         */
        upgrade_plugin_savepoint(true, 2026101200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101300) {
        /*
         * V1.1.0 - finishing what 1.0.99 started. No schema change.
         *
         * 1.0.99 changed what the score means and removed the style signals, but left the
         * old model's debris behind. The text a teacher actually reads still described an
         * AI-writing indicator.
         *
         *   USER-FACING TEXT. The interpretation guide said "Multiple strong AI-writing
         *       indicators present" beside a similarity percentage. The badge tooltips
         *       said "Significant signals detected". The student disclosure said the
         *       submission would be "analysed for indicators of plagiarism and
         *       AI-generated writing". The band labels said "High risk" where they now
         *       mean "most wording shared with another submission". All rewritten to
         *       describe what is actually measured, including the privacy metadata, which
         *       described signalsjson as per-signal findings that no longer exist.
         *
         *   CONCERN BANDS. report.php coloured and labelled pairs at 0.70 and 0.50 while
         *       the bands are 65 and 35, so the same pair was described one way in the
         *       table and another way everywhere else. This is the THIRD appearance of
         *       the same defect - the S12 reporting threshold drifted from the scoring
         *       threshold (1.0.93), band literals were duplicated out of band() (1.0.94),
         *       and now this. Every threshold a reader can see comes from the constants,
         *       with a mutation guard that reintroduces the literals.
         *
         *   THE COPY DECISION IS NOW TESTABLE. Which submissions to rescore, and to what,
         *       was a dozen $DB->set_field() calls inside observer and could not be
         *       executed by a test. That is the condition that hid both defects shipped
         *       in 1.0.94. It is now analyser::plan_copy_evidence(), a pure function over
         *       plain arrays, with six tests and five mutation guards, and the observer
         *       holds nothing but the reads and the writes. The raise-only rule is the one
         *       worth having a test for: a later, lower match must never pull an existing
         *       higher one down, or a student could clear a 90% match by submitting again.
         *
         *   COPYING LEADS THE CLASS REPORT. It used to sit below the submissions table,
         *       under a column of style scores. It is the only check the plugin performs
         *       that compares a submission against anything.
         *
         *   LEGACY ROWS. The class report counts submissions still carrying a score from
         *       the previous model and explains them. No bulk rescore action is offered:
         *       it needs new task and database code, the one area this plugin has
         *       repeatedly shipped defects in, so it waits for a release that can be
         *       verified against a real Moodle. The per-submission Re-analyse path works
         *       now, and the copying table is computed fresh regardless of which model
         *       scored a submission.
         *
         *   31 orphaned language strings for the deleted signal table were removed.
         *
         * Nothing in the database is altered by this upgrade.
         */
        upgrade_plugin_savepoint(true, 2026101300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101400) {
        /*
         * V1.1.1 - findings from a live site. No schema change.
         *
         * A LIVE SITE WAS QUERIED, which is the first time anything in this plugin has
         * been checked against a real installation rather than against a corpus written
         * for the purpose. Two things came out of it.
         *
         *   'minsectionwords' WAS A DEAD SETTING. It was saved, read back, rendered in the
         *       settings form, and described to the administrator as "Minimum word count
         *       per section to include it in analysis. Sections shorter than this are
         *       skipped" - and nothing in the plugin ever read it. It was found because
         *       the site had it set to 200, which is not the default: somebody configured
         *       it deliberately and got no effect.
         *
         *       Removed rather than implemented. Sections are no longer analysed at all -
         *       the comparison runs on the whole document - so the setting cannot do what
         *       it claims, and hiding short sections from a marker would be a loss rather
         *       than a control. The real behaviour, which the setting never governed, is
         *       question_parser::MIN_SECTION_CHARS (40 characters) with a fallback to the
         *       whole document, and that is now documented in the README. The orphaned
         *       config row is left in place rather than deleted, so an administrator
         *       downgrading does not silently lose a value they set.
         *
         *   TEXT RETENTION AND COPY DETECTION CONFLICT. Copy detection needs normtext; the
         *       cleanup task deletes normtext after retentiondays, default 90. On the site
         *       queried, two of three analysed submissions had already been pruned, so one
         *       submission was comparable and no pair could be formed. Within one activity
         *       this rarely bites, because classmates submit within days of each other. It
         *       makes cross-cohort detection impossible by construction, which is the
         *       thing an RTO most needs. Documented in the README, with the resolution for
         *       when cross-cohort comparison is built: store an irreversible bigram
         *       fingerprint that survives pruning, which is better for privacy than
         *       retaining the text and is all the comparison needs. Nothing in this
         *       release implements it.
         *
         * The upgrade path from the version actually installed on that site (2026091600,
         * release 1.0.92) was simulated end to end: eight savepoints, monotonically
         * increasing, no duplicates, none re-running a completed step, final savepoint
         * equal to version.php.
         */
        upgrade_plugin_savepoint(true, 2026101400, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101500) {
        /*
         * V1.1.2 - a measurement helper that measured the wrong thing. No schema change.
         *
         * question_parser::similarity() wrapped PHP's similar_text, a character-level
         * longest-common-substring measure. Copy detection in this plugin has never used
         * it - that is jaccard_sets() over word bigrams - and the two do not agree even
         * approximately:
         *
         *     pair                              old helper   production
         *     independent, same question           54.4%         9.8%
         *     unrelated topics (control)           23.0%         1.1%
         *
         * Any two pieces of English prose share most of their characters, so it reported
         * 23% for a hand-washing procedure against a paragraph on mitochondria.
         *
         * It was never called by production code. It was called by two tests, and that is
         * the damage: tests/signal_validity_test.php asserted the quotation-stripping
         * result - the finding that a shared quotation must not read as copying - against
         * the wrong measure, and passed. The guard for one of this release series' real
         * fixes was not measuring the fix.
         *
         * Removed rather than renamed. A function called similarity(), in a plugin whose
         * entire purpose is similarity, that computes something else, caught two separate
         * pieces of work written the same hour. Production has jaccard_sets() and nothing
         * needs a second answer. The test now measures exactly what
         * cross_student_similarity() measures, and a mutation guard fails if it drifts
         * back.
         *
         * Also hardened the mutation runner: when its checker crashed on the removed
         * function it produced no output, which is indistinguishable from 'no guard
         * fired', so 16 of 17 mutations reported NOT CAUGHT and the suite reported a
         * catastrophe that was really one stale call. It now refuses to interpret a
         * crashed or already-failing checker as a result.
         */
        upgrade_plugin_savepoint(true, 2026101500, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026101600) {
        /*
         * V1.1.3 - from the first full run of the plugin's own test suite on a real Moodle.
         * No schema change. 192 passed, 1 failed, 1 skipped, 631 assertions; both the
         * failure and the skip are addressed here.
         *
         *   A DELETED ACTIVITY WAS REPORTED AS DISABLED. scan_activity::execute() checked
         *       plagiarism_docguard_is_cm_active() before checking whether the course
         *       module still existed, and is_cm_active() returns false for a deleted
         *       module - so a queued scan whose activity had been removed before cron
         *       reached it reported "DocGuard is not enabled for this activity", and the
         *       "no longer exists" branch below it was unreachable.
         *
         *       Nothing was analysed wrongly; the scan stopped either way. The damage was
         *       to the diagnostic: an administrator reading cron output was sent to look
         *       for a setting on an activity that no longer exists. Existence is now
         *       checked first, which is also the cheaper question - a deleted activity
         *       short-circuits before the per-activity config lookup and before
         *       check_unlock(), which performs a licence verification.
         *
         *       Caught by test_execute_when_activity_has_gone, which calls
         *       course_delete_module() and asserts the message. It needs Moodle's data
         *       generator, so it had never been executed before.
         *
         *   THE PDF EXTRACTION TIER IS NOW VISIBLE. The digit-heavy extraction test
         *       skipped on that server because pdftotext was not installed. PDF text is
         *       extracted by a three-tier cascade - pdftotext, then Ghostscript, then a
         *       pure-PHP fallback that the code itself describes as lowest quality - and
         *       nothing anywhere told an administrator which tier their server was on.
         *       That site was silently running the fallback.
         *
         *       Not cosmetic: the comparison runs on extracted text, so a poor extraction
         *       produces a similarity figure about nothing. extractor::extraction_tools()
         *       now reports the tier, the settings page shows it with the remedy, and the
         *       install verifier reports it too.
         */
        upgrade_plugin_savepoint(true, 2026101600, 'plagiarism', 'docguard');
    }

    return true;
}
