<?php

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

$cmid = required_param('cmid', PARAM_INT);
$cm   = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

require_login($cm->course, false, $cm);
$context = context_module::instance($cmid);
require_capability('plagiarism/docguard:viewreport', $context);

// ── POST handler: scan this CM for untracked / pending submissions ────────────
// ADD-DG-PROCESS-PENDING (v1.0.69): Teachers can trigger an immediate on-demand
// scan without waiting for the process_pending cron task to next run.
// Handles two cases:
//   'scan_untracked' — finds submitted assign files with no DB record and queues analysis.
//   'reanalyse_pending' — re-runs analyse_and_store() on a specific pending subid.
$action = optional_param('dg_action', '', PARAM_ALPHA);
if ($action !== '') {
    require_sesskey();
    $redirect_url = new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);

    if ($action === 'scan_untracked') {
        // Find all submitted files for this CM that have no plagiarism_docguard_sub record.
        $queued  = 0;
        $errors  = 0;
        $fs      = get_file_storage();
        $assign  = $DB->get_record('assign', ['id' => $cm->instance]);
        if ($assign) {
            $subs = $DB->get_records('assign_submission', [
                'assignment' => $assign->id,
                'latest'     => 1,
                'status'     => 'submitted',
            ]);
            foreach ($subs as $sub) {
                $files = $fs->get_area_files(
                    $context->id,
                    'assignsubmission_file',
                    'submission_files',
                    (int)$sub->id,
                    'timemodified DESC',
                    false
                );
                foreach ($files as $file) {
                    if ($file->is_directory()) { continue; }
                    if (!\plagiarism_docguard\extractor::is_supported($file)) { continue; }

                    $existing = $DB->record_exists('plagiarism_docguard_sub', [
                        'cmid'        => $cmid,
                        'userid'      => (int)$sub->userid,
                        'contenthash' => $file->get_contenthash(),
                    ]);
                    if ($existing) { continue; }

                    try {
                        \plagiarism_docguard\observer::analyse_and_store(
                            $file,
                            $cmid,
                            (int)$sub->userid,
                            (int)$sub->id,
                            $context->id
                        );
                        $queued++;
                    } catch (\Throwable $e) {
                        $errors++;
                    }
                }
            }
        }
        $msg  = "DocGuard scan complete. {$queued} submission(s) queued for analysis.";
        $msg .= $errors ? " {$errors} error(s) — check Moodle logs." : '';
        $type = $queued > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO;
        redirect($redirect_url, $msg, null, $type);
    }

    if ($action === 'reanalyse_pending') {
        $subid  = required_param('subid', PARAM_INT);
        $sub    = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid, 'cmid' => $cmid], '*', MUST_EXIST);
        $filerec = $DB->get_record_sql(
            'SELECT id FROM {files} WHERE contenthash = ? AND filename != ? ORDER BY id DESC LIMIT 1',
            [$sub->contenthash, '.']
        );
        if (!$filerec) {
            redirect($redirect_url, 'Re-analyse failed: file no longer exists in Moodle storage.', null, \core\output\notification::NOTIFY_ERROR);
        }
        $fs   = get_file_storage();
        $file = $fs->get_file_by_id($filerec->id);
        if (!$file || $file->is_directory()) {
            redirect($redirect_url, 'Re-analyse failed: could not retrieve file.', null, \core\output\notification::NOTIFY_ERROR);
        }
        // Reset to pending so analyse_and_store() does not bail on existing status.
        $DB->set_field('plagiarism_docguard_sub', 'status', 'pending', ['id' => $subid]);
        $DB->delete_records('plagiarism_docguard_sec', ['subid' => $subid]);
        try {
            \plagiarism_docguard\observer::analyse_and_store(
                $file, $cmid, (int)$sub->userid, (int)$sub->submissionid, (int)$sub->contextid
            );
            redirect($redirect_url, 'Re-analysis complete.', null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (\Throwable $e) {
            $DB->set_field('plagiarism_docguard_sub', 'status', 'analysed', ['id' => $subid]);
            redirect($redirect_url, 'Re-analyse failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

$PAGE->set_url(new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title('DocGuard Class Report');
$PAGE->set_heading('DocGuard Class Report');
$PAGE->requires->css('/plagiarism/docguard/styles.css');

echo $OUTPUT->header();

// Fetch all submissions for this cmid.
$subs = $DB->get_records('plagiarism_docguard_sub', ['cmid' => $cmid], 'timemodified DESC');

$course     = get_course($cm->course);
$modinfo    = get_fast_modinfo($cm->course);
$cm_info    = $modinfo->get_cm($cmid);
$actname    = $cm_info->name;

$scan_url = new moodle_url('/plagiarism/docguard/report.php', [
    'cmid'       => $cmid,
    'dg_action'  => 'scan_untracked',
    'sesskey'    => sesskey(),
]);

echo '<div class="docguard-report-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">';
echo '<div>';
echo '<h2 style="margin:0 0 0.25rem;">DocGuard — Class Plagiarism Report</h2>';
echo '<p style="margin:0;opacity:0.8;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '</div>';
echo '<div>';
echo '<a href="' . $scan_url->out(false) . '" class="btn btn-outline-light btn-sm"'
    . ' onclick="return confirm(\'Scan all submitted files and run DocGuard analysis on any that are not yet processed? This may take a moment for large classes.\');"'
    . ' title="Finds submitted files that have no DocGuard record yet (e.g. submissions made before the plugin was installed) and runs analysis on them.">'
    . '&#128269; Scan &amp; Analyse Unprocessed Submissions</a>';
echo '</div>';
echo '</div>';

if (empty($subs)) {
    echo $OUTPUT->notification('No DocGuard analysis records found for this activity. Submissions are analysed automatically when students submit files.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Summary stats.
$total    = count($subs);
$statuses = ['analysed' => 0, 'pending' => 0, 'error' => 0];
$levels   = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($subs as $sub) {
    $statuses[$sub->status] = ($statuses[$sub->status] ?? 0) + 1;
    if ($sub->status === 'analysed') {
        $levels[$sub->overall_risklevel] = ($levels[$sub->overall_risklevel] ?? 0) + 1;
    }
}

echo '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">';
$stat_cards = [
    ['Total Submissions', $total, '#1e3a5f', '#fff'],
    ['High Risk', $levels['high'], '#b71c1c', '#fff'],
    ['Medium Risk', $levels['medium'], '#e65100', '#fff'],
    ['Low Risk', $levels['low'], '#2e7d32', '#fff'],
    ['Pending', $statuses['pending'], '#555', '#fff'],
    ['Errors', $statuses['error'], '#6b21a8', '#fff'],
];
foreach ($stat_cards as [$label, $val, $bg, $fg]) {
    echo '<div style="background:' . $bg . ';color:' . $fg . ';padding:0.75rem 1.25rem;border-radius:6px;min-width:100px;text-align:center;">'
        . '<div style="font-size:1.8rem;font-weight:700;">' . (int)$val . '</div>'
        . '<div style="font-size:0.8rem;opacity:0.85;">' . s($label) . '</div>'
        . '</div>';
}
echo '</div>';

// Cross-student similarity section.
echo '<h4 style="margin:1.5rem 0 0.5rem;">Submissions</h4>';

echo '<table class="generaltable docguard-signal-table" style="width:100%;">';
echo '<thead><tr>'
    . '<th>Student</th>'
    . '<th>File</th>'
    . '<th>Sections</th>'
    . '<th>Score</th>'
    . '<th>Risk</th>'
    . '<th>Analysed</th>'
    . '<th>Actions</th>'
    . '</tr></thead><tbody>';

$band_colours = [
    'high'   => ['#ffebee', '#b71c1c'],
    'medium' => ['#fff8e1', '#e65100'],
    'low'    => ['#e8f5e9', '#2e7d32'],
];

foreach ($subs as $sub) {
    $user = $DB->get_record('user', ['id' => $sub->userid], 'id,firstname,lastname,username,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
    $fn   = $user ? fullname($user) : 'Unknown (#' . $sub->userid . ')';

    $level = $sub->overall_risklevel ?? 'low';
    [$bg, $fg] = $band_colours[$level] ?? ['#f3f4f6', '#374151'];

    $score_html = '';
    if ($sub->status === 'analysed') {
        $score_html = '<span style="background:' . $bg . ';color:' . $fg . ';padding:2px 8px;border-radius:4px;font-weight:600;font-size:0.85rem;">'
            . (int)$sub->overall_riskscore . '/100 &nbsp; ' . strtoupper($level)
            . '</span>';
    } elseif ($sub->status === 'error') {
        $score_html = '<span style="color:#6b21a8;font-size:0.82rem;" title="' . s($sub->errormsg) . '">Error</span>';
    } else {
        $score_html = '<span style="color:#9ca3af;font-size:0.82rem;">Pending</span>';
    }

    $action_links = '';
    if ($sub->status === 'analysed') {
        $rurl = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $sub->id]);
        $action_links = '<a href="' . $rurl->out(false) . '" class="btn btn-sm btn-outline-secondary">View Report</a>';
    } elseif ($sub->status === 'pending' || $sub->status === 'error') {
        $aurl = new moodle_url('/plagiarism/docguard/report.php', [
            'cmid'      => $cmid,
            'dg_action' => 'reanalyse_pending',
            'subid'     => $sub->id,
            'sesskey'   => sesskey(),
        ]);
        $action_links = '<a href="' . $aurl->out(false) . '" class="btn btn-sm btn-outline-secondary"'
            . ' onclick="return confirm(\'Trigger DocGuard analysis for this submission now?\');">'
            . '&#9654; Analyse</a>';
    }

    echo '<tr>';
    echo '<td>' . s($fn) . '<br><small style="color:#9ca3af;">' . s($user->username ?? '') . '</small></td>';
    echo '<td style="font-size:0.82rem;">' . s($sub->filename) . ' <span style="color:#9ca3af;">(' . strtoupper($sub->filetype) . ')</span></td>';
    echo '<td style="text-align:center;">' . (int)$sub->section_count . '</td>';
    echo '<td>' . $score_html . '</td>';
    echo '<td>' . ($sub->status === 'analysed' ? '<span class="docguard-badge docguard-badge-' . s($level) . '">' . strtoupper($level) . '</span>' : s($sub->status)) . '</td>';
    echo '<td style="font-size:0.82rem;">' . ($sub->timemodified ? userdate($sub->timemodified, '%d %b %Y %H:%M') : '-') . '</td>';
    echo '<td>' . $action_links . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

// Cross-student similarity overview.
echo '<h4 style="margin:2rem 0 0.5rem;">Cross-Student Similarity</h4>';
echo '<p style="font-size:0.88rem;color:#555;">Pairs of submissions in this activity with Jaccard bigram similarity &ge; 30% are listed below. High similarity may indicate shared source material, group work, or academic misconduct.</p>';

$analysed = array_filter($subs, fn($s) => $s->status === 'analysed' && strlen((string)$s->normtext) > 100);
$pairs    = [];
$seen     = [];
$arr      = array_values($analysed);

for ($i = 0; $i < count($arr); $i++) {
    for ($j = $i + 1; $j < count($arr); $j++) {
        $key = min($arr[$i]->id, $arr[$j]->id) . '_' . max($arr[$i]->id, $arr[$j]->id);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;

        require_once($CFG->dirroot . '/plagiarism/docguard/classes/question_parser.php');
        $bgA  = \plagiarism_docguard\question_parser::bigrams((string)$arr[$i]->normtext);
        $bgB  = \plagiarism_docguard\question_parser::bigrams((string)$arr[$j]->normtext);
        $sim  = \plagiarism_docguard\question_parser::jaccard($bgA, $bgB);
        if ($sim >= 0.30) {
            $pairs[] = ['a' => $arr[$i], 'b' => $arr[$j], 'sim' => $sim];
        }
    }
}

if (empty($pairs)) {
    echo '<p style="color:#6b7280;font-style:italic;">No significant cross-student similarities detected.</p>';
} else {
    usort($pairs, fn($x, $y) => $y['sim'] <=> $x['sim']);
    echo '<table class="docguard-similarity-table">';
    echo '<thead><tr><th>Student A</th><th>Student B</th><th>Similarity</th><th>Concern</th></tr></thead><tbody>';
    foreach ($pairs as $pair) {
        $uA   = $DB->get_record('user', ['id' => $pair['a']->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
        $uB   = $DB->get_record('user', ['id' => $pair['b']->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
        $fnA  = $uA ? fullname($uA) : '#' . $pair['a']->userid;
        $fnB  = $uB ? fullname($uB) : '#' . $pair['b']->userid;
        $sim  = $pair['sim'];
        $pct  = round($sim * 100);
        $col  = $sim >= 0.70 ? '#b71c1c' : ($sim >= 0.50 ? '#e65100' : '#374151');
        $warn = $sim >= 0.70 ? 'HIGH concern' : ($sim >= 0.50 ? 'Medium concern' : 'Low concern');
        echo '<tr>';
        echo '<td>' . s($fnA) . ' <a href="' . (new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $pair['a']->id]))->out(false) . '" style="font-size:0.8rem;">[view]</a></td>';
        echo '<td>' . s($fnB) . ' <a href="' . (new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $pair['b']->id]))->out(false) . '" style="font-size:0.8rem;">[view]</a></td>';
        echo '<td style="font-weight:700;color:' . $col . ';">' . $pct . '%</td>';
        echo '<td style="color:' . $col . ';">' . $warn . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
