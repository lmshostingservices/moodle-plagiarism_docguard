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

defined('MOODLE_INTERNAL') || die();

$string['pluginname']            = 'DocGuard — Document Plagiarism Checker';
$string['docguard']              = 'DocGuard';
$string['enabled']               = 'Enable DocGuard';
$string['enabled_desc']          = 'Enable DocGuard plagiarism analysis globally.';
$string['siteid']                = 'Site ID';
$string['siteid_desc']           = 'Your AI Grader Site ID from lms-labs.com.';
$string['apikey']                = 'API Key';
$string['apikey_desc']           = 'Your AI Grader API Key from lms-labs.com.';
$string['retentiondays']         = 'Data retention (days)';
$string['retentiondays_desc']    = 'How many days to retain analysis data. 0 = keep forever.';
$string['minsectionwords']       = 'Minimum section words';
$string['minsectionwords_desc']  = 'Minimum word count per section to include it in analysis. Sections shorter than this are skipped.';
$string['enablebackfill']        = 'Analyse historical submissions (backfill)';
$string['enablebackfill_desc']   = 'When enabled, the hourly DocGuard task also scans for assignment submissions made before DocGuard was installed and analyses them, up to 15 files per run. Leave this OFF unless you specifically want historical submissions processed: on a large site it will work through every past assignment submission, which takes considerable server time and stores extracted text for every student who has ever submitted. New submissions are always analysed automatically regardless of this setting, and teachers can trigger an on-demand scan for a single activity from the DocGuard class report at any time.';
$string['savedconfigsuccess']    = 'DocGuard settings saved successfully.';
$string['viewreport']            = 'View DocGuard report';
$string['classreport']           = 'DocGuard class report';
$string['studentreport']         = 'DocGuard student report';
$string['risklow']               = 'Low risk';
$string['riskmedium']            = 'Medium risk';
$string['riskhigh']              = 'High risk';
$string['pending']               = 'Analysing…';
$string['unsupported']           = 'Not a PDF/DOCX';
$string['error']                 = 'Analysis error';
$string['nosections']            = 'No sections found';
$string['privacy:metadata']                    = 'DocGuard stores extracted submission text and analysis scores to detect plagiarism and AI-generated content.';
$string['docguard:viewreport']                 = 'View DocGuard plagiarism reports';
$string['cleanup_task']                        = 'DocGuard — clean up old submission text';
$string['process_pending_task']                = 'DocGuard — process pending and untracked submissions';
$string['privacy:metadata:docguard_sub']       = 'Stores one record per analysed file submission including risk score and extracted text.';
$string['privacy:metadata:docguard_sub:userid']            = 'The ID of the student who submitted the file.';
$string['privacy:metadata:docguard_sub:cmid']              = 'The course module (assignment) ID.';
$string['privacy:metadata:docguard_sub:filename']          = 'The original filename of the submitted document.';
$string['privacy:metadata:docguard_sub:overall_riskscore'] = 'The overall plagiarism/AI risk score (0–100).';
$string['privacy:metadata:docguard_sub:overall_risklevel'] = 'The overall risk level: low, medium, or high.';
$string['privacy:metadata:docguard_sub:status']            = 'Analysis status: pending, analysed, error, or unsupported.';
$string['privacy:metadata:docguard_sub:normtext']          = 'Normalised extracted text from the submitted document used for analysis.';
$string['privacy:metadata:docguard_sub:timecreated']       = 'The timestamp when the submission was first recorded.';
$string['privacy:metadata:docguard_sec']       = 'Stores per-section (question) analysis scores for each submission.';
$string['privacy:metadata:docguard_sec:subid']       = 'Foreign key referencing the parent submission record.';
$string['privacy:metadata:docguard_sec:sectionnum']  = 'The section/question number within the document.';
$string['privacy:metadata:docguard_sec:riskscore']   = 'The risk score for this section (0–100).';
$string['privacy:metadata:docguard_sec:risklevel']   = 'The risk level for this section: low, medium, or high.';
$string['privacy:metadata:docguard_sec:sectiontext'] = 'The extracted text for this section used during analysis.';
