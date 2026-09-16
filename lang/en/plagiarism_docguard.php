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

// V1.0.80: strings for the rebuilt settings page (see settings.php), the connection
// test action, the student disclosure, and the privacy external-location entry.
$string['connectionstatus']      = 'Connection and unlock status';
$string['enableplagiarismnotice'] = 'Moodle\'s plagiarism subsystem is switched off site-wide, so DocGuard cannot run on any activity. Turn on "Enable plagiarism plugins" in <a href="{$a}">Advanced features</a>.';
$string['nocredentialsnotice']   = '<strong>DocGuard is not analysing any submissions.</strong> No Site ID or API Key is configured, so this site is not licensed. Enter your credentials below and save, then use Test connection. Students are not shown the plagiarism disclosure while this is the case.';
$string['credsfromaiconfig']     = 'The Site ID and API Key shown below are supplied by the AI Config plugin, which this site also has installed. DocGuard uses those values, not any it stores itself, so editing the fields here will have no effect while AI Config provides them. Change them in AI Config instead.';
$string['credsfrommixed']        = 'Some of the credentials below come from the AI Config plugin and some from DocGuard\'s own settings. Where AI Config supplies a value it takes precedence, so editing that field here will have no effect. Set both in the same place to avoid confusion.';
$string['unlockunknown']         = 'Unlock status unknown — use Test connection below to check.';
$string['unlockunlocked']        = 'DocGuard is UNLOCKED (last verified {$a} min ago).';
$string['unlocklocked']          = 'DocGuard is NOT UNLOCKED (last checked {$a} min ago). Visit lms-labs.com → Dashboard → Plugins → DocGuard → Unlock.';
$string['testconnection']        = 'Test connection and unlock status';
$string['testconnection_desc']   = 'Forces a live check against lms-labs.com.';
$string['testconnection_ok']     = 'DocGuard is unlocked — analysis is active for enabled activities.';
$string['testconnection_locked'] = 'DocGuard is not unlocked. Visit lms-labs.com → Dashboard → Plugins → DocGuard → Unlock (5,000 credits).';
$string['aboutheading']          = 'About DocGuard';
$string['about_desc']            = 'Supported file types: PDF (.pdf) and Word (.docx). DocGuard extracts each submission\'s text, detects question/answer sections, and scores it across 12 plagiarism and AI-detection signals. To unlock: log in to lms-labs.com → Dashboard → Plugins → DocGuard → Unlock (5,000 credits).';
$string['disclosure']            = 'Plagiarism and AI-use checking is enabled for this activity. When you submit a PDF or Word document here, DocGuard extracts the text of your document and stores it, together with your name, this activity, the file name, and the resulting risk scores, in this Moodle site\'s database. The text is analysed for indicators of plagiarism and AI-generated writing, and is compared with other submissions to this same activity to detect copying. Your teachers and this site\'s administrators can see the results and the extracted text; your document itself is never sent to any third party — only your site\'s licence credentials are exchanged with lms-labs.com to verify that this plugin is licensed. Scores are heuristic indicators, not proof of misconduct, and a person always reviews them. The extracted text is deleted automatically after {$a} days; the scores are kept so past reports remain viewable.';
$string['disclosure_noretention'] = 'Plagiarism and AI-use checking is enabled for this activity. When you submit a PDF or Word document here, DocGuard extracts the text of your document and stores it, together with your name, this activity, the file name, and the resulting risk scores, in this Moodle site\'s database. The text is analysed for indicators of plagiarism and AI-generated writing, and is compared with other submissions to this same activity to detect copying. Your teachers and this site\'s administrators can see the results and the extracted text; your document itself is never sent to any third party — only your site\'s licence credentials are exchanged with lms-labs.com to verify that this plugin is licensed. Scores are heuristic indicators, not proof of misconduct, and a person always reviews them. This site has set the extracted text to be retained indefinitely.';
$string['scan_activity_task']    = 'DocGuard — scan and analyse an activity\'s unprocessed submissions';
$string['analyse_submission_task'] = 'DocGuard — analyse a submitted assignment';
$string['viewreport']            = 'View DocGuard report';
$string['classreport']           = 'DocGuard class report';
$string['studentreport']         = 'DocGuard student report';
$string['risklow']               = 'Low risk';
$string['riskmedium']            = 'Medium risk';
$string['riskhigh']              = 'High risk';
$string['pending']               = 'Analysing…';
$string['unsupported']           = 'Not a PDF/DOCX';
$string['error']                 = 'Analysis error';
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

// V1.0.88 FIX-DG-PRIVACY-UNDERDECLARED: strings for the columns get_metadata() used to
// omit. Every one of them is written for every analysed submission, and several are
// derived directly from the student's own document, so a privacy registry that listed
// only the previous eight understated what DocGuard holds.
$string['privacy:metadata:docguard_sub:contextid']     = 'The Moodle context of the activity the document was submitted to.';
$string['privacy:metadata:docguard_sub:submissionid']  = 'The ID of the assignment submission the document belongs to.';
$string['privacy:metadata:docguard_sub:filetype']      = 'The detected document type, PDF or DOCX.';
$string['privacy:metadata:docguard_sub:contenthash']   = 'The SHA-1 content hash of the submitted file, used to recognise the same document again. It is derived from the file the student uploaded.';
$string['privacy:metadata:docguard_sub:section_count'] = 'How many sections (questions) were found in the document.';
$string['privacy:metadata:docguard_sub:analysisjson']  = 'The full analysis result for the document, including per-signal findings and quoted excerpts of the student\'s own writing.';
$string['privacy:metadata:docguard_sub:errormsg']      = 'Why an analysis failed, where it did. It can name the submitted file.';
$string['privacy:metadata:docguard_sub:timemodified']  = 'The timestamp when the record was last analysed or updated.';
$string['privacy:metadata:docguard_sec:userid']        = 'The ID of the student whose document this section came from.';
$string['privacy:metadata:docguard_sec:cmid']          = 'The course module (assignment) the section belongs to.';
$string['privacy:metadata:docguard_sec:sectionlabel']  = 'The heading the section was found under, taken from the student\'s document, for example "Question 3".';
$string['privacy:metadata:docguard_sec:wordcount']     = 'How many words the student wrote in this section.';
$string['privacy:metadata:docguard_sec:signalsjson']   = 'The per-signal analysis of this section, including quoted phrases matched in the student\'s own text.';
$string['privacy:metadata:docguard_sec:timemodified']  = 'The timestamp when this section record was last written.';

// V1.0.80: the plugin talks to lms-labs.com on every site (licence verification and the
// platform-wide enable flags) and the privacy provider never declared it. An external
// location link is required whenever a plugin exchanges anything with a third party, and
// its absence made the site's own privacy registry inaccurate.
$string['privacy:metadata:lmslabs']            = 'DocGuard exchanges licensing and configuration information with the LMS-Labs service (lms-labs.com) to verify that this site is licensed to use the plugin and to read site-wide enablement flags. Submitted documents, extracted text and analysis results are NOT sent: all document analysis happens on this Moodle server.';
$string['privacy:metadata:lmslabs:siteid']     = 'The site licence identifier configured by the administrator. It identifies the Moodle site, not any individual user.';
$string['privacy:metadata:lmslabs:apikey']     = 'The site API key configured by the administrator, sent to authenticate the licence check.';
$string['privacy:metadata:lmslabs:pluginid']   = 'The fixed identifier of this plugin ("docguard"), sent so the service knows which licence is being verified.';

// V1.0.80: strings for the class report (report.php) and the per-student report
// (student_report.php). These pages previously emitted their labels, table headings,
// notifications and interpretation guidance as hardcoded English.
$string['analysebutton']            = 'Analyse';
$string['analyseconfirm']           = 'Trigger DocGuard analysis for this submission now?';
$string['analysiserror']            = 'Analysis error: {$a}';
$string['classreportshort']         = 'Class Report';
$string['classreportheading']       = 'DocGuard — Class Plagiarism Report';
$string['colactions']               = 'Actions';
$string['colanalysed']              = 'Analysed';
$string['colconcern']               = 'Concern';
$string['coldetail']                = 'Detail';
$string['colfile']                  = 'File';
$string['colpoints']                = 'Points';
$string['colrisk']                  = 'Risk';
$string['colscore']                 = 'Score';
$string['colsections']              = 'Sections';
$string['colsignal']                = 'Signal';
$string['colsimilarity']            = 'Similarity';
$string['colstatus']                = 'Status';
$string['colstudent']               = 'Student';
$string['colstudenta']              = 'Student A';
$string['colstudentb']              = 'Student B';
$string['coltheirreport']           = 'Their report';
$string['coltheirrisk']             = 'Their risk level';
$string['concernhigh']              = 'High concern';
$string['concernlow']               = 'Low concern';
$string['concernmedium']            = 'Medium concern';
$string['crosscopydesc']            = 'DocGuard scans every other student\'s submission for this same activity and measures how much of the text they have in common. A high similarity score means two students\' documents contain large amounts of matching content — this may indicate copying, shared notes, or use of the same source material. Each student\'s report also links to the other for easy side-by-side comparison.';
$string['crosscopyquestion']        = 'Has this student copied from another student in this class?';
$string['crossmethod']              = 'Method: Jaccard bigram similarity on normalised submission text. Matches flagged at &ge;30% similarity.';
$string['crosssimilarity']          = 'Cross-Student Similarity';
$string['crosssimilaritydesc']      = 'Pairs of submissions in this activity with Jaccard bigram similarity &ge; 30% are listed below. High similarity may indicate shared source material, group work, or academic misconduct.';
$string['crosssimilarityheading']   = 'Cross-Student Similarity (S12)';
$string['detailcloser']             = 'Closer: {$a}';
$string['detailmarkers']            = 'Markers found: {$a}';
$string['detailmeansentence']       = 'Mean sentence: {$a} words';
$string['detailnone']               = 'none';
$string['detailopener']             = 'Opener: {$a}';
$string['detailpassive']            = 'Passive constructs: {$a->count} (ratio: {$a->ratio})';
$string['detailper100']             = 'Per 100 words: {$a}';
$string['detailstddev']             = 'Std dev: {$a}';
$string['detailtrigram']            = 'Trigram uniqueness: {$a}%';
$string['detailttr']                = 'Type-token ratio: {$a}';
$string['detailttrstddev']          = 'Type-token ratio std dev: {$a}';
$string['detailuniformstarts']      = 'Uniform starts: {$a}% of sentences';
$string['disabledcmnotice']         = 'DocGuard is not enabled for this activity, so new submissions are not analysed. Tick "Enable DocGuard" in the activity settings to turn it on.';
$string['disabledglobalnotice']     = 'DocGuard is switched off site-wide. Existing results are shown below, but no new analysis will run until an administrator enables it at Site administration → Plugins → Plagiarism → DocGuard.';
$string['filelabel']                = 'File';
$string['insufficienttext']         = 'Insufficient text extracted for similarity comparison.';
$string['interprethigh']            = '<strong>HIGH (65–100):</strong> Multiple strong indicators of AI-generated content or plagiarism. Treat as a priority for review.';
$string['interpretlow']             = '<strong>LOW (0–34):</strong> Submission appears consistent with authentic student writing. No major concerns detected.';
$string['interpretmedium']          = '<strong>MEDIUM (35–64):</strong> Some indicators present. Human review recommended — contextual factors may explain results.';
$string['interpretnote']            = 'DocGuard uses heuristic signals — it does not make definitive plagiarism determinations. Always apply academic judgement.';
$string['interpretationguide']      = 'Interpretation Guide';
$string['norecords']                = 'No DocGuard analysis records found for this activity. Submissions are analysed automatically when students submit files.';
$string['nosectionbreakdown']       = 'No per-section breakdown is stored for this submission. The overall score above was calculated, but the section detail was either never stored or has since been removed. Use the Re-analyse button at the top of this page to rebuild it.';
$string['nosignals']                = 'No signals were evaluated for this section — fewer than 8 words were recognised in it. This is expected for headings and very short answers, and can also mean the text did not extract cleanly from the original file.';
$string['nosimilarities']           = 'No significant cross-student similarities detected.';
$string['nosimilaritiesthreshold']  = 'No significant similarities detected (threshold: &ge;30% Jaccard).';
$string['persectionheading']        = 'Per-Section Analysis';
$string['reanalysebutton']          = 'Re-analyse';
$string['reanalysecomplete']        = 'Re-analysis complete.';
$string['reanalyseconfirm']         = 'Re-run analysis using the latest PDF extractor? This will overwrite the current results.';
$string['reanalysefailed']          = 'Re-analyse failed: {$a}';
$string['reanalysefailednofile']    = 'Re-analyse failed: file no longer exists in Moodle storage.';
$string['reanalysefailednooriginal'] = 'Re-analyse failed: original file no longer exists in Moodle file storage.';
$string['reanalysefailednoretrieve'] = 'Re-analyse failed: could not retrieve file.';
$string['reanalysefailednoretrieveoriginal'] = 'Re-analyse failed: could not retrieve the original file.';
$string['reanalyseunavailable']     = 'Re-analyse is unavailable: DocGuard is switched off for this site or this activity.';
// V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED.
$string['reanalyseunlicensed']      = 'Re-analyse is unavailable: this site has no DocGuard Site ID and API Key, so DocGuard is not analysing submissions. An administrator can enter them at Site administration > Plugins > Plagiarism prevention > DocGuard.';
$string['risklevelbannerhigh']      = 'High risk';
$string['risklevelbannerlow']       = 'Low risk';
$string['risklevelbannermedium']    = 'Medium risk';
$string['risklevelshorthigh']       = 'High';
$string['risklevelshortlow']        = 'Low';
$string['risklevelshortmedium']     = 'Medium';
$string['scanalreadyqueued']        = 'A DocGuard scan is already queued for this activity — it is still working through the backlog.';
$string['scanbutton']               = 'Scan &amp; Analyse Unprocessed Submissions';
$string['scanbuttontitle']          = 'Finds submitted files that have no DocGuard record yet (e.g. submissions made before the plugin was installed) and runs analysis on them.';
$string['scanconfirm']              = 'Scan all submitted files and run DocGuard analysis on any that are not yet processed? This may take a moment for large classes.';
$string['scandisabledcm']           = 'DocGuard is not enabled for this activity. Tick "Enable DocGuard" in the activity settings first.';
$string['scandisabledglobal']       = 'DocGuard is switched off site-wide, so no analysis can be started. An administrator can enable it at Site administration → Plugins → Plagiarism → DocGuard.';
$string['scanqueued']               = 'DocGuard scan queued. Unprocessed submissions for this activity will be analysed in the background, in batches, starting at the next scheduled task run. Reload this page to follow progress — the Pending count falls as files complete.';
$string['sectionsanalysed']         = '{$a} section(s) analysed';
$string['separategroupsnotice']     = 'This activity uses separate groups. You are seeing only the submissions of students in your own group(s).';
$string['showless']                 = 'show less';
$string['showmore']                 = 'show more';
$string['signalfired']              = 'Fired';
$string['signalfireddesc']          = 'This signal detected a suspicious pattern. Its points were added to the section risk score.';
$string['signalfiredtitle']         = 'Fired — This signal detected a suspicious pattern and its points were added to the risk score.';
$string['signalsilent']             = 'Silent';
$string['signalsilentdesc']         = 'This signal was evaluated but found nothing suspicious. No points were added.';
$string['signalsilenttitle']        = 'Silent — This signal was evaluated but found nothing suspicious. No points were added.';
$string['signalstatuskey']          = 'Signal status key';
$string['stillanalysing']           = 'This submission is still being analysed. Refresh the page in a moment.';
$string['statuserror']              = 'Error';
$string['statuspending']            = 'Pending';
$string['staterrors']               = 'Errors';
$string['statpending']              = 'Pending';
$string['stattotal']                = 'Total Submissions';
$string['studentanswer']            = 'Student\'s Answer';
$string['studentreportheading']     = 'DocGuard Report — {$a}';
$string['submissionsheading']       = 'Submissions';
$string['unknownuser']              = 'Unknown (#{$a})';
$string['viewlink']                 = '[view]';
$string['viewword']                 = 'View';
$string['wordcountlabel']           = '{$a} words';

// V1.0.80: strings for the inline risk badge rendered by plagiarism_docguard_render_badge()
// in lib.php, and for the on-demand re-analysis entry point (reanalyse.php).
$string['agehours']                 = '{$a} hr';
$string['ageminutes']               = '{$a} min';
$string['analysiscomplete']         = 'DocGuard analysis complete. Reload the page to see the result.';
$string['analysisfailed']           = 'Analysis failed: {$a}';
$string['badgeerror']               = 'Plagiarism Check Error';
$string['badgelabel']               = 'Plagiarism Check {$a->level} {$a->score}%';
$string['badgelevelhigh']           = 'High';
$string['badgelevellow']            = 'Low';
$string['badgelevelmedium']         = 'Medium';
$string['badgepending']             = 'Plagiarism Check Pending';
$string['badgependingaged']         = 'Plagiarism Check Pending ({$a})';
$string['reanalysecompletereload']  = 'DocGuard re-analysis complete. Reload the page to see the updated result.';
$string['reanalyseconfirmbadge']    = 'Run DocGuard analysis now for this submission?';
$string['reanalysedisabledcm']      = 'DocGuard is not enabled for this activity, so analysis cannot be run.';
$string['reanalysedisabledglobal']  = 'DocGuard is switched off site-wide, so analysis cannot be run.';
$string['reanalysefailedfilescope'] = 'Re-analyse failed: that file does not belong to this assignment.';
$string['reanalysefailednofileid']  = 'Re-analyse failed: could not find the file (ID {$a}) in Moodle storage.';
$string['reanalysefailednostored']  = 'Re-analyse failed: the original file no longer exists in Moodle file storage. The student may need to resubmit.';
$string['reanalysefailednosubmission'] = 'Re-analyse failed: that user has no submission in this assignment.';
$string['reanalysefailedretrieve']  = 'Re-analyse failed: could not retrieve the file from Moodle storage.';
$string['reanalyseinvalid']         = 'Invalid re-analyse request.';
$string['reanalysenow']             = 'Re-analyse now';
$string['tooltiperror']             = 'DocGuard could not analyse this file. {$a}';
$string['tooltiperrordefault']      = 'Check plugin settings or ask the student to resubmit.';
$string['tooltiphigh']              = 'DocGuard: HIGH risk ({$a}/100). Significant signals detected — a detailed report is strongly recommended.';
$string['tooltiplow']               = 'DocGuard: LOW risk ({$a}/100). Very few plagiarism or AI-generation signals detected.';
$string['tooltipmedium']            = 'DocGuard: MEDIUM risk ({$a}/100). Some signals triggered — review the full report before drawing conclusions.';
$string['tooltippending']           = 'DocGuard is queued to analyse this file. Reload the page in a moment to see the result.';
$string['tooltipstuck']             = 'Analysis has been pending for {$a}. This usually means the Moodle cron scheduler is not running or is running infrequently. Ask your Moodle administrator to check the cron job.';
$string['tooltipstuckreanalyse']    = 'You can also click Re-analyse below to run it now.';
$string['viewreportlink']           = 'View DocGuard Report';
$string['savedconfig'] = 'Settings saved.';
