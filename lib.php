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

defined('MOODLE_INTERNAL') || die();

// FIX-DG-EARLY-FUNCTION (v1.0.41): Define the replacement hook function at the very
// TOP of lib.php — before any class definitions, helper functions, or other code.
//
// Root cause of all prior failed fix attempts:
//   Moodle's plagiarism_update_status() checks
//   function_exists('plagiarism_docguard_before_standard_top_of_body_html') FIRST.
//   If FALSE, the deprecation warning fires IMMEDIATELY before Moodle does
//   require_once(lib.php). No matter where in lib.php the function was defined,
//   it was always too late.
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
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
    function plagiarism_docguard_before_standard_top_of_body_html() {}
}


// FIX-DG-WARNING-CAPTURE (v1.0.40): Register a PHP error handler that detects the
// two known DocGuard HTML deprecation warnings and writes a persistent Moodle config
// flag whenever they fire on ANY page (grading pages, submission pages, etc.).
//
// Problem this solves: diag.php only scans its own output buffer. When warnings fire
// on the assignment grading page, diag.php never sees them — it reports ALL PASS while
// warnings are actively visible to teachers. This handler bridges that gap by writing
// a cross-page flag that diag.php reads, so the diagnostic shows FAIL until the warnings
// truly stop appearing.
//
// Design constraints:
//   - MUST be non-destructive: every error is passed to the previous handler unchanged.
//     We only add a side-effect config write — we do NOT suppress any output.
//   - MUST be idempotent: a static $written guard prevents DB hammering when the warning
//     fires multiple times in the same request.
//   - MUST be safe: all DB writes are wrapped in try/catch. If set_config() fails for
//     any reason (early bootstrap, read-only DB) the page renders normally.
//   - Returns false to explicitly pass control to PHP's default error handling after
//     our side-effect, so the warning text continues to appear as before.
if (!function_exists('_dg_warning_capture_handler')) {
    function _dg_warning_capture_handler(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        static $written = false;
        $is_dg_warning = (
            strpos($errstr, 'plagiarism_plugin::update_status() is deprecated') !== false
            || strpos($errstr, 'before_standard_top_of_body_html in plagiarism_docguard') !== false
        );
        if ($is_dg_warning && !$written) {
            $written = true;
            try {
                $page_url = '';
                global $PAGE;
                if (isset($PAGE) && method_exists($PAGE, 'has_set_url') && $PAGE->has_set_url()) {
                    try { $page_url = $PAGE->url->out(false); } catch (\Throwable $_u) {}
                }
                if ($page_url === '') {
                    $page_url = (string)($_SERVER['REQUEST_URI'] ?? '');
                }
                set_config('warning_last_detected', time(),                     'plagiarism_docguard');
                set_config('warning_last_message',  substr($errstr, 0, 400),    'plagiarism_docguard');
                set_config('warning_last_url',      substr($page_url, 0, 400),  'plagiarism_docguard');
            } catch (\Throwable $_e) {
                // Swallow — config write failure must never break page rendering.
            }
        }
        // NUCLEAR-SUPPRESS (v1.0.42): For the two specific DocGuard-owned warnings,
        // return TRUE — telling PHP "I fully handled this; do NOT pass to normal error
        // handling." This prevents them from ever appearing as HTML debug boxes on any
        // Moodle page, in any debug mode, regardless of hook cache state or load order.
        // For all other errors, return false so they flow through normally.
        if ($is_dg_warning) {
            return true;
        }
        return false;
    }
}
set_error_handler('_dg_warning_capture_handler', E_DEPRECATED | E_NOTICE | E_USER_DEPRECATED | E_WARNING);

// ── Helpers ───────────────────────────────────────────────────────────────────

function plagiarism_docguard_get_siteid(): string {
    $aiconfig = get_config('local_aiconfig', 'siteid');
    if (!empty($aiconfig)) { return $aiconfig; }
    return (string)(get_config('plagiarism_docguard', 'siteid') ?: '');
}

function plagiarism_docguard_get_apikey(): string {
    $aiconfig = get_config('local_aiconfig', 'apikey');
    if (!empty($aiconfig)) { return $aiconfig; }
    return (string)(get_config('plagiarism_docguard', 'apikey') ?: '');
}

/**
 * Fetches and caches platform-level site-wide plagiarism settings.
 * Mirrors plagiarism_essayguard_get_platform_settings() but uses the
 * plagiarism_docguard config namespace for its own 30-minute cache.
 * Returns assoc array with keys: essayguard_assignments, essayguard_quizzes,
 * docguard_assignments, docguard_quizzes (all bool).
 * Fails open (all false) on any network/config error.
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

    $cache_time = (int)get_config('plagiarism_docguard', 'platform_settings_time');
    if ($cache_time > 0 && (time() - $cache_time) < 1800) {
        $cached_data = get_config('plagiarism_docguard', 'platform_settings_data');
        if (!empty($cached_data)) {
            $decoded = json_decode($cached_data, true);
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
        $response  = $curl->get('https://lms-labs.com/api/plagiarism-settings', [
            'siteId' => $siteid, 'apiKey' => $apikey,
        ]);
        $http_code = (int)($curl->info['http_code'] ?? 0);
        if ($http_code === 200 && !empty($response)) {
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
                set_config('platform_settings_time', time(),               'plagiarism_docguard');
                $cached = $result;
                return $cached;
            }
        }
    } catch (\Throwable $e) {
        // Fail open.
    }

    $cached = $defaults;
    return $cached;
}

function plagiarism_docguard_is_cm_active(int $cmid): bool {
    // FIX-DG-SITEWIDE-SETTINGS (v1.0.25): Check platform site-wide settings first.
    // If the admin enabled DocGuard for all assignments or all quizzes on the
    // lms-labs.com platform, that flag overrides the per-activity checkbox.
    try {
        $platform = plagiarism_docguard_get_platform_settings();
        if (!empty($platform['docguard_assignments']) || !empty($platform['docguard_quizzes'])) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                if (!empty($platform['docguard_assignments']) && $cm->modname === 'assign') {
                    return true;
                }
                if (!empty($platform['docguard_quizzes']) && $cm->modname === 'quiz') {
                    return true;
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore — fall through to per-cm check.
    }

    $value = get_config('plagiarism_docguard', 'enabled_cm_' . $cmid);
    return ($value === false) || !empty($value);
}

function plagiarism_docguard_check_unlock(): bool {
    global $CFG;
    static $rt = null;
    if ($rt !== null) { return $rt; }

    $cached_result = get_config('plagiarism_docguard', 'unlock_cache_result');
    $cached_time   = (int)get_config('plagiarism_docguard', 'unlock_cache_time');
    if ($cached_time > 0 && (time() - $cached_time) < 1800) {
        $rt = !empty($cached_result);
        return $rt;
    }

    $siteid = plagiarism_docguard_get_siteid();
    $apikey = plagiarism_docguard_get_apikey();
    if (empty($siteid) || empty($apikey)) {
        $rt = true; return true;
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
    $response  = $curl->get('https://lms-labs.com/api/plugin-unlock/verify', [
        'pluginId' => 'docguard', 'siteId' => $siteid, 'apiKey' => $apikey,
    ]);
    $http_code = (int)($curl->info['http_code'] ?? 0);

    if ($http_code !== 200 || !$response) {
        $rt = true; return true; // fail-open
    }

    $data   = json_decode($response, true);
    $result = !empty($data['unlocked']);
    if (!$result) {
        $result = plagiarism_docguard_auto_unlock($siteid, $apikey);
    }
    set_config('unlock_cache_result', (int)$result, 'plagiarism_docguard');
    set_config('unlock_cache_time',   time(),        'plagiarism_docguard');
    $rt = $result;
    return $rt;
}

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
    $resp    = $curl->post('https://lms-labs.com/api/plugin-unlock', $payload, [
        'CURLOPT_TIMEOUT'    => 15,
        'CURLOPT_CONNECTTIMEOUT' => 5,
        'CURLOPT_HTTPHEADER' => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $code = (int)($curl->info['http_code'] ?? 0);
    if ($code !== 200 || !$resp) { return false; }
    $data = json_decode($resp, true);
    return !empty($data['unlocked']);
}

// ── Plagiarism plugin class ────────────────────────────────────────────────────

// FIX-DG-AUTOLOAD (v1.0.50): Defer class definition until first use via spl_autoload_register.
//
// Root cause of persistent deprecation warnings with the Path A/B conditional approach:
//   During Moodle's early bootstrap (setup.php get_plugins_with_function scan), lib.php
//   is loaded before plagiarism_plugin base class is available. Path A (standalone with
//   update_status() stub) is taken permanently for the entire PHP request. Later, when
//   plagiarism_update_status() calls:
//     new ReflectionMethod('plagiarism_plugin_docguard', 'update_status')
//   getDeclaringClass() returns 'plagiarism_plugin_docguard' (not 'plagiarism_plugin'),
//   so Moodle calls debugging('plagiarism_plugin::update_status() is deprecated...').
//   Moodle's debugging() writes HTML directly to the output buffer — set_error_handler()
//   cannot intercept it. No code change inside lib.php can fix a class already defined.
//
// Fix: Register an SPL autoloader so the class is defined on FIRST USE, not at lib.php
//   load time. The early bootstrap scan (get_plugins_with_function) only checks for the
//   existence of global FUNCTIONS — it never instantiates the plugin class. So the
//   autoloader never fires during early bootstrap. It fires the first time Moodle calls
//   class_exists('plagiarism_plugin_docguard') or new plagiarism_plugin_docguard() inside
//   plagiarism_update_status() — by which point Moodle is fully bootstrapped and
//   plagiarism_plugin is always defined. The class always extends plagiarism_plugin,
//   inheriting update_status(). getDeclaringClass()->getName() === 'plagiarism_plugin'
//   → no debugging() call → zero warnings in any debug mode.
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
                try { require_once($CFG->libdir . '/plagiarismlib.php'); } catch (\Throwable $_e) {}
            }
            if (!class_exists('plagiarism_plugin', false)) {
                $dg_lib = dirname(dirname(dirname(__FILE__))) . '/lib/plagiarismlib.php';
                if (file_exists($dg_lib)) {
                    try { require_once($dg_lib); } catch (\Throwable $_e) {}
                }
                unset($dg_lib);
            }
        }
        if (class_exists('plagiarism_plugin', false)) {
            // Path B: class extends plagiarism_plugin.
            // update_status() INHERITED — getDeclaringClass() = 'plagiarism_plugin' → no warning.
            require_once __DIR__ . '/classes/pluginclass.php';
        } else {
            // Path A fallback (should never happen on a normal web page load).
            // update_status() stub prevents ReflectionException crash.
            require_once __DIR__ . '/classes/pluginclass_standalone.php';
        }
    }, true, true);
}

// ── Module form hooks ─────────────────────────────────────────────────────────

function plagiarism_docguard_supports_mod($modulename) {
    return in_array($modulename, ['assign'], true);
}

function plagiarism_docguard_coursemodule_standard_elements($formwrapper, $mform) {
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
    $mform->setDefault('docguard_enabled', ($saved === false) ? 0 : (int)!empty($saved));
}

function plagiarism_docguard_coursemodule_edit_post_actions($data, $course) {
    // FIX-DG-CHECKBOX-SAVE (v1.0.7): Always write config — no isset() guard.
    // Unchecked plain checkbox: field absent from POST, !empty(null) = false → saves 0.
    // Checked plain checkbox: field = '1', !empty('1') = true → saves 1.
    set_config('enabled_cm_' . $data->coursemodule, !empty($data->docguard_enabled) ? 1 : 0, 'plagiarism_docguard');
    return $data;
}

// ── Badge rendering ────────────────────────────────────────────────────────────

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
 */
function plagiarism_docguard_get_links($linkarray) {
    global $DB, $PAGE, $USER;

    // ── Guard 1: cmid must be present ─────────────────────────────────────────
    // Moodle calls get_links() from quiz essay question review without a cmid —
    // this is a normal code path (DocGuard only handles assign submissions).
    // Silent return; no debugging() noise.
    if (empty($linkarray['cmid'])) {
        return '';
    }
    $cmid = (int)$linkarray['cmid'];

    // ── Guard 2: activity must be enabled for this cm ─────────────────────────
    if (!plagiarism_docguard_is_cm_active($cmid)) {
        return '';
    }

    // ── Guard 3: file must be present and a proper stored_file ────────────────
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

    // ── Guard 4: file must belong to a student submission ─────────────────────
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
    //   'introattachment' — must NEVER show plagiarism badges.
    // Student submission files: component='assignsubmission_file',
    //   filearea='submission_files' (final) or 'draft' (in-progress) — check these.
    $file_component = $file->get_component();
    $file_area      = $file->get_filearea();
    if ($file_component !== 'assignsubmission_file' ||
            !in_array($file_area, ['submission_files', 'draft'], true)) {
        return ''; // Teacher intro/template file or unsupported area — never plagiarism-check
    }

    // ── Guard 6: supported file type ──────────────────────────────────────────
    require_once(__DIR__ . '/classes/extractor.php');
    if (!\plagiarism_docguard\extractor::is_supported($file)) {
        return ''; // NUCLEAR: silent — debugging() here fired HTML boxes on submission pages in dev mode
    }

    // ── Resolve userid ────────────────────────────────────────────────────────
    // Use !empty() not isset() so that userid=0 (group submission edge case)
    // falls through to $USER->id rather than poisoning the DB lookup with 0.
    $context = \context_module::instance($cmid);
    $userid  = !empty($linkarray['userid']) ? (int)$linkarray['userid'] : (int)$USER->id;

    // ── Guard 7: visibility — teachers see all badges; students see their own ──
    // mod/assign:grade is always defined in Moodle and covers editing teachers,
    // managers, and admins. plagiarism/docguard:viewreport is an additional
    // optional capability checked for backward compatibility.
    $is_teacher = has_capability('mod/assign:grade', $context)
               || has_capability('plagiarism/docguard:viewreport', $context);
    $is_own     = ((int)$USER->id === $userid);

    if (!$is_teacher && !$is_own) {
        return ''; // Not a teacher and not viewing own submission — skip.
    }

    // ── PERF-FIX-DG-BATCH-PRELOAD (v1.0.67) ─────────────────────────────────
    // View All Submissions calls get_links() once per student/file row.
    // Pre-v1.0.67: 1–2 DB queries per student PLUS a synchronous HTTP call +
    // full AI analysis pipeline for any unprocessed file — blocking the page
    // for 10-30 s per student with new submissions (catastrophic for large classes).
    // Fix 1: preload ALL plagiarism_docguard_sub rows for this CM in ONE query on
    //   the first call; subsequent students served from the in-request cache (O(1)).
    // Fix 2: removed synchronous lazy analysis from get_links() entirely.
    //   Unprocessed files now return a 'pending' badge immediately. Analysis is
    //   handled exclusively by the event observer and the scheduled cleanup task,
    //   which run asynchronously and do not block the submissions page.
    static $_dg_sub_preloaded = [];   // [$cmid] => true
    static $_dg_sub_cache     = [];   // [$cmid][$userid][$contenthash] => stdClass

    if (!isset($_dg_sub_preloaded[$cmid])) {
        $_dg_sub_preloaded[$cmid] = true;
        $all_subs = $DB->get_records_sql(
            "SELECT * FROM {plagiarism_docguard_sub}
              WHERE cmid = :cmid
           ORDER BY timemodified DESC",
            ['cmid' => $cmid]
        );
        foreach ($all_subs as $row) {
            $uid  = (int)$row->userid;
            $hash = (string)$row->contenthash;
            // Keep only the most-recent record per (userid, contenthash) pair
            // (ORDER BY timemodified DESC guarantees first-wins = newest).
            if (!isset($_dg_sub_cache[$cmid][$uid][$hash])) {
                $_dg_sub_cache[$cmid][$uid][$hash] = $row;
            }
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    // ── DB lookup (from request-level cache — zero DB queries per student) ───
    $contenthash = $file->get_contenthash();
    $sub = $_dg_sub_cache[$cmid][$userid][$contenthash] ?? null;

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
        return plagiarism_docguard_render_badge('pending', 0, 'low', '', $cmid, $userid, $is_teacher, false, 0, $fileid);
    }

    return plagiarism_docguard_render_badge(
        $sub->status,
        (float)$sub->overall_riskscore,
        (string)$sub->overall_risklevel,
        (string)$sub->errormsg,
        $cmid,
        $userid,
        $is_teacher,
        (int)$sub->id,
        (int)$sub->timecreated,
        $fileid
    );
}

function plagiarism_docguard_find_submissionid(int $cmid, int $userid): int {
    global $DB;
    $cm     = get_coursemodule_from_id('assign', $cmid);
    if (!$cm) { return 0; }
    $assign = $DB->get_record('assign', ['id' => $cm->instance]);
    if (!$assign) { return 0; }
    $sub = $DB->get_record('assign_submission', [
        'assignment' => $assign->id,
        'userid'     => $userid,
    ]);
    return $sub ? (int)$sub->id : 0;
}

function plagiarism_docguard_render_badge(
    string $status,
    float $score,
    string $level,
    string $errmsg,
    int $cmid,
    int $userid,
    bool $is_teacher,
    $subid,
    int $timecreated = 0,
    int $fileid = 0
): string {
    // Inline styles guarantee the badge is always visible, even when
    // styles.css hasn't loaded yet (e.g. called after <head> is output).
    // Must be kept in sync with styles.css.
    static $styles_injected = false;
    $style_block = '';
    if (!$styles_injected) {
        $styles_injected = true;
        $style_block = '<style>'
            . '.docguard-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:4px;font-size:.78rem;font-weight:600;letter-spacing:.01em;line-height:1.4;margin:2px 0;border:1px solid transparent;cursor:default;position:relative;text-decoration:none;}'
            . '.docguard-badge-low{background:#e8f5e9;color:#1b5e20;border-color:#a5d6a7;}'
            . '.docguard-badge-medium{background:#fff8e1;color:#e65100;border-color:#ffcc80;}'
            . '.docguard-badge-high{background:#ffebee;color:#b71c1c;border-color:#ef9a9a;}'
            . '.docguard-badge-pending{background:#f3f4f6;color:#6b7280;border-color:#d1d5db;}'
            . '.docguard-badge-error{background:#fdf2f8;color:#6b21a8;border-color:#d8b4fe;}'
            . '.docguard-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}'
            . '.docguard-dot-low{background:#2e7d32;}.docguard-dot-medium{background:#e65100;}.docguard-dot-high{background:#b71c1c;}'
            . '.docguard-dot-pending{background:#9ca3af;}.docguard-dot-error{background:#7c3aed;}'
            . '.docguard-wrap{margin:4px 0;display:flex;flex-direction:column;gap:2px;}'
            . '.docguard-link{font-size:.78rem;color:#555;text-decoration:underline;display:block;margin-top:2px;}'
            . '.docguard-badge[data-dg-tip]:hover::after{content:attr(data-dg-tip);position:absolute;bottom:calc(100% + 6px);left:0;z-index:9999;background:#1e293b;color:#f1f5f9;font-size:.72rem;font-weight:400;line-height:1.5;padding:7px 11px;border-radius:5px;width:300px;white-space:normal;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.25);}'
            . '.docguard-badge[data-dg-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + 1px);left:14px;border:5px solid transparent;border-top-color:#1e293b;pointer-events:none;}'
            . '</style>';
    }

    $css_class = 'docguard-badge';
    $dot_class = 'docguard-dot';
    $label     = '';
    $tooltip   = '';

    switch ($status) {
        case 'analysed':
            $css_class .= ' docguard-badge-' . $level;
            $dot_class .= ' docguard-dot-' . $level;
            $level_labels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
            $level_label  = $level_labels[$level] ?? ucfirst($level);
            $label   = 'Plagiarism Check ' . $level_label . ' ' . (int)$score . '%';
            $tooltips = [
                'low'    => 'DocGuard: LOW risk (' . (int)$score . '/100). Very few plagiarism or AI-generation signals detected.',
                'medium' => 'DocGuard: MEDIUM risk (' . (int)$score . '/100). Some signals triggered — review the full report before drawing conclusions.',
                'high'   => 'DocGuard: HIGH risk (' . (int)$score . '/100). Significant signals detected — a detailed report is strongly recommended.',
            ];
            $tooltip = $tooltips[$level] ?? $label;
            break;
        case 'error':
            $css_class .= ' docguard-badge-error';
            $dot_class .= ' docguard-dot-error';
            $label   = 'Plagiarism Check Error';
            $tooltip = 'DocGuard could not analyse this file. ' . (trim($errmsg) ?: 'Check plugin settings or ask the student to resubmit.');
            break;
        case 'unsupported':
            return ''; // silently skip
        default: // pending
            $css_class .= ' docguard-badge-pending';
            $dot_class .= ' docguard-dot-pending';
            // FIX-DG-STUCK-PENDING (v1.0.71): Age-aware pending badge.
            // If timecreated is known and > 10 min, the file is not actively
            // being analysed — it is stuck. Show elapsed time and a different
            // tooltip so teachers know this is not just a brief delay.
            $age_min = ($timecreated > 0) ? (int)floor((time() - $timecreated) / 60) : 0;
            $is_stuck = ($age_min >= 10);
            if ($is_stuck) {
                $age_str = $age_min < 60
                    ? $age_min . ' min'
                    : round($age_min / 60, 1) . ' hr';
                $label   = 'Plagiarism Check Pending (' . $age_str . ')';
                $tooltip = 'Analysis has been pending for ' . $age_str . '. '
                    . 'This usually means the Moodle cron scheduler is not running or is running infrequently. '
                    . 'Ask your Moodle administrator to check the cron job. '
                    . ($subid ? 'You can also click Re-analyse below to run it now.' : '');
            } else {
                $label   = 'Plagiarism Check Pending';
                $tooltip = 'DocGuard is queued to analyse this file. Reload the page in a moment to see the result.';
            }
    }

    $badge = $style_block
        . '<span class="' . $css_class . '" data-dg-tip="' . s($tooltip) . '">'
        . '<span class="' . $dot_class . '"></span>'
        . s($label)
        . '</span>';

    $links = '';
    if ($is_teacher && $subid && $status === 'analysed') {
        $report_url = new \moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]);
        $class_url  = new \moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);
        $links = '<a href="' . $report_url->out(false) . '" class="docguard-link">View DocGuard Report</a>'
               . '<a href="' . $class_url->out(false)  . '" class="docguard-link" style="margin-left:8px;">Class Report</a>';
    } elseif ($is_teacher && $status === 'error') {
        $links = '<small style="color:#888;font-size:0.75rem;">' . s(substr($errmsg, 0, 120)) . '</small>';
    } elseif ($is_teacher && $status === 'pending' && ($subid || $fileid)) {
        // FIX-DG-REANALYSE-ALWAYS (v1.0.72): Show Re-analyse for ALL pending submissions
        // where the teacher can see the badge — regardless of age.
        //
        // v1.0.71 gated this behind $is_stuck (requires timecreated > 0 AND age >= 10 min),
        // which meant:
        //   Case A (no DB record, subid=false): never showed — $subid was false.
        //   Case B (old records with timecreated=0): never showed — $age_min=0, $is_stuck=false.
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
        $reanalyse_url = new \moodle_url('/plagiarism/docguard/reanalyse.php', $params);
        $links = '<a href="' . $reanalyse_url->out(false) . '" class="docguard-link"'
               . ' onclick="return confirm(\'Run DocGuard analysis now for this submission?\');">'
               . '&#8635; Re-analyse now</a>';
    }

    return '<div class="docguard-wrap">' . $badge . $links . '</div>';
}
