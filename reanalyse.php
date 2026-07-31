<?php
// FIX-DG-STUCK-PENDING (v1.0.71) + FIX-DG-REANALYSE-ALWAYS (v1.0.72):
// Inline re-analyse endpoint. Handles two cases:
//
//   Case B — DB record exists (subid provided): reset status to pending,
//            clear old section rows, re-run full analysis pipeline.
//
//   Case A — No DB record (fileid + userid provided): file was submitted
//            before DocGuard was active on this activity; observer never fired.
//            Find the file, resolve the assign submission ID, then call
//            analyse_and_store() which creates the record and runs analysis.
//
// Requires mod/assign:grade (the same capability that shows the badge).
// CSRF protected via sesskey.
// Redirects back to the assignment grading page with a success/error toast.

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

$subid    = optional_param('subid',   0, PARAM_INT);
$fileid   = optional_param('fileid',  0, PARAM_INT);
$puserid  = optional_param('userid',  0, PARAM_INT);
$cmid     = required_param('cmid',    PARAM_INT);

require_sesskey();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, false, $cm);
$context = context_module::instance($cmid);
require_capability('mod/assign:grade', $context);

$redirect_url = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);

// ── Case B: DB record exists — reset + re-analyse ────────────────────────────
if ($subid) {
    $sub = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid, 'cmid' => $cmid], '*', MUST_EXIST);

    // Locate the stored_file via the contenthash in the DB record.
    $filerecord = $DB->get_record_sql(
        'SELECT id FROM {files} WHERE contenthash = ? AND filename != ? ORDER BY id DESC LIMIT 1',
        [$sub->contenthash, '.']
    );
    if (!$filerecord) {
        redirect($redirect_url,
            'Re-analyse failed: the original file no longer exists in Moodle file storage. The student may need to resubmit.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($filerecord->id);
    if (!$file || $file->is_directory()) {
        redirect($redirect_url,
            'Re-analyse failed: could not retrieve the file from Moodle storage.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Reset to pending and wipe old section data.
    // Preserve timecreated — do NOT reset it (process_pending grace window depends on it).
    $upd = new stdClass();
    $upd->id           = $subid;
    $upd->status       = 'pending';
    $upd->errormsg     = null;
    $upd->timemodified = time();
    $DB->update_record('plagiarism_docguard_sub', $upd);
    $DB->delete_records('plagiarism_docguard_sec', ['subid' => $subid]);

    try {
        \plagiarism_docguard\observer::analyse_and_store(
            $file,
            (int)$sub->cmid,
            (int)$sub->userid,
            (int)$sub->submissionid,
            (int)$sub->contextid
        );
        redirect($redirect_url,
            'DocGuard re-analysis complete. Reload the page to see the updated result.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        redirect($redirect_url,
            'Re-analyse failed: ' . $e->getMessage(),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ── Case A: No DB record — find file by ID, create record + analyse ───────────
if ($fileid && $puserid) {
    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($fileid);
    if (!$file || $file->is_directory()) {
        redirect($redirect_url,
            'Re-analyse failed: could not find the file (ID ' . $fileid . ') in Moodle storage.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Verify the file belongs to this CM's context (security check).
    $expected_contextid = $context->id;

    // Resolve the assign submission ID for this student.
    $assign = $DB->get_record('assign', ['id' => $cm->instance]);
    $asub   = $assign
        ? $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $puserid, 'latest' => 1])
        : null;
    $submissionid = $asub ? (int)$asub->id : 0;

    try {
        \plagiarism_docguard\observer::analyse_and_store(
            $file,
            $cmid,
            $puserid,
            $submissionid,
            $expected_contextid
        );
        redirect($redirect_url,
            'DocGuard analysis complete. Reload the page to see the result.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        redirect($redirect_url,
            'Analysis failed: ' . $e->getMessage(),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Neither subid nor fileid+userid provided.
redirect($redirect_url, 'Invalid re-analyse request.', null, \core\output\notification::NOTIFY_ERROR);
