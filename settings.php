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
 * DocGuard settings page.
 *
 * v1.0.83: REVERTED to the standalone admin_externalpage pattern, which is what the
 * sibling plugin Essay Guard uses and what actually works.
 *
 * v1.0.80 converted this file into an $settings->add() admin-tree fragment on the
 * diagnosis that admin_externalpage_setup('plagiarismdocguard') was failing with
 * "Section error". That diagnosis was wrong, and it was never tested against a running
 * Moodle. Verified live: /admin/settings.php?section=plagiarismessayguard returns the
 * SAME "Section error" for Essay Guard, whose settings page works perfectly - because
 * that URL is simply not how plagiarism plugin settings are reached. Core registers an
 * admin_externalpage per plagiarism plugin pointing directly at
 * /plagiarism/<name>/settings.php, which is the URL the Plugins overview "Settings" link
 * actually uses.
 *
 * So the original page was fine, and the "fix" broke it: as an admin-tree fragment this
 * file produced a blank white page at the URL the Settings link points to, because
 * nothing there supplies $settings or $ADMIN.
 *
 * Structure below mirrors essayguard/settings.php exactly.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');

require_login();
admin_externalpage_setup('plagiarismdocguard');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Handle form submission.
if (optional_param('save', false, PARAM_BOOL) && confirm_sesskey()) {
    set_config('siteid', optional_param('siteid', '', PARAM_ALPHANUMEXT), 'plagiarism_docguard');
    set_config('apikey', optional_param('apikey', '', PARAM_TEXT), 'plagiarism_docguard');
    set_config('enabled', optional_param('enabled', 0, PARAM_BOOL), 'plagiarism_docguard');
    set_config('enablebackfill', optional_param('enablebackfill', 0, PARAM_BOOL), 'plagiarism_docguard');
    set_config('retentiondays', optional_param('retentiondays', 90, PARAM_INT), 'plagiarism_docguard');

    // V1.0.85: drop the cached licence verdict. Since FIX-DG-UNLICENSED-FAILS-OPEN a site
    // with no credentials caches a NEGATIVE result, so an administrator who has just
    // pasted their Site ID and API Key in would otherwise sit and watch the plugin report
    // itself unlicensed until the cache expired. The next check re-derives from scratch.
    unset_config('unlock_cache_result', 'plagiarism_docguard');
    unset_config('unlock_cache_time', 'plagiarism_docguard');

    redirect(
        new moodle_url('/plagiarism/docguard/settings.php'),
        get_string('savedconfig', 'plagiarism_docguard'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$siteid         = (string)(plagiarism_docguard_get_siteid() ?: '');
$apikey         = (string)(plagiarism_docguard_get_apikey() ?: '');
$enabled        = (int)get_config('plagiarism_docguard', 'enabled');
/*
 * V1.1.0. 'minsectionwords' is gone.
 *
 * It was a dead setting. It was saved, read back, rendered in this form, and described to
 * the administrator as "Minimum word count per section to include it in analysis. Sections
 * shorter than this are skipped" - and NOTHING in the plugin ever read it. Found because a
 * live site had it set to 200, deliberately, which is not the default: somebody configured
 * it expecting an effect and got none.
 *
 * It is removed rather than implemented. Under the current model sections are not analysed
 * at all - the comparison runs on the whole document - so the setting cannot do what it
 * says. What sections are for now is showing a marker the text that was extracted, and
 * hiding short ones from them would be a loss, not a control.
 *
 * The real behaviour, which was never the setting's: question_parser drops a section under
 * MIN_SECTION_CHARS (40 characters), and falls back to treating the whole document as one
 * section if that leaves nothing. That is now stated in the README instead of being
 * misdescribed by a control that did nothing.
 *
 * The orphaned config row is harmless and is left in place rather than deleted, so an
 * administrator downgrading does not silently lose a value they set.
 */
$enablebackfill = (int)get_config('plagiarism_docguard', 'enablebackfill');
$retentiondays  = plagiarism_docguard_retention_days();

/*
 * V1.1.3. Tell the administrator which PDF extraction tier this server is on.
 *
 * Nothing used to. A live site was found running the pure-PHP fallback because
 * poppler-utils was not installed, and no page in the plugin said so. Copy detection runs
 * on extracted text, so the tier is not a detail - it decides whether the similarity
 * figures mean anything.
 */
$dgtools = \plagiarism_docguard\extractor::extraction_tools();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_docguard'));

/*
 * V1.2.5. The "Document reading" panel.
 *
 * This replaces a one-line coloured banner that named the extraction tier and, whenever
 * poppler-utils was absent, told the administrator to install it. That advice is now
 * wrong - see extractor::extraction_tools() - and the banner never answered the question
 * an administrator actually has, which is "what can this thing read, and is there
 * anything I need to do about it?"
 *
 * So the panel answers exactly that, in this order: which reader is in use, whether
 * anything needs doing, whether scanned work can be read, the exact command if they want
 * it, and a table of every file type a student might submit with a plain yes or no. The
 * install commands are spelled out because "install Tesseract OCR" is not something an
 * administrator can act on without going to look it up, and the ones who most need this
 * panel are the ones least likely to.
 *
 * Green only when there is genuinely nothing to do. The OCR row is blue, not amber: a site
 * that never receives scans is not misconfigured, and colouring a deliberate choice as a
 * warning is how an admin panel loses credibility.
 */
$dgreaderstring = [
    'pdftotext'   => 'docreadingreader_pdftotext',
    'ghostscript' => 'docreadingreader_ghostscript',
    'builtin'     => 'docreadingreader_builtin',
][$dgtools['tier']] ?? 'docreadingreader_builtin';

echo html_writer::start_div('', ['style' =>
    'border:1px solid #d1d5db;border-radius:8px;margin:0 0 1.5rem;overflow:hidden;'
    . 'font-size:0.88rem;line-height:1.6;']);

echo html_writer::div(
    s(get_string('docreadingheading', 'plagiarism_docguard')),
    '',
    ['style' => 'background:#f3f4f6;padding:0.6rem 1.1rem;font-weight:700;color:#111827;'
        . 'border-bottom:1px solid #d1d5db;']
);

// Row 1: the reader in use. Green, because there is nothing to do about it.
echo html_writer::div(
    get_string($dgreaderstring, 'plagiarism_docguard')
        . '<br>' . s(get_string('docreadingreaderok', 'plagiarism_docguard')),
    '',
    ['style' => 'background:#ecfdf5;border-left:4px solid #065f46;color:#065f46;'
        . 'padding:0.85rem 1.1rem;']
);

// Row 2: OCR, on or off, with the exact command when off.
if ($dgtools['tesseract']) {
    echo html_writer::div(
        get_string('docreadingocron', 'plagiarism_docguard'),
        '',
        ['style' => 'background:#ecfdf5;border-left:4px solid #065f46;color:#065f46;'
            . 'padding:0.85rem 1.1rem;border-top:1px solid #d1d5db;']
    );
} else {
    $dgcmd = 'display:block;background:#0f172a;color:#e5e7eb;padding:0.5rem 0.75rem;'
           . 'border-radius:4px;margin:0.3rem 0 0.7rem;font-family:monospace;font-size:0.85rem;';
    echo html_writer::div(
        get_string('docreadingocroff', 'plagiarism_docguard')
        . '<p style="margin:0.7rem 0 0.3rem;">'
        . s(get_string('docreadingocrinstall', 'plagiarism_docguard')) . '</p>'
        . '<strong style="font-size:0.8rem;">'
        . s(get_string('docreadingocrdebian', 'plagiarism_docguard')) . '</strong>'
        . '<code style="' . $dgcmd . '">sudo apt-get install tesseract-ocr</code>'
        . '<strong style="font-size:0.8rem;">'
        . s(get_string('docreadingocrrhel', 'plagiarism_docguard')) . '</strong>'
        . '<code style="' . $dgcmd . '">sudo yum install tesseract</code>'
        . '<p style="margin:0;">' . s(get_string('docreadingocrsize', 'plagiarism_docguard')) . '</p>'
        . (!$dgtools['ghostscript']
            ? '<p style="margin:0.5rem 0 0;">'
                . s(get_string('docreadingocrnogs', 'plagiarism_docguard')) . '</p>'
            : ''),
        '',
        ['style' => 'background:#eff6ff;border-left:4px solid #1d4ed8;color:#1e3a8a;'
            . 'padding:0.85rem 1.1rem;border-top:1px solid #d1d5db;']
    );
}

// Row 3: every file type a student might submit, and whether this server reads it.
$dgocr  = (bool)$dgtools['tesseract'];
$dgyes  = '<span style="color:#065f46;font-weight:600;">'
        . s(get_string('docreadingyes', 'plagiarism_docguard')) . '</span>';
$dgno   = '<span style="color:#991b1b;font-weight:600;">'
        . s(get_string('docreadingno', 'plagiarism_docguard')) . '</span>';
$dgneed = '<span style="color:#92400e;font-weight:600;">'
        . s(get_string('docreadingneedsocr', 'plagiarism_docguard')) . '</span>';
$dgrep  = '<span style="color:#1e3a8a;font-weight:600;">'
        . s(get_string('docreadingreported', 'plagiarism_docguard')) . '</span>';

$dgrows = [
    ['docreadingrowpdf',       $dgyes],
    ['docreadingrowdocx',      $dgyes],
    ['docreadingrowscanpdf',   $dgocr ? $dgyes : $dgneed],
    ['docreadingrowimage',     $dgocr ? $dgyes : $dgneed],
    ['docreadingrowprotected', $dgrep],
    ['docreadingrowdoc',       $dgrep],
];
$dgtable = '<div style="padding:0.85rem 1.1rem;border-top:1px solid #d1d5db;">'
    . '<strong style="display:block;margin-bottom:0.5rem;color:#111827;">'
    . s(get_string('docreadingwhatitreads', 'plagiarism_docguard')) . '</strong>'
    . '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
foreach ($dgrows as [$dgkey, $dgverdict]) {
    $dgtable .= '<tr><td style="padding:0.3rem 0;border-bottom:1px solid #f3f4f6;color:#374151;">'
        . s(get_string($dgkey, 'plagiarism_docguard'))
        . '</td><td style="padding:0.3rem 0;border-bottom:1px solid #f3f4f6;text-align:right;'
        . 'white-space:nowrap;">' . $dgverdict . '</td></tr>';
}
$dgtable .= '</table></div>';
echo $dgtable;

echo html_writer::end_div();

// Status panel - CACHED config only. No vendor API call on a render path.
$notices = [];
if (empty($CFG->enableplagiarism)) {
    $notices[] = ['warn', get_string(
        'enableplagiarismnotice',
        'plagiarism_docguard',
        (new moodle_url('/admin/search.php', ['query' => 'enableplagiarism']))->out()
    )];
}
if ($siteid === '' || $apikey === '') {
    $notices[] = ['warn', get_string('nocredentialsnotice', 'plagiarism_docguard')];
} else {
    // V1.0.85: say where the credentials in force come from. The accessors silently
    // prefer local_aiconfig when that plugin supplies a value, so without this an
    // administrator could edit the field below, save, and watch the old value return
    // with nothing on the page explaining why.
    $credsource = plagiarism_docguard_credential_source();
    if ($credsource === 'local_aiconfig') {
        $notices[] = ['info', get_string('credsfromaiconfig', 'plagiarism_docguard')];
    } else if ($credsource === 'mixed') {
        $notices[] = ['warn', get_string('credsfrommixed', 'plagiarism_docguard')];
    }

    $cachedtime   = (int)get_config('plagiarism_docguard', 'unlock_cache_time');
    $cachedresult = get_config('plagiarism_docguard', 'unlock_cache_result');
    if ($cachedtime <= 0) {
        $notices[] = ['info', get_string('unlockunknown', 'plagiarism_docguard')];
    } else {
        $agemin = (int)round((time() - $cachedtime) / 60);
        $notices[] = empty($cachedresult)
            ? ['warn', get_string('unlocklocked', 'plagiarism_docguard', $agemin)]
            : ['ok', get_string('unlockunlocked', 'plagiarism_docguard', $agemin)];
    }
}
$palette = [
    'ok'   => ['#4caf5066', '#e8f5e9', '#1b5e20'],
    'warn' => ['#ff980066', '#fff3e0', '#e65100'],
    'info' => ['#9e9e9e66', '#fafafa', '#424242'],
];
foreach ($notices as [$kind, $text]) {
    [$border, $bg, $fg] = $palette[$kind];
    echo html_writer::div(
        $text,
        '',
        ['style' =>
            'padding:0.75rem 1rem;border-radius:6px;margin-bottom:0.75rem;'
            . "border:1px solid {$border};background:{$bg};color:{$fg};"]
    );
}

echo html_writer::start_tag(
    'form',
    ['method' => 'post',
        'action' => (new moodle_url('/plagiarism/docguard/settings.php'))->out(false)]
);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => '1']);
echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:900px;']);

$row = function ($labelkey, $field) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag(
        'td',
        html_writer::tag('strong', get_string($labelkey, 'plagiarism_docguard'))
            . html_writer::tag(
            'div',
            get_string($labelkey . '_desc', 'plagiarism_docguard'),
            ['style' => 'font-size:0.85em;color:#666;']
            ),
        ['style' => 'padding:0.6rem 1rem 0.6rem 0;width:45%;']
    );
    echo html_writer::tag('td', $field, ['style' => 'padding:0.6rem 0;']);
    echo html_writer::end_tag('tr');
};

$row(
    'siteid',
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'siteid',
        'value' => $siteid, 'class' => 'form-control', 'style' => 'max-width:420px;'])
);
$row(
    'apikey',
    html_writer::empty_tag('input', ['type' => 'password', 'name' => 'apikey',
        'value' => $apikey, 'class' => 'form-control', 'style' => 'max-width:420px;'])
);
$row(
    'enabled',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enabled',
        'value' => '1'] + ($enabled ? ['checked' => 'checked'] : []))
);
$row(
    'enablebackfill',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enablebackfill',
        'value' => '1'] + ($enablebackfill ? ['checked' => 'checked'] : []))
);
$row(
    'retentiondays',
    html_writer::empty_tag('input', ['type' => 'number', 'name' => 'retentiondays',
        'value' => $retentiondays, 'class' => 'form-control', 'style' => 'max-width:140px;'])
);

echo html_writer::end_tag('table');
echo html_writer::div(
    html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('savechanges'), 'class' => 'btn btn-primary']),
    '',
    ['style' => 'margin:1rem 0;']
);
echo html_writer::end_tag('form');

$testurl = new moodle_url('/plagiarism/docguard/testconnection.php', ['sesskey' => sesskey()]);
echo html_writer::div(
    html_writer::link(
        $testurl,
        get_string('testconnection', 'plagiarism_docguard'),
        ['class' => 'btn btn-secondary']
    )
    . ' ' . html_writer::tag(
        'small',
        get_string('testconnection_desc', 'plagiarism_docguard'),
        ['style' => 'margin-left:0.5rem;color:#666;']
    ),
    '',
    ['style' => 'margin-bottom:1rem;']
);

echo html_writer::tag('h3', get_string('aboutheading', 'plagiarism_docguard'));
echo html_writer::div(get_string('about_desc', 'plagiarism_docguard'));

echo $OUTPUT->footer();
