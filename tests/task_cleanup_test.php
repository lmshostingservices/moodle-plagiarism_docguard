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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');
require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the nightly retention task.
 *
 * The retention promise is made to students in print_disclosure() ("deleted
 * automatically after N days"), so these tests check both that the pruning happens and
 * that the number the task uses is the number the student was told.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\task\cleanup
 */
final class task_cleanup_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Run the cleanup task and return its output.
     *
     * @return string The captured mtrace output.
     */
    private function run_task(): string {
        ob_start();
        (new cleanup())->execute();
        return (string)ob_get_clean();
    }

    /**
     * Create one submission record of a given age, with a section row.
     *
     * @param int $daysold How many days ago the record was created.
     * @return int The submission record id.
     */
    private function aged_record(int $daysold): int {
        $subid = $this->create_sub([
            'timecreated'  => time() - ($daysold * DAYSECS),
            'timemodified' => time() - ($daysold * DAYSECS),
            'normtext'     => 'extracted document text aged ' . $daysold . ' days',
        ]);
        $this->create_sec($subid, ['section_text' => 'section text aged ' . $daysold . ' days']);
        return $subid;
    }

    /**
     * With no retention setting saved, the task uses 90 days: older text goes, newer
     * text stays, and the section text goes with it.
     *
     * @return void
     */
    public function test_default_retention_is_ninety_days(): void {
        global $DB;
        $this->resetAfterTest();

        $old   = $this->aged_record(120);
        $edge  = $this->aged_record(91);
        $young = $this->aged_record(89);

        $output = $this->run_task();

        $this->assertStringContainsString('older than 90 days', $output);
        $this->assertNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $old]));
        $this->assertNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $edge]));
        $this->assertNotNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $young]));

        $this->assertNull($DB->get_field('plagiarism_docguard_sec', 'section_text', ['subid' => $old]));
        $this->assertNotNull(
            $DB->get_field(
                'plagiarism_docguard_sec',
                'section_text',
                ['subid' => $young]
                )
        );
        $this->assertStringContainsString('2 submission record(s) and 2 section record(s)', $output);
    }

    /**
     * Scores, labels and word counts survive the prune so historical reports stay
     * readable — only the student's text is removed.
     *
     * @return void
     */
    public function test_only_text_is_pruned(): void {
        global $DB;
        $this->resetAfterTest();

        $subid = $this->aged_record(200);
        $this->run_task();

        $sub = $DB->get_record('plagiarism_docguard_sub', ['id' => $subid]);
        $this->assertEquals(12.5, $sub->overall_riskscore);
        $this->assertSame('low', $sub->overall_risklevel);
        $this->assertSame('analysed', $sub->status);
        $this->assertSame('essay.docx', $sub->filename);
        $this->assertNotNull($sub->analysisjson);

        $sec = $DB->get_record('plagiarism_docguard_sec', ['subid' => $subid]);
        $this->assertEquals(20.0, $sec->riskscore);
        $this->assertSame('Question 1', $sec->section_label);
        $this->assertEquals(10, $sec->wordcount);
    }

    /**
     * A retention setting of zero disables pruning entirely, which is what the setting
     * description ("0 = keep forever") promises.
     *
     * @return void
     */
    public function test_zero_retention_disables_pruning(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'plagiarism_docguard');

        $subid = $this->aged_record(3650);
        $output = $this->run_task();

        $this->assertStringContainsString('pruning disabled', $output);
        $this->assertNotNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $subid]));
        $this->assertNotNull(
            $DB->get_field(
                'plagiarism_docguard_sec',
                'section_text',
                ['subid' => $subid]
                )
        );
    }

    /**
     * A configured retention window is honoured exactly.
     *
     * @return void
     */
    public function test_configured_retention_window(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 30, 'plagiarism_docguard');

        $old   = $this->aged_record(31);
        $young = $this->aged_record(29);

        $output = $this->run_task();

        $this->assertStringContainsString('older than 30 days', $output);
        $this->assertNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $old]));
        $this->assertNotNull($DB->get_field('plagiarism_docguard_sub', 'normtext', ['id' => $young]));
    }

    /**
     * The task, the shared helper and the student-facing disclosure all resolve an
     * unsaved retention setting to the same number.
     *
     * @return void
     */
    public function test_task_helper_and_disclosure_agree_on_the_default(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $this->assertSame(90, plagiarism_docguard_retention_days());

        $act = $this->create_docguard_assign();
        $disclosure = plagiarism_docguard_print_disclosure((int)$act['cm']->id);
        $this->assertStringContainsString('90', $disclosure);

        $this->aged_record(100);
        $this->assertStringContainsString('older than 90 days', $this->run_task());
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-ORPHAN-ON-DELETE): the cleanup task removes DocGuard
     * records whose activity no longer exists.
     *
     * Until 1.0.88 nothing removed them. The observer added in this release covers an
     * activity being deleted on its own, but it cannot cover everything: deleting a COURSE
     * never fires \core\event\course_module_deleted at all —
     * lib/moodlelib.php::remove_course_contents() calls each module's *_delete_instance(),
     * then context_helper::delete_instance() and delete_records('course_modules', …)
     * directly, and triggers no per-module event — and no event can reach rows that were
     * already stranded by earlier releases.
     *
     * Both are the same shape, a record whose cmid has no {course_modules} row, and this
     * nightly sweep is what makes the fix self-healing on an existing site. It also runs
     * regardless of the retention setting, because the point is not that the text is old:
     * it is that the rows are unreachable by the privacy provider, which keys on a context
     * core has already deleted.
     *
     * Driven through core's real course_delete_module() rather than a hand-built event,
     * with retention pruning irrelevant (the record is one day old).
     *
     * @return void
     */
    public function test_orphaned_records_are_purged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_sub([
            'userid'      => $student->id,
            'cmid'        => $act['cm']->id,
            'contextid'   => $act['context']->id,
            'timecreated' => time(),
        ]);
        $this->create_sec($subid, ['userid' => $student->id, 'cmid' => $act['cm']->id]);

        // A live activity's records must survive the sweep untouched.
        $live    = $this->create_docguard_assign();
        $livesub = $this->create_sub([
            'userid'      => $student->id,
            'cmid'        => $live['cm']->id,
            'contextid'   => $live['context']->id,
            'timecreated' => time(),
        ]);
        $this->create_sec($livesub, ['userid' => $student->id, 'cmid' => $live['cm']->id]);

        // A stray section row whose parent record has already gone is caught too — it
        // still holds the student's answer text.
        $strayid = $this->create_sec(0, ['userid' => $student->id, 'cmid' => $act['cm']->id]);

        course_delete_module((int)$act['cm']->id);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $act['cm']->id]));

        $output = $this->run_task();

        $this->assertStringContainsString('belonging to deleted activities', $output);
        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $subid]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['subid' => $subid]));
        $this->assertFalse($DB->record_exists('plagiarism_docguard_sec', ['id' => $strayid]));

        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $livesub]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $livesub]));
    }

    /**
     * The orphan sweep never touches a record whose cmid is 0. Those are malformed rather
     * than orphaned, and deleting student text on the strength of a zero is not a
     * judgement a housekeeping task should make.
     *
     * @return void
     */
    public function test_orphan_purge_ignores_zero_cmid_records(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $subid = $this->create_sub(['cmid' => 0, 'contextid' => 0, 'timecreated' => time()]);
        $secid = $this->create_sec($subid, ['cmid' => 0]);

        $this->run_task();

        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $subid]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sec', ['id' => $secid]));
    }

    /**
     * The task reports a name from the language pack.
     *
     * @return void
     */
    public function test_get_name(): void {
        $this->resetAfterTest();
        $name = (new cleanup())->get_name();
        $this->assertNotEmpty($name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
