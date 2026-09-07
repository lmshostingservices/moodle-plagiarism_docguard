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

namespace plagiarism_docguard\task;

/**
 * Scheduled cleanup task for plagiarism_docguard.
 *
 * Prunes extracted normalised text (normtext) from old submission records
 * beyond the configured retention window. Score records and section-level
 * risk scores are retained permanently so teachers can review historical
 * analysis results. Only the raw extracted text is pruned.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Name shown for this task on the scheduled tasks admin page.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('cleanup_task', 'plagiarism_docguard');
    }

    /**
     * Clear extracted text from submission records past the retention window.
     *
     * Risk scores and section analysis are kept so historical reports stay viewable.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $this->purge_orphans();

        // V1.0.84: resolves the unset key to 90, the same answer
        // plagiarism_docguard_retention_days() and settings.php now give. Written inline
        // rather than calling that function because this class is autoloaded and Moodle
        // does not autoload a plugin's lib.php - a scheduled task cannot assume it is
        // loaded. Note `?? 90` happens to be correct here only because get_config() with
        // no second argument returns an array whose missing key IS null; the single-key
        // form returns false, which is why the other call sites needed the helper.
        $cfg = (array) get_config('plagiarism_docguard');
        $raw = $cfg['retentiondays'] ?? null;
        $retentiondays = ($raw === null || $raw === false || $raw === '') ? 90 : (int) $raw;

        if ($retentiondays <= 0) {
            mtrace('DocGuard cleanup: retention set to 0 — pruning disabled.');
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        // Null out the extracted text in old submission records to free up
        // database space. The risk scores and section analysis JSON are kept
        // so historical reports remain viewable.
        $count = $DB->count_records_select(
            'plagiarism_docguard_sub',
            'timecreated < :cutoff AND normtext IS NOT NULL',
            ['cutoff' => $cutoff]
        );
        if ($count > 0) {
            $DB->set_field_select(
                'plagiarism_docguard_sub',
                'normtext',
                null,
                'timecreated < :cutoff AND normtext IS NOT NULL',
                ['cutoff' => $cutoff]
            );
        }

        // V1.0.82: prune the SECTION text as well.
        //
        // This task nulled plagiarism_docguard_sub.normtext and nothing else, while
        // plagiarism_docguard_sec.section_text holds up to 65,000 characters of the
        // student's verbatim answer PER SECTION - by far the larger store of their text,
        // and the one student_report.php renders back under "Student's Answer".
        //
        // Meanwhile print_disclosure() tells every student at submission time that "the
        // extracted text is deleted automatically after N days". It was not. The section
        // rows stayed indefinitely and stayed visible, and the mtrace line below reported
        // the retention policy as honoured. That is a stated-policy breach on the exact
        // record that matters, not a housekeeping gap.
        //
        // Scoped through the parent submission's timecreated, because _sec has no
        // timestamp of its own. Scores, labels and word counts are kept so historical
        // reports still render - only the text goes.
        $seccount = $DB->count_records_select(
            'plagiarism_docguard_sec',
            'section_text IS NOT NULL AND subid IN '
                . '(SELECT id FROM {plagiarism_docguard_sub} WHERE timecreated < :cutoff)',
            ['cutoff' => $cutoff]
        );
        if ($seccount > 0) {
            $DB->set_field_select(
                'plagiarism_docguard_sec',
                'section_text',
                null,
                'section_text IS NOT NULL AND subid IN '
                    . '(SELECT id FROM {plagiarism_docguard_sub} WHERE timecreated < :cutoff)',
                ['cutoff' => $cutoff]
            );
        }

        mtrace(
            "DocGuard cleanup: cleared extracted text from {$count} submission record(s) "
                . "and {$seccount} section record(s) older than {$retentiondays} days."
        );
    }

    /**
     * Delete DocGuard records whose course module no longer exists.
     *
     * V1.0.88 FIX-DG-ORPHAN-ON-DELETE, second half.
     *
     * classes/observer.php now handles the activity-deletion case as it happens, but that
     * event does not cover everything:
     *
     *  - COURSE deletion never fires it. lib/moodlelib.php::remove_course_contents() calls
     *    each module's *_delete_instance(), then context_helper::delete_instance() and
     *    delete_records('course_modules', …) directly. No \core\event\course_module_deleted
     *    is triggered anywhere in that function, so deleting a course would otherwise strand
     *    every DocGuard record for every assignment in it.
     *  - Rows already stranded by every release before this one are not reachable by an
     *    event that has already been and gone.
     *
     * Both are the same shape — a record whose cmid has no {course_modules} row — and both
     * are fixed by the same sweep. Doing it here, in a task that already runs nightly, is
     * also what makes the fix self-healing on existing client sites rather than dependent
     * on the upgrade step alone.
     *
     * Deliberately keyed on cmid and not on contextid: by the time a record is orphaned its
     * context row is gone, which is exactly why the privacy provider cannot see it.
     *
     * NOT EXISTS rather than a NOT IN list: on a large site {course_modules} holds tens of
     * thousands of rows and an IN clause built from them would be neither portable nor
     * bounded. Records with cmid = 0 are excluded — those are malformed rather than
     * orphaned, and deleting data on the strength of a zero is not a judgement a cleanup
     * task should make.
     *
     * @return void
     */
    private function purge_orphans(): void {
        global $DB;

        $orphanwhere = 'cmid > 0 AND NOT EXISTS ('
            . 'SELECT 1 FROM {course_modules} cm WHERE cm.id = {plagiarism_docguard_sub}.cmid)';

        // Section rows go via their parent's id so the child is never left dangling, and
        // then by their own cmid so a section whose parent has already gone is caught too.
        $subids = $DB->get_fieldset_select('plagiarism_docguard_sub', 'id', $orphanwhere, []);
        if ($subids) {
            [$in, $params] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
            $DB->delete_records_select('plagiarism_docguard_sub', $orphanwhere, []);
        }
        $secorphans = $DB->count_records_select(
            'plagiarism_docguard_sec',
            'cmid > 0 AND NOT EXISTS ('
                . 'SELECT 1 FROM {course_modules} cm WHERE cm.id = {plagiarism_docguard_sec}.cmid)',
            []
        );
        if ($secorphans > 0) {
            $DB->delete_records_select(
                'plagiarism_docguard_sec',
                'cmid > 0 AND NOT EXISTS ('
                    . 'SELECT 1 FROM {course_modules} cm WHERE cm.id = {plagiarism_docguard_sec}.cmid)',
                []
            );
        }

        mtrace(
            'DocGuard cleanup: removed ' . count($subids) . ' submission record(s) and '
                . $secorphans . ' extra section record(s) belonging to deleted activities.'
        );
    }
}
