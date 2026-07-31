<?php

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
require_capability('plagiarism/docguard:viewreport', $context);

// ── Re-analyse POST handler ───────────────────────────────────────────────────
// Allows a teacher to force re-extraction + re-analysis of a submission that
// was stored before the CMap-aware PDF extractor was installed (v1.0.52+).
// The handler finds the original stored_file by contenthash, resets the DB
// status to 'pending' (so analyse_and_store does not bail on 'already analysed'),
// then runs the full analysis pipeline and redirects back to this page.
if (optional_param('reanalyse', 0, PARAM_INT) === 1) {
    require_sesskey();

    $redirect_url = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]);

    // Find the stored_file by contenthash. We search all files in the DB with
    // this hash (not a directory stub) then instantiate via file storage.
    $filerecord = $DB->get_record_sql(
        'SELECT id FROM {files} WHERE contenthash = ? AND filename != ? ORDER BY id DESC LIMIT 1',
        [$sub->contenthash, '.']
    );

    if (!$filerecord) {
        redirect($redirect_url, 'Re-analyse failed: original file no longer exists in Moodle file storage.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($filerecord->id);

    if (!$file || $file->is_directory()) {
        redirect($redirect_url, 'Re-analyse failed: could not retrieve the original file.', null, \core\output\notification::NOTIFY_ERROR);
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
        redirect($redirect_url, 'Re-analysis complete.', null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        // Restore status so the page still renders previous results.
        $DB->set_field('plagiarism_docguard_sub', 'status', 'analysed', ['id' => $subid]);
        redirect($redirect_url, 'Re-analyse failed: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$student = $DB->get_record('user', ['id' => $sub->userid], 'id,firstname,lastname,username,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
$fn      = $student ? fullname($student) : 'Unknown (#' . $sub->userid . ')';

$PAGE->set_url(new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid]));
$PAGE->set_context($context);
$PAGE->set_title('DocGuard Report — ' . $fn);
$PAGE->set_heading('DocGuard Student Report');
$PAGE->requires->css('/plagiarism/docguard/styles.css');

echo $OUTPUT->header();

// ── Header panel ──────────────────────────────────────────────────────────────

$level = $sub->overall_risklevel ?? 'low';
$level_colours = [
    'high'   => ['#b71c1c', '#ffebee'],
    'medium' => ['#e65100', '#fff8e1'],
    'low'    => ['#2e7d32', '#e8f5e9'],
];
[$lc, $lb] = $level_colours[$level] ?? ['#374151', '#f3f4f6'];

$course  = get_course($cm->course);
$modinfo = get_fast_modinfo($cm->course);
$actname = $modinfo->get_cm($cmid)->name;

$class_url = new moodle_url('/plagiarism/docguard/report.php', ['cmid' => $cmid]);

echo '<div class="docguard-report-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">';
echo '<div>';
echo '<h2 style="margin:0 0 0.25rem;">DocGuard Report &mdash; ' . s($fn) . '</h2>';
echo '<p style="margin:0;opacity:0.8;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '<p style="margin:0.25rem 0 0;opacity:0.75;font-size:0.82rem;">File: ' . s($sub->filename) . ' (' . strtoupper($sub->filetype) . ') &nbsp; Analysed: ' . userdate($sub->timemodified, '%d %b %Y %H:%M') . '</p>';
echo '</div>';
echo '<div style="display:flex;gap:0.5rem;align-items:flex-start;flex-wrap:wrap;">';
$reanalyse_url = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $subid, 'reanalyse' => 1, 'sesskey' => sesskey()]);
echo '<a href="' . $reanalyse_url->out(false) . '" class="btn btn-outline-light btn-sm" style="align-self:flex-start;" onclick="return confirm(\'Re-run analysis using the latest PDF extractor? This will overwrite the current results.\');">&#8635; Re-analyse</a>';
echo '<a href="' . $class_url->out(false) . '" class="btn btn-outline-light btn-sm" style="align-self:flex-start;">&#8592; Class Report</a>';
echo '</div>';
echo '</div>';

// ── Status check ──────────────────────────────────────────────────────────────

if ($sub->status === 'error') {
    echo $OUTPUT->notification('Analysis error: ' . s($sub->errormsg), 'error');
    echo $OUTPUT->footer();
    exit;
}
if ($sub->status !== 'analysed') {
    echo $OUTPUT->notification('This submission is still being analysed. Refresh the page in a moment.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// ── Overall score card ────────────────────────────────────────────────────────

$score = (float)$sub->overall_riskscore;
$bar_w = (int)min(100, $score);

echo '<div style="background:' . $lb . ';border:1px solid ' . $lc . '33;border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">';
echo '<div style="text-align:center;">';
echo '<div style="font-size:2.8rem;font-weight:800;color:' . $lc . ';">' . (int)$score . '</div>';
echo '<div style="font-size:0.8rem;color:' . $lc . ';font-weight:600;">/ 100</div>';
echo '</div>';
echo '<div style="flex:1;min-width:200px;">';
echo '<div style="font-size:1.2rem;font-weight:700;color:' . $lc . ';margin-bottom:0.25rem;">' . strtoupper($level) . ' RISK</div>';
echo '<div class="docguard-score-bar-wrap" style="width:100%;max-width:300px;">';
echo '<span class="docguard-score-bar docguard-score-bar-' . s($level) . '" style="width:' . $bar_w . '%;"></span>';
echo '</div>';
echo '<div style="font-size:0.83rem;color:#555;margin-top:0.4rem;">';
echo (int)$sub->section_count . ' section(s) analysed &nbsp;|&nbsp; ';
echo 'File: ' . s($sub->filename);
echo '</div>';
echo '</div>';

// Score legend.
echo '<div style="font-size:0.8rem;color:#555;line-height:1.8;">';
echo '<span style="color:#2e7d32;font-weight:600;">&#9679; LOW</span>: 0–34 &nbsp; ';
echo '<span style="color:#e65100;font-weight:600;">&#9679; MEDIUM</span>: 35–64 &nbsp; ';
echo '<span style="color:#b71c1c;font-weight:600;">&#9679; HIGH</span>: 65–100';
echo '</div>';
echo '</div>';

// ── Per-section breakdown ─────────────────────────────────────────────────────

$sections = $DB->get_records('plagiarism_docguard_sec', ['subid' => $subid], 'section_num ASC');

if (empty($sections)) {
    echo $OUTPUT->notification('No section data available.', 'info');
} else {
    echo '<h4 style="margin:0 0 1rem;">Per-Section Analysis</h4>';

    foreach ($sections as $sec) {
        $sl    = $sec->risklevel ?? 'low';
        [$slc, $slb] = $level_colours[$sl] ?? ['#374151', '#f3f4f6'];
        $signals = json_decode((string)$sec->signalsjson, true) ?: [];

        // Build clean plain-text version of section_text for display.
        $full_text = strip_tags(html_entity_decode((string)$sec->section_text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $full_text = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $full_text)));
        $text_long = mb_strlen($full_text) > 600;
        $text_short = $text_long ? mb_substr($full_text, 0, 600) : $full_text;

        echo '<div class="docguard-section-card">';
        echo '<div class="docguard-section-head">';
        echo '<span class="docguard-badge docguard-badge-' . s($sl) . '" style="margin:0;">'
            . '<span class="docguard-dot docguard-dot-' . s($sl) . '"></span>'
            . strtoupper($sl) . '</span>';
        echo '<strong>' . s($sec->section_label) . '</strong>';
        echo '<span style="margin-left:auto;font-size:0.82rem;color:#6b7280;">'
            . (int)$sec->riskscore . '/100 &nbsp; '
            . (int)$sec->wordcount . ' words</span>';
        echo '</div>';
        echo '<div class="docguard-section-body">';

        if ($full_text) {
            $sid = 'dg-ans-' . (int)$sec->id;
            echo '<div style="margin-bottom:0.85rem;">';
            echo '<div style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.35rem;">Student\'s Answer</div>';
            echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:0.75rem 1rem;font-size:0.88rem;color:#374151;line-height:1.65;">';
            echo '<span id="' . $sid . '-short">' . s($text_short);
            if ($text_long) {
                echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $sid . '-short\').style.display=\'none\';document.getElementById(\'' . $sid . '-full\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">show more</a>';
            }
            echo '</span>';
            if ($text_long) {
                echo '<span id="' . $sid . '-full" style="display:none;">' . s($full_text) . ' <a href="#" onclick="document.getElementById(\'' . $sid . '-full\').style.display=\'none\';document.getElementById(\'' . $sid . '-short\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">show less</a></span>';
            }
            echo '</div>';
            echo '</div>';
        }

        if (!empty($signals)) {
            echo '<details style="font-size:0.82rem;margin-top:0.65rem;margin-bottom:0.4rem;">';
            echo '<summary style="cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;padding:0.2rem 0.65rem;border-radius:5px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;font-weight:600;font-size:0.8rem;list-style:none;-webkit-appearance:none;">&#9432;&nbsp;Signal status key</summary>';
            echo '<div style="margin-top:0.4rem;padding:0.65rem 0.9rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;max-width:600px;display:flex;flex-direction:column;gap:0.5rem;">';
            echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
                . '<span style="min-width:82px;font-size:0.8rem;font-weight:700;color:#991b1b;white-space:nowrap;flex-shrink:0;">&#9679;&nbsp;FIRED</span>'
                . '<span style="font-size:0.82rem;color:#374151;">This signal detected a suspicious pattern. Its points were added to the section risk score.</span>'
                . '</div>';
            echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
                . '<span style="min-width:82px;font-size:0.8rem;font-weight:600;color:#9ca3af;white-space:nowrap;flex-shrink:0;">&#9711;&nbsp;Silent</span>'
                . '<span style="font-size:0.82rem;color:#374151;">This signal was evaluated but found nothing suspicious. No points were added.</span>'
                . '</div>';
            echo '</div></details>';
            echo '<table class="docguard-signal-table" style="margin-top:0.5rem;">';
            echo '<thead><tr><th>Signal</th><th>Status</th><th>Points</th><th style="min-width:220px;">Detail</th></tr></thead><tbody>';

            $signal_order = [
                's1_ai_markers', 's2_sentence_uniformity', 's3_ttr_uniformity',
                's4_transitions', 's5_contractions', 's6_passive_voice',
                's7_template', 's8_trigrams', 's9_sentence_starts',
                's10_vocab_richness', 's11_cross_section',
            ];

            $defined = array_merge(
                array_intersect($signal_order, array_keys($signals)),
                array_diff(array_keys($signals), $signal_order)
            );

            foreach ($defined as $key) {
                if (!isset($signals[$key])) { continue; }
                $sig = $signals[$key];
                $pts = (int)($sig['points'] ?? 0);
                $max = (int)($sig['max'] ?? 0);
                $lbl = $sig['label'] ?? $key;
                $desc= $sig['description'] ?? '';
                $frd = !empty($sig['fired']);

                $status_html = $frd
                    ? '<span class="docguard-signal-fired" title="FIRED — This signal detected a suspicious pattern and its points were added to the risk score.">&#9679; FIRED</span>'
                    : '<span class="docguard-signal-silent" title="Silent — This signal was evaluated but found nothing suspicious. No points were added.">&#9711; Silent</span>';

                $detail_parts = [];
                if (isset($sig['marker_count'])) {
                    $detail_parts[] = 'Markers found: ' . $sig['marker_count'];
                    if (!empty($sig['matches'])) {
                        $detail_parts[] = '"' . implode('", "', array_slice($sig['matches'], 0, 5)) . '"';
                    }
                }
                if (isset($sig['mean_words'])) {
                    $detail_parts[] = 'Mean sentence: ' . $sig['mean_words'] . ' words';
                }
                if (isset($sig['std_dev']) && $sig['std_dev'] !== null) {
                    $detail_parts[] = 'Std dev: ' . $sig['std_dev'];
                }
                if (isset($sig['ttr_std_dev']) && $sig['ttr_std_dev'] !== null) {
                    $detail_parts[] = 'TTR std dev: ' . $sig['ttr_std_dev'];
                }
                if (isset($sig['per_100'])) {
                    $detail_parts[] = 'Per 100 words: ' . $sig['per_100'];
                    if (!empty($sig['hits'])) {
                        $detail_parts[] = implode(', ', array_slice($sig['hits'], 0, 5));
                    }
                }
                if (isset($sig['passive_count'])) {
                    $detail_parts[] = 'Passive constructs: ' . $sig['passive_count'] . ' (ratio: ' . $sig['ratio'] . ')';
                }
                if (isset($sig['opener_found'])) {
                    $detail_parts[] = 'Opener: ' . ($sig['opener_found'] ? '"' . $sig['opener_hit'] . '"' : 'none');
                    $detail_parts[] = 'Closer: ' . ($sig['closer_found'] ? '"' . $sig['closer_hit'] . '"' : 'none');
                }
                if (isset($sig['unique_ratio']) && $sig['unique_ratio'] !== null) {
                    $detail_parts[] = 'Trigram uniqueness: ' . round($sig['unique_ratio'] * 100) . '%';
                }
                if (isset($sig['uniform_ratio']) && $sig['uniform_ratio'] !== null) {
                    $detail_parts[] = 'Uniform starts: ' . round($sig['uniform_ratio'] * 100) . '% of sentences';
                }
                if (isset($sig['ttr'])) {
                    $detail_parts[] = 'TTR: ' . $sig['ttr'];
                }
                if (isset($sig['note'])) {
                    $detail_parts[] = $sig['note'];
                }

                $detail_html = implode('<br>', array_map('s', $detail_parts));
                if ($desc) {
                    $detail_html .= '<div style="margin-top:3px;color:#9ca3af;font-size:0.78rem;">' . s($desc) . '</div>';
                }

                echo '<tr>';
                echo '<td class="' . ($frd ? 'docguard-signal-fired' : 'docguard-signal-silent') . '">' . s($lbl) . '</td>';
                echo '<td>' . $status_html . '</td>';
                echo '<td>';
                if ($frd) {
                    echo '<strong>' . $pts . '</strong> / ' . $max;
                } else {
                    echo '0 / ' . $max;
                }
                echo '</td>';
                echo '<td>' . $detail_html . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#9ca3af;font-style:italic;font-size:0.85rem;">No signal data available.</p>';
        }

        echo '</div></div>';
    }
}

// ── Cross-student similarity ──────────────────────────────────────────────────

echo '<h4 style="margin:2rem 0 0.75rem;">Cross-Student Similarity (S12)</h4>';
echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.85rem 1.1rem;margin-bottom:1rem;">';
echo '<div style="font-weight:700;color:#92400e;font-size:0.93rem;margin-bottom:0.3rem;">Has this student copied from another student in this class?</div>';
echo '<div style="font-size:0.83rem;color:#78350f;line-height:1.55;">DocGuard scans every other student\'s submission for this same activity and measures how much of the text they have in common. A high similarity score means two students\' documents contain large amounts of matching content — this may indicate copying, shared notes, or use of the same source material. Each student\'s report also links to the other for easy side-by-side comparison.</div>';
echo '</div>';
echo '<p style="font-size:0.8rem;color:#9ca3af;margin-bottom:0.75rem;">Method: Jaccard bigram similarity on normalised submission text. Matches flagged at &ge;30% similarity.</p>';

if (strlen((string)$sub->normtext) > 100) {
    $similar = \plagiarism_docguard\analyser::cross_student_similarity(
        $subid, $cmid, (string)$sub->normtext
    );

    if (empty($similar)) {
        echo '<p style="color:#6b7280;font-style:italic;">No significant similarities detected (threshold: &ge;30% Jaccard).</p>';
    } else {
        echo '<table class="docguard-similarity-table">';
        echo '<thead><tr><th>Student</th><th>Similarity</th><th>Their Risk Level</th><th>Their Report</th></tr></thead><tbody>';
        foreach ($similar as $match) {
            $sim_pct = round($match['similarity'] * 100);
            $col     = $match['similarity'] >= 0.70 ? '#b71c1c' : ($match['similarity'] >= 0.50 ? '#e65100' : '#374151');
            $rurl    = new moodle_url('/plagiarism/docguard/student_report.php', ['subid' => $match['subid']]);
            echo '<tr>';
            echo '<td>' . s($match['fullname']) . ' <small style="color:#9ca3af;">(' . s($match['username']) . ')</small></td>';
            echo '<td style="font-weight:700;color:' . $col . ';">' . $sim_pct . '%</td>';
            echo '<td><span class="docguard-badge docguard-badge-' . s($match['risklevel']) . '" style="display:inline-flex;">'
                . strtoupper($match['risklevel']) . '</span></td>';
            echo '<td><a href="' . $rurl->out(false) . '">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} else {
    echo '<p style="color:#9ca3af;font-style:italic;">Insufficient text extracted for similarity comparison.</p>';
}

// ── Interpretation guide ──────────────────────────────────────────────────────

echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:1rem 1.25rem;margin-top:2rem;font-size:0.83rem;">';
echo '<strong>Interpretation Guide</strong><br>';
echo '<ul style="margin:0.5rem 0 0;padding-left:1.25rem;color:#555;">';
echo '<li><strong>LOW (0–34):</strong> Submission appears consistent with authentic student writing. No major concerns detected.</li>';
echo '<li><strong>MEDIUM (35–64):</strong> Some indicators present. Human review recommended — contextual factors may explain results.</li>';
echo '<li><strong>HIGH (65–100):</strong> Multiple strong indicators of AI-generated content or plagiarism. Treat as a priority for review.</li>';
echo '<li>DocGuard uses heuristic signals — it does not make definitive plagiarism determinations. Always apply academic judgement.</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
