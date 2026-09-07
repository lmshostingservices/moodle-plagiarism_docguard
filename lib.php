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
 * Library entry points for the DocGuard plagiarism plugin.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// FIX-DG-EARLY-FUNCTION (v1.0.41): Define the replacement hook function at the very
// TOP of lib.php — before any class definitions, helper functions, or other code.
//
// Root cause of all prior failed fix attempts:
// Moodle's plagiarism_update_status() checks
// function_exists('plagiarism_docguard_before_standard_top_of_body_html') FIRST.
// If FALSE, the deprecation warning fires IMMEDIATELY before Moodle does
// require_once(lib.php). No matter where in lib.php the function was defined,
// it was always too late.
//
// Hook-based pre-loading also required a fresh Moodle hook registry cache.
// With a stale cache the hook callbacks never fire and the warning persists.
//
// This fix is cache-independent: the function is defined at the very first line
// of real code. Whether lib.php is loaded by a hook callback OR by
// plagiarism_update_status()'s own require_once() fallback, this function is
// defined before plagiarism_update_status() checks function_exists().
if (!function_exists('plagiarism_docguard_before_standard_top_of_body_html')) {
    /**
     * Replacement for the deprecated plagiarism_plugin::update_status() hook.
     * No-op: DocGuard injects nothing into the page body.
     * Sole purpose: make function_exists() return TRUE so plagiarism_update_status()
     * never reaches the deprecated update_status() path.
     *
     * @return void Nothing is emitted; the function exists only so function_exists() succeeds.
     */
    function plagiarism_docguard_before_standard_top_of_body_html() {
    }
}


// V1.0.80: REMOVED the global set_error_handler('_dg_warning_capture_handler', …) and the
// handler function itself.
//
// What was wrong: this file is loaded on essentially every Moodle page (the plagiarism
// dispatcher and the early get_plugins_with_function() scan both pull it in), so a single
// plagiarism plugin was replacing Moodle's own error handler site-wide, for every
// component, for the whole request. It swallowed E_DEPRECATED/E_NOTICE/E_WARNING before
// Moodle's handler could see them (returning TRUE for the two messages it recognised,
// which suppresses them outright), and it wrote three config rows from inside an error
// handler. A plugin does not get to decide how the site reports errors — that is core's
// job, it is what $CFG->debug and debugdisplay exist for, and hiding a deprecation is not
// the same as not causing one.
//
// Why removing it is safe: the deprecation it was papering over is already fixed at the
// cause. Moodle's plagiarism_update_status() only calls debugging(…update_status() is
// deprecated…) when the plugin class DECLARES update_status(). The spl_autoload_register
// below defers the class definition to first use, by which point plagiarism_plugin is
// always defined, so plagiarism_plugin_docguard extends it and INHERITS update_status() —
// ReflectionMethod::getDeclaringClass() then returns 'plagiarism_plugin' and core never
// calls debugging() at all. The global no-op function above covers the other branch of
// the same core check. Nothing is left to suppress.
//
// diag.php's "warning_last_detected" panel is now permanently empty, which is the correct
// reading: nothing writes it because nothing fires.

/* ── Helpers ─────────────────────────────────────────────────────────────────── */

/**
 * Is DocGuard switched on site-wide?
 *
 * v1.0.80: settings.php has written a boolean 'enabled' config value since v1.0.x and
 * NOTHING in the plugin ever read it. An administrator who unticked "Enable DocGuard"
 * still had every submission extracted, scored, and its full document text stored — the
 * switch was decorative. This is the single site-wide gate; every entry point that can
 * start processing (event observer, cron task, class-report scan, manual re-analyse, the
 * badge, and the activity form) now asks it.
 *
 * Also requires core's own $CFG->enableplagiarism, because a plagiarism plugin must not
 * act while Moodle's plagiarism subsystem is off.
 *
 * @return bool True when both core's plagiarism subsystem and DocGuard's own site-wide
 *              "enabled" setting are on, and DocGuard may therefore process submissions.
 */
function plagiarism_docguard_is_enabled(): bool {
    global $CFG;
    if (empty($CFG->enableplagiarism)) {
        return false;
    }
    return !empty(get_config('plagiarism_docguard', 'enabled'));
}

/**
 * Find the id of a non-directory {files} row for a content hash, newest first.
 *
 * v1.0.80: replaces four separate copies of
 *     get_record_sql('… ORDER BY id DESC LIMIT 1')
 * in report.php, student_report.php, reanalyse.php and task/process_pending.php.
 * Raw "LIMIT 1" is MySQL/PostgreSQL syntax; SQL Server wants TOP and Oracle wants
 * ROWNUM/FETCH FIRST, so on those two databases — both supported Moodle platforms —
 * every re-analyse path threw dml_read_exception. $limitfrom/$limitnum let the DML
 * layer emit the right dialect for whatever the site runs on.
 *
 * @param string $contenthash SHA1 content hash of the stored file to look up.
 * @return int {files}.id of the newest non-directory row with that hash, or 0 when
 *                 the file is no longer in storage.
 */
function plagiarism_docguard_find_file_id_by_hash(string $contenthash): int {
    global $DB;
    if ($contenthash === '') {
        return 0;
    }
    $rows = $DB->get_records_sql(
        'SELECT id FROM {files} WHERE contenthash = :hash AND filename <> :dot ORDER BY id DESC',
        ['hash' => $contenthash, 'dot' => '.'],
        0,
        1
    );
    $row = reset($rows);
    return $row ? (int)$row->id : 0;
}

/**
 * The site licence identifier used when contacting lms-labs.com.
 *
 * Prefers the value published by the local_aiconfig plugin when it is installed,
 * falling back to DocGuard's own setting.
 *
 * @return string The configured site ID, or the empty string when none is set.
 */
function plagiarism_docguard_get_siteid(): string {
    $aiconfig = get_config('local_aiconfig', 'siteid');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return (string)(get_config('plagiarism_docguard', 'siteid') ?: '');
}

/**
 * The API key used to authenticate this site to lms-labs.com.
 *
 * Prefers the value published by the local_aiconfig plugin when it is installed,
 * falling back to DocGuard's own setting.
 *
 * @return string The configured API key, or the empty string when none is set.
 */
function plagiarism_docguard_get_apikey(): string {
    $aiconfig = get_config('local_aiconfig', 'apikey');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return (string)(get_config('plagiarism_docguard', 'apikey') ?: '');
}

/**
 * Where the credentials in force actually come from.
 *
 * v1.0.85: the two accessors above silently prefer local_aiconfig when that plugin is
 * installed and has values. The settings page displayed whatever they returned in its own
 * Site ID and API Key fields, so an administrator editing those fields could be looking at
 * a value this plugin does not store, changing it, saving, and seeing the old value come
 * back - with nothing on the page explaining why. Worse, an administrator debugging a
 * licence failure had no way to tell which set of credentials was being sent.
 *
 * settings.php uses this to say so plainly.
 *
 * @return string 'local_aiconfig' when the shared plugin supplies both values,
 *                'mixed' when it supplies one of them, 'docguard' otherwise.
 */
function plagiarism_docguard_credential_source(): string {
    $sharedid  = !empty(get_config('local_aiconfig', 'siteid'));
    $sharedkey = !empty(get_config('local_aiconfig', 'apikey'));

    if ($sharedid && $sharedkey) {
        return 'local_aiconfig';
    }
    if ($sharedid || $sharedkey) {
        return 'mixed';
    }
    return 'docguard';
}

/**
 * Fetches and caches platform-level site-wide plagiarism settings.
 * Mirrors plagiarism_essayguard_get_platform_settings() but uses the
 * plagiarism_docguard config namespace for its own 30-minute cache.
 * Returns assoc array with keys: essayguard_assignments, essayguard_quizzes,
 * docguard_assignments, docguard_quizzes (all bool).
 * Fails open (all false) on any network/config error.
 *
 * v1.0.85: the four keys mirror the vendor response verbatim, but DocGuard reads only
 * `docguard_assignments`. `docguard_quizzes` is deliberately NOT consulted - DocGuard has
 * never been able to process a quiz (supports_mod() is ['assign'], and every extraction
 * and cron query is joined to {assign}), and honouring the flag made the plugin advertise
 * a capability it does not have: the disclosure appeared on quizzes, the teacher saw
 * DocGuard reported as active, and nothing was ever analysed. See
 * FIX-DG-QUIZ-ADVERTISED-NOT-IMPLEMENTED in is_cm_active(). Do not wire it back up
 * without first making supports_mod() and the extraction path agree with it. The two
 * essayguard_* keys are not read here at all; they belong to the other plugin and are
 * kept only so this mirrors the vendor payload.
 *
 * @return array Associative array of four booleans keyed essayguard_assignments,
 *               essayguard_quizzes, docguard_assignments and docguard_quizzes, saying
 *               which activity types the platform licence permits each plugin to analyse.
 *               Only docguard_assignments affects DocGuard's behaviour.
 */
function plagiarism_docguard_get_platform_settings(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'essayguard_assignments' => false,
        'essayguard_quizzes'     => false,
        'docguard_assignments'   => false,
        'docguard_quizzes'       => false,
    ];

    $cachetime = (int)get_config('plagiarism_docguard', 'platform_settings_time');
    if ($cachetime > 0 && (time() - $cachetime) < 1800) {
        $cacheddata = get_config('plagiarism_docguard', 'platform_settings_data');
        if (!empty($cacheddata)) {
            $decoded = json_decode($cacheddata, true);
            if (is_array($decoded)) {
                $cached = $decoded;
                return $cached;
            }
        }
    }

    $siteid = plagiarism_docguard_get_siteid();
    $apikey = plagiarism_docguard_get_apikey();
    if (empty($siteid) || empty($apikey)) {
        $cached = $defaults;
        return $cached;
    }

    try {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // FIX-SESSION-CLOSE-GUARD (v1.0.43): Guard write_close() to AJAX/CLI contexts only.
        // During a normal web page render (e.g. /plagiarism/docguard/settings.php), calling
        // write_close() causes Moodle to emit "Session mutated after close: $SESSION->editedpages"
        // because the admin framework writes that key at the very end of page render, AFTER
        // this function returns. In AJAX/CLI the session is not needed for further rendering,
        // so releasing the lock there is still correct and avoids blocking concurrent requests.
        if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
            \core\session\manager::write_close();
        }
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);
        $response  = $curl->get(
            'https://lms-labs.com/api/plagiarism-settings',
            [
                'siteId' => $siteid, 'apiKey' => $apikey,
                ]
        );
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        if ($httpcode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && isset($data['settings'])) {
                $s = $data['settings'];
                $result = [
                    'essayguard_assignments' => !empty($s['essayguardAssignmentsEnabled']),
                    'essayguard_quizzes'     => !empty($s['essayguardQuizzesEnabled']),
                    'docguard_assignments'   => !empty($s['docguardAssignmentsEnabled']),
                    'docguard_quizzes'       => !empty($s['docguardQuizzesEnabled']),
                ];
                set_config('platform_settings_data', json_encode($result), 'plagiarism_docguard');
                set_config('platform_settings_time', time(), 'plagiarism_docguard');
                $cached = $result;
                return $cached;
            }
        }
    } catch (\Throwable $e) {
        // V1.0.80: was an empty catch. Still fails open to the defaults (a vendor
        // outage must not break page rendering), but an administrator chasing "why is
        // the site-wide flag not applying" now has something to find in the logs.
        debugging(
            'DocGuard: could not fetch platform settings from lms-labs.com — '
                . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    $cached = $defaults;
    return $cached;
}

/**
 * Whether DocGuard should analyse submissions to one activity.
 *
 * False whenever the plugin is switched off site-wide; otherwise decided by the
 * per-activity "Enable DocGuard" setting, which defaults to off.
 *
 * @param int $cmid The course module to test.
 * @return bool True when DocGuard is active for that activity.
 */
function plagiarism_docguard_is_cm_active(int $cmid): bool {
    // V1.0.80: site-wide switch first. Nothing below can turn DocGuard on for an
    // activity while the plugin is switched off site-wide or Moodle's plagiarism
    // subsystem is disabled.
    if (!plagiarism_docguard_is_enabled()) {
        return false;
    }

    // V1.0.80: the PER-ACTIVITY value is now read FIRST and is decisive.
    //
    // What was wrong (two faults in three lines):
    //
    // (a) The lms-labs.com platform flags were consulted BEFORE the activity's own
    // setting, and returned true on their own. A teacher who deliberately
    // unticked DocGuard on their assignment — the documented way to opt a
    // sensitive activity out — had it silently re-enabled by a remote flag set by
    // someone else, on a vendor server, with no record of it in the course. A
    // remote convenience toggle must never overrule a local, explicit, auditable
    // opt-out. It can now only ever ENABLE an activity that has expressed no
    // preference at all.
    //
    // (b) `($value === false) || !empty($value)` treated "no saved value" as ENABLED,
    // while the module form's checkbox defaults to UNCHECKED. The form and the
    // engine therefore disagreed for every activity created before DocGuard was
    // installed and every activity whose form was never saved: the teacher saw an
    // unticked box while the plugin extracted, scored, and stored the full text of
    // their students' documents. For a plugin that stores student document text,
    // the defensible default is opt-in, and it is the one the UI already claimed.
    // Absent value now means OFF.
    $value = get_config('plagiarism_docguard', 'enabled_cm_' . $cmid);
    if ($value !== false) {
        return !empty($value);   // Explicit teacher choice — both ways — always wins.
    }

    // No per-activity preference recorded. A platform-level flag may enable it.
    //
    // v1.0.85 FIX-DG-QUIZ-ADVERTISED-NOT-IMPLEMENTED: the quiz branch is gone. DocGuard
    // has never processed quizzes - supports_mod() is ['assign'] and every extraction and
    // cron query is hard-joined to {assign} - but a `docguardQuizzesEnabled` flag set on
    // the vendor platform made this function return true for a quiz. The consequences
    // were all of the "claims to work" kind: the student saw the plagiarism disclosure on
    // a quiz, the teacher saw DocGuard reported as active on it, and nothing was ever
    // analysed. A site could believe its quizzes were covered indefinitely.
    //
    // The activity type is now decided by supports_mod(), which is the code that actually
    // knows, and a platform flag can only enable a type the plugin can genuinely process.
    // If quiz support is built later, adding 'quiz' to supports_mod() is the single
    // change that turns it on here too.
    try {
        $platform = plagiarism_docguard_get_platform_settings();
        if (!empty($platform['docguard_assignments'])) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if ($cm && plagiarism_docguard_supports_mod($cm->modname)) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        // V1.0.80: was a silent catch. Fail closed (opt-in default) but say why, so a
        // site whose platform lookup is broken is diagnosable instead of just quiet.
        debugging(
            'DocGuard: platform settings lookup failed for cmid ' . $cmid . ' — '
                . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    // Opt-in default.
    return false;
}

/**
 * Whether the current user may view DocGuard reports in this context.
 *
 * FIX-DG-REPORT-ACCESS (v1.0.78): single source of truth for report visibility.
 *
 * Before this fix, plagiarism_docguard_get_links() rendered the badge and the
 * "View DocGuard Report" link to anyone holding mod/assign:grade, while
 * report.php and student_report.php required plagiarism/docguard:viewreport and
 * nothing else. Since db/access.php granted that capability to editingteacher
 * and manager only, every non-editing teacher and every custom marker role saw a
 * link that always answered "Sorry, but you do not currently have permissions to
 * do that". Both the link and the pages now ask this one function.
 *
 * Allowing mod/assign:grade is not a widening of access: a user who can grade the
 * assignment can already open every submitted file the report describes.
 *
 * @param \context $context Module context of the activity.
 * @return bool True when the current user holds plagiarism/docguard:viewreport or
 *                 mod/assign:grade in this context and no CAP_PROHIBIT overrides it.
 */
function plagiarism_docguard_can_view_reports(\context $context): bool {
    global $USER;

    // Get_links() is called once per row of View All Submissions, so this must be
    // cheap on repeat calls within a request.
    static $cache = [];
    $cachekey = $context->id . ':' . (int)$USER->id;
    if (isset($cache[$cachekey])) {
        return $cache[$cachekey];
    }

    // An explicit PROHIBIT on the DocGuard capability is honoured ahead of everything
    // else. Institutions do restrict who may see AI-risk scores, since they feed
    // misconduct referrals; without this check the grading fallback below would make
    // the capability grant-only and its role override meaningless.
    if (plagiarism_docguard_capability_prohibited('plagiarism/docguard:viewreport', $context)) {
        return $cache[$cachekey] = false;
    }

    // Only test the DocGuard capability if it is actually installed. On a site whose
    // plugin version never advanced (see FIX-DG-VERSION-FREEZE in version.php),
    // update_capabilities() never ran, the capability is absent from {capabilities},
    // and has_capability() would emit a debugging warning on every page that renders
    // a badge.
    $capinstalled = !function_exists('get_capability_info')
        || (bool)get_capability_info('plagiarism/docguard:viewreport');

    if ($capinstalled && has_capability('plagiarism/docguard:viewreport', $context)) {
        return $cache[$cachekey] = true;
    }

    // Fallback: anyone who can grade this activity. Note this is a fallback, not the
    // primary test — a role explicitly granted the DocGuard capability is served by
    // the branch above, and a role explicitly prohibited never reaches here.
    return $cache[$cachekey] = has_capability('mod/assign:grade', $context);
}

/**
 * Whether the current user may see this student's work in this activity.
 *
 * V1.0.88 FIX-DG-REANALYSE-PENDING-GROUPS: one function, because three hand-written
 * copies of this check produced three separate releases of the same bug.
 *
 * report.php's page body was hardened for SEPARATEGROUPS in v1.0.80, student_report.php
 * in v1.0.80/v1.0.81, and reanalyse.php in v1.0.84 — and the "Analyse" action inside
 * report.php, which lives in the same file as the first of those, was missed by all
 * three. It checked only plagiarism_docguard_can_view_reports() and that the record's cmid
 * matched, so a teacher restricted to one group could put any subid belonging to the
 * activity into the query string and trigger re-extraction and re-storage of another
 * group's student's document text. Every one of those fixes carried a note saying the same
 * restriction belongs on every door into the same data; the way to make that true is to
 * have one door.
 *
 * Returns true for NOGROUPS and VISIBLEGROUPS, and for anyone holding
 * moodle/site:accessallgroups, because in each of those cases the viewer is permitted to
 * see every participant — which is what those modes mean. The user always passes for
 * themselves, matching what Moodle's own grading table shows a teacher with no group.
 *
 * @param \stdClass $cm The course module record, as returned by get_coursemodule_from_id().
 * @param \context $context Module context of the activity.
 * @param int $userid The student whose work is being opened or re-analysed.
 * @return bool True when the current user may act on that student's submission here.
 */
function plagiarism_docguard_user_visible(\stdClass $cm, \context $context, int $userid): bool {
    global $USER;

    if ((int)$USER->id === $userid) {
        return true;
    }
    if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
        return true;
    }
    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    foreach (groups_get_activity_allowed_groups($cm) as $group) {
        // The second argument is EXTRA user fields, not an SQL field list.
        // core_user\fields::including() stores it verbatim and get_sql() then prefixes it
        // with the 'u.' alias, so ['u.id'] emits `u.u.id` and fatals the page (v1.0.81).
        foreach (groups_get_groups_members([$group->id], null, 'u.id') as $member) {
            if ((int)$member->id === $userid) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Whether any role the user holds in this context's path explicitly PROHIBITs a
 * capability.
 *
 * has_capability() cannot distinguish "not set" from "denied", and DocGuard needs to:
 * "not set" is the normal state for a non-editing teacher and must fall through to
 * the grading check, whereas a deliberate administrative denial must win.
 *
 * Deliberately limited to CAP_PROHIBIT. CAP_PROHIBIT is the only permission Moodle
 * treats as absolute; CAP_PREVENT is merely the lowest-priority negative and is
 * routinely overridden by an ALLOW on another role or at a deeper context. Honouring
 * CAP_PREVENT here would re-create the very fault this release fixes — for example,
 * the standard delegation pattern of Prevent at system level plus Allow at course
 * level would deny the marker at exactly the point Moodle itself would allow them.
 * Site administrators are exempt, matching has_capability()'s own behaviour.
 *
 * This is a conservative approximation of Moodle's permission resolution, not a
 * replacement for it: it can only ever deny, never grant.
 *
 * @param string $capability Name of the Moodle capability to test, e.g. mod/assign:grade.
 * @param \context $context Context the capability is being tested in, and whose parent
 *                          contexts are walked when looking for a prohibit.
 * @return bool True only when a CAP_PROHIBIT is in force for the current user in this
 *                 context or one of its parents; false for every other outcome, including
 *                 "not set", CAP_PREVENT, and site administrators.
 */
function plagiarism_docguard_capability_prohibited(string $capability, \context $context): bool {
    global $DB, $USER;

    if (function_exists('get_capability_info') && !get_capability_info($capability)) {
        return false; // Capability not installed — nothing can prohibit it.
    }
    if (empty($USER->id) || isguestuser()) {
        return false;
    }
    if (is_siteadmin()) {
        return false; // Admins bypass capability checks in core; do not diverge here.
    }

    try {
        // Get_user_roles() reads role_assignments only. get_user_roles_with_special()
        // additionally includes the Authenticated user and front page roles, which is
        // exactly where a site-wide denial is normally set.
        $roles = function_exists('get_user_roles_with_special')
            ? get_user_roles_with_special($context, $USER->id)
            : get_user_roles($context, $USER->id, true);
        if (empty($roles)) {
            return false;
        }
        $roleids = array_values(array_unique(array_map(fn($r) => (int)$r->roleid, $roles)));
        $ctxids  = $context->get_parent_context_ids(true);
        if (empty($roleids) || empty($ctxids)) {
            return false;
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        [$ctxsql, $ctxparams]  = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'c');
        $params = array_merge(
            $roleparams,
            $ctxparams,
            [
                'cap'      => $capability,
                'prohibit' => CAP_PROHIBIT,
                ]
        );

        return $DB->record_exists_select(
            'role_capabilities',
            "capability = :cap AND permission = :prohibit AND roleid $rolesql AND contextid $ctxsql",
            $params
        );
    } catch (\Throwable $e) {
        // Never let this check break page rendering — fail open to the normal
        // capability logic above.
        // v1.0.80: log it. A swallowed exception here means PROHIBIT overrides are
        // silently not being honoured, which is a permissions fault worth seeing.
        debugging(
            'DocGuard: PROHIBIT check for ' . $capability . ' failed — '
                . $e->getMessage(),
            DEBUG_DEVELOPER
        );
        return false;
    }
}

/**
 * Whether a Site ID and API Key are configured at all.
 *
 * V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED: the licence gate was applied by the event
 * observer, the backfill phase of process_pending and the scan_activity adhoc task, but
 * by none of the three teacher-facing re-analyse endpoints — reanalyse.php,
 * report.php?dg_action=reanalyse_pending and student_report.php?reanalyse=1. Since
 * v1.0.85 a site with no credentials is deliberately treated as unlicensed and must not
 * analyse anything; print_disclosure() stops telling students their work is checked, and
 * the settings page reports "Credentials not configured". A teacher on such a site could
 * still press Re-analyse and have the plugin extract and store the full text of a
 * student's document — which is precisely the processing the student was just told was
 * not happening.
 *
 * Those three endpoints test the CREDENTIALS rather than calling check_unlock(), for the
 * same three reasons print_disclosure() gives:
 *  - it is certain: no credentials means no analysis, with no network call and no cache
 *    to be stale;
 *  - it cannot add latency: check_unlock() can spend up to 15 seconds on a cache miss,
 *    and its write_close() guard fires only for AJAX and CLI, so on an ordinary web
 *    request it would hold the session write lock for that whole window;
 *  - it errs the safe way: a licensed site whose vendor is briefly unreachable still
 *    analyses, because check_unlock() fails open on an outage and this test never
 *    consults the vendor at all.
 *
 * Cron paths stay on the full check_unlock(), which is the stricter test and is free of
 * the latency and session-lock concerns because it runs under CLI.
 *
 * Both accessors are declared `: string` and normalise an unset value to '', so the
 * empty test is the whole test.
 *
 * @return bool True when both a Site ID and an API Key are configured for this site.
 */
function plagiarism_docguard_has_credentials(): bool {
    return plagiarism_docguard_get_siteid() !== '' && plagiarism_docguard_get_apikey() !== '';
}

/**
 * Whether this site holds a current DocGuard licence.
 *
 * Answers from a 30 minute config cache where possible, and otherwise makes one
 * licence call to lms-labs.com carrying only the site ID and API key. The result
 * is memoised for the rest of the request.
 *
 * @return bool True when the site is unlocked for DocGuard.
 */
function plagiarism_docguard_check_unlock(): bool {
    global $CFG;

    // V1.0.88: the request-level memo is keyed on the credentials it was computed FOR.
    //
    // It used to be a bare `static $rt = null`, so the first answer of the request stood
    // for the rest of it whatever happened to the credentials in between. That is wrong on
    // its own terms — testconnection.php exists precisely to force a fresh verdict, and it
    // clears the config cache to do so, but a memo already set earlier in the same request
    // would have returned the stale answer regardless of that effort. Keying on the
    // credentials keeps the whole point of the memo (one licence check per request, not
    // one per badge) while making a change of credentials produce a fresh answer.
    static $rt = [];

    $siteid = plagiarism_docguard_get_siteid();
    $apikey = plagiarism_docguard_get_apikey();
    $memokey = sha1($siteid . "\0" . $apikey);
    if (isset($rt[$memokey])) {
        return $rt[$memokey];
    }

    $cachedresult = get_config('plagiarism_docguard', 'unlock_cache_result');
    $cachedtime   = (int)get_config('plagiarism_docguard', 'unlock_cache_time');
    if ($cachedtime > 0 && (time() - $cachedtime) < 1800) {
        $rt[$memokey] = !empty($cachedresult);
        return $rt[$memokey];
    }

    if (empty($siteid) || empty($apikey)) {
        // V1.0.85 FIX-DG-UNLICENSED-FAILS-OPEN: this returned TRUE - a site with no Site
        // ID and no API Key passed the licence gate and analysed submissions, while
        // settings.php told the administrator "Credentials not configured". Two
        // components describing the same site in opposite terms is not a licensing
        // question, it is a correctness one: nothing in the product could tell you
        // whether analysis was running.
        //
        // Fail CLOSED. No credentials is not an outage, it is an unconfigured site, and
        // the distinction matters:
        //
        // - A vendor outage is transient and outside the administrator's control, so
        // the check below still fails OPEN. Analysis must not stop because
        // lms-labs.com is briefly unreachable.
        // - Missing credentials are permanent until someone acts, entirely within the
        // administrator's control, and visible on the settings page. Failing open
        // there means the product silently behaves as though it were licensed, for
        // as long as nobody notices.
        //
        // This DOES change behaviour for any existing site that never entered
        // credentials: it stops analysing. That is the intended outcome - such a site was
        // never licensed - and it is surfaced rather than silent. The settings page shows
        // the reason, and print_disclosure() stops telling students their work is being
        // checked when it is not.
        //
        // Cached for a short period only: an administrator who has just pasted their
        // credentials in should not wait half an hour for the plugin to notice, and
        // settings.php clears the cache on save regardless.
        plagiarism_docguard_cache_unlock(false, 300);
        debugging(
            'DocGuard: no Site ID / API Key configured - analysis is disabled until'
                . ' credentials are entered at Site administration > Plugins > Plagiarism'
                . ' prevention > DocGuard.',
            DEBUG_DEVELOPER
        );
        $rt[$memokey] = false;
        return false;
    }

    // FIX-DG-SESSION-LOCK (v1.0.29): Release the Moodle session write lock before the
    // outbound HTTP call. Without this the session file stays locked for the full curl
    // timeout, blocking every other request from the same browser session (identical to
    // the bug fixed in Essay Guard as FIX-EG-SESSION-LOCK v1.2.162).
    // FIX-SESSION-CLOSE-GUARD (v1.0.43): Guard to AJAX/CLI only — see get_platform_settings().
    if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
        \core\session\manager::write_close();
    }

    require_once($CFG->libdir . '/filelib.php');
    $curl = new \curl();
    $curl->setopt(['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 5]);
    $response  = $curl->get(
        'https://lms-labs.com/api/plugin-unlock/verify',
        [
            'pluginId' => 'docguard', 'siteId' => $siteid, 'apiKey' => $apikey,
            ]
    );
    $httpcode = (int)($curl->info['http_code'] ?? 0);

    if ($httpcode !== 200 || !$response) {
        // Fail-open: a licence server outage must not disable analysis site-wide.
        //
        // v1.0.84 FIX-DG-UNLOCK-NOCACHE: this path returned WITHOUT writing the cache,
        // which is only written on the success path below. On a site whose outbound
        // network is blocked - an air-gapped or firewalled Moodle, which is common in
        // the VET sector - $cachedtime therefore stayed 0 forever and every single
        // request that reached this function made a fresh curl call with a 5-second
        // connect and 10-second total timeout. Up to 15 seconds per page load, on every
        // page load, permanently.
        //
        // Worse than the latency: the write_close() guard above fires only for AJAX and
        // CLI, so an ordinary web request holds the Moodle session write lock for that
        // whole window and serialises every other request from the same browser. The
        // site does not look slow, it looks broken.
        //
        // Cache the failure too, but briefly - five minutes, not thirty - so a site
        // recovers from a transient outage quickly while a permanently offline site pays
        // the timeout at most once every five minutes instead of on every request.
        plagiarism_docguard_cache_unlock(true, 300);
        $rt[$memokey] = true;
        return true;
    }

    $data   = json_decode($response, true);
    $result = !empty($data['unlocked']);
    if (!$result) {
        $result = plagiarism_docguard_auto_unlock($siteid, $apikey);
    }
    plagiarism_docguard_cache_unlock($result, 1800);
    $rt[$memokey] = $result;
    return $rt[$memokey];
}

/**
 * Record the outcome of a licence check so the next request does not repeat it.
 *
 * v1.0.84: the cache used to be written only where the vendor answered successfully, so
 * the two fail-open paths re-ran the whole check - including a 15-second curl timeout on
 * an offline site - on every request. Every exit path now goes through here.
 *
 * The stored time is back-dated when a shorter time-to-live is wanted, because the reader
 * has a single hard-coded 1800-second window: writing (time() - 1800 + $ttl) makes that
 * one reader expire the entry after $ttl seconds without needing a second stored field,
 * and therefore without a database upgrade step.
 *
 * @param bool $result Whether the site is considered unlocked.
 * @param int $ttl How long the answer should stand, in seconds.
 * @return void
 */
function plagiarism_docguard_cache_unlock(bool $result, int $ttl): void {
    $stamp = ($ttl >= 1800) ? time() : (time() - 1800 + max(0, $ttl));
    set_config('unlock_cache_result', (int)$result, 'plagiarism_docguard');
    set_config('unlock_cache_time', $stamp, 'plagiarism_docguard');
}

/**
 * Attempt to unlock this site for DocGuard against the licence service.
 *
 * Called by plagiarism_docguard_check_unlock() when the site is licensed but not
 * yet unlocked. Spends credits on the vendor account when it succeeds.
 *
 * @param string $siteid The site licence identifier.
 * @param string $apikey The site API key.
 * @return bool True when the site is now unlocked.
 */
function plagiarism_docguard_auto_unlock(string $siteid, string $apikey): bool {
    global $CFG;
    // FIX-DG-SESSION-LOCK (v1.0.29): Release session lock before outbound HTTP call.
    // Called from plagiarism_docguard_check_unlock() which already called write_close(),
    // but write_close() is idempotent so this is safe when auto_unlock() is called
    // on its own in future code paths.
    // FIX-SESSION-CLOSE-GUARD (v1.0.43): Guard to AJAX/CLI only — see get_platform_settings().
    if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
        \core\session\manager::write_close();
    }
    require_once($CFG->libdir . '/filelib.php');
    $payload = json_encode(['pluginId' => 'docguard', 'siteId' => $siteid, 'apiKey' => $apikey]);
    $curl    = new \curl();
    $resp    = $curl->post(
        'https://lms-labs.com/api/plugin-unlock',
        $payload,
        [
            'CURLOPT_TIMEOUT'    => 15,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json', 'Accept: application/json'],
            ]
    );
    $code = (int)($curl->info['http_code'] ?? 0);
    if ($code !== 200 || !$resp) {
        return false;
    }
    $data = json_decode($resp, true);
    return !empty($data['unlocked']);
}

/* ── Plagiarism plugin class ──────────────────────────────────────────────────── */

// FIX-DG-AUTOLOAD (v1.0.50): Defer class definition until first use via spl_autoload_register.
//
// Root cause of persistent deprecation warnings with the Path A/B conditional approach:
// During Moodle's early bootstrap (setup.php get_plugins_with_function scan), lib.php
// is loaded before plagiarism_plugin base class is available. Path A (standalone with
// update_status() stub) is taken permanently for the entire PHP request. Later, when
// plagiarism_update_status() calls:
// new ReflectionMethod('plagiarism_plugin_docguard', 'update_status')
// getDeclaringClass() returns 'plagiarism_plugin_docguard' (not 'plagiarism_plugin'),
// so Moodle calls debugging('plagiarism_plugin::update_status() is deprecated...').
// Moodle's debugging() writes HTML directly to the output buffer — set_error_handler()
// cannot intercept it. No code change inside lib.php can fix a class already defined.
//
// Fix: Register an SPL autoloader so the class is defined on FIRST USE, not at lib.php
// load time. The early bootstrap scan (get_plugins_with_function) only checks for the
// existence of global FUNCTIONS — it never instantiates the plugin class. So the
// autoloader never fires during early bootstrap. It fires the first time Moodle calls
// class_exists('plagiarism_plugin_docguard') or new plagiarism_plugin_docguard() inside
// plagiarism_update_status() — by which point Moodle is fully bootstrapped and
// plagiarism_plugin is always defined. The class always extends plagiarism_plugin,
// inheriting update_status(). getDeclaringClass()->getName() === 'plagiarism_plugin'
// → no debugging() call → zero warnings in any debug mode.
if (!class_exists('plagiarism_plugin_docguard', false)) {
    spl_autoload_register(function ($classname) {
        if ($classname !== 'plagiarism_plugin_docguard') {
            return;
        }
        if (!class_exists('plagiarism_plugin', false)) {
            // Edge case: autoloader fired before plagiarism_plugin was defined.
            // Try loading plagiarismlib.php. Should only happen in unusual bootstrap
            // orders (e.g. unit tests or CLI scripts that use the class very early).
            global $CFG;
            if (!empty($CFG->libdir)) {
                // V1.0.80: empty catches replaced with debugging() — a failure to load
                // plagiarismlib.php here silently drops the class onto the standalone
                // fallback path, which is exactly the state that used to produce the
                // deprecation noise. Worth a developer-level line.
                try {
                    require_once($CFG->libdir . '/plagiarismlib.php');
                } catch (\Throwable $e) {
                    debugging(
                        'DocGuard: could not load ' . $CFG->libdir . '/plagiarismlib.php — '
                            . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            if (!class_exists('plagiarism_plugin', false)) {
                $dglib = dirname(dirname(dirname(__FILE__))) . '/lib/plagiarismlib.php';
                if (file_exists($dglib)) {
                    try {
                        require_once($dglib);
                    } catch (\Throwable $e) {
                        debugging(
                            'DocGuard: could not load ' . $dglib . ' — '
                                . $e->getMessage(),
                            DEBUG_DEVELOPER
                        );
                    }
                }
                unset($dglib);
            }
        }
        if (class_exists('plagiarism_plugin', false)) {
            // Path B: class extends plagiarism_plugin.
            // update_status() INHERITED — getDeclaringClass() = 'plagiarism_plugin' → no warning.
            require_once(__DIR__ . '/classes/pluginclass.php');
        } else {
            // Path A fallback (should never happen on a normal web page load).
            // update_status() stub prevents ReflectionException crash.
            require_once(__DIR__ . '/classes/pluginclass_standalone.php');
        }
    }, true, true);
}

/* ── Module form hooks ───────────────────────────────────────────────────────── */

/**
 * Whether DocGuard can act on this activity type.
 *
 * @param string $modulename The activity type name, e.g. "assign".
 * @return bool True only for activity types DocGuard analyses.
 */
function plagiarism_docguard_supports_mod($modulename) {
    return in_array($modulename, ['assign'], true);
}

/**
 * Add the "Enable DocGuard" checkbox to the activity settings form.
 *
 * Adds nothing for activity types DocGuard cannot act on, or when the plugin is
 * switched off site-wide, so no teacher is shown a control that cannot take effect.
 *
 * @param object $formwrapper The moodleform_mod instance being built.
 * @param object $mform       The underlying QuickForm to add elements to.
 * @return void
 */
function plagiarism_docguard_coursemodule_standard_elements($formwrapper, $mform) {
    global $CFG;
    // V1.0.80: only offer this section on activity types the plugin can actually act on.
    //
    // Moodle calls coursemodule_standard_elements() for EVERY activity type, so the
    // "DocGuard" section was being added to Page, URL, Label, Folder, Book, Choice - every
    // form in the site - offering a setting that does nothing there. plagiarism_docguard_supports_mod()
    // already existed for precisely this check and was never called from anywhere.
    //
    // It is also hidden when the plugin is switched off site-wide, or when the site is not
    // unlocked: a teacher should not be shown a control that cannot take effect.
    $modulename = '';
    if ($formwrapper && method_exists($formwrapper, 'get_current')) {
        $current = $formwrapper->get_current();
        if (!empty($current->modulename)) {
            $modulename = $current->modulename;
        }
    }
    if ($modulename !== '' && !plagiarism_docguard_supports_mod($modulename)) {
        return;
    }
    // V1.0.80: one shared gate (was an inline duplicate of the same two checks).
    if (!plagiarism_docguard_is_enabled()) {
        return;
    }

    $mform->addElement('header', 'docguardhdr', get_string('pluginname', 'plagiarism_docguard'));
    // FIX-DG-CHECKBOX-SAVE (v1.0.7): Use plain checkbox, not advcheckbox.
    // advcheckbox relies on a hidden-field trick to transmit 0 when unchecked.
    // In Moodle 4.x plagiarism form paths that value can arrive as null, making
    // isset($data->docguard_enabled) === false and silently skipping set_config.
    // Plain checkbox omits the field from POST when unchecked; edit_post_actions
    // uses !empty() unconditionally so 0 is always written on every save.
    $mform->addElement('checkbox', 'docguard_enabled', get_string('enabled', 'plagiarism_docguard'));
    // FIX-DG-CHECKBOX-DISPLAY (v1.0.10): Use ->coursemodule (CMID) not ->id.
    // In Moodle's moodleform_mod, get_current()->id is the MODULE INSTANCE id
    // (e.g. the quiz's own DB id), while get_current()->coursemodule is the CMID.
    // The save paths (save_form_elements + coursemodule_edit_post_actions) both
    // use $data->coursemodule — the CMID. Using ->id here read the wrong config
    // key, so the form always showed unchecked on re-open even when the value
    // had been saved as 1. For new activities coursemodule is 0 or unset,
    // giving $saved = false → default 0 (correct: new activities start unchecked).
    $cmid  = $formwrapper->get_current()->coursemodule ?? 0;
    $saved = $cmid ? get_config('plagiarism_docguard', 'enabled_cm_' . $cmid) : false;
    // Default to unchecked for new activities; preserve saved state for existing ones.
    // v1.0.80: this line was always right — it is plagiarism_docguard_is_cm_active()
    // that has been corrected to agree with it. "No saved value" now means OFF in both
    // places, so what the teacher sees on the form is what the engine does.
    $mform->setDefault('docguard_enabled', ($saved === false) ? 0 : (int)!empty($saved));
}

/**
 * Save the "Enable DocGuard" checkbox when an activity is saved.
 *
 * Writes the value unconditionally so that clearing the checkbox stores 0 rather
 * than leaving the previous value in place.
 *
 * @param object $data   The submitted course module form data.
 * @param object $course The course the activity belongs to.
 * @return object The unmodified $data, as the hook contract requires.
 */
function plagiarism_docguard_coursemodule_edit_post_actions($data, $course) {
    // FIX-DG-CHECKBOX-SAVE (v1.0.7): Always write config — no isset() guard.
    // Unchecked plain checkbox: field absent from POST, !empty(null) = false → saves 0.
    // Checked plain checkbox: field = '1', !empty('1') = true → saves 1.
    set_config('enabled_cm_' . $data->coursemodule, !empty($data->docguard_enabled) ? 1 : 0, 'plagiarism_docguard');
    return $data;
}

/**
 * The number of days extracted text is retained before the cleanup task prunes it.
 *
 * v1.0.84: this exists because three components each resolved the unset case for
 * themselves and reached different answers - print_disclosure() said 0 (retain forever),
 * the cleanup task said 90 and pruned, and the settings page displayed 90. A student was
 * therefore told the opposite of the site's actual behaviour. There is now one answer.
 *
 * get_config() returns false for a key that has never been saved, so `?? $default` never
 * fires and `(int)` turns it into 0. Only the never-saved sentinels take the default; an
 * administrator who deliberately enters 0 means 0, which disables pruning and makes the
 * "retained indefinitely" disclosure correct.
 *
 * @return int Retention period in days; 0 means retain indefinitely.
 */
function plagiarism_docguard_retention_days(): int {
    $raw = get_config('plagiarism_docguard', 'retentiondays');
    return ($raw === false || $raw === null || $raw === '') ? 90 : (int)$raw;
}

/**
 * Student-facing disclosure shown on the submission page.
 *
 * v1.0.80: both plugin classes returned '' from print_disclosure(), so a student
 * uploading an assignment was told NOTHING — not that their document would be opened and
 * its full text extracted, not that the text would be stored in the site database, not
 * that it would be compared against their classmates' work, and not for how long any of
 * it is kept. Moodle calls this method for exactly that purpose, and for a plugin that
 * retains student document text, staying silent is not a defensible position with a
 * paying institution, let alone under GDPR transparency obligations.
 *
 * Returns the empty string when DocGuard is not actually active for this activity — a
 * disclosure about processing that will not happen is its own kind of misinformation.
 *
 * @param int $cmid Course module id of the activity the student is submitting to.
 * @return string Rendered disclosure HTML, or the empty string when DocGuard is not
 *                active for this activity and there is nothing to disclose.
 */
function plagiarism_docguard_print_disclosure(int $cmid): string {
    global $OUTPUT;

    if (!plagiarism_docguard_is_cm_active($cmid)) {
        return '';
    }

    // V1.0.85: an unlicensed site analyses nothing (see FIX-DG-UNLICENSED-FAILS-OPEN in
    // check_unlock()), so telling the student their document will be extracted, stored
    // and compared against their classmates' work would be false. The docblock above
    // already states the principle - "a disclosure about processing that will not happen
    // is its own kind of misinformation" - it just had no way to know.
    //
    // Tested on the CREDENTIALS rather than by calling check_unlock(), deliberately:
    // - It is certain. No credentials means no analysis, with no network call and no
    // cache to be stale.
    // - It cannot add latency to a submission page. check_unlock() can spend up to 15
    // seconds on a cache miss, and this runs while a student is trying to submit.
    // - It errs the safe way. A licensed site whose vendor is briefly unreachable
    // still analyses (the check fails open), and still shows the disclosure. Silence
    // while processing is happening is the failure that matters; a disclosure shown
    // during a transient outage is merely early.
    // V1.0.88: the same test, now shared with the three re-analyse endpoints via
    // plagiarism_docguard_has_credentials() — see the docblock there. Behaviour is
    // unchanged; the reasoning above is the reasoning that helper records.
    if (!plagiarism_docguard_has_credentials()) {
        return '';
    }

    // V1.0.84 FIX-DG-RETENTION-DISCLOSURE: this read `(int)get_config(...)`, and
    // get_config() returns false - not null - for a key an administrator has never
    // written, so on any site that had not saved DocGuard's settings page the retention
    // period evaluated to (int)false = 0 and this function served
    // "disclosure_noretention": "extracted text is retained indefinitely."
    //
    // That is the opposite of what happens. classes/task/cleanup.php defaults the same
    // unset key to 90 and prunes normtext and section_text after 90 days, and
    // settings.php displays 90. Three components disagreed about one value, and the one
    // that faces the STUDENT - the disclosure that exists to state the site's data
    // policy accurately - was the one that had it wrong.
    //
    // All three now resolve the default through plagiarism_docguard_retention_days().
    $retention = plagiarism_docguard_retention_days();
    $text = ($retention > 0)
        ? get_string('disclosure', 'plagiarism_docguard', $retention)
        : get_string('disclosure_noretention', 'plagiarism_docguard');

    // Format_text() rather than raw echo: the string is translatable and language packs
    // are not trusted markup sources.
    $html = format_text($text, FORMAT_MOODLE, ['context' => \context_module::instance($cmid)]);

    if (isset($OUTPUT) && method_exists($OUTPUT, 'box')) {
        return $OUTPUT->box($html, 'generalbox boxaligncenter docguard-disclosure');
    }
    return \html_writer::div($html, 'docguard-disclosure');
}

/* ── Badge rendering ──────────────────────────────────────────────────────────── */

/**
 * Render the risk badge and report link for a submitted file.
 *
 * Called by Moodle's plagiarism dispatcher when a teacher views a submission.
 * $linkarray keys used:
 *   cmid      (int)
 *   userid    (int)    — student's userid; falls back to $USER->id if absent/zero
 *   file      (stored_file) — the submitted file object
 *   component (string) — optional: 'assignsubmission_file' expected; others skipped
 *   area      (string) — optional: 'submission_files' or 'draft' expected; others skipped
 *
 * @param array $linkarray Moodle plagiarism link data. Keys used: cmid, userid,
 *                         file, and optionally component and area.
 * @return string HTML for the badge, or the empty string when nothing is shown.
 */
function plagiarism_docguard_get_links($linkarray) {
    global $DB, $PAGE, $USER;

    /* ── Guard 1: cmid must be present ───────────────────────────────────────── */
    // Moodle calls get_links() from quiz essay question review without a cmid —
    // this is a normal code path (DocGuard only handles assign submissions).
    // Silent return; no debugging() noise.
    if (empty($linkarray['cmid'])) {
        return '';
    }
    $cmid = (int)$linkarray['cmid'];

    /* ── Guard 2: activity must be enabled for this cm ───────────────────────── */
    if (!plagiarism_docguard_is_cm_active($cmid)) {
        return '';
    }

    /* ── Guard 3: file must be present and a proper stored_file ──────────────── */
    // FIX-DG-REMOVE-DEBUG-NOOP (v1.0.24): Removed debugging() calls here.
    // DocGuard is a file-based plagiarism checker. Essay questions are content-only
    // (no uploaded file), so this guard fires on every quiz overview/attempt page
    // for every essay column — generating a DEBUG_DEVELOPER notice that fills Moodle
    // logs on every page load. Silently return '' is correct behaviour.
    if (empty($linkarray['file'])) {
        return '';
    }
    $file = $linkarray['file'];
    if (!($file instanceof \stored_file)) {
        return '';
    }

    /* ── Guard 4: file must belong to a student submission ───────────────────── */
    // FIX-DG-INTRO-FILES (v1.0.55): Use stored_file::get_component() / get_filearea()
    // instead of $linkarray['component'] / $linkarray['area'].
    //
    // The previous Guards 4 & 5 were fail-open: if Moodle did not include the
    // 'component' or 'area' keys in $linkarray (which it does NOT for assignment
    // intro/template files on many Moodle versions), both guards passed silently and
    // DocGuard badges appeared on teacher-uploaded template documents attached to the
    // assignment description — files students are supposed to DOWNLOAD, not submit.
    //
    // stored_file metadata is always accurate — it is set at file creation time and
    // comes from the file store, not from the calling context. It cannot be absent.
    //
    // Teacher intro attachments: component='mod_assign', filearea='intro' or
    // 'introattachment' — must NEVER show plagiarism badges.
    // Student submission files: component='assignsubmission_file',
    // filearea='submission_files' (final) or 'draft' (in-progress) — check these.
    $filecomponent = $file->get_component();
    $filearea      = $file->get_filearea();
    if (
        $filecomponent !== 'assignsubmission_file' ||
            !in_array($filearea, ['submission_files', 'draft'], true)
    ) {
        return ''; // Teacher intro/template file or unsupported area — never plagiarism-check.
    }

    /* ── Guard 6: supported file type ────────────────────────────────────────── */
    require_once(__DIR__ . '/classes/extractor.php');
    if (!\plagiarism_docguard\extractor::is_supported($file)) {
        return ''; // NUCLEAR: silent — debugging() here fired HTML boxes on submission pages in dev mode.
    }

    /* ── Resolve userid ──────────────────────────────────────────────────────── */
    // Use !empty() not isset() so that userid=0 (group submission edge case)
    // falls through to $USER->id rather than poisoning the DB lookup with 0.
    $context = \context_module::instance($cmid);
    $userid  = !empty($linkarray['userid']) ? (int)$linkarray['userid'] : (int)$USER->id;

    /* ── Guard 7: visibility — teachers see all badges; students see their own ── */
    // FIX-DG-REPORT-ACCESS (v1.0.78): delegate to the shared helper so this test
    // and the one enforced by report.php / student_report.php can never diverge
    // again. Previously this was an inline mod/assign:grade check while the report
    // pages required a capability non-editing teachers did not hold.
    $isteacher = plagiarism_docguard_can_view_reports($context);
    $isown     = ((int)$USER->id === $userid);

    if (!$isteacher && !$isown) {
        return ''; // Not a teacher and not viewing own submission — skip.
    }

    /* ── PERF-FIX-DG-BATCH-PRELOAD (v1.0.67) ───────────────────────────────── */
    // View All Submissions calls get_links() once per student/file row.
    // Pre-v1.0.67: 1–2 DB queries per student PLUS a synchronous HTTP call +
    // full AI analysis pipeline for any unprocessed file — blocking the page
    // for 10-30 s per student with new submissions (catastrophic for large classes).
    // Fix 1: preload ALL plagiarism_docguard_sub rows for this CM in ONE query on
    // the first call; subsequent students served from the in-request cache (O(1)).
    // Fix 2: removed synchronous lazy analysis from get_links() entirely.
    // Unprocessed files now return a 'pending' badge immediately. Analysis is
    // handled exclusively by the event observer and the scheduled cleanup task,
    // which run asynchronously and do not block the submissions page.
    // Keyed by course module id; set once that activity's rows have been preloaded.
    static $dgsubpreloaded = [];
    // Keyed by course module id, then user id, then file content hash; each leaf is the
    // most recent submission record for that student and file.
    static $dgsubcache     = [];

    if (!isset($dgsubpreloaded[$cmid])) {
        $dgsubpreloaded[$cmid] = true;
        $allsubs = $DB->get_records_sql(
            "SELECT * FROM {plagiarism_docguard_sub}
              WHERE cmid = :cmid
           ORDER BY timemodified DESC",
            ['cmid' => $cmid]
        );
        foreach ($allsubs as $row) {
            $uid  = (int)$row->userid;
            $hash = (string)$row->contenthash;
            // Keep only the most-recent record per (userid, contenthash) pair
            // (ORDER BY timemodified DESC guarantees first-wins = newest).
            if (!isset($dgsubcache[$cmid][$uid][$hash])) {
                $dgsubcache[$cmid][$uid][$hash] = $row;
            }
        }
    }
    /* ───────────────────────────────────────────────────────────────────────── */

    /* ── DB lookup (from request-level cache — zero DB queries per student) ─── */
    $contenthash = $file->get_contenthash();
    $sub = $dgsubcache[$cmid][$userid][$contenthash] ?? null;

    // NOTE: Synchronous lazy analysis removed in v1.0.67 (PERF-FIX-DG-BATCH-PRELOAD).
    // Pre-v1.0.67 ran analyse_and_store() inline here when $sub was missing/pending,
    // blocking the submissions page for the full duration of file extraction + HTTP
    // round-trip to the AI Grader API (10-30 s per student). Analysis is now handled
    // by the event observer (fires on submission) and the scheduled cleanup task.
    // Teachers can also manually trigger re-analysis from the student_report.php page.

    // Pass the file's stored ID so render_badge can show a Re-analyse button
    // even when there is no DB record yet (Case A: no sub at all).
    $fileid = (int)$file->get_id();

    if (!$sub) {
        return plagiarism_docguard_render_badge('pending', 0, 'low', '', $cmid, $userid, $isteacher, false, 0, $fileid);
    }

    return plagiarism_docguard_render_badge(
        $sub->status,
        (float)$sub->overall_riskscore,
        (string)$sub->overall_risklevel,
        (string)$sub->errormsg,
        $cmid,
        $userid,
        $isteacher,
        (int)$sub->id,
        (int)$sub->timecreated,
        $fileid
    );
}

/**
 * Find a student's current assign_submission id for one assignment activity.
 *
 * V1.0.88 FIX-DG-FIND-SUBMISSION-ATTEMPTS: this asked get_record() for
 * (assignment, userid) alone. mod_assign keeps one assign_submission row PER ATTEMPT —
 * db/install.xml for mod_assign declares attemptnumber and a `latest` flag, and
 * assign::add_attempt() inserts a further row each time a teacher allows another try —
 * so on any activity configured for multiple attempts the second attempt made
 * get_record() throw dml_multiple_records_exception. It also matched group submissions,
 * whose rows carry userid = 0, and it could return a superseded attempt.
 *
 * Fixed to ask for latest = 1, which is the row mod_assign itself treats as current, and
 * to tolerate more than one match rather than throwing. Note this function currently has
 * no callers inside the plugin; it is corrected rather than deleted because it is a
 * global function in a plugin's lib.php, which is reachable from anywhere, and because
 * reanalyse.php already resolves the same thing with the same latest = 1 condition.
 *
 * @param int $cmid   The course module id of the assignment.
 * @param int $userid The student.
 * @return int The submission id, or 0 when there is no current submission.
 */
function plagiarism_docguard_find_submissionid(int $cmid, int $userid): int {
    global $DB;
    if ($cmid <= 0 || $userid <= 0) {
        return 0;
    }
    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return 0;
    }
    $assign = $DB->get_record('assign', ['id' => $cm->instance]);
    if (!$assign) {
        return 0;
    }
    $rows = $DB->get_records(
        'assign_submission',
        ['assignment' => $assign->id, 'userid' => $userid, 'latest' => 1],
        'attemptnumber DESC, id DESC',
        'id',
        0,
        1
    );
    $sub = $rows ? reset($rows) : null;
    return $sub ? (int)$sub->id : 0;
}

/**
 * Build the risk badge and its accompanying links for one submission.
 *
 * @param string $status The stored analysis status: analysed, pending, error or unsupported.
 * @param float $score The overall risk score, 0-100.
 * @param string $level The risk band: low, medium or high.
 * @param string $errmsg The stored error message, for the error status.
 * @param int $cmid The course module, used to build the report links.
 * @param int $userid The student the badge belongs to.
 * @param bool $isteacher Whether the viewer may see the report links.
 * @param int|bool $subid The submission record id, or false when none exists yet.
 * @param int $timecreated When the record was created, used to detect submissions stuck
 *                in "pending"; 0 when unknown.
 * @param int $fileid The Moodle file id, used by the re-analyse link when no submission
 *                record exists yet.
 * @return string HTML for the badge and links, or the empty string for "unsupported".
 */
function plagiarism_docguard_render_badge(
    string $status,
    float $score,
    string $level,
    string $errmsg,
    int $cmid,
    int $userid,
    bool $isteacher,
    $subid,
    int $timecreated = 0,
    int $fileid = 0
): string {
    // Inline styles guarantee the badge is always visible, even when
    // styles.css hasn't loaded yet (e.g. called after <head> is output).
    // Must be kept in sync with styles.css.
    static $stylesinjected = false;
    $styleblock = '';
    if (!$stylesinjected) {
        $stylesinjected = true;
        $styleblock = '<style>'
            . '.docguard-badge{display:inline-flex;align-items:center;gap:5px;padding:3px '
                . '10px;border-radius:4px;font-size:.78rem;font-weight:600;letter-spacing:.01em;line-height:1.4;margin:2px '
                . '0;border:1px solid transparent;cursor:default;position:relative;text-decoration:none;}'
            . '.docguard-badge-low{background:#e8f5e9;color:#1b5e20;border-color:#a5d6a7;}'
            . '.docguard-badge-medium{background:#fff8e1;color:#e65100;border-color:#ffcc80;}'
            . '.docguard-badge-high{background:#ffebee;color:#b71c1c;border-color:#ef9a9a;}'
            . '.docguard-badge-pending{background:#f3f4f6;color:#6b7280;border-color:#d1d5db;}'
            . '.docguard-badge-error{background:#fdf2f8;color:#6b21a8;border-color:#d8b4fe;}'
            . '.docguard-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}'
            . '.docguard-dot-low{background:#2e7d32;}.docguard-dot-medium{background:#e65100;}'
            . '.docguard-dot-high{background:#b71c1c;}'
            . '.docguard-dot-pending{background:#9ca3af;}.docguard-dot-error{background:#7c3aed;}'
            . '.docguard-wrap{margin:4px 0;display:flex;flex-direction:column;gap:2px;}'
            . '.docguard-link{font-size:.78rem;color:#555;text-decoration:underline;display:block;margin-top:2px;}'
            . '.docguard-badge[data-dg-tip]:hover::after{content:attr(data-dg-tip);position:absolute;bottom:calc(100% '
                . '+ '
                . '6px);left:0;z-index:9999;background:#1e293b;color:#f1f5f9;font-size:.72rem;'
                . 'font-weight:400;line-height:1.5;padding:7px '
                . '11px;border-radius:5px;width:300px;white-space:normal;pointer-events:none;box-shadow:0 4px 12px '
                . 'rgba(0,0,0,.25);}'
            . '.docguard-badge[data-dg-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + '
                . '1px);left:14px;border:5px solid transparent;border-top-color:#1e293b;pointer-events:none;}'
            . '</style>';
    }

    $cssclass = 'docguard-badge';
    $dotclass = 'docguard-dot';
    $label     = '';
    $tooltip   = '';

    switch ($status) {
        case 'analysed':
            $cssclass .= ' docguard-badge-' . $level;
            $dotclass .= ' docguard-dot-' . $level;
            $levellabels = [
                'low'    => get_string('badgelevellow', 'plagiarism_docguard'),
                'medium' => get_string('badgelevelmedium', 'plagiarism_docguard'),
                'high'   => get_string('badgelevelhigh', 'plagiarism_docguard'),
            ];
            $levellabel  = $levellabels[$level] ?? ucfirst($level);
            $label   = get_string(
                'badgelabel',
                'plagiarism_docguard',
                (object) ['level' => $levellabel, 'score' => (int)$score]
            );
            $tooltips = [
                'low'    => get_string('tooltiplow', 'plagiarism_docguard', (int)$score),
                'medium' => get_string('tooltipmedium', 'plagiarism_docguard', (int)$score),
                'high'   => get_string('tooltiphigh', 'plagiarism_docguard', (int)$score),
            ];
            $tooltip = $tooltips[$level] ?? $label;
            break;
        case 'error':
            $cssclass .= ' docguard-badge-error';
            $dotclass .= ' docguard-dot-error';
            $label   = get_string('badgeerror', 'plagiarism_docguard');
            $tooltip = get_string(
                'tooltiperror',
                'plagiarism_docguard',
                trim($errmsg) ?: get_string('tooltiperrordefault', 'plagiarism_docguard')
            );
            break;
        case 'unsupported':
            return ''; // Silently skip.
        default: // Pending.
            $cssclass .= ' docguard-badge-pending';
            $dotclass .= ' docguard-dot-pending';
            // FIX-DG-STUCK-PENDING (v1.0.71): Age-aware pending badge.
            // If timecreated is known and > 10 min, the file is not actively
            // being analysed — it is stuck. Show elapsed time and a different
            // tooltip so teachers know this is not just a brief delay.
            $agemin = ($timecreated > 0) ? (int)floor((time() - $timecreated) / 60) : 0;
            $isstuck = ($agemin >= 10);
            if ($isstuck) {
                $agestr = $agemin < 60
                    ? get_string('ageminutes', 'plagiarism_docguard', $agemin)
                    : get_string('agehours', 'plagiarism_docguard', round($agemin / 60, 1));
                $label   = get_string('badgependingaged', 'plagiarism_docguard', $agestr);
                $tooltip = get_string('tooltipstuck', 'plagiarism_docguard', $agestr) . ' '
                    . ($subid ? get_string('tooltipstuckreanalyse', 'plagiarism_docguard') : '');
            } else {
                $label   = get_string('badgepending', 'plagiarism_docguard');
                $tooltip = get_string('tooltippending', 'plagiarism_docguard');
            }
    }

    $badge = $styleblock
        . '<span class="' . $cssclass . '" data-dg-tip="' . s($tooltip) . '">'
        . '<span class="' . $dotclass . '"></span>'
        . s($label)
        . '</span>';

    $links = '';
    if ($isteacher && $subid && $status === 'analysed') {
        $reporturl = new \moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]);
        $classurl  = new \moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);
        $links = '<a href="' . $reporturl->out(false) . '" class="docguard-link">'
                    . get_string('viewreportlink', 'plagiarism_docguard') . '</a>'
               . '<a href="' . $classurl->out(false)  . '" class="docguard-link" style="margin-left:8px;">'
                    . get_string('classreportshort', 'plagiarism_docguard') . '</a>';
    } else if ($isteacher && $status === 'error') {
        // Core_text::substr, not substr: a byte-wise cut can sever a multi-byte
        // character in an error message (same class of bug as FIX-DG-MB-TRUNCATE).
        $links = '<small style="color:#888;font-size:0.75rem;">' . s(\core_text::substr($errmsg, 0, 120)) . '</small>';
    } else if ($isteacher && $status === 'pending' && ($subid || $fileid)) {
        // FIX-DG-REANALYSE-ALWAYS (v1.0.72): Show Re-analyse for ALL pending submissions
        // where the teacher can see the badge — regardless of age.
        //
        // v1.0.71 gated this behind $isstuck (requires timecreated > 0 AND age >= 10 min),
        // which meant:
        // Case A (no DB record, subid=false): never showed — $subid was false.
        // Case B (old records with timecreated=0): never showed — $agemin=0, $isstuck=false.
        //
        // Fix: check ($subid || $fileid). subid covers Case B; fileid covers Case A.
        // The URL includes subid for Case B and fileid+userid for Case A so reanalyse.php
        // knows which path to take.
        $params = ['cmid' => $cmid, 'sesskey' => sesskey()];
        if ($subid) {
            $params['subid'] = $subid;
        } else {
            // Case A: no DB record yet — pass the file ID and user ID so reanalyse.php
            // can create the record and run analysis from scratch.
            $params['fileid'] = $fileid;
            $params['userid'] = $userid;
        }
        $reanalyseurl = new \moodle_url('/plagiarism/docguard/reanalyse.php', $params);
        $links = '<a href="' . $reanalyseurl->out(false) . '" class="docguard-link"'
               . ' onclick="return confirm(\''
               . s(addslashes(get_string('reanalyseconfirmbadge', 'plagiarism_docguard'))) . '\');">'
               . '&#8635; ' . get_string('reanalysenow', 'plagiarism_docguard') . '</a>';
    }

    // FIX-DG-CLASS-REPORT-ROUTE (v1.0.78): give teachers a route to the class report
    // from the NON-analysed badge states too.
    //
    // The plugin registers no navigation callback, so the badge link is the only way
    // to reach report.php — and it was emitted only for analysed submissions. A
    // teacher whose whole class was stuck on "Pending" therefore had no route to the
    // class report at all, and so no route to its "Scan & Analyse Unprocessed
    // Submissions" button, which is the one tool that recovers exactly that
    // situation. From the teacher's side that is indistinguishable from a
    // permissions fault, which is how this ticket was reported.
    //
    // Scoped to pending/error only: analysed rows already carry this link above, so
    // this adds no duplicate links to a fully-analysed class list.
    if ($isteacher && $status !== 'analysed') {
        $classurl = new \moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);
        $links .= '<a href="' . $classurl->out(false) . '" class="docguard-link"'
               . ($links !== '' ? ' style="margin-left:8px;"' : '') . '>'
               . get_string('classreportshort', 'plagiarism_docguard') . '</a>';
    }

    return '<div class="docguard-wrap">' . $badge . $links . '</div>';
}
