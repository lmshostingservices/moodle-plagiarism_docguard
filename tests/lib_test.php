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

namespace plagiarism_docguard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the shared helpers in lib.php.
 *
 * These cover the three gates every entry point into DocGuard's processing and reporting
 * has to pass: is this site licensed at all, may this viewer see this student's work, and
 * which submission does a student's work actually belong to. Each was previously written
 * out by hand at some call sites and omitted at others, which is how the defects fixed in
 * v1.0.88 arose.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::plagiarism_docguard_has_credentials
 * @covers     ::plagiarism_docguard_user_visible
 * @covers     ::plagiarism_docguard_find_submissionid
 */
final class lib_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * REGRESSION (V1.0.88 FIX-DG-MANUAL-PATHS-UNLICENSED): the shared credentials test
     * the three re-analyse endpoints now use.
     *
     * Until 1.0.88 the licence gate was applied by the event observer, by the cron
     * backfill and by the scan_activity task — and by none of reanalyse.php,
     * report.php?dg_action=reanalyse_pending or student_report.php?reanalyse=1. Since
     * v1.0.85 a site with no Site ID and no API Key is deliberately unlicensed and must
     * not analyse; a teacher on such a site could still press Re-analyse and have the
     * plugin extract and store the full text of a student's document, which is exactly the
     * processing print_disclosure() had just stopped telling that student was happening.
     *
     * @return void
     */
    public function test_has_credentials(): void {
        $this->resetAfterTest();

        // Nothing configured at all: get_config() returns false, not null, for a key that
        // was never saved — the trap this plugin has been caught by repeatedly.
        $this->assertFalse(get_config('plagiarism_docguard', 'siteid'));
        $this->assertFalse(plagiarism_docguard_has_credentials());

        set_config('siteid', 'SITE-1', 'plagiarism_docguard');
        $this->assertFalse(plagiarism_docguard_has_credentials(), 'A Site ID alone is not enough.');

        set_config('apikey', 'KEY-1', 'plagiarism_docguard');
        $this->assertTrue(plagiarism_docguard_has_credentials());

        // An empty string saved deliberately counts as absent.
        set_config('apikey', '', 'plagiarism_docguard');
        $this->assertFalse(plagiarism_docguard_has_credentials());

        // Credentials published by local_aiconfig are honoured, as the accessors do.
        unset_config('siteid', 'plagiarism_docguard');
        unset_config('apikey', 'plagiarism_docguard');
        set_config('siteid', 'SHARED', 'local_aiconfig');
        set_config('apikey', 'SHAREDKEY', 'local_aiconfig');
        $this->assertTrue(plagiarism_docguard_has_credentials());
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-REANALYSE-PENDING-GROUPS): in a SEPARATEGROUPS activity a
     * teacher may only act on their own group's students.
     *
     * This check had been written out by hand three times — report.php's page body in
     * v1.0.80, student_report.php in v1.0.80/v1.0.81, reanalyse.php in v1.0.84 — and the
     * fourth door, the "Analyse" action inside report.php, was missed every time. It
     * checked only can_view_reports() and that the record's cmid matched, so a teacher
     * restricted to one group could put any subid belonging to the activity in the query
     * string and trigger re-extraction and re-storage of another group's student's document
     * text. All four now ask this one function.
     *
     * @return void
     */
    public function test_user_visible_under_separate_groups(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $course  = $act['course'];
        $context = $act['context'];

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // A NON-EDITING teacher: core grants moodle/site:accessallgroups to the
        // editingteacher and manager archetypes (lib/db/access.php), so an editing teacher
        // would pass this check for every group and prove nothing. Markers and tutors on
        // the non-editing role are exactly the people separate groups restricts.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $mine    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $theirs  = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $theirs->id]);

        $cm = get_coursemodule_from_id('assign', $act['cm']->id, 0, false, MUST_EXIST);

        // NOGROUPS: the mode says everyone may see everyone.
        $this->setUser($teacher);
        $this->assertTrue(plagiarism_docguard_user_visible($cm, $context, (int)$theirs->id));

        set_coursemodule_groupmode($cm->id, SEPARATEGROUPS);
        \course_modinfo::purge_course_module_cache($course->id, $cm->id);
        rebuild_course_cache($course->id, true);
        $cm = get_coursemodule_from_id('assign', $act['cm']->id, 0, false, MUST_EXIST);

        $this->setUser($teacher);
        $this->assertTrue(
            plagiarism_docguard_user_visible($cm, $context, (int)$mine->id),
            'A teacher must still reach their own group.'
        );
        $this->assertFalse(
            plagiarism_docguard_user_visible($cm, $context, (int)$theirs->id),
            'A separate-groups teacher must not reach another group.'
        );
        $this->assertTrue(
            plagiarism_docguard_user_visible($cm, $context, (int)$teacher->id),
            'A user always passes for themselves.'
        );

        // The moodle/site:accessallgroups capability is what the mode itself defers to.
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->setUser($manager);
        $this->assertTrue(plagiarism_docguard_user_visible($cm, $context, (int)$theirs->id));
    }

    /**
     * VISIBLEGROUPS means everyone may see everyone, so nothing is restricted.
     *
     * @return void
     */
    public function test_user_visible_under_visible_groups(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act    = $this->create_docguard_assign();
        $course = $act['course'];
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        set_coursemodule_groupmode($act['cm']->id, VISIBLEGROUPS);
        \course_modinfo::purge_course_module_cache($course->id, $act['cm']->id);
        rebuild_course_cache($course->id, true);
        $cm = get_coursemodule_from_id('assign', $act['cm']->id, 0, false, MUST_EXIST);

        $this->setUser($teacher);
        $this->assertTrue(plagiarism_docguard_user_visible($cm, $act['context'], (int)$student->id));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-FIND-SUBMISSION-ATTEMPTS): resolving a student's current
     * submission survives an activity with more than one attempt.
     *
     * This asked get_record() for (assignment, userid) alone. mod_assign keeps one
     * assign_submission row per attempt — attemptnumber plus a `latest` flag, with
     * assign::add_attempt() inserting a further row each time a teacher allows another try
     * — so the second attempt made get_record() throw dml_multiple_records_exception, and
     * a group submission (userid = 0) matched too.
     *
     * @return void
     */
    public function test_find_submissionid_with_multiple_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $cmid    = (int)$act['cm']->id;

        $this->assertSame(0, plagiarism_docguard_find_submissionid($cmid, (int)$student->id));

        $first = $this->create_assign_submission($act['assign'], (int)$student->id);
        $this->assertSame($first, plagiarism_docguard_find_submissionid($cmid, (int)$student->id));

        // A second attempt, as mod_assign records one: the earlier row stops being latest.
        $second = $this->create_assign_submission($act['assign'], (int)$student->id);
        $DB->set_field('assign_submission', 'latest', 0, ['id' => $first]);

        $this->assertSame(
            $second,
            plagiarism_docguard_find_submissionid($cmid, (int)$student->id),
            'The current attempt is the one mod_assign flags as latest.'
        );

        // Nonsense input is answered, not thrown at.
        $this->assertSame(0, plagiarism_docguard_find_submissionid(0, (int)$student->id));
        $this->assertSame(0, plagiarism_docguard_find_submissionid($cmid, 0));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-TESTCONNECTION-RETURN-URL): the plugin's settings page is
     * an admin_externalpage at /plagiarism/docguard/settings.php, NOT a section of
     * /admin/settings.php.
     *
     * testconnection.php used to redirect to /admin/settings.php?section=plagiarismdocguard.
     * core\plugininfo\plagiarism::load_settings() registers each plagiarism plugin as an
     * admin_externalpage pointing straight at the plugin's own settings.php, and
     * admin/settings.php throws moodle_exception('sectionerror') for anything that is not
     * an admin_settingpage. So "Test connection" always finished on Moodle's error page and
     * the administrator never saw its answer — which is the entire output of that script.
     *
     * This pins the core contract the corrected redirect relies on.
     *
     * @return void
     */
    public function test_settings_page_is_an_external_page_not_a_settings_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableplagiarism', 1);

        // The plagiarism category is only built for a user with moodle/site:config, and
        // the plugin nodes hang off it, so the full tree has to be requested.
        $page = admin_get_root(true, true)->locate('plagiarismdocguard');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertNotInstanceOf(
            \admin_settingpage::class,
            $page,
            'admin/settings.php only renders an admin_settingpage; anything else throws sectionerror.'
        );
        $this->assertStringEndsWith('/plagiarism/docguard/settings.php', $page->url);
    }
}
