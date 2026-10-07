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
$string['disclosure']            = 'Copying checks are enabled for this activity. When you submit a PDF or Word document here, DocGuard extracts the text of your document and stores it, together with your name, this activity and the file name, in this Moodle site\'s database. Your submission is compared with other submissions to this same activity to detect copying, and the result is a similarity percentage. Quotations and your reference list are removed before the comparison, so the figure describes your own writing. DocGuard does not judge whether your work was written with AI, and it does not check the web, published sources or other courses. Your teachers and this site\'s administrators can see the result and the extracted text; your document itself is never sent to any third party — only your site\'s licence credentials are exchanged with lms-labs.com to verify that this plugin is licensed. A similarity figure is a reason for a person to read your work, not a finding of misconduct, and a person always reviews it. The extracted text is deleted automatically after {$a} days; the results are kept so past reports remain viewable.';
$string['disclosure_noretention'] = 'Copying checks are enabled for this activity. When you submit a PDF or Word document here, DocGuard extracts the text of your document and stores it, together with your name, this activity and the file name, in this Moodle site\'s database. Your submission is compared with other submissions to this same activity to detect copying, and the result is a similarity percentage. Quotations and your reference list are removed before the comparison, so the figure describes your own writing. DocGuard does not judge whether your work was written with AI, and it does not check the web, published sources or other courses. Your teachers and this site\'s administrators can see the result and the extracted text; your document itself is never sent to any third party — only your site\'s licence credentials are exchanged with lms-labs.com to verify that this plugin is licensed. A similarity figure is a reason for a person to read your work, not a finding of misconduct, and a person always reviews it. This site has set the extracted text to be retained indefinitely.';
$string['scan_activity_task']    = 'DocGuard — scan and analyse an activity\'s unprocessed submissions';
$string['analyse_submission_task'] = 'DocGuard — analyse a submitted assignment';
$string['viewreport']            = 'View DocGuard report';
$string['classreport']           = 'DocGuard class report';
$string['studentreport']         = 'DocGuard student report';
$string['risklow']               = 'No significant similarity';
$string['riskmedium']            = 'Moderate similarity';
$string['riskhigh']              = 'High similarity';
$string['pending']               = 'Analysing…';
$string['unsupported']           = 'Not a PDF/DOCX';
$string['error']                 = 'Analysis error';
$string['privacy:metadata']                    = 'DocGuard stores extracted submission text and similarity results to detect copying between submissions.';
$string['docguard:viewreport']                 = 'View DocGuard plagiarism reports';
$string['cleanup_task']                        = 'DocGuard — clean up old submission text';
$string['process_pending_task']                = 'DocGuard — process pending and untracked submissions';
$string['privacy:metadata:docguard_sub']       = 'Stores one record per analysed file submission including risk score and extracted text.';
$string['privacy:metadata:docguard_sub:userid']            = 'The ID of the student who submitted the file.';
$string['privacy:metadata:docguard_sub:cmid']              = 'The course module (assignment) ID.';
$string['privacy:metadata:docguard_sub:filename']          = 'The original filename of the submitted document.';
$string['privacy:metadata:docguard_sub:overall_riskscore'] = 'The percentage of this submission\'s word pairs that also appear in another submission to the same activity (0–100).';
$string['privacy:metadata:docguard_sub:overall_risklevel'] = 'The overall risk level: low, medium, or high.';
$string['privacy:metadata:docguard_sub:status']            = 'Analysis status: pending, analysed, error, or unsupported.';
$string['privacy:metadata:docguard_sub:normtext']          = 'Normalised extracted text from the submitted document used for analysis.';
$string['privacy:metadata:docguard_sub:timecreated']       = 'The timestamp when the submission was first recorded.';
$string['privacy:metadata:docguard_sec']       = 'Stores per-section (question) analysis scores for each submission.';
$string['privacy:metadata:docguard_sec:subid']       = 'Foreign key referencing the parent submission record.';
$string['privacy:metadata:docguard_sec:sectionnum']  = 'The section/question number within the document.';
$string['privacy:metadata:docguard_sec:riskscore']   = 'Retained at zero. Sections are not scored; the submission score is the similarity figure.';
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
/*
 * V1.2.0. This string IS the site's record of processing for this column, printed at
 * /admin/tool/dataprivacy and shown to a data subject who asks what is held about them. The
 * authenticity checks added in 1.2.0 put materially more personal data in this column -
 * verbatim excerpts of the student's writing, a whole sentence of theirs inside each
 * verification question, and names read out of the submitted file's own metadata, which may
 * belong to someone other than the student. Describing it as "how many sections were found"
 * would be a statement to a regulator that is not true.
 */
$string['privacy:metadata:docguard_sub:analysisjson']  = 'The analysis result for the document. This includes how many sections were found, how much text was extracted and which scoring model produced the stored score; the authenticity findings, each of which quotes a short passage of the student\'s own writing verbatim as the evidence for that finding; the verification questions generated for the assessor, which quote a sentence from the submission; and the properties the submitted file records about itself, such as the recorded editing time, the number of saves, and the author and last-modified-by names stored in the document — which may name a person other than the student.';
$string['privacy:metadata:docguard_sub:errormsg']      = 'Why an analysis failed, where it did. It can name the submitted file.';
$string['privacy:metadata:docguard_sub:timemodified']  = 'The timestamp when the record was last analysed or updated.';
$string['privacy:metadata:docguard_sec:userid']        = 'The ID of the student whose document this section came from.';
$string['privacy:metadata:docguard_sec:cmid']          = 'The course module (assignment) the section belongs to.';
$string['privacy:metadata:docguard_sec:sectionlabel']  = 'The heading the section was found under, taken from the student\'s document, for example "Question 3".';
$string['privacy:metadata:docguard_sec:wordcount']     = 'How many words the student wrote in this section.';
$string['privacy:metadata:docguard_sec:signalsjson']   = 'Per-section notes recording how many words were set aside as quotation or reference list before this submission was compared with others.';
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
$string['colfile']                  = 'File';
$string['colrisk']                  = 'Risk';
$string['colscore']                 = 'Score';
$string['colsections']              = 'Sections';
$string['colsimilarity']            = 'Similarity';
$string['colstudent']               = 'Student';
$string['colstudenta']              = 'Student A';
$string['colstudentb']              = 'Student B';
$string['coltheirreport']           = 'Their report';
$string['coltheirrisk']             = 'Their risk level';
$string['concernhigh']              = 'Most wording shared — read both';
$string['concernlow']               = 'Some overlap — may be the question, not the student';
$string['concernmedium']            = 'Substantial overlap — compare the two';
$string['crosscopydesc']            = 'DocGuard scans every other student\'s submission for this same activity and measures how much of the text they have in common. A high similarity score means two students\' documents contain large amounts of matching content — this may indicate copying, shared notes, or use of the same source material. Each student\'s report also links to the other for easy side-by-side comparison.';
$string['crosscopyquestion']        = 'Has this student copied from another student in this class?';
$string['crossmethod']              = 'Method: Jaccard bigram similarity on normalised submission text. Matches flagged at &ge;35% similarity.';
$string['crosssimilarity']          = 'Cross-Student Similarity';
$string['crosssimilaritydesc']      = 'Pairs of submissions in this activity with Jaccard bigram similarity &ge; 35% are listed below. High similarity may indicate shared source material, group work, or academic misconduct.';
$string['crosssimilarityheading']   = 'Cross-Student Similarity (S12)';
$string['disabledcmnotice']         = 'DocGuard is not enabled for this activity, so new submissions are not analysed. Tick "Enable DocGuard" in the activity settings to turn it on.';
$string['disabledglobalnotice']     = 'DocGuard is switched off site-wide. Existing results are shown below, but no new analysis will run until an administrator enables it at Site administration → Plugins → Plagiarism → DocGuard.';
$string['filelabel']                = 'File';
$string['insufficienttext']         = 'Insufficient text extracted for similarity comparison.';
$string['interprethigh']            = '<strong>HIGH (65–100%):</strong> Most of this submission\'s wording also appears in another submission to this activity. In testing, a genuine copy with light paraphrasing measured 92%. Open both submissions and read them.';
$string['interpretlow']             = '<strong>LOW (0–34%):</strong> No other submission to this activity shares significant wording with this one. That is all it means. It is not evidence the work is original, and not evidence a person wrote it.';
$string['interpretmedium']          = '<strong>MEDIUM (35–64%):</strong> A substantial amount of wording is shared with another submission. Two students answering the same closed procedural question independently measured 13% in testing, so a figure in this range is worth reading both submissions for — it is not by itself an explanation.';
$string['interpretnote']            = 'A similarity figure is a measurement, not a finding. Two students can share wording because one copied the other, because both worked from the same unit materials, or because the question only has one sensible answer. Open both submissions and read them before you draw a conclusion, and never treat a number from this plugin as the basis for an accusation on its own.';
$string['interpretationguide']      = 'What the percentage means';

/*
 * V1.0.93, revised V1.1.0. The scope note exists because the score above it answers a
 * much narrower question than a teacher would assume from a plugin of this kind.
 *
 * DocGuard holds no external corpus: no web, no published work, no essay bank, no other
 * activity on this site, no previous cohort. The only comparison it makes is against
 * other submissions to THIS activity. A LOW score therefore means "no other submission
 * to this activity shares significant wording with this one", and a teacher who reads it
 * as "checked for plagiarism and clean" has been misled by the absence of this paragraph.
 */
$string['scopeheading']             = 'What was checked';
$string['scopenote']                = 'DocGuard compared this submission against <strong>other submissions to this activity only</strong>. It did <strong>not</strong> check the web, published sources, essay banks, other courses, other cohorts, or this student\'s earlier work. Quotations of 20 words or more and the reference list were removed before comparison, so the figure describes the students\' own prose. <strong>A low score means no other submission to this activity shares significant wording with this one — nothing more.</strong> DocGuard does not estimate whether a submission was written by a language model, and no score it produces is evidence of that.';
$string['scopenoteshort']           = 'Similarity against other submissions to this activity only. No web, published-source, other-course or previous-cohort checking.';
$string['aiindicatorsheading']      = 'Scored under the previous model';
$string['copycheckheading']         = 'Copying check (this activity)';
$string['copycheckclean']           = 'No matching submission found in this activity.';
$string['copycheckfound']           = 'Matching text found with {$a} other submission(s) in this activity.';
$string['norecords']                = 'No DocGuard analysis records found for this activity. Submissions are analysed automatically when students submit files.';
$string['nosectionbreakdown']       = 'No per-section breakdown is stored for this submission. The overall score above was calculated, but the section detail was either never stored or has since been removed. Use the Re-analyse button at the top of this page to rebuild it.';
$string['legacyscorebulk']          = '<strong>{$a} submission(s) on this page were scored under the previous model.</strong> Until DocGuard 1.0.98 the score was a sum of writing-style signals. Those signals were tested against 48 documents written by three current language models and did not detect any of them, so the numbers they produced are not evidence of anything, and they are not comparable with the similarity percentages shown beside them. Use the Re-analyse link on a submission to rescore it against the current model. The copying table above is unaffected: it is computed fresh from the stored text on every page load, for every analysed submission, whichever model scored it.';
$string['pdfextractiontier']        = 'PDF text extraction on this server: {$a->tier} ({$a->quality} quality).';
$string['legacyscore']              = 'Scored under the previous model. This submission was analysed by DocGuard 1.0.98 or earlier, when the score was a sum of writing-style signals. Those signals were tested against 48 documents written by three current language models and did not detect any of them, so the number below is not evidence of anything and is not comparable with scores produced since. Use Re-analyse to rescore this submission against the current model.';
$string['copyevidenceheading']      = 'Copying between submissions';
$string['copyevidencenone']         = 'No other submission to this activity shares significant wording with this one. This is the only check DocGuard performs that compares the submission against anything, and it covers this activity only — not the web, published sources, essay banks, other courses or previous cohorts.';
$string['copyevidencescore']        = 'This submission shares {$a}% of its word pairs with another submission to this activity. Open both and read them before drawing any conclusion: two students answering the same closed question independently measured 13% in testing, and a genuine copy with light paraphrasing measured 92%.';
$string['scopeexcluded']            = 'Scoring scope: {$a->words} words were set aside before the writing-style signals were measured ({$a->reason}). The signals below describe the student\'s own prose only. Quoted material and reference list entries are written by somebody else, so charging the student for their phrasing would be wrong in both directions — it inflates the score for an honest student who quotes carefully, and it dilutes the score for a submission padded with citations.';
$string['nosignalsquoted']          = 'No writing-style signals were measured for this section, because almost all of it is quoted or cited material rather than the student\'s own writing — {$a} words were set aside. This is not a low-risk result and it is not an empty submission: there is simply not enough of the student\'s own prose here to measure. Whether that much quotation is acceptable for this task is a marking judgement, not something DocGuard can answer.';
/*
 * V1.2.0 authenticity findings.
 *
 * Every string here is written to be read by a trainer who may be deciding whether to open a
 * misconduct file, and by a student who may be contesting it. So: no score, no probability, no
 * use of the word "detected" about AI, and the limits stated in the panel itself rather than
 * buried in documentation nobody opens.
 */
$string['authheading']              = 'Authenticity checks';
$string['authtally']                = '{$a->strong} strong, {$a->notable} notable, {$a->context} contextual';
$string['authnofindings']           = 'No authenticity findings. Nothing in this file was recognisable as a chat-assistant artefact, markdown pasted from elsewhere, or an unverifiable reference. This is not a statement that the work is the student\'s own — these checks only find things that are present, and a submission can be written with AI help and leave none of them behind.';
$string['authsevstrong']            = 'Strong';
$string['authsevnotable']           = 'Notable';
$string['authsevcontext']           = 'Context only';
$string['authexpectedyears']        = 'An Act of this name exists, but for the year(s): {$a}. A real Act cited with a year that does not exist is a common signature of a reference that was not looked up.';
$string['authprovheading']          = 'What the file records about how it was made';
$string['authprovnone']             = 'This file carries no usable metadata. That is not a finding either way — plenty of ordinary tools strip it, and its absence says nothing about who wrote the document.';
$string['authquestionsheading']     = 'Verification questions';
$string['authquestionsintro']       = 'Drawn from this submission\'s own wording. If the learner can talk through what they wrote, that settles the question in their favour better than any check above. If they cannot, that is a conversation about competence — which is yours to have, not the software\'s.';
$string['authlimits']               = '<strong>What these checks can and cannot tell you.</strong> They find things that should not be in an assessment file — a chat assistant\'s own words, markdown from a copy-paste, a citation that does not check out — and they report how the file records its own history. They do <strong>not</strong> detect AI writing, and DocGuard makes no attempt to: the writing-style signals this plugin used to ship flagged 0 of 48 answers generated by ChatGPT, Gemini and Claude, while the highest-scoring document in that test was a human student writing in their second language. A learner who pastes only the answer text, with no framing and no markdown, will produce no findings here. Nothing on this panel is a finding of misconduct on its own, and none of it should be put to a learner as one.';
$string['paircapped']               = 'This activity has more analysed submissions than the pairwise table compares at once, so the {$a->shown} most recent of {$a->total} are shown here. The similarity figure beside each individual submission is computed when that submission is analysed and is not affected by this — it covers the whole activity. Open a submission to see its own matches.';
$string['nosimilarities']           = 'No significant cross-student similarities detected.';
$string['nosimilaritiesthreshold']  = 'No significant similarities detected (threshold: &ge;35% Jaccard).';
// V1.1.5. The comparison excludes the text the assessment tool supplied to every student,
// and says so. A trainer taking a pair into a misconduct meeting has to be able to state
// what the figure measures and what it leaves out, and a student has to be able to question
// it. A number nobody can interrogate is not evidence.
$string['comparisonbasis']          = 'Compared across {$a->cohort} submissions, on each student\'s own writing only. Wording carried by {$a->min} or more of these submissions is treated as part of the assessment template and excluded from the comparison ({$a->excluded} phrase pairs excluded here). Cover sheets, student declarations, the question itself and material quoted from the unit resources are not evidence of copying — every student submits them because the assessment tool told them to.';
$string['toofewforcomparison']      = 'Not enough submissions to compare yet — {$a->count} of the {$a->min} needed. Below {$a->min}, wording shared by two submissions cannot be told apart from the assessment template they were both built on, so no similarity figure is reported rather than one that would be wrong. Measured on two independent answers carrying an ordinary cover sheet and declaration, that figure would have read 50.5%. This section fills in as soon as more students submit.';
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
$string['risklevelbannerhigh']      = 'High similarity';
$string['risklevelbannerlow']       = 'No significant similarity';
$string['risklevelbannermedium']    = 'Moderate similarity';
$string['risklevelshorthigh']       = 'HIGH';
$string['risklevelshortlow']        = 'LOW';
$string['risklevelshortmedium']     = 'MED';
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
$string['tooltiphigh']              = 'DocGuard: {$a}% similar to another submission in this activity. Open both and read them.';
$string['tooltiplow']               = 'DocGuard: no other submission to this activity shares significant wording with this one ({$a}%). This is not a check against the web, other courses or previous cohorts.';
$string['tooltipmedium']            = 'DocGuard: {$a}% similar to another submission in this activity. Worth comparing the two.';
$string['tooltippending']           = 'DocGuard is queued to analyse this file. Reload the page in a moment to see the result.';
$string['tooltipstuck']             = 'Analysis has been pending for {$a}. This usually means the Moodle cron scheduler is not running or is running infrequently. Ask your Moodle administrator to check the cron job.';
$string['tooltipstuckreanalyse']    = 'You can also click Re-analyse below to run it now.';
$string['viewreportlink']           = 'View DocGuard Report';
$string['savedconfig'] = 'Settings saved.';
