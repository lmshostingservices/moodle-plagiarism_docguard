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
 * plagiarism_docguard file.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// DocGuard — Diagnostic Tool v1.0.34
// Access: /plagiarism/docguard/diag.php
//         /plagiarism/docguard/diag.php?cmid=<cmid>   (enables per-activity config checks)
// Requires: site admin or moodle/site:config capability.
// Safe to ship — no data is modified and no external requests are made.
//
// v1.0.34:
//   FIX-DG-DIAG-OB-SCAN: Added live output-buffer scan (Section 3.0). Reads the Moodle
//   output buffer accumulated before this diagnostic runs and searches for the two known
//   HTML deprecation warning strings. If either is found the check is FAIL, not PASS.
//   This is the DEFINITIVE runtime test — static code checks (3.1–3.7) verify the fix
//   is in place, but 3.0 catches the case where warnings are actually appearing right now.
//
//   FIX-DG-DIAG-VERSION-FALLBACK: Added regex fallback for version detection. If the
//   include(__DIR__ . '/version.php') approach fails (e.g. $plugin variable collision in
//   certain Moodle bootstrap contexts), a second pass reads the file as text and extracts
//   release/version via regex — same resilience as EssayGuard diag.php.
//
//   FIX-DG-DIAG-SUMMARY: Added SUMMARY row at the end of Section 3 that consolidates
//   all HTML-warning checks into a single PASS/FAIL verdict, including whether
//   DEBUG_DEVELOPER mode is active (warnings only show in developer debug mode).

require_once(__DIR__ . '/../../config.php');
// FIX-DG-DIAG-OB-SCAN (v1.0.34): Snapshot the Moodle output buffer before lib.php loads.
// Moodle buffers all page output — if PHP emitted deprecation warning HTML during the
// bootstrap/config.php phase (or a previous hook callback), it is sitting in the buffer
// right now. We capture it here so 3.0 can scan it for known HTML warning strings.
// ob_get_contents() is non-destructive (read-only, does not clear the buffer).
$dg_ob_pre_lib = (ob_get_level() > 0) ? (ob_get_contents() ?: '') : '';

require_once(__DIR__ . '/lib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Capture everything accumulated AFTER lib.php loads too, in case the deprecation
// warning fires as a side-effect of loading lib.php on a stale installation.
$dg_ob_post_lib = (ob_get_level() > 0) ? (ob_get_contents() ?: '') : '';
// Combine both snapshots and deduplicate.
$dg_ob_raw = $dg_ob_post_lib ?: $dg_ob_pre_lib;

$cmid = optional_param('cmid', 0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);

// FIX-DG-WARNING-CAPTURE (v1.0.40): Handle ?clear_warning=1 action.
// Admin clicks "Clear flag" on the diagnostic after verifying warnings are gone.
// Requires sesskey to prevent CSRF.
if (optional_param('clear_warning', 0, PARAM_INT) && confirm_sesskey()) {
    unset_config('warning_last_detected', 'plagiarism_docguard');
    unset_config('warning_last_message',  'plagiarism_docguard');
    unset_config('warning_last_url',      'plagiarism_docguard');
    $dg_redirect_params = $cmid ? ['cmid' => $cmid] : [];
    redirect(new moodle_url('/plagiarism/docguard/diag.php', $dg_redirect_params),
             'Warning detection flag cleared. Reload the grading page to confirm no new warnings appear, then reload this page.',
             3);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function dg_pass(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="pass">PASS</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}
function dg_fail(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="fail">FAIL</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}
function dg_info(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="info">INFO</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

$rows_plugin = '';
$rows_config = '';
$rows_html   = '';
$overall_pass = true;

// ── SECTION 1: Plugin version ─────────────────────────────────────────────────
// FIX-DG-DIAG-VERSION (v1.0.33): Moodle stores $plugin->version (integer) in
// mdl_config_plugins but does NOT store $plugin->release (string). Reading
// get_config('plagiarism_docguard', 'release') always returns empty, causing
// Section 1 to permanently show FAIL / "unknown". Fix: include version.php
// directly (same pattern as EssayGuard diag.php Section 1).
$plugin = new stdClass();
include(__DIR__ . '/version.php');
$dg_ver_release = $plugin->release ?? '';
$dg_ver_build   = $plugin->version ?? '';
unset($plugin);

// FIX-DG-DIAG-VERSION-FALLBACK (v1.0.34): If the include() approach left $plugin->release
// empty (can happen when Moodle bootstrap already defined $plugin for a different component),
// fall back to reading version.php as raw text and extracting values via regex.
// This is fully immune to variable-scope collisions and always returns the correct value.
if (empty($dg_ver_release) || empty($dg_ver_build)) {
    $dg_ver_raw_text = @file_get_contents(__DIR__ . '/version.php');
    if ($dg_ver_raw_text) {
        if (empty($dg_ver_release) && preg_match('/\$plugin->release\s*=\s*[\'"]([^\'";\s]+)[\'"]/', $dg_ver_raw_text, $_vrm)) {
            $dg_ver_release = trim($_vrm[1]);
        }
        if (empty($dg_ver_build) && preg_match('/\$plugin->version\s*=\s*(\d{10,})/', $dg_ver_raw_text, $_vbm)) {
            $dg_ver_build = (int)$_vbm[1];
        }
    }
}

if ($dg_ver_release) {
    $dg_ver_parts = array_map('intval', explode('.', trim($dg_ver_release, "v \t")));
    $dg_ver_int = (count($dg_ver_parts) === 3)
        ? ($dg_ver_parts[0] * 1000000 + $dg_ver_parts[1] * 1000 + $dg_ver_parts[2])
        : 0;
    // v1.0.31 = FIX-DG-BODY-PRELOAD-LIB — both HTML-warning fixes present.
    $dg_min_html_fix = 1 * 1000000 + 0 * 1000 + 31;
    if ($dg_ver_int >= $dg_min_html_fix) {
        $rows_plugin .= dg_pass(
            'Plugin version (min v1.0.31)',
            $dg_ver_release . ' (build ' . $dg_ver_build . ') — HTML-warning fixes present'
        );
    } else {
        $rows_plugin .= dg_fail(
            'Plugin version (min v1.0.31)',
            'Installed: ' . $dg_ver_release . ' — upgrade to v1.0.31+ to get the HTML-warning fix (FIX-DG-PRELOAD-LIB + FIX-DG-BODY-PRELOAD-LIB)'
        );
        $overall_pass = false;
    }
} else {
    $rows_plugin .= dg_fail(
        'Plugin version',
        'Cannot determine installed release — check Site Admin → Plugins → Plagiarism → DocGuard'
    );
    $overall_pass = false;
}

// ── SECTION 2: Plugin config ──────────────────────────────────────────────────

$dg_enabled_global = get_config('plagiarism', 'plagiarism_use_docguard');
if ($dg_enabled_global) {
    $rows_config .= dg_pass('Plagiarism — DocGuard enabled globally', 'plagiarism_use_docguard = 1');
} else {
    $rows_config .= dg_info('Plagiarism — DocGuard not enabled globally',
        'plagiarism_use_docguard is 0 or not set. DocGuard will not run on any activity. '
        . 'Enable via Site Admin → Plugins → Plagiarism → Manage plagiarism plugins.');
}

$dg_api_key = get_config('plagiarism_docguard', 'apikey');
if ($dg_api_key) {
    $rows_config .= dg_pass('API key configured', 'API key is set (value hidden)');
} else {
    $rows_config .= dg_fail('API key missing', 'No API key found in plagiarism_docguard config. DocGuard cannot submit files without a valid API key.');
    $overall_pass = false;
}

if ($cmid) {
    $dg_cm_enabled = get_config('plagiarism_docguard', 'enabled_cm_' . $cmid);
    if ($dg_cm_enabled === false || $dg_cm_enabled === null) {
        $rows_config .= dg_info('Activity cmid=' . $cmid . ' — default state',
            'No explicit per-activity setting saved — defaults to enabled when global DocGuard is on.');
    } elseif ($dg_cm_enabled) {
        $rows_config .= dg_pass('Activity cmid=' . $cmid . ' — enabled', 'DocGuard is explicitly enabled for this activity.');
    } else {
        $rows_config .= dg_info('Activity cmid=' . $cmid . ' — explicitly disabled',
            'DocGuard is turned off for this specific activity. Badge will not appear for student submissions here.');
    }
} else {
    $rows_config .= dg_info('Per-activity check', 'Append ?cmid=N to this URL to check a specific activity\'s DocGuard setting.');
}

// ── SECTION 3: HTML deprecation warning diagnosis ────────────────────────────
// Diagnoses why "plagiarism_plugin::update_status() is deprecated" and
// "Callback before_standard_top_of_body_html in plagiarism_docguard should be
// migrated to new hook callback" may still appear as raw HTML output on Moodle
// assignment grading pages when developer debug mode is on.
//
// The fix (FIX-DG-PRELOAD-LIB v1.0.30 + FIX-DG-BODY-PRELOAD-LIB v1.0.31)
// works through two interlocking guards:
//
//   Guard 1 — function_exists() bypass:
//     plagiarism_update_status() in plagiarismlib.php calls function_exists(
//     'plagiarism_docguard_before_standard_top_of_body_html'). When TRUE,
//     Moodle calls the function directly and NEVER reaches the ReflectionMethod
//     branch — so the deprecation notice is never emitted.
//
//   Guard 2 — dual-class Path B (belt-and-suspenders):
//     The before_standard_head_html_generation hook callback (priority 600)
//     pre-loads plagiarismlib.php and lib.php. When process_legacy_callbacks()
//     later includes lib.php, class_exists('plagiarism_plugin', false) = TRUE
//     → the `extends plagiarism_plugin` branch is taken → update_status() is
//     inherited → getDeclaringClass() = 'plagiarism_plugin' → deprecation
//     check suppressed even if function_exists() somehow returned FALSE.
//
//   The before_standard_top_of_body_html_generation hook (priority 500)
//   provides a second preload of lib.php as a safety net in case a stale
//   Moodle hook registry cache means the head callback did not fire.
//
// Checks are ordered by execution sequence (earliest to latest in page load).

// 3.0 — FIX-DG-DIAG-OB-SCAN (v1.0.34): Live output-buffer scan.
// This is the DEFINITIVE runtime check. If the HTML deprecation warning strings are
// actually appearing in Moodle page output right now, they will be present in the
// Moodle output buffer that was snapshotted at the top of this file (before lib.php
// loaded). Checks 3.1–3.7 verify the fix is correctly deployed; 3.0 tells you whether
// warnings ARE still appearing regardless of what the code says.
//
// Note: on most deployments where the fix is working correctly, the diag page itself
// will show PASS here because lib.php is already loaded (require_once at the top of this
// file) so no deprecation fires during this page load. A FAIL here means warnings are
// actively being output — even on the diag page itself — which indicates a serious issue
// such as the fix not being installed, a broken require_once chain, or a PHP parse error.
$dg_html_warning_needles = [
    'plagiarism_plugin::update_status() is deprecated',
    'Callback before_standard_top_of_body_html in plagiarism_docguard should be migrated',
    'before_standard_top_of_body_html in plagiarism_docguard',
    'update_status() is deprecated',
    'Deprecated:.*plagiarism_docguard',
];
$dg_live_warning_found  = false;
$dg_live_warning_match  = '';
foreach ($dg_html_warning_needles as $_needle) {
    // Use preg for the regex-pattern needle (last one), strpos for literals.
    $dg_needle_found = (strpos($_needle, '.*') !== false)
        ? (bool)preg_match('/' . $_needle . '/i', $dg_ob_raw)
        : (strpos($dg_ob_raw, $_needle) !== false);
    if ($dg_needle_found) {
        $dg_live_warning_found = true;
        $dg_live_warning_match = $_needle;
        break;
    }
}
// Also flag if ANY unexpected raw HTML appears in the buffer (not just known warning strings)
// — plain HTML tags in the output buffer on an admin page indicates uncaught PHP output.
$dg_ob_has_html = !empty($dg_ob_raw) && (
    strpos($dg_ob_raw, '<b>') !== false
    || strpos($dg_ob_raw, '<br') !== false
    || strpos($dg_ob_raw, '<div') !== false
    || strpos($dg_ob_raw, 'Deprecated:') !== false
    || strpos($dg_ob_raw, 'Warning:') !== false
    || strpos($dg_ob_raw, 'Notice:') !== false
);

if ($dg_live_warning_found) {
    $rows_html .= dg_fail(
        'LIVE — HTML warning detected in page output',
        'DEPRECATION WARNING TEXT WAS FOUND in the Moodle output buffer during this page load. '
        . 'Matched pattern: "' . htmlspecialchars($dg_live_warning_match) . '". '
        . 'This confirms the HTML warning is actively appearing in Moodle page output. '
        . 'Snippet (first 400 chars, HTML stripped): '
        . htmlspecialchars(mb_substr(strip_tags($dg_ob_raw), 0, 400))
    );
    $overall_pass = false;
} elseif ($dg_ob_has_html) {
    // Unexpected HTML in buffer — not a known DocGuard warning but still worth flagging.
    $rows_html .= dg_info(
        'LIVE — unexpected output in page buffer',
        'HTML or PHP message text appeared in the Moodle output buffer but it does not match '
        . 'the known DocGuard deprecation warning strings. This may be from another plugin. '
        . 'Snippet: ' . htmlspecialchars(mb_substr(strip_tags($dg_ob_raw), 0, 300))
    );
} elseif (!empty(trim($dg_ob_raw))) {
    // Non-HTML text in buffer.
    $rows_html .= dg_info(
        'LIVE — non-HTML text in page buffer',
        'Some non-HTML text appeared in the Moodle output buffer but does not match any '
        . 'known DocGuard warning string. Snippet: '
        . htmlspecialchars(mb_substr($dg_ob_raw, 0, 200))
    );
} else {
    $rows_html .= dg_pass(
        'LIVE — output buffer clean',
        'No DocGuard HTML deprecation warning strings detected in the Moodle output buffer '
        . 'during this page load. '
        . ($dg_ob_raw === '' ? '(Buffer was empty — Moodle output buffering may not be active on this page; '
            . 'checks 3.1–3.7 below are the primary verification in that case.) ' : '')
        . 'Known warning strings scanned: "plagiarism_plugin::update_status() is deprecated", '
        . '"Callback before_standard_top_of_body_html in plagiarism_docguard should be migrated".'
    );
}

// 3.0b — FIX-DG-WARNING-CAPTURE (v1.0.40): Cross-page warning detection flag.
//
// Check 3.0 scans the output buffer of THIS page only — warnings that fire on the
// assignment grading page are invisible to it, so 3.0 always shows PASS even when
// teachers are actively seeing HTML on grading pages.
//
// lib.php now registers a PHP error handler (_dg_warning_capture_handler) that writes
// a Moodle config flag whenever either known warning string fires anywhere. This check
// reads that flag and shows FAIL until the admin explicitly clears it (after verifying
// the warnings are gone). Only show PASS when the flag has never been set or was cleared.
$dg_warning_ts  = (int)get_config('plagiarism_docguard', 'warning_last_detected');
$dg_warning_msg = (string)get_config('plagiarism_docguard', 'warning_last_message');
$dg_warning_url = (string)get_config('plagiarism_docguard', 'warning_last_url');
$dg_clear_params = array_merge($cmid ? ['cmid' => $cmid] : [], ['clear_warning' => 1, 'sesskey' => sesskey()]);
$dg_clear_url    = (new moodle_url('/plagiarism/docguard/diag.php', $dg_clear_params))->out(false);
$dg_warning_age_hrs = $dg_warning_ts ? round((time() - $dg_warning_ts) / 3600, 1) : null;

if ($dg_warning_ts && (time() - $dg_warning_ts) < (7 * 86400)) {
    // Warning detected within the last 7 days — still active, show FAIL.
    $dg_detected_human = date('Y-m-d H:i:s', $dg_warning_ts) . ' (' . $dg_warning_age_hrs . ' hours ago)';
    $rows_html .= dg_fail(
        'CROSS-PAGE — HTML warning detected on grading/submission page',
        'A DocGuard HTML deprecation warning was detected at ' . $dg_detected_human . '. '
        . ($dg_warning_url ? 'Detected on page: ' . $dg_warning_url . '. ' : '')
        . ($dg_warning_msg ? 'Warning text: ' . $dg_warning_msg . '. ' : '')
        . 'This confirms warnings ARE actively appearing on Moodle pages with developer debug on. '
        . 'WHAT TO DO: Install DocGuard v1.0.40+, purge all Moodle caches '
        . '(Site Admin → Development → Purge all caches), then reload a grading page. '
        . 'If the warning no longer appears, click: ' . $dg_clear_url
    );
    $overall_pass = false;
} elseif ($dg_warning_ts) {
    // Warning was detected but more than 7 days ago — likely stale, show INFO.
    $dg_days_ago = round((time() - $dg_warning_ts) / 86400);
    $rows_html .= dg_info(
        'CROSS-PAGE — warning flag present (> 7 days old, likely stale)',
        'A warning was last detected ' . $dg_days_ago . ' day(s) ago. '
        . 'No new warnings have been recorded since then — the fix may be working. '
        . 'If you have confirmed warnings are no longer appearing on grading pages, '
        . 'clear the flag: ' . $dg_clear_url
    );
} else {
    $rows_html .= dg_pass(
        'CROSS-PAGE — no HTML warnings recorded on any page',
        'lib.php\'s error handler has not detected any DocGuard deprecation warnings '
        . 'firing on any page since this flag was last cleared (or since v1.0.40 was installed). '
        . 'This is the definitive cross-page confirmation that warnings are not appearing.'
    );
}

// 3.1 — Moodle hook system availability (Moodle 4.3+ required).
$dg_hook_class = '\core\hook\output\before_standard_head_html_generation';
$dg_has_hook_system = class_exists($dg_hook_class);
if ($dg_has_hook_system) {
    $rows_html .= dg_pass(
        'Moodle hook system',
        'core\\hook\\output\\before_standard_head_html_generation exists — Moodle 4.3+ hook API available'
    );
} else {
    $rows_html .= dg_info(
        'Moodle hook system',
        'core\\hook\\output\\before_standard_head_html_generation not found — this is Moodle ≤4.2. '
        . 'The dual-class preload fix requires the hook API (Moodle 4.3+). '
        . 'On Moodle 4.0–4.2 the fix is partially effective via Guard 1 only (function_exists bypass). '
        . 'Upgrading to Moodle 4.3+ enables the full two-guard protection.'
    );
}

// 3.2 — db/hooks.php contains both required hook registrations.
$dg_hooks_file = __DIR__ . '/db/hooks.php';
if (file_exists($dg_hooks_file)) {
    $dg_hooks_content = file_get_contents($dg_hooks_file);

    // Check before_standard_head_html_generation entry (primary preload, priority 600).
    if (strpos($dg_hooks_content, 'before_standard_head_html_generation') !== false) {
        // Extract priority if present.
        $dg_head_priority = '';
        if (preg_match("/before_standard_head_html_generation.*?priority.*?=>\s*(\d+)/s", $dg_hooks_content, $pm)) {
            $dg_head_priority = ' (priority ' . $pm[1] . ')';
        }
        $rows_html .= dg_pass(
            'db/hooks.php — head hook registered',
            'before_standard_head_html_generation callback entry found' . $dg_head_priority . '. '
            . 'This is the primary preload — fires before process_legacy_callbacks() includes lib.php'
        );
    } else {
        $rows_html .= dg_fail(
            'db/hooks.php — head hook MISSING',
            'before_standard_head_html_generation is NOT registered in db/hooks.php. '
            . 'The preload callback will never fire → class_exists() = false at lib.php load time → '
            . 'Path A (standalone class) taken → deprecation possible. '
            . 'Fix: add the before_standard_head_html_generation callback to db/hooks.php and purge Moodle caches.'
        );
        $overall_pass = false;
    }

    // Check before_standard_top_of_body_html_generation entry (belt-and-suspenders).
    if (strpos($dg_hooks_content, 'before_standard_top_of_body_html_generation') !== false) {
        $rows_html .= dg_pass(
            'db/hooks.php — body hook registered',
            'before_standard_top_of_body_html_generation callback entry found (secondary belt-and-suspenders preload)'
        );
    } else {
        $rows_html .= dg_info(
            'db/hooks.php — body hook not registered',
            'before_standard_top_of_body_html_generation is absent from db/hooks.php. '
            . 'This secondary fallback is not required if the head callback is working. '
            . 'Recommended: add it and purge caches for belt-and-suspenders coverage.'
        );
    }
} else {
    $rows_html .= dg_fail(
        'db/hooks.php — file not found',
        'Cannot locate ' . $dg_hooks_file . '. Plugin may be missing its hook registration entirely.'
    );
    $overall_pass = false;
}

// 3.3 — Hook callback class files exist on disk.
$dg_head_cb_file = __DIR__ . '/classes/hook/before_standard_head_html_generation.php';
$dg_body_cb_file = __DIR__ . '/classes/hook/before_standard_top_of_body_html_generation.php';

if (file_exists($dg_head_cb_file)) {
    $rows_html .= dg_pass(
        'Hook callback file — head',
        'classes/hook/before_standard_head_html_generation.php exists on disk'
    );
    $dg_head_cb_content = file_get_contents($dg_head_cb_file);

    // Check it loads plagiarismlib.php (enables Path B class selection).
    $dg_head_has_plagiarismlib = strpos($dg_head_cb_content, 'plagiarismlib.php') !== false;
    if ($dg_head_has_plagiarismlib) {
        $rows_html .= dg_pass(
            'Head callback — loads plagiarismlib.php',
            'require_once(plagiarismlib.php) found — ensures plagiarism_plugin class is in memory '
            . 'before process_legacy_callbacks() includes lib.php → Path B (extends plagiarism_plugin) selected '
            . '→ update_status() inherited → getDeclaringClass() = plagiarism_plugin → NO deprecation'
        );
    } else {
        $rows_html .= dg_fail(
            'Head callback — plagiarismlib.php NOT loaded',
            'require_once(plagiarismlib.php) missing from before_standard_head_html_generation.php. '
            . 'Without this, plagiarism_plugin is not in memory when lib.php is included → Path A taken → '
            . 'update_status() is the local stub → getDeclaringClass() = plagiarism_plugin_docguard → deprecation emitted. '
            . 'Apply FIX-DG-PRELOAD-PLAGIARISMLIB (v1.0.23).'
        );
        $overall_pass = false;
    }

    // Check it also loads lib.php (enables Guard 1: defines the no-op global function).
    $dg_head_has_lib = strpos($dg_head_cb_content, 'lib.php') !== false;
    if ($dg_head_has_lib) {
        $rows_html .= dg_pass(
            'Head callback — loads lib.php',
            "require_once('../../lib.php') found (FIX-DG-PRELOAD-LIB v1.0.30) — ensures "
            . 'plagiarism_docguard_before_standard_top_of_body_html() is defined before '
            . 'plagiarism_update_status() calls function_exists(). '
            . 'Guard 1 active: function_exists() returns TRUE → Moodle calls the no-op directly, '
            . 'never reaching the ReflectionMethod / update_status() branch.'
        );
    } else {
        $rows_html .= dg_fail(
            'Head callback — lib.php NOT pre-loaded',
            "require_once('../../lib.php') missing from before_standard_head_html_generation.php. "
            . 'Guard 1 (function_exists bypass) relies on lib.php being loaded before '
            . 'plagiarism_update_status() runs. Without this preload the global no-op function '
            . 'may not be in memory in time. Apply FIX-DG-PRELOAD-LIB (v1.0.30).'
        );
        $overall_pass = false;
    }
} else {
    $rows_html .= dg_fail(
        'Hook callback file — head MISSING',
        'classes/hook/before_standard_head_html_generation.php does not exist. '
        . 'The entire preload chain (Guard 2 / Path B) cannot fire without this file. '
        . 'Create the file and register its callback in db/hooks.php. Apply FIX-DG-PRELOAD-PLAGIARISMLIB (v1.0.23).'
    );
    $overall_pass = false;
}

if (file_exists($dg_body_cb_file)) {
    $rows_html .= dg_pass(
        'Hook callback file — body',
        'classes/hook/before_standard_top_of_body_html_generation.php exists (belt-and-suspenders secondary preload)'
    );
    $dg_body_cb_content = file_get_contents($dg_body_cb_file);
    $dg_body_has_lib = strpos($dg_body_cb_content, 'lib.php') !== false;
    if ($dg_body_has_lib) {
        $rows_html .= dg_pass(
            'Body callback — loads lib.php',
            'require_once(lib.php) found (FIX-DG-BODY-PRELOAD-LIB v1.0.31) — second guarantee that '
            . 'plagiarism_docguard_before_standard_top_of_body_html() is defined even if the head '
            . 'callback hook registration is stale in the Moodle hook registry cache'
        );
    } else {
        $rows_html .= dg_info(
            'Body callback — lib.php not loaded',
            'lib.php preload absent from before_standard_top_of_body_html_generation.php. '
            . 'This secondary safety net is only needed if the head callback is also not firing. '
            . 'Consider applying FIX-DG-BODY-PRELOAD-LIB (v1.0.31) for belt-and-suspenders coverage.'
        );
    }
} else {
    $rows_html .= dg_info(
        'Hook callback file — body not present',
        'classes/hook/before_standard_top_of_body_html_generation.php does not exist. '
        . 'This is the secondary safety net — its absence matters only if the head callback is also missing or stale.'
    );
}

// 3.4 — lib.php static code checks: dual-class guard + global no-op function.
$dg_lib_content = file_get_contents(__DIR__ . '/lib.php');

$dg_has_dual_class = strpos($dg_lib_content, "class_exists('plagiarism_plugin', false)") !== false;
if ($dg_has_dual_class) {
    $rows_html .= dg_pass(
        'lib.php — dual-class guard present',
        "if (class_exists('plagiarism_plugin', false)) { extends ... } else { standalone ... } pattern found. "
        . 'Path B (extends plagiarism_plugin) is selected on normal page loads where the hook fires first, '
        . 'giving getDeclaringClass() = plagiarism_plugin and suppressing the deprecation notice.'
    );
} else {
    $rows_html .= dg_fail(
        'lib.php — dual-class guard MISSING',
        "class_exists('plagiarism_plugin', false) conditional not found in lib.php. "
        . 'The class may use a bare `extends plagiarism_plugin` (crashes on early bootstrap when plagiarismlib.php '
        . 'is not yet loaded) or a standalone class with no extends (always Path A → deprecation on every page). '
        . 'Apply FIX-DG-CONDITIONAL-EXTENDS (v1.0.22).'
    );
    $overall_pass = false;
}

$dg_has_noop_fn = strpos($dg_lib_content, 'function plagiarism_docguard_before_standard_top_of_body_html()') !== false;
if ($dg_has_noop_fn) {
    $rows_html .= dg_pass(
        'lib.php — no-op global function present',
        'plagiarism_docguard_before_standard_top_of_body_html() defined in lib.php (FIX-DG-PRELOAD-LIB v1.0.30). '
        . 'When loaded, function_exists() returns TRUE → plagiarism_update_status() calls it directly '
        . 'and NEVER reaches the ReflectionMethod / update_status() branch (Guard 1 active).'
    );
} else {
    $rows_html .= dg_fail(
        'lib.php — no-op global function MISSING',
        'plagiarism_docguard_before_standard_top_of_body_html() not found in lib.php. '
        . 'Guard 1 (function_exists bypass) is absent. The "update_status() is deprecated" notice '
        . 'will be emitted whenever plagiarism_update_status() runs and Path A was taken. '
        . 'Apply FIX-DG-PRELOAD-LIB (v1.0.30).'
    );
    $overall_pass = false;
}

// Check update_status() stub in the standalone (Path A) class for safety net.
$dg_has_stub = preg_match('/class\s+plagiarism_plugin_docguard\s*\{[^}]*public\s+function\s+update_status\s*\(\)/s', $dg_lib_content)
            || (strpos($dg_lib_content, 'public function update_status() {}') !== false);
if ($dg_has_stub) {
    $rows_html .= dg_pass(
        'lib.php — Path A update_status() stub',
        'update_status() stub found in the standalone (Path A) class. '
        . 'Prevents ReflectionException on Moodle builds whose plagiarismlib.php lacks a try/catch guard.'
    );
} else {
    $rows_html .= dg_info(
        'lib.php — Path A update_status() stub not found',
        'update_status() stub not detected in the standalone (Path A) class. '
        . 'This is only a problem on Moodle builds whose plagiarismlib.php uses ReflectionMethod without '
        . 'a try/catch guard — rare on Moodle 4.x. Monitored for completeness.'
    );
}

// 3.5 — Runtime: is the global no-op function actually in memory right now?
// Note: diag.php loads lib.php via require_once(__DIR__ . '/lib.php') at the top,
// so function_exists() will be TRUE for this diag page itself. What matters is
// that the hook-driven preload also runs before plagiarism_update_status() is
// called on other pages (assign grading, quiz results) — that is confirmed by
// checks 3.1–3.4 above. We report the runtime state for completeness.
if (function_exists('plagiarism_docguard_before_standard_top_of_body_html')) {
    $rows_html .= dg_pass(
        'RUNTIME — function_exists() check',
        'plagiarism_docguard_before_standard_top_of_body_html() IS defined in memory right now '
        . '(loaded via diag.php → require_once(lib.php)). '
        . 'On other pages (assign grading table, quiz Results) this function must be in memory before '
        . 'plagiarism_update_status() runs — confirmed by hook preload checks above.'
    );
} else {
    $rows_html .= dg_fail(
        'RUNTIME — function_exists() check',
        'plagiarism_docguard_before_standard_top_of_body_html() is NOT in memory even though '
        . 'diag.php loaded lib.php at the top. This is unexpected — check lib.php for PHP parse errors '
        . 'or a missing/mismatched function definition.'
    );
    $overall_pass = false;
}

// 3.6 — Runtime: which class path was taken? (Path A = standalone, Path B = extends plagiarism_plugin)
if (class_exists('plagiarism_plugin_docguard', false)) {
    try {
        $refl_dg = new ReflectionClass('plagiarism_plugin_docguard');
        $method_dg = $refl_dg->getMethod('update_status');
        $declaring_dg = $method_dg->getDeclaringClass()->getName();
        if ($declaring_dg === 'plagiarism_plugin') {
            $rows_html .= dg_pass(
                'RUNTIME — class path (Path B)',
                'plagiarism_plugin_docguard::update_status() declaring class = plagiarism_plugin. '
                . 'Path B (extends plagiarism_plugin) was taken — desired state. '
                . 'plagiarism_update_status() reflection check sees getDeclaringClass() = plagiarism_plugin '
                . '→ deprecation suppressed (Guard 2 active).'
            );
        } else {
            $rows_html .= dg_info(
                'RUNTIME — class path (Path A)',
                'plagiarism_plugin_docguard::update_status() declaring class = ' . $declaring_dg . '. '
                . 'Path A (standalone class with stub) was taken here. '
                . 'On this diag page that is expected because diag.php itself loads lib.php before '
                . 'before_standard_head_html_generation fires. Guard 1 (function_exists) remains active '
                . 'as long as the global function is in memory. '
                . 'On normal Moodle pages where the hook fires first, Path B should be selected.'
            );
        }
    } catch (ReflectionException $e) {
        $rows_html .= dg_info(
            'RUNTIME — class path check failed',
            'ReflectionClass threw: ' . $e->getMessage()
        );
    }
} else {
    $rows_html .= dg_info(
        'RUNTIME — class not yet loaded',
        'plagiarism_plugin_docguard is not defined — check lib.php for PHP parse errors.'
    );
}

// 3.7 — Live hook registry check: is our callback in Moodle's hook manager?
// FIX-DG-DIAG-LIVE-HOOK-CHECK (v1.0.33): replaced the passive INFO advisory
// with an active PASS/FAIL test. When db/hooks.php is correct on disk but the
// live hook manager does not contain our callback, the Moodle hook registry
// cache is stale — the exact root cause of "all checks pass but warnings still
// appear". A FAIL here tells the admin exactly what to do.
if ($dg_has_hook_system) {
    $dg_hook_live       = false;
    $dg_hook_live_count = 0;
    $dg_hook_live_note  = '';
    try {
        $hm = \core\hook\manager::get_instance();
        if (method_exists($hm, 'get_callbacks_for_hook')) {
            // FIX-DG-DIAG-HOOK-QUERY (v1.0.35): Same false-positive as EssayGuard diag.
            // Moodle stores hook class names via ::class (no leading backslash).
            // The previous query used '\core\hook\...' so get_callbacks_for_hook()
            // always returned 0 — falsely showing FAIL even after a cache purge.
            // Fix: strip leading backslash. Fallback tries both forms for safety.
            $dg_hook_class_bare = ltrim('\core\hook\output\before_standard_head_html_generation', '\\');
            $cbs = $hm->get_callbacks_for_hook($dg_hook_class_bare);
            if (empty($cbs)) {
                $cbs_alt = $hm->get_callbacks_for_hook('\core\hook\output\before_standard_head_html_generation');
                if (!empty($cbs_alt)) {
                    $cbs = $cbs_alt;
                }
            }
            $dg_hook_live_count = count($cbs);
            foreach ($cbs as $cb) {
                $cb_str = is_array($cb) ? serialize($cb) : (string)$cb;
                if (strpos($cb_str, 'docguard') !== false
                    || strpos($cb_str, 'plagiarism_docguard') !== false) {
                    $dg_hook_live = true;
                    break;
                }
            }
            $dg_hook_live_note = $dg_hook_live_count . ' total callback(s) registered for before_standard_head_html_generation in live hook manager';
        } else {
            // Older Moodle 4.3 build — try reflection fallback.
            $refl_hm = new ReflectionObject($hm);
            foreach ($refl_hm->getProperties() as $hm_prop) {
                $hm_prop->setAccessible(true);
                try {
                    $hm_val = $hm_prop->getValue($hm);
                    $hm_ser = is_scalar($hm_val) ? (string)$hm_val : @serialize($hm_val);
                    if (strpos($hm_ser, 'before_standard_head_html_generation') !== false
                        && strpos($hm_ser, 'docguard') !== false) {
                        $dg_hook_live = true;
                        $dg_hook_live_note = 'Found via hook manager property: ' . $hm_prop->getName();
                        break;
                    }
                } catch (Throwable $_e) {}
            }
            if (!$dg_hook_live) {
                $dg_hook_live_note = 'get_callbacks_for_hook() not available — reflection scan found no docguard entry';
            }
        }
    } catch (Throwable $e) {
        $dg_hook_live_note = 'Hook manager check exception: ' . $e->getMessage();
    }

    if ($dg_hook_live) {
        $rows_html .= dg_pass(
            'LIVE — hook registry',
            'DocGuard before_standard_head_html_generation callback IS registered in the live Moodle hook manager '
            . '→ preload chain will fire on grading pages. ' . $dg_hook_live_note
        );
    } else {
        $rows_html .= dg_fail(
            'LIVE — hook registry cache STALE',
            'DocGuard before_standard_head_html_generation is NOT in the live Moodle hook manager even though '
            . 'db/hooks.php is correct on disk. The hook registry cache was built before this callback was added '
            . '→ the preload never fires → HTML warnings appear on grading pages despite all file checks passing. '
            . 'FIX: Site Admin → Development → Purge all caches '
            . '(or run "php admin/cli/purge_caches.php" on the server). '
            . 'Then reload the grading page — warnings will stop. '
            . $dg_hook_live_note
        );
        $overall_pass = false;
    }
} else {
    // Moodle ≤ 4.2: no hook system, Guard 1 (function_exists) is the only protection.
    $rows_html .= dg_info(
        'LIVE — hook registry (N/A on Moodle ≤4.2)',
        'Moodle hook system not available on this version. Guard 1 (function_exists bypass) '
        . 'is the sole protection. HTML warnings will persist unless Moodle is upgraded to 4.3+.'
    );
}
// FIX-DG-DIAG-SUMMARY (v1.0.34): Consolidated verdict row.
// Aggregates all Section 3 checks into one clear PASS/FAIL answer to the question
// "are HTML deprecation warnings currently appearing on Moodle pages?".
// Factors in: live output scan (3.0), all code checks (3.1–3.7), and whether
// DEBUG_DEVELOPER mode is active (warnings are only visible in that mode).
$dg_debug_level     = (int)get_config('core', 'debug');
$dg_debug_display   = (bool)get_config('core', 'debugdisplay');
// DEBUG_DEVELOPER = 32767 (E_ALL | E_STRICT) in Moodle.
$dg_is_dev_mode     = $dg_debug_level >= 32767;

// Collect whether any of the code checks failed ($overall_pass is updated throughout).
// The summary uses $overall_pass as-is (any prior FAIL propagates here).
if ($dg_live_warning_found) {
    // 3.0 caught actual warning text in the output buffer — definitive FAIL.
    $rows_html .= dg_fail(
        'SUMMARY — HTML warnings ARE appearing',
        'CONFIRMED: HTML deprecation warning text was detected in the Moodle page output buffer '
        . 'during this page load (see check 3.0 above). '
        . 'Warnings ARE actively being output on Moodle pages when debug mode is on. '
        . 'Fix: ensure DocGuard v1.0.31+ is installed, then purge all Moodle caches '
        . '(Site Admin → Development → Purge all caches).'
    );
    // $overall_pass already set false by 3.0.
} elseif (!$overall_pass) {
    // No live warning captured yet, but one or more code checks failed.
    $dg_dev_note = $dg_is_dev_mode
        ? ' DEBUG_DEVELOPER mode IS active — warnings are currently visible to site admins on grading pages.'
        : ' DEBUG_DEVELOPER mode is OFF — warnings would only appear if you enable it for debugging.';
    $rows_html .= dg_fail(
        'SUMMARY — HTML warnings LIKELY to appear',
        'One or more code checks above failed, meaning the HTML-warning fix is not fully in place. '
        . 'HTML deprecation warnings will appear on assignment/quiz grading pages when debug mode is on.'
        . $dg_dev_note . ' '
        . 'Fix: install DocGuard v1.0.34+, then purge all Moodle caches.'
    );
} else {
    $dg_dev_note = $dg_is_dev_mode
        ? ' DEBUG_DEVELOPER mode IS active — and no warnings were found. Fix is confirmed working.'
        : ' (DEBUG_DEVELOPER mode is off — warnings would be hidden anyway, but the fix is in place.)';
    $rows_html .= dg_pass(
        'SUMMARY — HTML warnings NOT appearing',
        'All checks passed and no HTML deprecation warning text was detected in the page output buffer. '
        . 'The DocGuard HTML-warning fix is correctly deployed.'
        . $dg_dev_note
    );
}

$rows_html .= dg_info(
    'NOTE — warnings only in DEBUG_DEVELOPER mode',
    'These HTML warnings are only visible when Moodle debug level is set to DEVELOPER '
    . '(Site Admin → Development → Debugging → DEBUG_DEVELOPER). '
    . 'Students and teachers with non-developer debug levels never see them.'
);

// ── Page output ───────────────────────────────────────────────────────────────

$overall_label = $overall_pass
    ? '<span class="pass-banner">ALL CHECKS PASSED</span>'
    : '<span class="fail-banner">ONE OR MORE CHECKS FAILED — see details below</span>';

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>DocGuard Diagnostic</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; background: #f5f5f5; color: #111; }
  h1 { font-size: 1.4rem; margin-bottom: 0.3rem; }
  h2 { font-size: 1.05rem; margin: 1.5rem 0 0.4rem; border-bottom: 2px solid #ddd; padding-bottom: 0.3rem; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
  td, th { padding: 0.35rem 0.6rem; font-size: 0.84rem; border-bottom: 1px solid #eee; vertical-align: top; }
  th { background: #f0f0f0; font-weight: 600; }
  td.label { width: 30%; font-weight: 500; }
  td.pass  { width: 6%; color: #155724; font-weight: 700; }
  td.fail  { width: 6%; color: #721c24; font-weight: 700; }
  td.info  { width: 6%; color: #004085; font-weight: 700; }
  td.val   { font-family: "SFMono-Regular", Consolas, monospace; font-size: 0.8rem; color: #333; white-space: pre-wrap; word-break: break-all; }
  .overall { margin: 1rem 0; }
  .pass-banner { background: #d4edda; color: #155724; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; font-weight: 600; }
  .fail-banner { background: #f8d7da; color: #721c24; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; font-weight: 600; }
  .meta { font-size: 0.8rem; color: #555; margin-bottom: 0.5rem; }
  a { color: #0070f3; }
  code { background: #eee; padding: 2px 5px; border-radius: 3px; }
</style></head><body>';

echo '<h1>DocGuard — Diagnostic Report</h1>';
echo '<p class="meta">';
if ($cmid) {
    $dg_cm = get_coursemodule_from_id(false, $cmid, 0, false, IGNORE_MISSING);
    echo 'Activity: <strong>' . htmlspecialchars($dg_cm ? $dg_cm->name : 'not found') . '</strong> &nbsp;|&nbsp; ';
    echo 'cmid: <strong>' . $cmid . '</strong> &nbsp;|&nbsp; ';
}
echo 'Plugin version: <strong>' . htmlspecialchars($dg_ver_release ?: 'unknown') . '</strong>';
echo '</p>';

echo '<div class="overall">' . $overall_label . '</div>';

echo '<h2>1. Plugin version</h2>';
echo '<table>' . $rows_plugin . '</table>';

echo '<h2>2. Plugin configuration</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">';
echo 'Global enable state and API key presence. Append <code>?cmid=N</code> to also check a specific activity.';
echo '</p>';
echo '<table>' . $rows_config . '</table>';

echo '<h2>3. HTML deprecation warning diagnosis</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">'
   . 'Diagnoses why <code>plagiarism_plugin::update_status() is deprecated</code> and '
   . '<code>Callback before_standard_top_of_body_html in plagiarism_docguard should be migrated</code> '
   . 'may still appear as raw HTML output on assignment grading pages when Moodle developer debug mode is on. '
   . 'Checks run in page-load execution order: Moodle hook system → hook registration (db/hooks.php) → '
   . 'callback files on disk → callback content (preloads plagiarismlib.php + lib.php) → '
   . 'lib.php dual-class guard + global no-op function → runtime confirmation.</p>';
echo '<table>' . $rows_html . '</table>';

echo '<p class="meta">No data is modified by this diagnostic. Safe to reload at any time.</p>';
echo '<p>';
if ($cmid) {
    echo '<a href="' . (new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]))->out() . '">&larr; DocGuard report</a>';
} else {
    echo '<a href="' . (new moodle_url('/admin/category.php', ['category' => 'plagiarismsettings']))->out() . '">&larr; Plagiarism settings</a>';
}
echo '</p>';
echo '</body></html>';
