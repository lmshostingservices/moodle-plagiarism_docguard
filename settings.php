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

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');

require_login();
admin_externalpage_setup('plagiarismdocguard');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

if (optional_param('save', false, PARAM_BOOL) && confirm_sesskey()) {
    set_config('siteid',          optional_param('siteid',          '', PARAM_ALPHANUMEXT), 'plagiarism_docguard');
    set_config('apikey',          optional_param('apikey',          '', PARAM_TEXT),        'plagiarism_docguard');
    set_config('enabled',         optional_param('enabled',         0,  PARAM_BOOL),        'plagiarism_docguard');
    set_config('retentiondays',   optional_param('retentiondays',   90, PARAM_INT),         'plagiarism_docguard');
    set_config('minsectionwords', optional_param('minsectionwords', 30, PARAM_INT),         'plagiarism_docguard');
    // FIX-DG-BACKFILL-OPTIN (v1.0.78): off by default — see lang string.
    set_config('enablebackfill',  optional_param('enablebackfill',  0,  PARAM_BOOL),        'plagiarism_docguard');
    set_config('unlock_cache_result', '', 'plagiarism_docguard');
    set_config('unlock_cache_time',   0,  'plagiarism_docguard');
    plagiarism_docguard_check_unlock();
    redirect(
        new moodle_url('/plagiarism/docguard/settings.php'),
        get_string('savedconfigsuccess', 'plagiarism_docguard'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$connection_test_result = null;
if (optional_param('testconnection', false, PARAM_BOOL) && confirm_sesskey()) {
    set_config('unlock_cache_result', '', 'plagiarism_docguard');
    set_config('unlock_cache_time',   0,  'plagiarism_docguard');
    $connection_test_result = plagiarism_docguard_check_unlock();
}

$cfg             = (array) get_config('plagiarism_docguard');
$siteid          = $cfg['siteid']          ?? '';
$apikey          = $cfg['apikey']          ?? '';
$enabled         = !empty($cfg['enabled']);
$retentiondays   = (int)($cfg['retentiondays']   ?? 90);
$minsectionwords = (int)($cfg['minsectionwords'] ?? 30);
$enablebackfill  = !empty($cfg['enablebackfill']);

$creds_configured = (!empty($siteid) || !empty(get_config('local_aiconfig', 'siteid')))
                 && (!empty($apikey) || !empty(get_config('local_aiconfig', 'apikey')));

$cached_result = get_config('plagiarism_docguard', 'unlock_cache_result');
$cached_time   = (int)get_config('plagiarism_docguard', 'unlock_cache_time');
if ($creds_configured && ($cached_time === 0 || (time() - $cached_time) >= 1800)) {
    plagiarism_docguard_check_unlock();
    $cached_result = get_config('plagiarism_docguard', 'unlock_cache_result');
    $cached_time   = (int)get_config('plagiarism_docguard', 'unlock_cache_time');
}

$cache_age_min      = $cached_time > 0 ? (int)round((time() - $cached_time) / 60) : null;
$is_unlocked_cached = $cached_time > 0 && !empty($cached_result);
$cache_fresh        = $cached_time > 0 && (time() - $cached_time) < 1800;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_docguard'));

$ps = 'padding:1rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;border:1px solid ';
if ($connection_test_result !== null) {
    if ($connection_test_result) {
        echo '<div style="' . $ps . '#4caf5066;background:#e8f5e9;color:#1b5e20;"><strong>&#x2714; DocGuard is UNLOCKED</strong> — analysis is active for all enabled assignment activities.</div>';
    } else {
        echo '<div style="' . $ps . '#e5393566;background:#ffebee;color:#b71c1c;"><strong>&#x2718; DocGuard is NOT UNLOCKED</strong><br><small>Visit <a href="https://lms-labs.com" target="_blank">lms-labs.com</a> to unlock (5,000 credits).</small></div>';
    }
} elseif (!$creds_configured) {
    echo '<div style="' . $ps . '#ff980066;background:#fff3e0;color:#e65100;"><strong>&#x26a0; Credentials not configured</strong> — enter your Site ID and API Key below.</div>';
} elseif ($cache_fresh && $is_unlocked_cached) {
    echo '<div style="' . $ps . '#4caf5066;background:#e8f5e9;color:#1b5e20;"><strong>&#x2714; DocGuard UNLOCKED</strong> (last verified ' . $cache_age_min . ' min ago).</div>';
} elseif ($cache_fresh && !$is_unlocked_cached) {
    echo '<div style="' . $ps . '#e5393566;background:#ffebee;color:#b71c1c;"><strong>&#x2718; DocGuard NOT UNLOCKED</strong> (last checked ' . $cache_age_min . ' min ago).</div>';
} else {
    echo '<div style="' . $ps . '#9e9e9e66;background:#fafafa;color:#424242;"><strong>&#x25cc; Unlock status unknown</strong> — click Test Connection below.</div>';
}

$actionurl = new moodle_url('/plagiarism/docguard/settings.php');
?>
<form action="<?php echo $actionurl; ?>" method="post">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="save" value="1">
    <table class="admintable generaltable" cellspacing="0">
        <tbody>
            <tr>
                <td class="cell c0"><label for="id_siteid"><?php echo get_string('siteid', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="text" id="id_siteid" name="siteid" value="<?php echo s($siteid); ?>" size="40">
                    <div class="form-text"><?php echo get_string('siteid_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
            <tr>
                <td class="cell c0"><label for="id_apikey"><?php echo get_string('apikey', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="password" id="id_apikey" name="apikey" value="<?php echo s($apikey); ?>" size="40" autocomplete="new-password">
                    <div class="form-text"><?php echo get_string('apikey_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
            <tr>
                <td class="cell c0"><label for="id_enabled"><?php echo get_string('enabled', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_enabled" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('enabled_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
            <tr>
                <td class="cell c0"><label for="id_minsectionwords"><?php echo get_string('minsectionwords', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_minsectionwords" name="minsectionwords" value="<?php echo $minsectionwords; ?>" min="5" max="500">
                    <div class="form-text"><?php echo get_string('minsectionwords_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
            <tr>
                <td class="cell c0"><label for="id_enablebackfill"><?php echo get_string('enablebackfill', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_enablebackfill" name="enablebackfill" value="1" <?php echo $enablebackfill ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('enablebackfill_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
            <tr>
                <td class="cell c0"><label for="id_retentiondays"><?php echo get_string('retentiondays', 'plagiarism_docguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_retentiondays" name="retentiondays" value="<?php echo $retentiondays; ?>" min="0" max="3650">
                    <div class="form-text"><?php echo get_string('retentiondays_desc', 'plagiarism_docguard'); ?></div>
                </td>
            </tr>
        </tbody>
    </table>
    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <input type="submit" class="btn btn-primary" value="<?php echo get_string('savechanges'); ?>">
    </div>
</form>

<form action="<?php echo $actionurl; ?>" method="post" style="margin-top:1rem;">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="testconnection" value="1">
    <button type="submit" class="btn btn-secondary">Test Connection &amp; Unlock Status</button>
    <small style="margin-left:0.5rem;color:#666;">Forces a live check to lms-labs.com.</small>
</form>

<?php
echo html_writer::tag('div',
    '<strong>Supported file types:</strong> PDF (.pdf) and Word documents (.docx). '
    . 'DocGuard analyses each submission\'s text, detects question/answer sections, and scores across 12 plagiarism and AI-detection signals. '
    . '<strong>To unlock:</strong> Log in to <a href="https://lms-labs.com" target="_blank">lms-labs.com</a> → Dashboard → Plugins → DocGuard → Unlock (5,000 credits).',
    ['style' => 'margin-top:1.25rem;padding:0.75rem 1rem;background:#f5f5f5;border-radius:4px;font-size:0.9rem;']
);

echo $OUTPUT->footer();
