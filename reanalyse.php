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

// FIX-DG-STUCK-PENDING (v1.0.71) + FIX-DG-REANALYSE-ALWAYS (v1.0.72):
// Inline re-analyse endpoint. Handles two cases:
//
// Case B — DB record exists (subid provided): reset status to pending,
// clear old section rows, re-run full analysis pipeline.
//
// Case A — No DB record (fileid + userid provided): file was submitted
// before DocGuard was active on this activity; observer never fired.
// Find the file, resolve the assign submission ID, then call
// analyse_and_store() which creates the record and runs analysis.
//
// FIX-DG-REPORT-ACCESS (v1.0.78): gated by the same check that renders the badge
// and guards the two report pages — plagiarism_docguard_can_view_reports().
// Previously this hard-required mod/assign:grade on its own, which meant (a) a role
// explicitly denied the DocGuard capability could still POST here and trigger
// analysis, and (b) a custom marker role granted only plagiarism/docguard:viewreport
// was shown the "Re-analyse now" link and then denied — the same link-then-deny
// defect this release exists to remove, on the one page it was not fixed.
// CSRF protected via sesskey.
// Redirects back to the assignment grading page with a success/error toast.

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/observer.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/analyser.php');
require_once($CFG->dirroot . '/plagiarism/docguard/classes/extractor.php');

$subid    = optional_param('subid', 0, PARAM_INT);
$fileid   = optional_param('fileid', 0, PARAM_INT);
$puserid  = optional_param('userid', 0, PARAM_INT);
$cmid     = required_param('cmid', PARAM_INT);

require_sesskey();

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, false, $cm);
$context = context_module::instance($cmid);
if (!plagiarism_docguard_can_view_reports($context)) {
    require_capability('plagiarism/docguard:viewreport', $context);
}

$redirecturl = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);

// V1.0.80: this endpoint extracts and stores student document text, so it honours the
// site-wide switch and the per-activity opt-out like every other entry point. Without
// this check a teacher could re-analyse — and therefore store text for — an activity
// whose DocGuard checkbox they had just unticked, or a site whose administrator had
// switched the plugin off entirely.
if (!plagiarism_docguard_is_enabled()) {
    redirect(
        $redirecturl,
        get_string('reanalysedisabledglobal', 'plagiarism_docguard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
if (!plagiarism_docguard_is_cm_active($cmid)) {
    redirect(
        $redirecturl,
        get_string('reanalysedisabledcm', 'plagiarism_docguard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
// V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED: and the licence gate, which this endpoint had
// never applied even though the observer, the cron backfill and the scan_activity task
// all do. See plagiarism_docguard_has_credentials() in lib.php for why the credentials
// are tested here rather than calling check_unlock() on a web request.
if (!plagiarism_docguard_has_credentials()) {
    redirect(
        $redirecturl,
        get_string('reanalyseunlicensed', 'plagiarism_docguard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

/* ── Case B: DB record exists — reset + re-analyse ──────────────────────────── */
if ($subid) {
    $sub = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid, 'cmid' => $cmid], '*', MUST_EXIST);

    // V1.0.84 FIX-DG-REANALYSE-GROUPS: report.php and student_report.php were both
    // hardened for SEPARATEGROUPS in v1.0.80/v1.0.81; this entry point was missed. It
    // checked only can_view_reports() and that the record's cmid matched, so a teacher
    // restricted to one group could post a subid belonging to another group and trigger
    // re-extraction and re-storage of that student's document text. Lower severity than
    // a direct read - the redirect goes back to the grading page rather than showing the
    // report - but it is a write against a submission the caller may not view, and the
    // same restriction belongs on every door into the same data.
    //
    // V1.0.88: the check itself now lives in plagiarism_docguard_user_visible() in
    // lib.php, shared with report.php and student_report.php. It had been written out by
    // hand three times, and the fourth door — report.php's "Analyse" action — was missed
    // every time.
    if (!plagiarism_docguard_user_visible($cm, $context, (int)$sub->userid)) {
        throw new \moodle_exception(
            'nopermissions',
            'error',
            '',
            get_string('reanalysebutton', 'plagiarism_docguard')
        );
    }

    // Locate the stored_file via the contenthash in the DB record.
    // v1.0.80: shared helper instead of a raw "LIMIT 1" — see lib.php.
    $fileidbyhash = plagiarism_docguard_find_file_id_by_hash((string)$sub->contenthash);
    if (!$fileidbyhash) {
        redirect(
            $redirecturl,
            get_string('reanalysefailednostored', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($fileidbyhash);
    if (!$file || $file->is_directory()) {
        redirect(
            $redirecturl,
            get_string('reanalysefailedretrieve', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
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
        redirect(
            $redirecturl,
            get_string('reanalysecompletereload', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $redirecturl,
            get_string('reanalysefailed', 'plagiarism_docguard', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

/* ── Case A: No DB record — find file by ID, create record + analyse ─────────── */
if ($fileid && $puserid) {
    $fs   = get_file_storage();
    $file = $fs->get_file_by_id($fileid);
    if (!$file || $file->is_directory()) {
        redirect(
            $redirecturl,
            get_string('reanalysefailednofileid', 'plagiarism_docguard', $fileid),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // FIX-DG-REANALYSE-FILE-SCOPE (v1.0.78): actually perform the check this comment
    // has always claimed to perform.
    //
    // Previously $expectedcontextid was merely ASSIGNED from $context->id and never
    // compared against the file — while $fileid and $userid arrive straight from the
    // query string as unvalidated PARAM_INT. get_file_by_id() will return ANY file in
    // the Moodle filestore, so a user who could reach this page could pass any file
    // id, have DocGuard extract that file and store its full text against their own
    // activity, then read the entire document back through the DocGuard report —
    // other courses' submissions, private files, anything. The user id was equally
    // unchecked, so a foreign document could be attributed to any account and would
    // then surface in that person's GDPR export.
    //
    // The file must live in THIS activity's context and be a student submission file.
    if (
        (int)$file->get_contextid() !== (int)$context->id
            || $file->get_component() !== 'assignsubmission_file'
            // V1.0.84: 'draft' removed. Draft files live in the USER context, so the
            // contextid test on the line above already rejects every one of them - the
            // entry was unreachable and wrongly suggested drafts are accepted here.
            || $file->get_filearea() !== 'submission_files'
    ) {
        redirect(
            $redirecturl,
            get_string('reanalysefailedfilescope', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Resolve the assign submission ID for this student.
    $assign = $DB->get_record('assign', ['id' => $cm->instance]);
    $asub   = $assign
        ? $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $puserid, 'latest' => 1])
        : null;

    // The named user must actually have a submission in this activity. Without this
    // the analysis record — including the full extracted document text — could be
    // filed against an arbitrary account.
    if (!$asub) {
        redirect(
            $redirecturl,
            get_string('reanalysefailednosubmission', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $submissionid       = (int)$asub->id;
    $expectedcontextid = $context->id;

    try {
        \plagiarism_docguard\observer::analyse_and_store(
            $file,
            $cmid,
            $puserid,
            $submissionid,
            $expectedcontextid
        );
        redirect(
            $redirecturl,
            get_string('analysiscomplete', 'plagiarism_docguard'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $redirecturl,
            get_string('analysisfailed', 'plagiarism_docguard', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// Neither subid nor fileid+userid provided.
redirect(
    $redirecturl,
    get_string('reanalyseinvalid', 'plagiarism_docguard'),
    null,
    \core\output\notification::NOTIFY_ERROR
);
