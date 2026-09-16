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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/question_parser.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');

$subid = required_param('subid', PARAM_INT);
$sub   = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid], '*', MUST_EXIST);

$cmid = (int)$sub->cmid;
$cm   = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

require_login($cm->course, false, $cm);
$context = context_module::instance($cmid);
// FIX-DG-REPORT-ACCESS (v1.0.78): Accept mod/assign:grade OR
// plagiarism/docguard:viewreport — see lib.php for the rationale. This is the page
// holding the per-signal breakdown, which is what markers were unable to open.
if (!plagiarism_docguard_can_view_reports($context)) {
    require_capability('plagiarism/docguard:viewreport', $context);
}

/* ── Group mode ──────────────────────────────────────────────────────────────── */
// V1.0.80: this page ignored group mode, like report.php did. In a SEPARATEGROUPS
// activity a teacher restricted to one group could open any student's full DocGuard
// report — name, file name, extracted answer text — simply by changing subid in the URL,
// and the Cross-Student Similarity table at the bottom named students from other groups
// and linked straight to their reports. Both are closed here: the subject must be
// visible to this user, and the similarity list is filtered to the same set.
$dgcourse     = get_course($cm->course);
$dgallowedids = null;   // Null = no restriction.
if (
    groups_get_activity_groupmode($cm, $dgcourse) == SEPARATEGROUPS
        && !has_capability('moodle/site:accessallgroups', $context)
) {
    $dgallowedids = [(int)$USER->id => true];
    foreach (groups_get_activity_allowed_groups($cm) as $dggroup) {
        // V1.0.81: see report.php - ['u.id'] produced `u.u.id` and fataled the page.
        foreach (groups_get_groups_members([$dggroup->id], null, 'u.id') as $dgmember) {
            $dgallowedids[(int)$dgmember->id] = true;
        }
    }
    // V1.0.88: the access decision is now taken by plagiarism_docguard_user_visible() in
    // lib.php, which report.php and reanalyse.php also use, so the four doors into this
    // data cannot answer differently. The id map above is still built because the
    // Cross-Student Similarity table at the bottom of this page is filtered through it.
    if (!plagiarism_docguard_user_visible($cm, $context, (int)$sub->userid)) {
        throw new \moodle_exception('nopermissions', 'error', '', get_string('studentreport', 'plagiarism_docguard'));
    }
}

/* ── Re-analyse POST handler ─────────────────────────────────────────────────── */
// Allows a teacher to force re-extraction + re-analysis of a submission that
// was stored before the CMap-aware PDF extractor was installed (v1.0.52+).
// The handler finds the original stored_file by contenthash, resets the DB
// status to 'pending' (so analyse_and_store does not bail on 'already analysed'),
// then runs the full analysis pipeline and redirects back to this page.
if (optional_param('reanalyse', 0, PARAM_INT) === 1) {
    require_sesskey();

    $redirecturl = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]);

    // V1.0.80: re-analysis is processing, so it obeys the site-wide and per-activity
    // switches like every other entry point.
    if (!plagiarism_docguard_is_enabled() || !plagiarism_docguard_is_cm_active($cmid)) {
        redirect(
            $redirecturl,
            get_string('reanalyseunavailable', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    // V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED: the licence gate, which the observer and
    // both cron tasks apply and this endpoint did not. See
    // plagiarism_docguard_has_credentials() in lib.php.
    if (!plagiarism_docguard_has_credentials()) {
        redirect(
            $redirecturl,
            get_string('reanalyseunlicensed', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Find the stored_file by contenthash. We search all files in the DB with
    // this hash (not a directory stub) then instantiate via file storage.
    // v1.0.80: shared helper instead of a raw "LIMIT 1" — see lib.php.
    $fileid = plagiarism_docguard_find_file_id_by_hash((string)$sub->contenthash);

    if (!$fileid) {
        redirect(
            $redirecturl,
            get_string('reanalysefailednooriginal', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($fileid);

    if (!$file || $file->is_directory()) {
        redirect(
            $redirecturl,
            get_string('reanalysefailednoretrieveoriginal', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Reset status to 'pending' so analyse_and_store proceeds (it skips if status='analysed').
    $DB->set_field('plagiarism_docguard_sub', 'status', 'pending', ['id' => $subid]);
    // Delete existing section records — analyse_and_store will re-insert them.
    $DB->delete_records('plagiarism_docguard_sec', ['subid' => $subid]);

    try {
        \plagiarism_docguard\observer::analyse_and_store(
            $file,
            (int)$sub->cmid,
            (int)$sub->userid,
            (int)$sub->submissionid,
            (int)$sub->contextid
        );
        redirect(
            $redirecturl,
            get_string('reanalysecomplete', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        // V1.0.80: mark the record 'error', NOT 'analysed'.
        //
        // "Restore status so the page still renders previous results" was wrong on its own
        // terms — the section rows were deleted a few lines above, so there were no
        // previous results left to render. What it actually produced was a record claiming
        // to be a finished analysis with an empty breakdown, and that state is a dead end:
        // observer::analyse_and_store() returns early on status='analysed', process_pending
        // Phase 1 only picks up status='pending', and report.php only offers its Analyse
        // action for pending/error rows. The submission could never be recovered by cron,
        // by the class report, or by anything except a teacher happening to press
        // Re-analyse on this page again — and the page said the analysis had succeeded.
        //
        // 'error' is the truth, it is retryable everywhere, and the message says why.
        $upd = new stdClass();
        $upd->id           = $subid;
        $upd->status       = 'error';
        $upd->errormsg     = \core_text::substr('Re-analyse failed: ' . $e->getMessage(), 0, 250);
        $upd->timemodified = time();
        $DB->update_record('plagiarism_docguard_sub', $upd);
        redirect(
            $redirecturl,
            get_string('reanalysefailed', 'plagiarism_docguard', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$student = $DB->get_record(
    'user',
    ['id' => $sub->userid],
    'id,firstname,lastname,username,firstnamephonetic,lastnamephonetic,middlename,alternatename',
    IGNORE_MISSING
);
$fn      = $student ? fullname($student) : get_string('unknownuser', 'plagiarism_docguard', $sub->userid);

$PAGE->set_url(new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('studentreportheading', 'plagiarism_docguard', $fn));
$PAGE->set_heading(get_string('studentreport', 'plagiarism_docguard'));
// V1.0.92: no manual css() call. Moodle aggregates every plugin's styles.css into
// the theme stylesheet automatically, so requiring it here loaded the file a second
// time, outside the theme cache.

echo $OUTPUT->header();

/* ── Header panel ────────────────────────────────────────────────────────────── */

$level = $sub->overall_risklevel ?? 'low';
$levelcolours = [
    'high'   => ['#b71c1c', '#ffebee'],
    'medium' => ['#e65100', '#fff8e1'],
    'low'    => ['#2e7d32', '#e8f5e9'],
];
[$lc, $lb] = $levelcolours[$level] ?? ['#374151', '#f3f4f6'];

$course  = get_course($cm->course);
$modinfo = get_fast_modinfo($cm->course);
$actname = $modinfo->get_cm($cmid)->name;

$classurl = new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);

echo '<div class="docguard-report-header" '
    . 'style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">';
echo '<div>';
echo '<h2 style="margin:0 0 0.25rem;">'
    . get_string('studentreportheading', 'plagiarism_docguard', s($fn)) . '</h2>';
echo '<p style="margin:0;opacity:0.8;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '<p style="margin:0.25rem 0 0;opacity:0.75;font-size:0.82rem;">'
    . get_string('filelabel', 'plagiarism_docguard') . ': ' . s($sub->filename)
    . ' (' . strtoupper($sub->filetype) . ') &nbsp; '
    . get_string('colanalysed', 'plagiarism_docguard') . ': '
    . userdate($sub->timemodified, '%d %b %Y %H:%M') . '</p>';
echo '</div>';
echo '<div style="display:flex;gap:0.5rem;align-items:flex-start;flex-wrap:wrap;">';
$reanalyseurl = new moodle_url(
    '/plagiarism/docguard/student_report.php',
    ['subid' => $subid, 'reanalyse' => 1, 'sesskey' => sesskey()]
);
echo '<a href="' . $reanalyseurl->out(false) . '" class="btn btn-outline-light btn-sm" style="align-self:flex-start;"'
    . ' onclick="return confirm(\'' . s(addslashes(get_string('reanalyseconfirm', 'plagiarism_docguard'))) . '\');">'
    . '&#8635; ' . get_string('reanalysebutton', 'plagiarism_docguard') . '</a>';
echo '<a href="' . $classurl->out(false) . '" class="btn btn-outline-light btn-sm" style="align-self:flex-start;">'
    . '&#8592; ' . get_string('classreportshort', 'plagiarism_docguard') . '</a>';
echo '</div>';
echo '</div>';

/* ── Status check ────────────────────────────────────────────────────────────── */

if ($sub->status === 'error') {
    echo $OUTPUT->notification(
        get_string('analysiserror', 'plagiarism_docguard', s($sub->errormsg)),
        'error'
    );
    echo $OUTPUT->footer();
    exit;
}
if ($sub->status !== 'analysed') {
    echo $OUTPUT->notification(get_string('stillanalysing', 'plagiarism_docguard'), 'info');
    echo $OUTPUT->footer();
    exit;
}

/* ── Overall score card ──────────────────────────────────────────────────────── */

$score = (float)$sub->overall_riskscore;
$barw = (int)min(100, $score);

echo '<div style="background:' . $lb . ';border:1px solid ' . $lc . '33;border-radius:8px;padding:1.25rem '
    . '1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">';
echo '<div style="text-align:center;">';
echo '<div style="font-size:2.8rem;font-weight:800;color:' . $lc . ';">' . (int)$score . '</div>';
echo '<div style="font-size:0.8rem;color:' . $lc . ';font-weight:600;">/ 100</div>';
echo '</div>';
echo '<div style="flex:1;min-width:200px;">';
$dgriskbanners = [
    'low'    => core_text::strtoupper(get_string('risklevelbannerlow', 'plagiarism_docguard')),
    'medium' => core_text::strtoupper(get_string('risklevelbannermedium', 'plagiarism_docguard')),
    'high'   => core_text::strtoupper(get_string('risklevelbannerhigh', 'plagiarism_docguard')),
];
$dgriskshort = [
    'low'    => core_text::strtoupper(get_string('risklevelshortlow', 'plagiarism_docguard')),
    'medium' => core_text::strtoupper(get_string('risklevelshortmedium', 'plagiarism_docguard')),
    'high'   => core_text::strtoupper(get_string('risklevelshorthigh', 'plagiarism_docguard')),
];
echo '<div style="font-size:1.2rem;font-weight:700;color:' . $lc . ';margin-bottom:0.25rem;">'
    . ($dgriskbanners[$level] ?? strtoupper($level) . ' RISK') . '</div>';
echo '<div class="docguard-score-bar-wrap" style="width:100%;max-width:300px;">';
echo '<span class="docguard-score-bar docguard-score-bar-' . s($level) . '" style="width:' . $barw . '%;"></span>';
echo '</div>';
echo '<div style="font-size:0.83rem;color:#555;margin-top:0.4rem;">';
echo get_string('sectionsanalysed', 'plagiarism_docguard', (int)$sub->section_count) . ' &nbsp;|&nbsp; ';
echo get_string('filelabel', 'plagiarism_docguard') . ': ' . s($sub->filename);
echo '</div>';
echo '</div>';

// Score legend.
echo '<div style="font-size:0.8rem;color:#555;line-height:1.8;">';
echo '<span style="color:#2e7d32;font-weight:600;">&#9679; ' . $dgriskshort['low'] . '</span>: 0–34 &nbsp; ';
echo '<span style="color:#e65100;font-weight:600;">&#9679; ' . $dgriskshort['medium'] . '</span>: 35–64 &nbsp; ';
echo '<span style="color:#b71c1c;font-weight:600;">&#9679; ' . $dgriskshort['high'] . '</span>: 65–100';
echo '</div>';
echo '</div>';

/* ── Per-section breakdown ───────────────────────────────────────────────────── */

$sections = $DB->get_records('plagiarism_docguard_sec', ['subid' => $subid], 'section_num ASC');

if (empty($sections)) {
    // FIX-DG-EMPTY-BREAKDOWN-MSG (v1.0.78): "No section data available." gave the
    // teacher no idea whether this was a bug, a permissions problem or an empty
    // document. Explain it and point at the recovery action on this same page.
    echo $OUTPUT->notification(get_string('nosectionbreakdown', 'plagiarism_docguard'), 'info');
} else {
    echo '<h4 style="margin:0 0 1rem;">' . get_string('persectionheading', 'plagiarism_docguard') . '</h4>';

    foreach ($sections as $sec) {
        $sl    = $sec->risklevel ?? 'low';
        [$slc, $slb] = $levelcolours[$sl] ?? ['#374151', '#f3f4f6'];
        $signals = json_decode((string)$sec->signalsjson, true) ?: [];

        // Build clean plain-text version of section_text for display.
        $fulltext = strip_tags(html_entity_decode((string)$sec->section_text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $fulltext = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $fulltext)));
        $textlong = mb_strlen($fulltext) > 600;
        $textshort = $textlong ? mb_substr($fulltext, 0, 600) : $fulltext;

        echo '<div class="docguard-section-card">';
        echo '<div class="docguard-section-head">';
        echo '<span class="docguard-badge docguard-badge-' . s($sl) . '" style="margin:0;">'
            . '<span class="docguard-dot docguard-dot-' . s($sl) . '"></span>'
            . ($dgriskshort[$sl] ?? strtoupper($sl)) . '</span>';
        echo '<strong>' . s($sec->section_label) . '</strong>';
        echo '<span style="margin-left:auto;font-size:0.82rem;color:#6b7280;">'
            . (int)$sec->riskscore . '/100 &nbsp; '
            . get_string('wordcountlabel', 'plagiarism_docguard', (int)$sec->wordcount) . '</span>';
        echo '</div>';
        echo '<div class="docguard-section-body">';

        if ($fulltext) {
            $sid = 'dg-ans-' . (int)$sec->id;
            echo '<div style="margin-bottom:0.85rem;">';
            echo '<div '
                . 'style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;'
                . 'letter-spacing:0.06em;margin-bottom:0.35rem;">'
                . get_string('studentanswer', 'plagiarism_docguard') . '</div>';
            echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:0.75rem '
                . '1rem;font-size:0.88rem;color:#374151;line-height:1.65;">';
            echo '<span id="' . $sid . '-short">' . s($textshort);
            if ($textlong) {
                echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $sid
                    . '-short\').style.display=\'none\';document.getElementById(\'' . $sid
                    . '-full\').style.display=\'inline\';return false;" '
                    . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                    . get_string('showmore', 'plagiarism_docguard') . '</a>';
            }
            echo '</span>';
            if ($textlong) {
                echo '<span id="' . $sid . '-full" style="display:none;">' . s($fulltext)
                    . ' <a href="#" onclick="document.getElementById(\'' . $sid
                    . '-full\').style.display=\'none\';document.getElementById(\'' . $sid
                    . '-short\').style.display=\'inline\';return false;" '
                    . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                    . get_string('showless', 'plagiarism_docguard') . '</a></span>';
            }
            echo '</div>';
            echo '</div>';
        }

        if (!empty($signals)) {
            echo '<details style="font-size:0.82rem;margin-top:0.65rem;margin-bottom:0.4rem;">';
            echo '<summary style="cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;padding:0.2rem '
                . '0.65rem;border-radius:5px;border:1px solid '
                . '#e5e7eb;background:#f9fafb;color:#374151;font-weight:600;font-size:0.8rem;'
                . 'list-style:none;-webkit-appearance:none;">&#9432;&nbsp;'
                . get_string('signalstatuskey', 'plagiarism_docguard') . '</summary>';
            echo '<div style="margin-top:0.4rem;padding:0.65rem 0.9rem;background:#f9fafb;border:1px solid '
                . '#e5e7eb;border-radius:6px;max-width:600px;display:flex;flex-direction:column;gap:0.5rem;">';
            echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
                . '<span style="min-width:82px;font-size:0.8rem;font-weight:700;color:#991b1b;white-space:nowrap;flex-shrink:0;">'
                . '&#9679;&nbsp;' . core_text::strtoupper(get_string('signalfired', 'plagiarism_docguard')) . '</span>'
                . '<span style="font-size:0.82rem;color:#374151;">'
                . get_string('signalfireddesc', 'plagiarism_docguard') . '</span>'
                . '</div>';
            echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
                . '<span style="min-width:82px;font-size:0.8rem;font-weight:600;color:#9ca3af;white-space:nowrap;flex-shrink:0;">'
                . '&#9711;&nbsp;' . get_string('signalsilent', 'plagiarism_docguard') . '</span>'
                . '<span style="font-size:0.82rem;color:#374151;">'
                . get_string('signalsilentdesc', 'plagiarism_docguard') . '</span>'
                . '</div>';
            echo '</div></details>';
            echo '<table class="docguard-signal-table" style="margin-top:0.5rem;">';
            echo '<thead><tr>'
                . '<th>' . get_string('colsignal', 'plagiarism_docguard') . '</th>'
                . '<th>' . get_string('colstatus', 'plagiarism_docguard') . '</th>'
                . '<th>' . get_string('colpoints', 'plagiarism_docguard') . '</th>'
                . '<th style="min-width:220px;">' . get_string('coldetail', 'plagiarism_docguard') . '</th>'
                . '</tr></thead><tbody>';

            $signalorder = [
                's1_ai_markers', 's2_sentence_uniformity', 's3_ttr_uniformity',
                's4_transitions', 's5_contractions', 's6_passive_voice',
                's7_template', 's8_trigrams', 's9_sentence_starts',
                's10_vocab_richness', 's11_cross_section',
            ];

            $defined = array_merge(
                array_intersect($signalorder, array_keys($signals)),
                array_diff(array_keys($signals), $signalorder)
            );

            foreach ($defined as $key) {
                if (!isset($signals[$key])) {
                    continue;
                }
                $sig = $signals[$key];
                $pts = (int)($sig['points'] ?? 0);
                $max = (int)($sig['max'] ?? 0);
                $lbl = $sig['label'] ?? $key;
                $desc = $sig['description'] ?? '';
                $frd = !empty($sig['fired']);

                $statushtml = $frd
                    ? '<span class="docguard-signal-fired" title="'
                        . s(get_string('signalfiredtitle', 'plagiarism_docguard')) . '">&#9679; '
                        . core_text::strtoupper(get_string('signalfired', 'plagiarism_docguard')) . '</span>'
                    : '<span class="docguard-signal-silent" title="'
                        . s(get_string('signalsilenttitle', 'plagiarism_docguard')) . '">&#9711; '
                        . get_string('signalsilent', 'plagiarism_docguard') . '</span>';

                $detailparts = [];
                if (isset($sig['marker_count'])) {
                    $detailparts[] = get_string('detailmarkers', 'plagiarism_docguard', $sig['marker_count']);
                    if (!empty($sig['matches'])) {
                        $detailparts[] = '"' . implode('", "', array_slice($sig['matches'], 0, 5)) . '"';
                    }
                }
                if (isset($sig['mean_words'])) {
                    $detailparts[] = get_string('detailmeansentence', 'plagiarism_docguard', $sig['mean_words']);
                }
                if (isset($sig['std_dev']) && $sig['std_dev'] !== null) {
                    $detailparts[] = get_string('detailstddev', 'plagiarism_docguard', $sig['std_dev']);
                }
                if (isset($sig['ttr_std_dev']) && $sig['ttr_std_dev'] !== null) {
                    $detailparts[] = get_string('detailttrstddev', 'plagiarism_docguard', $sig['ttr_std_dev']);
                }
                if (isset($sig['per_100'])) {
                    $detailparts[] = get_string('detailper100', 'plagiarism_docguard', $sig['per_100']);
                    if (!empty($sig['hits'])) {
                        $detailparts[] = implode(', ', array_slice($sig['hits'], 0, 5));
                    }
                }
                if (isset($sig['passive_count'])) {
                    $detailparts[] = get_string(
                        'detailpassive',
                        'plagiarism_docguard',
                        (object) ['count' => $sig['passive_count'], 'ratio' => $sig['ratio']]
                    );
                }
                if (isset($sig['opener_found'])) {
                    $detailparts[] = get_string(
                        'detailopener',
                        'plagiarism_docguard',
                        $sig['opener_found']
                            ? '"' . $sig['opener_hit'] . '"'
                        : get_string('detailnone', 'plagiarism_docguard')
                    );
                    $detailparts[] = get_string(
                        'detailcloser',
                        'plagiarism_docguard',
                        $sig['closer_found']
                            ? '"' . $sig['closer_hit'] . '"'
                        : get_string('detailnone', 'plagiarism_docguard')
                    );
                }
                if (isset($sig['unique_ratio']) && $sig['unique_ratio'] !== null) {
                    $detailparts[] = get_string(
                        'detailtrigram',
                        'plagiarism_docguard',
                        round($sig['unique_ratio'] * 100)
                    );
                }
                if (isset($sig['uniform_ratio']) && $sig['uniform_ratio'] !== null) {
                    $detailparts[] = get_string(
                        'detailuniformstarts',
                        'plagiarism_docguard',
                        round($sig['uniform_ratio'] * 100)
                    );
                }
                if (isset($sig['ttr'])) {
                    $detailparts[] = get_string('detailttr', 'plagiarism_docguard', $sig['ttr']);
                }
                if (isset($sig['note'])) {
                    $detailparts[] = $sig['note'];
                }

                $detailhtml = implode('<br>', array_map('s', $detailparts));
                if ($desc) {
                    $detailhtml .= '<div style="margin-top:3px;color:#9ca3af;font-size:0.78rem;">' . s($desc) . '</div>';
                }

                echo '<tr>';
                echo '<td class="' . ($frd ? 'docguard-signal-fired' : 'docguard-signal-silent') . '">' . s($lbl) . '</td>';
                echo '<td>' . $statushtml . '</td>';
                echo '<td>';
                if ($frd) {
                    echo '<strong>' . $pts . '</strong> / ' . $max;
                } else {
                    echo '0 / ' . $max;
                }
                echo '</td>';
                echo '<td>' . $detailhtml . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            // FIX-DG-EMPTY-BREAKDOWN-MSG (v1.0.78): analyser::score_section() returns
            // an empty signal set for any section under 8 recognised words, and
            // records the reason as a section-level 'insufficient_text' note that was
            // never rendered anywhere. State the reason instead of showing a blank card.
            echo '<p style="color:#9ca3af;font-style:italic;font-size:0.85rem;">'
                . get_string('nosignals', 'plagiarism_docguard') . '</p>';
        }

        echo '</div></div>';
    }
}

/* ── Cross-student similarity ────────────────────────────────────────────────── */

echo '<h4 style="margin:2rem 0 0.75rem;">'
    . get_string('crosssimilarityheading', 'plagiarism_docguard') . '</h4>';
echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.85rem 1.1rem;margin-bottom:1rem;">';
echo '<div style="font-weight:700;color:#92400e;font-size:0.93rem;margin-bottom:0.3rem;">'
    . get_string('crosscopyquestion', 'plagiarism_docguard') . '</div>';
echo '<div style="font-size:0.83rem;color:#78350f;line-height:1.55;">'
    . get_string('crosscopydesc', 'plagiarism_docguard') . '</div>';
echo '</div>';
echo '<p style="font-size:0.8rem;color:#9ca3af;margin-bottom:0.75rem;">'
    . get_string('crossmethod', 'plagiarism_docguard') . '</p>';

if (strlen((string)$sub->normtext) > 100) {
    $similar = \plagiarism_docguard\analyser::cross_student_similarity(
        $subid,
        $cmid,
        (string)$sub->normtext
    );

    // V1.0.80: never name a student this viewer is not permitted to see (separate groups).
    if ($dgallowedids !== null) {
        $similar = array_values(
            array_filter(
                $similar,
                fn($m) => isset($dgallowedids[(int)$m['userid']])
                )
        );
    }

    if (empty($similar)) {
        echo '<p style="color:#6b7280;font-style:italic;">'
            . get_string('nosimilaritiesthreshold', 'plagiarism_docguard') . '</p>';
    } else {
        echo '<table class="docguard-similarity-table">';
        echo '<thead><tr>'
            . '<th>' . get_string('colstudent', 'plagiarism_docguard') . '</th>'
            . '<th>' . get_string('colsimilarity', 'plagiarism_docguard') . '</th>'
            . '<th>' . get_string('coltheirrisk', 'plagiarism_docguard') . '</th>'
            . '<th>' . get_string('coltheirreport', 'plagiarism_docguard') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($similar as $match) {
            $simpct = round($match['similarity'] * 100);
            $col     = $match['similarity'] >= 0.70 ? '#b71c1c' : ($match['similarity'] >= 0.50 ? '#e65100' : '#374151');
            $rurl    = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $match['subid']]);
            echo '<tr>';
            echo '<td>' . s($match['fullname']) . ' <small style="color:#9ca3af;">(' . s($match['username']) . ')</small></td>';
            echo '<td style="font-weight:700;color:' . $col . ';">' . $simpct . '%</td>';
            echo '<td><span class="docguard-badge docguard-badge-' . s($match['risklevel']) . '" style="display:inline-flex;">'
                . ($dgriskshort[$match['risklevel']] ?? strtoupper($match['risklevel'])) . '</span></td>';
            echo '<td><a href="' . $rurl->out(false) . '">'
                . get_string('viewword', 'plagiarism_docguard') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} else {
    echo '<p style="color:#9ca3af;font-style:italic;">'
        . get_string('insufficienttext', 'plagiarism_docguard') . '</p>';
}

/* ── Interpretation guide ────────────────────────────────────────────────────── */

echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:1rem '
    . '1.25rem;margin-top:2rem;font-size:0.83rem;">';
echo '<strong>' . get_string('interpretationguide', 'plagiarism_docguard') . '</strong><br>';
echo '<ul style="margin:0.5rem 0 0;padding-left:1.25rem;color:#555;">';
echo '<li>' . get_string('interpretlow', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interpretmedium', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interprethigh', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interpretnote', 'plagiarism_docguard') . '</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
