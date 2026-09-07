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
require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

$cmid = required_param('cmid', PARAM_INT);
$cm   = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

require_login($cm->course, false, $cm);
$context = context_module::instance($cmid);
// FIX-DG-REPORT-ACCESS (v1.0.78): Accept mod/assign:grade OR
// plagiarism/docguard:viewreport — the same test lib.php uses to decide whether to
// render the link to this page. Previously this required the DocGuard capability
// alone, which db/access.php granted only to editing teachers and managers, so
// non-editing teachers were shown a link and then denied.
if (!plagiarism_docguard_can_view_reports($context)) {
    require_capability('plagiarism/docguard:viewreport', $context);
}

/* ── POST handler: scan this CM for untracked / pending submissions ──────────── */
// ADD-DG-PROCESS-PENDING (v1.0.69): Teachers can trigger an immediate on-demand
// scan without waiting for the process_pending cron task to next run.
// Handles two cases:
// 'scan_untracked' — finds submitted assign files with no DB record and queues analysis.
// 'reanalyse_pending' — re-runs analyse_and_store() on a specific pending subid.
// v1.0.82: PARAM_ALPHA strips underscores, so 'scan_untracked' arrived as
// 'scanuntracked' and matched neither handler. Both teacher buttons - Scan & Analyse and
// the per-row Analyse - passed require_sesskey(), fell through every branch, and silently
// re-rendered the page. Dead since v1.0.69, which also made the whole scan_activity adhoc
// task unreachable from the UI. PARAM_ALPHAEXT permits underscores.
$action = optional_param('dg_action', '', PARAM_ALPHAEXT);
if ($action !== '') {
    require_sesskey();
    $redirecturl = new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);

    // V1.0.80: nothing here may start processing while DocGuard is switched off
    // site-wide, or switched off for this activity. Both actions extract and store
    // student document text, which is precisely what those switches exist to prevent.
    // Viewing already-stored results below is still allowed.
    if (!plagiarism_docguard_is_enabled()) {
        redirect(
            $redirecturl,
            get_string('scandisabledglobal', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (!plagiarism_docguard_is_cm_active($cmid)) {
        redirect(
            $redirecturl,
            get_string('scandisabledcm', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    // V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED: an unlicensed site must not analyse from
    // here either — see plagiarism_docguard_has_credentials() in lib.php. Both actions
    // below lead to extraction and storage of student document text.
    if (!plagiarism_docguard_has_credentials()) {
        redirect(
            $redirecturl,
            get_string('reanalyseunlicensed', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($action === 'scan_untracked') {
        // V1.0.80: QUEUE AN ADHOC TASK — do not analyse the class in this request.
        //
        // What was wrong: this loop ran extraction and scoring for EVERY unprocessed
        // submission in the activity, synchronously, inside the teacher's page request.
        // Each file means shelling out to pdftotext or Ghostscript (each allowed 60
        // seconds) plus the full scoring pipeline — comfortably seconds per file. A class
        // of 120 submissions is not a slow page, it is a page that cannot finish: PHP hits
        // max_execution_time and is killed mid-loop. What the teacher then has is a white
        // screen or a gateway timeout, an unknown number of files analysed, no record of
        // where it stopped, and no way to resume except clicking the same button again and
        // re-walking the list from the start. Worse, the files that WERE processed have
        // already had their text written, so the work is neither complete nor undone.
        //
        // The fix is the mechanism Moodle provides for exactly this: an adhoc task. It is
        // picked up by the next cron run, it processes the activity in bounded batches and
        // re-queues itself if it runs out of budget, and if the process dies Moodle
        // re-runs the task rather than losing the job. The teacher gets an immediate,
        // truthful response instead of a hung browser tab.
        $queued = \plagiarism_docguard\task\scan_activity::queue_for_cm($cmid, $context->id, (int)$USER->id);
        if ($queued) {
            redirect(
                $redirecturl,
                get_string('scanqueued', 'plagiarism_docguard'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect(
            $redirecturl,
            get_string('scanalreadyqueued', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    if ($action === 'reanalyse_pending') {
        // Single file, so this one stays synchronous: it is bounded by the same 60-second
        // extractor timeout the observer lives with on every submission.
        $subid  = required_param('subid', PARAM_INT);
        $sub    = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid, 'cmid' => $cmid], '*', MUST_EXIST);

        // V1.0.88 FIX-DG-REANALYSE-PENDING-GROUPS: honour SEPARATEGROUPS here too.
        //
        // report.php, student_report.php and reanalyse.php were each hardened for separate
        // groups (v1.0.80, v1.0.81, v1.0.84 respectively) — and this action, which lives
        // inside report.php but runs long before the group filter further down the page,
        // was missed every time. It checked only can_view_reports() and that the record's
        // cmid matched, so a teacher restricted to one group could put any subid belonging
        // to the activity in the query string and trigger re-extraction and re-storage of
        // another group's student's document text. The subid is a plain integer in a GET
        // link, so it does not have to be guessed carefully; the row it names is simply
        // filtered out of the table this teacher can see.
        //
        // The check itself now lives in plagiarism_docguard_user_visible() in lib.php, so
        // that this door and the other three cannot drift apart again.
        if (!plagiarism_docguard_user_visible($cm, $context, (int)$sub->userid)) {
            throw new \moodle_exception(
                'nopermissions',
                'error',
                '',
                get_string('analysebutton', 'plagiarism_docguard')
            );
        }

        // V1.0.80: shared helper instead of a raw "LIMIT 1" — see lib.php.
        $fileid = plagiarism_docguard_find_file_id_by_hash((string)$sub->contenthash);
        if (!$fileid) {
            redirect(
                $redirecturl,
                get_string('reanalysefailednofile', 'plagiarism_docguard'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $fs   = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file || $file->is_directory()) {
            redirect(
                $redirecturl,
                get_string('reanalysefailednoretrieve', 'plagiarism_docguard'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        // Reset to pending so analyse_and_store() does not bail on existing status.
        $DB->set_field('plagiarism_docguard_sub', 'status', 'pending', ['id' => $subid]);
        $DB->delete_records('plagiarism_docguard_sec', ['subid' => $subid]);
        try {
            \plagiarism_docguard\observer::analyse_and_store(
                $file,
                $cmid,
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
            // This was the dead end. On failure the old code wrote status='analysed' —
            // after the section rows had already been deleted three lines above. The
            // record then claimed to be a completed analysis with an empty breakdown, and
            // three things conspired to make that permanent:
            // * observer::analyse_and_store() returns immediately when it finds an
            // existing record with status='analysed', so cron never touched it again;
            // * process_pending Phase 1 only selects status='pending', so it never saw it;
            // * this page only offers the Analyse action for 'pending' and 'error' rows,
            // so the one button that could have fixed it was hidden by the state it
            // had just been put into.
            // 'error' is the honest state, it is retryable from this page and from the
            // badge, and it shows the teacher the reason instead of a silent blank report.
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
}

$PAGE->set_url(new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('classreport', 'plagiarism_docguard'));
$PAGE->set_heading(get_string('classreport', 'plagiarism_docguard'));
$PAGE->requires->css('/plagiarism/docguard/styles.css');

echo $OUTPUT->header();

// Fetch all submissions for this cmid.
// FIX-DG-HIDE-UNSUPPORTED (v1.0.78): exclude the 'unsupported' bookkeeping rows the
// backfill task writes to mark a submission as permanently skipped. They carry no
// analysis, render nothing in the badge, and would otherwise appear here as a blank
// filename with a grey "Pending" score and the raw word "unsupported" in the Risk
// column, while inflating the Total Submissions count.
$subs = $DB->get_records_select(
    'plagiarism_docguard_sub',
    'cmid = :cmid AND status <> :unsupported',
    ['cmid' => $cmid, 'unsupported' => 'unsupported'],
    'timemodified DESC'
);

$course     = get_course($cm->course);
$modinfo    = get_fast_modinfo($cm->course);
$cminfo    = $modinfo->get_cm($cmid);
$actname    = $cminfo->name;

/* ── Group mode ──────────────────────────────────────────────────────────────── */
// V1.0.80: this page ignored group mode entirely.
//
// In a SEPARATEGROUPS activity, a teacher assigned to one group is only permitted to see
// that group's participants — that is what the mode means, and Moodle enforces it on the
// grading table, the participants list and the gradebook. This report listed EVERY
// student who had submitted to the activity, by full name and username, with their risk
// scores, and the Cross-Student Similarity table paired students across group boundaries
// and linked to each other's full reports. On a site where separate groups exist because
// the groups are separate cohorts — different employers, different schools, different
// clients — that is a data disclosure, not a cosmetic bug.
//
// groups_get_activity_allowed_groups() returns exactly the groups this user may see in
// this activity (all of them for a user with moodle/site:accessallgroups, which is
// therefore checked implicitly as well as explicitly below). VISIBLEGROUPS and NOGROUPS
// are unaffected: in those modes everyone may see everyone, which is what they mean.
$dgalloweduserids = null;   // Null = no restriction.
if (
    groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS
        && !has_capability('moodle/site:accessallgroups', $context)
) {
    $allowedgroups = groups_get_activity_allowed_groups($cm);
    $dgalloweduserids = [];
    if (!empty($allowedgroups)) {
        // V1.0.81: the second argument is EXTRA user fields, not an SQL field list.
        // core_user\fields::including() stores it verbatim and get_sql() then prefixes it
        // with the 'u.' alias, so ['u.id'] emitted `u.u.id` in the SELECT and every
        // separate-groups teacher got a dml_read_exception instead of the report. id is
        // already selected by for_userpic().
        $members = groups_get_groups_members(array_keys($allowedgroups), null, 'u.id');
        foreach ($members as $member) {
            $dgalloweduserids[(int)$member->id] = true;
        }
    }
    // A teacher with no group of their own in a separate-groups activity sees nobody,
    // which is the same answer Moodle's own grading table gives them.
    $dgalloweduserids[(int)$USER->id] = true;

    foreach ($subs as $key => $sub) {
        if (!isset($dgalloweduserids[(int)$sub->userid])) {
            unset($subs[$key]);
        }
    }
}

$scanurl = new moodle_url(
    '/plagiarism/docguard/report.php',
    [
        'cmid'       => $cmid,
        'dg_action'  => 'scan_untracked',
        'sesskey'    => sesskey(),
        ]
);

echo '<div class="docguard-report-header" '
    . 'style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">';
echo '<div>';
echo '<h2 style="margin:0 0 0.25rem;">' . get_string('classreportheading', 'plagiarism_docguard') . '</h2>';
echo '<p style="margin:0;opacity:0.8;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '</div>';
echo '<div>';
echo '<a href="' . $scanurl->out(false) . '" class="btn btn-outline-light btn-sm"'
    . ' onclick="return confirm(\'' . s(addslashes(get_string('scanconfirm', 'plagiarism_docguard'))) . '\');"'
    . ' title="' . s(get_string('scanbuttontitle', 'plagiarism_docguard')) . '">'
    . '&#128269; ' . get_string('scanbutton', 'plagiarism_docguard') . '</a>';
echo '</div>';
echo '</div>';

// V1.0.80: say so, rather than quietly showing a partial class. A teacher comparing this
// against the grading table needs to know why the counts differ.
if ($dgalloweduserids !== null) {
    echo $OUTPUT->notification(get_string('separategroupsnotice', 'plagiarism_docguard'), 'info');
}

// V1.0.80: the class report is readable when the plugin is off — old results do not
// disappear — but the action buttons above cannot do anything, so say why.
if (!plagiarism_docguard_is_enabled()) {
    echo $OUTPUT->notification(get_string('disabledglobalnotice', 'plagiarism_docguard'), 'warning');
} else if (!plagiarism_docguard_is_cm_active($cmid)) {
    echo $OUTPUT->notification(get_string('disabledcmnotice', 'plagiarism_docguard'), 'warning');
}

if (empty($subs)) {
    echo $OUTPUT->notification(get_string('norecords', 'plagiarism_docguard'), 'info');
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
$statcards = [
    [get_string('stattotal', 'plagiarism_docguard'), $total, '#1e3a5f', '#fff'],
    [get_string('riskhigh', 'plagiarism_docguard'), $levels['high'], '#b71c1c', '#fff'],
    [get_string('riskmedium', 'plagiarism_docguard'), $levels['medium'], '#e65100', '#fff'],
    [get_string('risklow', 'plagiarism_docguard'), $levels['low'], '#2e7d32', '#fff'],
    [get_string('statpending', 'plagiarism_docguard'), $statuses['pending'], '#555', '#fff'],
    [get_string('staterrors', 'plagiarism_docguard'), $statuses['error'], '#6b21a8', '#fff'],
];
foreach ($statcards as [$label, $val, $bg, $fg]) {
    echo '<div style="background:' . $bg . ';color:' . $fg . ';padding:0.75rem '
        . '1.25rem;border-radius:6px;min-width:100px;text-align:center;">'
        . '<div style="font-size:1.8rem;font-weight:700;">' . (int)$val . '</div>'
        . '<div style="font-size:0.8rem;opacity:0.85;">' . s($label) . '</div>'
        . '</div>';
}
echo '</div>';

// Cross-student similarity section.
echo '<h4 style="margin:1.5rem 0 0.5rem;">' . get_string('submissionsheading', 'plagiarism_docguard') . '</h4>';

echo '<table class="generaltable docguard-signal-table" style="width:100%;">';
echo '<thead><tr>'
    . '<th>' . get_string('colstudent', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colfile', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colsections', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colscore', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colrisk', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colanalysed', 'plagiarism_docguard') . '</th>'
    . '<th>' . get_string('colactions', 'plagiarism_docguard') . '</th>'
    . '</tr></thead><tbody>';

// V1.0.80: display labels for the three risk bands, keyed by the value stored in the
// database. Unknown values fall back to the raw stored value, as before.
$dgrisklabels = [
    'low'    => core_text::strtoupper(get_string('risklevelshortlow', 'plagiarism_docguard')),
    'medium' => core_text::strtoupper(get_string('risklevelshortmedium', 'plagiarism_docguard')),
    'high'   => core_text::strtoupper(get_string('risklevelshorthigh', 'plagiarism_docguard')),
];

$bandcolours = [
    'high'   => ['#ffebee', '#b71c1c'],
    'medium' => ['#fff8e1', '#e65100'],
    'low'    => ['#e8f5e9', '#2e7d32'],
];

// V1.0.80: fetch every user this page names in ONE query. The row loop below ran a
// get_record() per submission and the similarity loop ran two more per pair, so a class of
// 100 with 40 flagged pairs issued ~180 single-row user queries per page load.
$dguserids = [];
foreach ($subs as $sub) {
    $dguserids[(int)$sub->userid] = true;
}
$dgusers = $dguserids
    ? $DB->get_records_list(
        'user',
        'id',
        array_keys($dguserids),
        '',
        'id,firstname,lastname,username,firstnamephonetic,lastnamephonetic,middlename,alternatename'
    )
    : [];

foreach ($subs as $sub) {
    $user = $dgusers[(int)$sub->userid] ?? null;
    $fn   = $user ? fullname($user) : get_string('unknownuser', 'plagiarism_docguard', $sub->userid);

    $level = $sub->overall_risklevel ?? 'low';
    [$bg, $fg] = $bandcolours[$level] ?? ['#f3f4f6', '#374151'];

    $scorehtml = '';
    if ($sub->status === 'analysed') {
        $scorehtml = '<span style="background:' . $bg . ';color:' . $fg . ';padding:2px '
            . '8px;border-radius:4px;font-weight:600;font-size:0.85rem;">'
            . (int)$sub->overall_riskscore . '/100 &nbsp; ' . ($dgrisklabels[$level] ?? strtoupper($level))
            . '</span>';
    } else if ($sub->status === 'error') {
        $scorehtml = '<span style="color:#6b21a8;font-size:0.82rem;" title="' . s($sub->errormsg) . '">'
            . get_string('statuserror', 'plagiarism_docguard') . '</span>';
    } else {
        $scorehtml = '<span style="color:#9ca3af;font-size:0.82rem;">'
            . get_string('statuspending', 'plagiarism_docguard') . '</span>';
    }

    $actionlinks = '';
    if ($sub->status === 'analysed') {
        $rurl = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $sub->id]);
        $actionlinks = '<a href="' . $rurl->out(false) . '" class="btn btn-sm btn-outline-secondary">'
            . get_string('viewreport', 'plagiarism_docguard') . '</a>';
    } else if ($sub->status === 'pending' || $sub->status === 'error') {
        $aurl = new moodle_url(
            '/plagiarism/docguard/report.php',
            [
                'cmid'      => $cmid,
                'dg_action' => 'reanalyse_pending',
                'subid'     => $sub->id,
                'sesskey'   => sesskey(),
                ]
        );
        $actionlinks = '<a href="' . $aurl->out(false) . '" class="btn btn-sm btn-outline-secondary"'
            . ' onclick="return confirm(\'' . s(addslashes(get_string('analyseconfirm', 'plagiarism_docguard'))) . '\');">'
            . '&#9654; ' . get_string('analysebutton', 'plagiarism_docguard') . '</a>';
    }

    echo '<tr>';
    echo '<td>' . s($fn) . '<br><small style="color:#9ca3af;">' . s($user->username ?? '') . '</small></td>';
    echo '<td style="font-size:0.82rem;">' . s($sub->filename)
        . ' <span style="color:#9ca3af;">(' . strtoupper($sub->filetype) . ')</span></td>';
    echo '<td style="text-align:center;">' . (int)$sub->section_count . '</td>';
    echo '<td>' . $scorehtml . '</td>';
    echo '<td>' . ($sub->status === 'analysed'
        ? '<span class="docguard-badge docguard-badge-' . s($level) . '">'
            . ($dgrisklabels[$level] ?? strtoupper($level)) . '</span>'
        : s($sub->status)) . '</td>';
    echo '<td style="font-size:0.82rem;">' . ($sub->timemodified ? userdate($sub->timemodified, '%d %b %Y %H:%M') : '-') . '</td>';
    echo '<td>' . $actionlinks . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

// Cross-student similarity overview.
echo '<h4 style="margin:2rem 0 0.5rem;">' . get_string('crosssimilarity', 'plagiarism_docguard') . '</h4>';
echo '<p style="font-size:0.88rem;color:#555;">'
    . get_string('crosssimilaritydesc', 'plagiarism_docguard') . '</p>';

// V1.0.80: PRECOMPUTE each document's bigram set ONCE.
//
// What was wrong: the inner loop called bigrams() on BOTH documents of every pair. For n
// analysed submissions that is n(n-1) tokenisations of a string up to 65 KB — 9,900 of
// them for a class of 100, when there are only 100 distinct documents to tokenise. Each
// bigrams() call does an explode() into ~10,000 words, builds a ~10,000-entry hash and
// then throws the hash away with array_keys(), and jaccard() immediately array_flip()s it
// back and array_merge()s both sides to count the union. Every document in the class was
// therefore re-tokenised 99 times, and the page did the heaviest work it does in the
// hottest loop it has.
//
// Now: one bigram_set() per document (n calls), then n(n-1)/2 comparisons that only
// intersect two ready-made hashes — jaccard_sets() is |A∩B| / (|A|+|B|-|A∩B|), identical
// arithmetic to the old jaccard(). Tokenisation drops from O(n²) to O(n); the comparison
// loop stays O(n²) because comparing every pair is the feature, but each comparison is now
// a hash intersection instead of two full tokenisations. Also removed the redundant
// $seen[] map: the j = i+1 loop cannot generate a pair twice.
require_once($CFG->dirroot . '/plagiarism/docguard/classes/question_parser.php');

$analysed = array_filter($subs, fn($s) => $s->status === 'analysed' && strlen((string)$s->normtext) > 100);
$pairs    = [];
$arr      = array_values($analysed);
$count    = count($arr);

$bigramsets = [];
foreach ($arr as $idx => $row) {
    $bigramsets[$idx] = \plagiarism_docguard\question_parser::bigram_set((string)$row->normtext);
}

for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $sim = \plagiarism_docguard\question_parser::jaccard_sets($bigramsets[$i], $bigramsets[$j]);
        if ($sim >= 0.30) {
            $pairs[] = ['a' => $arr[$i], 'b' => $arr[$j], 'sim' => $sim];
        }
    }
}
unset($bigramsets);

if (empty($pairs)) {
    echo '<p style="color:#6b7280;font-style:italic;">'
        . get_string('nosimilarities', 'plagiarism_docguard') . '</p>';
} else {
    usort($pairs, fn($x, $y) => $y['sim'] <=> $x['sim']);
    echo '<table class="docguard-similarity-table">';
    echo '<thead><tr>'
        . '<th>' . get_string('colstudenta', 'plagiarism_docguard') . '</th>'
        . '<th>' . get_string('colstudentb', 'plagiarism_docguard') . '</th>'
        . '<th>' . get_string('colsimilarity', 'plagiarism_docguard') . '</th>'
        . '<th>' . get_string('colconcern', 'plagiarism_docguard') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($pairs as $pair) {
        // V1.0.80: served from the batch fetched above — no per-pair user queries.
        $ua   = $dgusers[(int)$pair['a']->userid] ?? null;
        $ub   = $dgusers[(int)$pair['b']->userid] ?? null;
        $fna  = $ua ? fullname($ua) : '#' . $pair['a']->userid;
        $fnb  = $ub ? fullname($ub) : '#' . $pair['b']->userid;
        $sim  = $pair['sim'];
        $pct  = round($sim * 100);
        $col  = $sim >= 0.70 ? '#b71c1c' : ($sim >= 0.50 ? '#e65100' : '#374151');
        $warn = $sim >= 0.70
            ? get_string('concernhigh', 'plagiarism_docguard')
            : ($sim >= 0.50
                ? get_string('concernmedium', 'plagiarism_docguard')
                : get_string('concernlow', 'plagiarism_docguard'));
        echo '<tr>';
        echo '<td>' . s($fna) . ' <a href="'
            . (new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $pair['a']->id]))->out(false)
            . '" style="font-size:0.8rem;">' . get_string('viewlink', 'plagiarism_docguard') . '</a></td>';
        echo '<td>' . s($fnb) . ' <a href="'
            . (new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $pair['b']->id]))->out(false)
            . '" style="font-size:0.8rem;">' . get_string('viewlink', 'plagiarism_docguard') . '</a></td>';
        echo '<td style="font-weight:700;color:' . $col . ';">' . $pct . '%</td>';
        echo '<td style="color:' . $col . ';">' . $warn . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
