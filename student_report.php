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
    // V1.1.4: one source of truth - see analyser::user_fields_for_fullname().
    \plagiarism_docguard\analyser::user_fields_for_fullname(),
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

/*
 * V1.0.99. What this number means depends on when the row was written.
 *
 * Model 1 (to 1.0.98) was a sum of writing-style points. Model 2 is the percentage of
 * this submission's word pairs that appear in another submission to the same activity.
 * Rows written before the change carry no stamp, and presenting an old style score under
 * a heading about copying would be a straightforward misrepresentation, so legacy rows
 * are labelled and their number is not shown as a verdict.
 */
$dganalysis   = json_decode((string)$sub->analysisjson, true) ?: [];
$dgscoremodel = (int)($dganalysis['score_model'] ?? 1);
$dglegacy     = $dgscoremodel < \plagiarism_docguard\analyser::SCORE_MODEL;

if ($dglegacy) {
    echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;'
        . 'border-radius:6px;padding:0.9rem 1.2rem;margin-bottom:1.25rem;font-size:0.86rem;'
        . 'color:#78350f;line-height:1.6;">'
        . get_string('legacyscore', 'plagiarism_docguard')
        . '</div>';
}

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
/*
 * V1.0.93: the banner names what the number measures.
 *
 * It used to read "HIGH RISK" over a figure produced almost entirely by writing-style
 * heuristics, next to a page headed "Plagiarism Report". A teacher had no way to tell
 * that nothing had been compared against any source outside this activity. The band is
 * unchanged; what it is a band OF is now stated.
 */
/*
 * V1.0.99. The headline is copy evidence. Under model 1 it was a style-signal sum, and
 * the style signals were measured against 48 answers written by three current language
 * models and flagged none of them, while the highest-scoring document in the test set was
 * a human second-language student. A number that cannot separate its two classes must not
 * head a report a teacher may act on.
 */
echo '<div style="font-size:0.72rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;'
    . 'color:#6b7280;margin-bottom:0.15rem;">'
    . s(get_string($dglegacy ? 'aiindicatorsheading' : 'copyevidenceheading', 'plagiarism_docguard'))
    . '</div>';
echo '<div style="font-size:1.2rem;font-weight:700;color:' . $lc . ';margin-bottom:0.25rem;">'
    . ($dgriskbanners[$level] ?? strtoupper($level) . ' RISK') . '</div>';
if (!$dglegacy) {
    echo '<div style="font-size:0.84rem;color:#374151;line-height:1.55;margin-bottom:0.4rem;'
        . 'max-width:46rem;">'
        . ($score > 0
            ? get_string('copyevidencescore', 'plagiarism_docguard', (int)round($score))
            : get_string('copyevidencenone', 'plagiarism_docguard'))
        . '</div>';
}
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

        /*
         * V1.0.99. The per-section signal table is gone.
         *
         * It listed eleven writing-style measurements with points and a fired/silent
         * status. Tested against 48 answers written by three current language models it
         * flagged none of them, while the highest-scoring document in the test set was a
         * human second-language student. Those measurements are no longer computed, so
         * there is nothing to table - and nothing here for a marker to read as
         * corroborating the copy result above, which is how institutions came to
         * over-rely on detector output in the appeals the OIA upheld.
         *
         * What is left is what a marker can use: the extracted text above, and a note
         * saying what was set aside before this submission was compared with the others.
         */
        $dgscope = $signals['_scope'] ?? null;

        if (!empty($dgscope['excluded']) && !empty($dgscope['reason'])) {
            echo '<p style="background:#eff6ff;border-left:3px solid #60a5fa;'
                . 'padding:0.55rem 0.8rem;margin:0.6rem 0 0.2rem;font-size:0.82rem;'
                . 'color:#1e3a8a;line-height:1.5;">'
                . get_string('scopeexcluded', 'plagiarism_docguard', (object)[
                    'words'  => (int)$dgscope['excluded'],
                    'reason' => s((string)$dgscope['reason']),
                ])
                . '</p>';
        }

        if (($dgscope['note'] ?? '') === 'mostly_not_own_prose') {
            echo '<p style="background:#fffbeb;border-left:3px solid #f59e0b;'
                . 'padding:0.55rem 0.8rem;margin:0.4rem 0;font-size:0.84rem;'
                . 'color:#78350f;line-height:1.55;">'
                . get_string('nosignalsquoted', 'plagiarism_docguard',
                    (int)($dgscope['excluded'] ?? 0))
                . '</p>';
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

    /*
     * V1.0.93: state the copying result as its own finding, in words, before the table.
     *
     * The AI-writing score at the top of this page and the copying check down here answer
     * two different questions, and the page previously ran them together under one "risk"
     * heading. This is the only part of DocGuard that compares the submission against
     * anybody else's work, so it gets its own plain-language verdict rather than being
     * left for the teacher to infer from an empty table.
     */
    echo '<p style="font-weight:600;font-size:0.9rem;margin:0 0 0.5rem;">'
        . s(get_string('copycheckheading', 'plagiarism_docguard')) . ': '
        . '<span style="font-weight:400;">'
        . (empty($similar)
            ? s(get_string('copycheckclean', 'plagiarism_docguard'))
            : s(get_string('copycheckfound', 'plagiarism_docguard', count($similar))))
        . '</span></p>';

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

/* ── Scope note ──────────────────────────────────────────────────────────────── */
/*
 * V1.0.93. Placed with the interpretation guide so the two are read together, and worded
 * so that it answers the question a teacher actually has when they look at a low score:
 * does this mean the work is original? It does not, and nothing else on this page said so.
 */
echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.9rem '
    . '1.15rem;margin-top:1.5rem;font-size:0.84rem;line-height:1.55;">';
echo '<strong>' . s(get_string('scopeheading', 'plagiarism_docguard')) . '</strong><br>';
echo get_string('scopenote', 'plagiarism_docguard');
echo '</div>';

/* ── Authenticity findings (V1.2.0) ──────────────────────────────────────────── */

/*
 * A SEPARATE panel, deliberately, with no score and no band colour.
 *
 * These findings answer a different question from the similarity figure above - "is there
 * anything in this file that should not be here, and do its references check out" rather than
 * "does this match another submission" - and folding them into one number would make both
 * unreadable. The previous version of this plugin showed a single confident badge derived
 * from style signals that measured nothing; a count of findings, each with its evidence
 * quoted, is what a trainer can act on and what a student can contest.
 *
 * There is no composite score here and there will not be one until these checks have been
 * calibrated against real student submissions.
 */
$dgauth = $dganalysis['authenticity'] ?? null;

if (is_array($dgauth)) {
    $dgfindings = $dgauth['findings'] ?? [];
    $dgtally    = $dgauth['tally'] ?? ['strong' => 0, 'notable' => 0, 'context' => 0];
    $dgprov     = $dgauth['provenance'] ?? ['available' => false, 'fields' => []];

    echo '<div style="border:1px solid #e5e7eb;border-radius:8px;margin-top:1.75rem;overflow:hidden;">';
    echo '<div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.85rem 1.25rem;">';
    echo '<div style="font-size:0.72rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;'
        . 'color:#6b7280;">' . s(get_string('authheading', 'plagiarism_docguard')) . '</div>';
    echo '<div style="font-size:0.95rem;font-weight:700;color:#374151;margin-top:0.2rem;">'
        . s(get_string('authtally', 'plagiarism_docguard', (object)[
            'strong'  => (int)($dgtally['strong'] ?? 0),
            'notable' => (int)($dgtally['notable'] ?? 0),
            'context' => (int)($dgtally['context'] ?? 0),
        ])) . '</div>';
    echo '</div>';

    echo '<div style="padding:1.1rem 1.25rem;">';

    if (empty($dgfindings)) {
        echo '<p style="margin:0 0 0.75rem;font-size:0.86rem;color:#374151;line-height:1.6;">'
            . get_string('authnofindings', 'plagiarism_docguard') . '</p>';
    } else {
        $dgsevcolour = ['strong' => '#b71c1c', 'notable' => '#e65100', 'context' => '#6b7280'];
        $dgsevlabel  = [
            'strong'  => get_string('authsevstrong', 'plagiarism_docguard'),
            'notable' => get_string('authsevnotable', 'plagiarism_docguard'),
            'context' => get_string('authsevcontext', 'plagiarism_docguard'),
        ];
        echo '<ul style="list-style:none;margin:0 0 1rem;padding:0;">';
        foreach ($dgfindings as $dgf) {
            $dgsev = (string)($dgf['severity'] ?? 'context');
            $dgcol = $dgsevcolour[$dgsev] ?? '#6b7280';
            echo '<li style="border-left:3px solid ' . $dgcol . ';padding:0.5rem 0 0.5rem 0.85rem;'
                . 'margin-bottom:0.85rem;">';
            echo '<div style="font-size:0.74rem;font-weight:700;text-transform:uppercase;'
                . 'letter-spacing:0.04em;color:' . $dgcol . ';">'
                . s($dgsevlabel[$dgsev] ?? $dgsev) . '</div>';
            echo '<div style="font-size:0.88rem;font-weight:600;color:#374151;margin:0.1rem 0;">'
                . s((string)($dgf['label'] ?? ''))
                . (!empty($dgf['count']) && (int)$dgf['count'] > 1
                    ? ' <span style="font-weight:400;color:#6b7280;">(&times;'
                        . (int)$dgf['count'] . ')</span>'
                    : '')
                . '</div>';
            if (!empty($dgf['matched'])) {
                echo '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:0.8rem;'
                    . 'color:#1f2937;background:#f3f4f6;border-radius:4px;padding:0.3rem 0.5rem;'
                    . 'margin:0.25rem 0;display:inline-block;max-width:100%;overflow-wrap:anywhere;">'
                    . s((string)$dgf['matched']) . '</div>';
            }
            if (!empty($dgf['expected'])) {
                echo '<div style="font-size:0.82rem;color:#374151;">'
                    . s(get_string('authexpectedyears', 'plagiarism_docguard', (string)$dgf['expected']))
                    . '</div>';
            }
            if (!empty($dgf['evidence'])) {
                echo '<div style="font-size:0.82rem;color:#6b7280;line-height:1.55;margin-top:0.2rem;'
                    . 'font-style:italic;">' . s((string)$dgf['evidence']) . '</div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    // What the file records about how it was made. Facts, laid out as facts.
    echo '<div style="border-top:1px solid #f3f4f6;padding-top:0.9rem;">';
    echo '<div style="font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:0.35rem;">'
        . s(get_string('authprovheading', 'plagiarism_docguard')) . '</div>';
    if (empty($dgprov['available']) || empty($dgprov['fields'])) {
        echo '<p style="margin:0;font-size:0.83rem;color:#6b7280;line-height:1.55;">'
            . get_string('authprovnone', 'plagiarism_docguard') . '</p>';
    } else {
        echo '<table style="font-size:0.83rem;color:#374151;border-collapse:collapse;">';
        foreach ((array)$dgprov['fields'] as $dgk => $dgv) {
            echo '<tr><td style="padding:0.15rem 0.9rem 0.15rem 0;color:#6b7280;white-space:nowrap;">'
                . s(str_replace('_', ' ', (string)$dgk)) . '</td>'
                . '<td style="padding:0.15rem 0;overflow-wrap:anywhere;">' . s((string)$dgv) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // Verification questions: the part of this panel that actually settles things.
    if (!empty($dgauth['questions'])) {
        echo '<div style="border-top:1px solid #f3f4f6;margin-top:0.9rem;padding-top:0.9rem;">';
        echo '<div style="font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:0.2rem;">'
            . s(get_string('authquestionsheading', 'plagiarism_docguard')) . '</div>';
        echo '<p style="margin:0 0 0.5rem;font-size:0.82rem;color:#6b7280;line-height:1.55;">'
            . get_string('authquestionsintro', 'plagiarism_docguard') . '</p>';
        echo '<ol style="margin:0;padding-left:1.25rem;font-size:0.86rem;color:#374151;line-height:1.6;">';
        foreach ((array)$dgauth['questions'] as $dgq) {
            echo '<li style="margin-bottom:0.4rem;">' . s((string)($dgq['question'] ?? '')) . '</li>';
        }
        echo '</ol>';
        echo '</div>';
    }

    // The limits, in the product, where a trainer reads them - not only in a manual.
    echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;'
        . 'padding:0.8rem 1rem;margin-top:1rem;font-size:0.82rem;color:#78350f;line-height:1.6;">'
        . get_string('authlimits', 'plagiarism_docguard') . '</div>';

    echo '</div></div>';
}

/* ── Interpretation guide ────────────────────────────────────────────────────── */

echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:1rem '
    . '1.25rem;margin-top:1.5rem;font-size:0.83rem;">';
echo '<strong>' . get_string('interpretationguide', 'plagiarism_docguard') . '</strong><br>';
echo '<ul style="margin:0.5rem 0 0;padding-left:1.25rem;color:#555;">';
echo '<li>' . get_string('interpretlow', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interpretmedium', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interprethigh', 'plagiarism_docguard') . '</li>';
echo '<li>' . get_string('interpretnote', 'plagiarism_docguard') . '</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
