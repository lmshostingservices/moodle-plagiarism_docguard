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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_docguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026051200) {
        upgrade_plugin_savepoint(true, 2026051200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051200) {
        upgrade_plugin_savepoint(true, 2026051200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051200) {
        upgrade_plugin_savepoint(true, 2026051200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051200) {
        // v1.0.3: added scheduled cleanup task and privacy provider.
        // No DB schema changes — savepoint only.
        upgrade_plugin_savepoint(true, 2026051200, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051200) {
        // v1.0.18: FIX-DG-UPDATE-STATUS-REFLECTION (12 May 2026)
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

    // FIX-DG-PRELOAD-PLAGIARISMLIB + FIX-DG-REMOVE-LEGACY-TOP-OF-BODY (v1.0.23):
    // No DB schema changes. New before_standard_head_html_generation hook callback pre-loads
    // plagiarismlib.php; legacy global plagiarism_docguard_before_standard_top_of_body_html() removed.
    if ($oldversion < 2026051300) {
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    // FIX-DG-PRELOAD-INLINE + FIX-DG-REMOVE-DEBUG-NOOP (v1.0.24): No DB schema changes.
    // Guarded require_once at top of lib.php; removed noisy debugging() calls in get_links().
    if ($oldversion < 2026051300) {
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    // FIX-DG-SITEWIDE-SETTINGS (v1.0.25): Added plagiarism_docguard_get_platform_settings()
    // which fetches and caches site-wide enable/disable toggles from the AI Grader platform.
    // is_cm_active() now checks platform settings first — if the admin enabled DocGuard
    // site-wide for all assignments or all quizzes, that overrides the per-activity checkbox.
    // Cached 30 minutes in Moodle config. No DB schema changes.
    if ($oldversion < 2026051300) {
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }


    if ($oldversion < 2026051300) {
        // ADD-DG-STUDENT-ANSWER-DISPLAY (v1.0.26): student_report.php now shows the full
        // student answer text for each document section (previously only a 280-char excerpt
        // was shown). Text is rendered in a styled "Student's Answer" box with plain-text
        // normalisation (strip_tags, entity decode, NBSP collapse). Answers >600 chars get
        // a "show more / show less" inline toggle. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }


    if ($oldversion < 2026051300) {
        // ADD-DG-SIGNAL-STATUS-KEY (v1.0.27): student_report.php signal table now includes a
        // collapsible "<details> Signal status key" legend above each signal table explaining
        // FIRED (suspicious pattern detected, points added) and Silent (evaluated, nothing
        // suspicious found, no points added). Each status badge also has a native title=""
        // tooltip for instant hover context. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }


    if ($oldversion < 2026051300) {
        // ADD-DG-S12-PLAIN-ENGLISH (v1.0.28): student_report.php Cross-Student Similarity
        // section now leads with a highlighted amber callout box asking "Has this student
        // copied from another student in this class?" followed by a plain-English explanation
        // of what S12 checks (scans every other student's submission, measures text overlap,
        // flags high similarity as potential copying/shared notes). The technical Jaccard
        // method note is demoted to a small grey sub-caption below the callout box. No DB
        // schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }


    if ($oldversion < 2026051300) {
        // FIX-DG-SESSION-LOCK (v1.0.29): Added \core\session\manager::write_close() before
        // all outbound HTTP calls in plagiarism_docguard_check_unlock() and
        // plagiarism_docguard_auto_unlock(). No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051300) {
        // FIX-DG-PRELOAD-LIB: Hook callback now also require_once(lib.php).
        // Re-added plagiarism_docguard_before_standard_top_of_body_html() function.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051300) {
        // FIX-DG-BODY-PRELOAD-LIB: before_standard_top_of_body_html_generation
        // callback now also loads plagiarismlib.php + lib.php as a secondary
        // belt-and-suspenders guarantee. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051300) {
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
        upgrade_plugin_savepoint(true, 2026051400, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051400) {
        // FIX-DG-PATH-A-STUB (v1.0.46): Restored update_status() stub on the Path A
        // (standalone) class declaration in lib.php.
        //
        // Root cause of "Method plagiarism_plugin_docguard::update_status() does not exist"
        // crash on the assignment grading page:
        //
        // 1. During early Moodle bootstrap (setup.php:855 get_plugins_with_function scan),
        //    lib.php is require_once()'d for the first time.
        // 2. At that moment class_exists('plagiarism_plugin', false) === false — plagiarismlib.php
        //    has not loaded yet — so Path A (standalone class, no update_status()) is compiled.
        // 3. require_once() prevents lib.php from executing again; Path A class is permanent.
        // 4. Later, on the assign grading page, plagiarism_update_status() is called.
        // 5. Moodle 4.4+ plagiarismlib.php:106 calls
        //      new ReflectionMethod('plagiarism_plugin_docguard', 'update_status')
        //    with NO surrounding try/catch.
        // 6. The method does not exist on the Path A class → ReflectionException → page crash.
        //
        // v1.0.19 had this stub; it was accidentally removed in v1.0.21 (parent class
        // provided it) then never restored when v1.0.45 reintroduced the Path A/B conditional.
        //
        // Fix: update_status() no-op stub added to Path A class. Method now always exists
        // regardless of which path the class definition took. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026051400) {
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
        // v1.0.56: Removed DEFAULT="" from CHAR NOT NULL columns in install.xml (filename, filetype, contenthash, section_label).
        // No DB schema changes to existing sites.
        upgrade_plugin_savepoint(true, 2026052200, 'plagiarism', 'docguard');
    }

    // v1.0.67: PERF-FIX-DG-BATCH-PRELOAD — replaced N+1 DB query pattern + removed
    // synchronous lazy analysis from get_links(). No DB schema changes.
    if ($oldversion < 2026060500) {
        upgrade_plugin_savepoint(true, 2026060500, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026061000) {
        // ADD-DG-PROCESS-PENDING (v1.0.69): Added process_pending scheduled task
        // that runs every hour and processes two classes of stuck submissions:
        //   Phase 1 — status='pending' DB records older than 5 minutes whose
        //              analyse_and_store() failed (API error, PDF extraction failure).
        //   Phase 2 — submitted files in DocGuard-enabled assign CMs that have NO
        //              plagiarism_docguard_sub record at all (common when the plugin
        //              is installed or upgraded after students have already submitted
        //              and the assessable_submitted observer never fired).
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
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['lib.php', 'version.php', 'classes/extractor.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026072000, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026072300) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026072300) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026072300) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300, 'plagiarism', 'docguard');
    }

    if ($oldversion < 2026081500) {
        // ── v1.0.78 — report visibility and breakdown storage ────────────────
        //
        // FIX-DG-VERSION-FREEZE: this is the first version bump since 2026072300,
        // so this is the first time since then that Moodle runs any upgrade step
        // for this plugin at all.
        //
        // FIX-DG-CAP-ARCHETYPES: db/access.php now lists the 'teacher' (non-editing
        // teacher) archetype. That alone does NOT change an existing site:
        // update_capabilities() applies archetypes only when it first creates a
        // capability row, and thereafter re-syncs only captype, contextlevel and
        // riskbitmask. The explicit grant below is what updates existing sites, so
        // that Define Roles reflects reality rather than the runtime fallback in
        // lib.php silently doing all the work.
        //
        // FIX-DG-REPORT-ACCESS: report.php and student_report.php now accept
        // mod/assign:grade OR plagiarism/docguard:viewreport — the same test
        // lib.php uses to decide whether to render the link — so the two can no
        // longer disagree.
        //
        // FIX-DG-SECTION-NUM: per-section rows are now numbered sequentially
        // before insert. Documents labelled "Question 1 / Answer 1 / Question 2 /
        // Answer 2" produced duplicate section_num values, which collided with the
        // UNIQUE index subid_secnum_ix and aborted the insert loop — leaving a
        // record marked 'analysed' with a missing or partial breakdown.
        //
        // No schema changes. Existing rows are untouched: submissions whose
        // breakdown was lost to the collision need a Re-analyse to rebuild it.

        // ── Grant the report capability to existing non-editing teacher roles ──
        // Deliberately conservative:
        //   - $overwrite = false, so any explicit permission row an administrator has
        //     already created (Allow, Prevent or Prohibit) is left untouched. Note
        //     this protects explicit rows only: "Not set" is the absence of a row, so
        //     a role where an admin previously un-set this capability is re-granted.
        //     That is the same behaviour as a fresh install applying its archetypes.
        //   - Applied at system context only, i.e. it edits the role definition
        //     exactly as ticking the box in Define Roles would. No course-level or
        //     activity-level overrides are created.
        //   - Skipped entirely if the capability is somehow absent.
        if (function_exists('get_capability_info') && get_capability_info('plagiarism/docguard:viewreport')
                && function_exists('get_archetype_roles')) {
            try {
                $systemcontext = context_system::instance();
                $granted       = 0;
                foreach (get_archetype_roles('teacher') as $role) {
                    // assign_capability() returns true both when it writes and when it
                    // declines to overwrite an existing row, so count by checking
                    // first — otherwise the log line always reports every role.
                    $already = $DB->record_exists('role_capabilities', [
                        'roleid'     => $role->id,
                        'contextid'  => $systemcontext->id,
                        'capability' => 'plagiarism/docguard:viewreport',
                    ]);
                    assign_capability('plagiarism/docguard:viewreport', CAP_ALLOW,
                        $role->id, $systemcontext->id, false);
                    if (!$already) {
                        $granted++;
                    }
                }
                // No explicit cache flush here: Moodle purges all caches at the end of
                // an upgrade (upgrade_finished() → purge_all_caches()), which covers
                // the role definitions changed above. Calling accesslib internals from
                // a plugin upgrade step is more likely to break across versions than
                // to help.
                upgrade_log(UPGRADE_LOG_NORMAL, 'plagiarism_docguard',
                    "Granted plagiarism/docguard:viewreport to {$granted} non-editing teacher role(s).");
            } catch (\Throwable $e) {
                // Never fail the upgrade over a role default — the runtime check in
                // lib.php already guarantees markers can open the report.
                upgrade_log(UPGRADE_LOG_NOTICE, 'plagiarism_docguard',
                    'Could not grant plagiarism/docguard:viewreport to teacher roles: ' . $e->getMessage());
            }
        }

        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach ([
                'version.php',
                'lib.php',
                'report.php',
                'student_report.php',
                'db/access.php',
                'db/upgrade.php',
                'classes/observer.php',
                'classes/extractor.php',
                'classes/privacy/provider.php',
                'classes/task/process_pending.php',
            ] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        }
        // No opcache_reset() fallback here: it flushes the entire server-wide opcode
        // cache, which on shared hosting is other tenants' problem, and it warns
        // under opcache.restrict_api. Per-file invalidation is sufficient.
        upgrade_plugin_savepoint(true, 2026081500, 'plagiarism', 'docguard');
    }

    return true;
}