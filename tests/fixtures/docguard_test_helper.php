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
 * Shared helpers for the DocGuard integration tests.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Builds the courses, activities, files and DocGuard records the DB-facing tests need.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait docguard_test_helper {
    /**
     * Switch DocGuard and Moodle's plagiarism subsystem on, with a valid licence cache.
     *
     * The licence cache is pre-seeded rather than left to plagiarism_docguard_check_unlock(),
     * which would otherwise attempt a real outbound HTTP call to lms-labs.com.
     *
     * @return void
     */
    protected function enable_docguard(): void {
        set_config('enableplagiarism', 1);
        set_config('enabled', 1, 'plagiarism_docguard');
        set_config('siteid', 'TESTSITE', 'plagiarism_docguard');
        set_config('apikey', 'TESTKEY', 'plagiarism_docguard');
        set_config('unlock_cache_result', 1, 'plagiarism_docguard');
        set_config('unlock_cache_time', time(), 'plagiarism_docguard');
        // Stop plagiarism_docguard_get_platform_settings() reaching the network.
        set_config('platform_settings_data', json_encode([
            'essayguard_assignments' => false,
            'essayguard_quizzes'     => false,
            'docguard_assignments'   => false,
            'docguard_quizzes'       => false,
        ]), 'plagiarism_docguard');
        set_config('platform_settings_time', time(), 'plagiarism_docguard');
    }

    /**
     * Create a course with an assign activity that has DocGuard switched on.
     *
     * @param bool $active Whether to record the per-activity "enabled" preference.
     * @return array{course: \stdClass, cm: \stdClass, context: \context_module, assign: \stdClass}
     */
    protected function create_docguard_assign(bool $active = true): array {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm     = get_coursemodule_from_instance('assign', $assign->id);
        $ctx    = \context_module::instance($cm->id);
        if ($active) {
            set_config('enabled_cm_' . $cm->id, 1, 'plagiarism_docguard');
        }
        return ['course' => $course, 'cm' => $cm, 'context' => $ctx, 'assign' => $assign];
    }

    /**
     * Build a .docx file as a binary string from a list of paragraphs.
     *
     * @param array $paragraphs One string per w:p paragraph.
     * @return string The raw bytes of the .docx container.
     */
    protected function docx_bytes(array $paragraphs): string {
        $path = make_request_directory() . '/helper.docx';
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<w:p><w:r><w:t>' . htmlspecialchars($p, ENT_XML1) . '</w:t></w:r></w:p>';
        }
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '</w:body></w:document>'
        );
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    /**
     * A two-question assessment document long enough for every section to survive parsing.
     *
     * @param string $flavour Distinguishing word woven into the prose so two documents differ.
     * @return string The raw bytes of the .docx container.
     */
    protected function two_question_docx(string $flavour = 'alpha'): string {
        $answer = 'The ' . $flavour . ' workplace hazard was identified during the routine inspection '
            . 'of the loading dock and recorded in the register on the same day. Staff were briefed '
            . 'about the barrier and the revised procedure before the next shift started.';
        return $this->docx_bytes([
            'Question 1: Describe the hazard.',
            $answer,
            'Question 2: Describe the control.',
            $answer . ' A second control was added after the review meeting.',
        ]);
    }

    /**
     * Store a file in an assignsubmission_file area so DocGuard's code paths can find it.
     *
     * @param \context $context Module context of the assignment.
     * @param int $itemid The assign_submission id the file belongs to.
     * @param string $filename Name of the file, including its extension.
     * @param string $content Raw file bytes.
     * @return \stored_file The file as stored.
     */
    protected function store_submission_file(
        \context $context,
        int $itemid,
        string $filename,
        string $content
    ): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'assignsubmission_file',
            'filearea'  => 'submission_files',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $content);
    }

    /**
     * Create a submitted assign_submission row for a student.
     *
     * @param \stdClass $assign The assign instance record.
     * @param int $userid The student.
     * @param int $timemodified Submission time, defaulting to now.
     * @return int The new assign_submission id.
     */
    protected function create_assign_submission(\stdClass $assign, int $userid, int $timemodified = 0): int {
        global $DB;
        // Core puts a unique index on (assignment, userid, groupid, attemptnumber), so a
        // test that needs several submissions for one student gets successive attempts.
        $attempt = $DB->count_records('assign_submission', [
            'assignment' => $assign->id,
            'userid'     => $userid,
            'groupid'    => 0,
        ]);
        $record = (object)[
            'assignment'   => $assign->id,
            'userid'       => $userid,
            'timecreated'  => $timemodified ?: time(),
            'timemodified' => $timemodified ?: time(),
            'status'       => 'submitted',
            'groupid'      => 0,
            'attemptnumber' => $attempt,
            'latest'       => 1,
        ];
        return (int)$DB->insert_record('assign_submission', $record);
    }

    /**
     * Insert the assignsubmission_file row that records a file submission.
     *
     * @param \stdClass $assign The assign instance record.
     * @param int $submissionid The assign_submission id.
     * @param int $numfiles How many files the student submitted.
     * @return int The new assignsubmission_file id.
     */
    protected function create_assignsubmission_file(\stdClass $assign, int $submissionid, int $numfiles = 1): int {
        global $DB;
        return (int)$DB->insert_record('assignsubmission_file', (object)[
            'assignment' => $assign->id,
            'submission' => $submissionid,
            'numfiles'   => $numfiles,
        ]);
    }

    /**
     * Insert a plagiarism_docguard_sub row directly.
     *
     * @param array $overrides Column values to override on top of the defaults.
     * @return int The new record id.
     */
    protected function create_sub(array $overrides = []): int {
        global $DB;
        $now = time();
        $record = (object)array_merge([
            'userid'            => 0,
            'cmid'              => 0,
            'contextid'         => 0,
            'submissionid'      => 0,
            'filename'          => 'essay.docx',
            'filetype'          => 'docx',
            'contenthash'       => sha1('essay'),
            'section_count'     => 1,
            'overall_riskscore' => 12.5,
            'overall_risklevel' => 'low',
            'status'            => 'analysed',
            'analysisjson'      => '{"section_count":1}',
            'normtext'          => 'the normalised extracted text of the student document',
            'errormsg'          => null,
            'timecreated'       => $now,
            'timemodified'      => $now,
        ], $overrides);
        return (int)$DB->insert_record('plagiarism_docguard_sub', $record);
    }

    /**
     * Insert a plagiarism_docguard_sec row directly.
     *
     * @param int $subid Parent submission record id.
     * @param array $overrides Column values to override on top of the defaults.
     * @return int The new record id.
     */
    protected function create_sec(int $subid, array $overrides = []): int {
        global $DB;
        $record = (object)array_merge([
            'subid'         => $subid,
            'userid'        => 0,
            'cmid'          => 0,
            'section_num'   => 1,
            'section_label' => 'Question 1',
            'section_text'  => 'the verbatim answer the student wrote for question one',
            'wordcount'     => 10,
            'riskscore'     => 20.0,
            'risklevel'     => 'low',
            'signalsjson'   => '{}',
            'timemodified'  => time(),
        ], $overrides);
        return (int)$DB->insert_record('plagiarism_docguard_sec', $record);
    }

    /**
     * Delete a course module, whichever API this Moodle offers.
     *
     * V1.2.4. Moodle 5.2 deprecated course_delete_module() in favour of
     * \core_courseformat\local\cmactions::delete(), and on 5.3 the old function emits a
     * deprecation notice that Moodle's own PHPUnit integration reports as an unexpected
     * debugging() call. This plugin supports Moodle 4.4 upwards, so neither call works
     * everywhere and the choice has to be made at runtime.
     *
     * V1.2.6 FIX-DG-CMACTIONS-METHOD: the runtime test is method_exists(), NOT
     * class_exists(), and the difference was a release blocker.
     *
     * 1.2.4 asked `class_exists('\core_courseformat\local\cmactions')`, on the stated
     * reasoning that the class "does not exist before 5.2". It does. On Moodle 4.4 the
     * class is right there, at course/format/classes/local/cmactions.php, carrying exactly
     * one method: rename(). delete() and set_groupmode() were added later. So on the
     * plugin's own declared FLOOR the guard passed, the newer branch was taken, and the
     * suite died with "Call to undefined method
     * core_courseformat\local\cmactions::delete()" - four errors on Moodle 4.4.
     *
     * Nobody saw it because 1.2.4 and 1.2.5 were verified on 5.3 only. The release that
     * said "Moodle 4.4 remains the floor, so no existing site loses support" was never run
     * on 4.4. This is test-only code, so no customer site was ever affected - but it meant
     * the floor could not be verified at all, and an untestable floor is an undefended one.
     *
     * The lesson is narrow and worth keeping: a feature test must ask about the thing being
     * called. The class is not the API. The method is.
     *
     * Not suppressed, and not silenced with assertDebuggingCalled(): a warning Moodle emits
     * is information, and hiding it here is how a plugin arrives at a release where the
     * warning was real.
     *
     * @param int $cmid Course module id.
     * @param \stdClass|null $course The course, needed by the newer API.
     * @return void
     */
    protected function docguard_delete_module(int $cmid, ?\stdClass $course = null): void {
        global $DB;

        if (method_exists('\\core_courseformat\\local\\cmactions', 'delete')) {
            if ($course === null) {
                $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
                $course = get_course($courseid);
            }
            (new \core_courseformat\local\cmactions($course))->delete($cmid);
            return;
        }

        course_delete_module($cmid);
    }

    /**
     * Set a course module's group mode, whichever API this Moodle offers.
     *
     * Same reasoning as docguard_delete_module(), including V1.2.6
     * FIX-DG-CMACTIONS-METHOD: the test is on the METHOD. On Moodle 4.4 the cmactions
     * class exists with only rename(), so a class_exists() guard sends 4.4 down the branch
     * that calls a method it does not have.
     *
     * @param int $cmid Course module id.
     * @param int $groupmode One of NOGROUPS, SEPARATEGROUPS or VISIBLEGROUPS.
     * @return void
     */
    protected function docguard_set_groupmode(int $cmid, int $groupmode): void {
        global $DB;

        if (method_exists('\\core_courseformat\\local\\cmactions', 'set_groupmode')) {
            $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
            (new \core_courseformat\local\cmactions(get_course($courseid)))->set_groupmode($cmid, $groupmode);
            return;
        }

        set_coursemodule_groupmode($cmid, $groupmode);
    }
}
