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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use plagiarism_docguard\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the DocGuard GDPR privacy provider.
 *
 * Every assertion here was taken from an observed run against a real database: the
 * records are inserted, the provider is called, and the rows or exported data that
 * actually resulted are asserted. Where the observed behaviour is wrong, the test
 * asserts what the code really does and the docblock records the defect, so the
 * suite stays a description of the plugin rather than of an intention.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    use \docguard_test_helper;

    /**
     * Collect the provider's metadata and index it by table or external service name.
     *
     * @return array<string, \core_privacy\local\metadata\types\type> The declared items.
     */
    private function collect_metadata(): array {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_docguard'));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }
        return $items;
    }

    /**
     * The provider declares both tables and the vendor service, and every declared key
     * is a column that really exists.
     *
     * @return void
     */
    public function test_metadata_declares_real_columns(): void {
        global $DB;
        $this->resetAfterTest();

        $items = $this->collect_metadata();
        $this->assertEqualsCanonicalizing(
            ['plagiarism_docguard_sub', 'plagiarism_docguard_sec', 'lms_labs'],
            array_keys($items)
        );

        foreach (['plagiarism_docguard_sub', 'plagiarism_docguard_sec'] as $table) {
            $columns = array_keys($DB->get_columns($table));
            foreach (array_keys($items[$table]->get_privacy_fields()) as $field) {
                $this->assertContains($field, $columns, "Declared field {$field} is not a column of {$table}");
            }
        }
    }

    /**
     * Every language string the metadata refers to resolves.
     *
     * @return void
     */
    public function test_metadata_strings_exist(): void {
        $this->resetAfterTest();

        foreach ($this->collect_metadata() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'plagiarism_docguard'),
                'Missing summary string ' . $item->get_summary()
            );
            foreach ($item->get_privacy_fields() as $stringid) {
                $this->assertTrue(
                    get_string_manager()->string_exists($stringid, 'plagiarism_docguard'),
                    'Missing field string ' . $stringid
                );
            }
        }
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-PRIVACY-UNDERDECLARED): every column of both tables
     * must be declared to the privacy registry.
     *
     * Until 1.0.88 get_metadata() declared eight of the sixteen columns of
     * plagiarism_docguard_sub and five of the twelve of plagiarism_docguard_sec, while
     * observer::analyse_and_store() writes all of them for every analysed submission. The
     * omissions included plagiarism_docguard_sec.userid, a direct user identifier in a
     * table the registry described only as "per-section analysis scores"; analysisjson and
     * signalsjson, which quote phrases taken from the student's own writing; section_label,
     * which is text lifted verbatim from their document; and contenthash, derived from
     * their file.
     *
     * What get_metadata() returns is the content of the site's own privacy registry at
     * /admin/tool/dataprivacy — the record of processing an institution publishes, and what
     * a data subject is shown when they ask what a plugin holds — so under-declaring there
     * is a false statement, not a documentation gap. It was already inconsistent with the
     * plugin itself: export_user_data() has returned filetype, errormsg, section_label and
     * wordcount since v1.0.82, none of which the registry mentioned.
     *
     * This test derives the expectation from the live schema rather than from a hard-coded
     * list, so a column added to db/install.xml in future fails here until it is declared.
     *
     * @return void
     */
    public function test_metadata_declares_every_column(): void {
        global $DB;
        $this->resetAfterTest();

        $items = $this->collect_metadata();

        foreach (['plagiarism_docguard_sub', 'plagiarism_docguard_sec'] as $table) {
            $undeclared = array_values(
                array_diff(
                    array_keys($DB->get_columns($table)),
                    array_keys($items[$table]->get_privacy_fields()),
                    ['id']
                    )
            );
            $this->assertSame(
                [],
                $undeclared,
                $table . ' has columns that get_metadata() does not declare: '
                    . implode(', ', $undeclared)
            );
        }
    }

    /**
     * get_contexts_for_userid() returns exactly the module contexts holding the user's
     * submission records, and nothing for a user with none.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $one   = $this->create_docguard_assign();
        $two   = $this->create_docguard_assign();
        $three = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_user();
        $other   = $this->getDataGenerator()->create_user();

        $this->create_sub(['userid' => $student->id, 'cmid' => $one['cm']->id,
            'contextid' => $one['context']->id]);
        $this->create_sub(['userid' => $student->id, 'cmid' => $two['cm']->id,
            'contextid' => $two['context']->id]);
        $this->create_sub(['userid' => $other->id, 'cmid' => $three['cm']->id,
            'contextid' => $three['context']->id]);

        $contextids = provider::get_contexts_for_userid($student->id)->get_contextids();
        $this->assertEqualsCanonicalizing(
            [$one['context']->id, $two['context']->id],
            $contextids
        );

        $stranger = $this->getDataGenerator()->create_user();
        $this->assertSame([], provider::get_contexts_for_userid($stranger->id)->get_contextids());
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-PRIVACY-SECTION-USERID): a user named only by section
     * rows is still found, exported from, and erased.
     *
     * plagiarism_docguard_sec carries its own userid column. Until 1.0.88
     * get_contexts_for_userid() looked only at the submission table, so such a row was
     * invisible to a subject access request and survived erasure — section rows are deleted
     * through their parent submission's id, and the parent belongs to someone else. Those
     * rows hold section_text, up to 65,000 characters of a student's verbatim answer.
     *
     * @return void
     */
    public function test_section_rows_are_discoverable_and_erasable_by_their_own_userid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act    = $this->create_docguard_assign();
        $owner  = $this->getDataGenerator()->create_user();
        $tagged = $this->getDataGenerator()->create_user();

        $subid = $this->create_sub(['userid' => $owner->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $secid = $this->create_sec($subid, ['userid' => $tagged->id, 'cmid' => $act['cm']->id]);

        // Discoverable.
        $this->assertEquals(
            [$act['context']->id],
            provider::get_contexts_for_userid($tagged->id)->get_contextids()
        );

        // Offered to a per-context user deletion request.
        $userlist = new userlist($act['context'], 'plagiarism_docguard');
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$tagged->id, array_map('intval', $userlist->get_userids()));

        // And erased when that user asks, without touching the owner's own record.
        provider::delete_data_for_user(
            new approved_contextlist(
                \core_user::get_user($tagged->id),
                'plagiarism_docguard',
                [$act['context']->id]
                )
        );
        $this->assertFalse($DB->record_exists('plagiarism_docguard_sec', ['id' => $secid]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $subid]));
    }

    /**
     * get_users_in_context() finds the students with records in a module context and
     * returns nothing for a non-module context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act = $this->create_docguard_assign();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $absent = $this->getDataGenerator()->create_user();

        $this->create_sub(['userid' => $one->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sub(['userid' => $two->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);

        $userlist = new userlist($act['context'], 'plagiarism_docguard');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $userlist->get_userids());
        $this->assertNotContains($absent->id, $userlist->get_userids());

        $courselist = new userlist(\context_course::instance($act['course']->id), 'plagiarism_docguard');
        provider::get_users_in_context($courselist);
        $this->assertSame([], $courselist->get_userids());
    }

    /**
     * export_user_data() writes the student's submission rows, including the full
     * extracted text of the document and of every section.
     *
     * @return void
     */
    public function test_export_user_data_includes_extracted_text(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_user();
        $other   = $this->getDataGenerator()->create_user();

        $subid = $this->create_sub([
            'userid'    => $student->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
            'filename'  => 'my-assessment.docx',
            'normtext'  => 'full normalised document text belonging to the student',
            'timecreated' => 1700000000,
        ]);
        $this->create_sec(
            $subid,
            [
                'userid' => $student->id,
                'cmid'   => $act['cm']->id,
                'section_num' => 1,
                'section_label' => 'Question 1',
                'section_text'  => 'verbatim answer to question one',
                ]
        );
        $this->create_sec(
            $subid,
            [
                'userid' => $student->id,
                'cmid'   => $act['cm']->id,
                'section_num' => 2,
                'section_label' => 'Question 2',
                'section_text'  => 'verbatim answer to question two',
                ]
        );
        // A second student's record in the same activity must not leak into the export.
        $othersub = $this->create_sub(['userid' => $other->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'filename' => 'someone-else.docx']);
        $this->create_sec($othersub, ['userid' => $other->id, 'section_text' => 'not mine']);

        $this->export_context_data_for_user($student->id, $act['context'], 'plagiarism_docguard');

        $writer = writer::with_context($act['context']);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'plagiarism_docguard')]);
        $this->assertCount(1, $data->submissions);

        $exported = $data->submissions[0];
        $this->assertSame('my-assessment.docx', $exported['filename']);
        $this->assertSame('full normalised document text belonging to the student', $exported['extracted_text']);
        $this->assertSame(userdate(1700000000), $exported['timecreated']);
        $this->assertCount(2, $exported['sections']);
        $texts = array_column($exported['sections'], 'extracted_text');
        $this->assertEqualsCanonicalizing(
            ['verbatim answer to question one', 'verbatim answer to question two'],
            $texts
        );
        $this->assertNotContains('not mine', $texts);
    }

    /**
     * export_user_data() exports each approved context separately and skips a context
     * in which the student has nothing.
     *
     * @return void
     */
    public function test_export_user_data_across_contexts(): void {
        $this->resetAfterTest();
        $this->enable_docguard();

        $withdata = $this->create_docguard_assign();
        $empty    = $this->create_docguard_assign();
        $student  = $this->getDataGenerator()->create_user();

        $this->create_sub(['userid' => $student->id, 'cmid' => $withdata['cm']->id,
            'contextid' => $withdata['context']->id]);

        $approved = new approved_contextlist(
            \core_user::get_user($student->id),
            'plagiarism_docguard',
            [$withdata['context']->id, $empty['context']->id]
        );
        provider::export_user_data($approved);

        $this->assertTrue(writer::with_context($withdata['context'])->has_any_data());
        $this->assertFalse(writer::with_context($empty['context'])->has_any_data());
    }

    /**
     * delete_data_for_all_users_in_context() removes every submission and section row in
     * that module context and leaves other contexts untouched.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $target = $this->create_docguard_assign();
        $keep   = $this->create_docguard_assign();
        $one    = $this->getDataGenerator()->create_user();
        $two    = $this->getDataGenerator()->create_user();

        foreach ([$one, $two] as $user) {
            $subid = $this->create_sub(['userid' => $user->id, 'cmid' => $target['cm']->id,
                'contextid' => $target['context']->id]);
            $this->create_sec($subid, ['userid' => $user->id, 'cmid' => $target['cm']->id]);
        }
        $keepsub = $this->create_sub(['userid' => $one->id, 'cmid' => $keep['cm']->id,
            'contextid' => $keep['context']->id]);
        $this->create_sec($keepsub, ['userid' => $one->id, 'cmid' => $keep['cm']->id]);

        provider::delete_data_for_all_users_in_context($target['context']);

        $this->assertSame(
            0,
            $DB->count_records(
                'plagiarism_docguard_sub',
                ['contextid' => $target['context']->id]
                )
        );
        $this->assertSame(
            0,
            $DB->count_records(
                'plagiarism_docguard_sec',
                ['cmid' => $target['cm']->id]
                )
        );
        $this->assertSame(
            1,
            $DB->count_records(
                'plagiarism_docguard_sub',
                ['contextid' => $keep['context']->id]
                )
        );
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $keepsub]));
    }

    /**
     * delete_data_for_all_users_in_context() ignores contexts that are not module
     * contexts, so a course-level purge does not touch DocGuard data.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_course_context_is_a_no_op(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act  = $this->create_docguard_assign();
        $user = $this->getDataGenerator()->create_user();
        $this->create_sub(['userid' => $user->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);

        provider::delete_data_for_all_users_in_context(\context_course::instance($act['course']->id));

        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * delete_data_for_user() removes only that student's rows in the approved contexts.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act   = $this->create_docguard_assign();
        $other = $this->create_docguard_assign();
        $student  = $this->getDataGenerator()->create_user();
        $classmate = $this->getDataGenerator()->create_user();

        $mine = $this->create_sub(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sec($mine, ['userid' => $student->id, 'cmid' => $act['cm']->id]);
        $theirs = $this->create_sub(['userid' => $classmate->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sec($theirs, ['userid' => $classmate->id, 'cmid' => $act['cm']->id]);
        $elsewhere = $this->create_sub(['userid' => $student->id, 'cmid' => $other['cm']->id,
            'contextid' => $other['context']->id]);
        $this->create_sec($elsewhere, ['userid' => $student->id, 'cmid' => $other['cm']->id]);

        $approved = new approved_contextlist(
            \core_user::get_user($student->id),
            'plagiarism_docguard',
            [$act['context']->id]
        );
        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $mine]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['subid' => $mine]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $theirs]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $theirs]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $elsewhere]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $elsewhere]));
    }

    /**
     * delete_data_for_users() removes the approved users' rows in that context only.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act   = $this->create_docguard_assign();
        $other = $this->create_docguard_assign();
        $one   = $this->getDataGenerator()->create_user();
        $two   = $this->getDataGenerator()->create_user();
        $three = $this->getDataGenerator()->create_user();

        $subs = [];
        foreach ([$one, $two, $three] as $user) {
            $subs[$user->id] = $this->create_sub(['userid' => $user->id, 'cmid' => $act['cm']->id,
                'contextid' => $act['context']->id]);
            $this->create_sec($subs[$user->id], ['userid' => $user->id, 'cmid' => $act['cm']->id]);
        }
        $elsewhere = $this->create_sub(['userid' => $one->id, 'cmid' => $other['cm']->id,
            'contextid' => $other['context']->id]);

        $approved = new approved_userlist($act['context'], 'plagiarism_docguard', [$one->id, $two->id]);
        provider::delete_data_for_users($approved);

        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $subs[$one->id]]));
        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $subs[$two->id]]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $subs[$three->id]]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['subid' => $subs[$one->id]]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $subs[$three->id]]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $elsewhere]));
    }

    /**
     * delete_data_for_users() with an empty approved list, and with a non-module
     * context, both leave the data alone.
     *
     * @return void
     */
    public function test_delete_data_for_users_edge_cases(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act  = $this->create_docguard_assign();
        $user = $this->getDataGenerator()->create_user();
        $subid = $this->create_sub(['userid' => $user->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sec($subid, ['userid' => $user->id]);

        provider::delete_data_for_users(
            new approved_userlist($act['context'], 'plagiarism_docguard', [])
        );
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));

        provider::delete_data_for_users(
            new approved_userlist(
                \context_course::instance($act['course']->id),
                'plagiarism_docguard',
                [$user->id]
                )
        );
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sub'));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec'));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-ORPHAN-ON-DELETE): deleting the activity deletes the
     * DocGuard records that belonged to it.
     *
     * Until 1.0.88 DocGuard observed no deletion event, so the rows — including normtext,
     * the complete extracted text of the student's document, and section_text, their
     * verbatim answers — stayed in the database for ever AND became unreachable by every
     * GDPR path the site offers. course/lib.php::course_delete_module() deletes the module
     * context before it triggers course_module_deleted, both privacy paths key on
     * contextid, and core_privacy's contextlist_base::get_contexts() silently drops any
     * context it cannot instantiate. Export returned nothing, erasure deleted nothing and
     * reported success, and delete_data_for_all_users_in_context() was never called for a
     * context that no longer existed.
     *
     * @return void
     */
    public function test_records_are_deleted_when_the_activity_is_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid = $this->create_sub(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sec($subid, ['userid' => $student->id, 'cmid' => $act['cm']->id]);

        // A second activity's records must be left completely alone.
        $other = $this->create_docguard_assign();
        $keep  = $this->create_sub(['userid' => $student->id, 'cmid' => $other['cm']->id,
            'contextid' => $other['context']->id]);
        $this->create_sec($keep, ['userid' => $student->id, 'cmid' => $other['cm']->id]);

        // The per-activity config key is dead weight once the activity has gone.
        $this->assertNotFalse(get_config('plagiarism_docguard', 'enabled_cm_' . $act['cm']->id));

        observer::on_course_module_deleted(
            \core\event\course_module_deleted::create([
                'courseid' => $act['course']->id,
                'context'  => $act['context'],
                'objectid' => (int)$act['cm']->id,
                'other'    => ['modulename' => 'assign', 'instanceid' => (int)$act['assign']->id],
                ])
        );

        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $subid]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['subid' => $subid]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['cmid' => $act['cm']->id]));
        $this->assertFalse(get_config('plagiarism_docguard', 'enabled_cm_' . $act['cm']->id));

        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $keep]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $keep]));
        $this->assertNotFalse(get_config('plagiarism_docguard', 'enabled_cm_' . $other['cm']->id));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-ORPHAN-ON-DELETE): the observer is actually registered.
     *
     * The handler above is useless unless Moodle calls it, and db/events.php is the only
     * thing that says it should. This reads the plugin's own registration file rather than
     * triggering the event, because Moodle's PHPUnit runs each test inside a database
     * transaction and core defers non-internal observers until that transaction commits —
     * which never happens in a test — so a triggered event would prove nothing either way.
     * The end-to-end behaviour after a real course_delete_module() is covered by the
     * cleanup task's orphan sweep, in task_cleanup_test.
     *
     * @return void
     */
    public function test_course_module_deleted_observer_is_registered(): void {
        global $CFG;
        $this->resetAfterTest();

        $observers = [];
        include($CFG->dirroot . '/plagiarism/docguard/db/events.php');

        $found = false;
        foreach ($observers as $observer) {
            if ($observer['eventname'] === '\\core\\event\\course_module_deleted') {
                $found = true;
                $this->assertSame(
                    'plagiarism_docguard\\observer::on_course_module_deleted',
                    $observer['callback']
                );
                $this->assertTrue(is_callable($observer['callback']));
            }
        }
        $this->assertTrue($found, 'db/events.php must observe \\core\\event\\course_module_deleted.');
    }

    /**
     * A full round trip on data the plugin itself wrote: analyse a real submission,
     * export it, then delete it, asserting on the rows the plugin actually produced
     * rather than on hand-built ones.
     *
     * @return void
     */
    public function test_round_trip_on_plugin_written_records(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $subid   = $this->create_assign_submission($act['assign'], $student->id);
        $file    = $this->store_submission_file(
            $act['context'],
            $subid,
            'report.docx',
            $this->two_question_docx('privacy')
        );

        observer::analyse_and_store(
            $file,
            (int)$act['cm']->id,
            (int)$student->id,
            $subid,
            (int)$act['context']->id
        );

        $written = $DB->get_record('plagiarism_docguard_sub', ['userid' => $student->id]);
        $this->assertSame('analysed', $written->status);
        $this->assertNotEmpty($written->normtext);
        $this->assertGreaterThan(
            0,
            $DB->count_records(
                'plagiarism_docguard_sec',
                ['subid' => $written->id]
                )
        );

        $contextids = provider::get_contexts_for_userid($student->id)->get_contextids();
        $this->assertEquals([$act['context']->id], $contextids);

        $this->export_context_data_for_user($student->id, $act['context'], 'plagiarism_docguard');
        $data = writer::with_context($act['context'])
            ->get_data([get_string('pluginname', 'plagiarism_docguard')]);
        $this->assertSame($written->normtext, $data->submissions[0]['extracted_text']);
        $this->assertNotEmpty($data->submissions[0]['sections']);

        provider::delete_data_for_user(
            new approved_contextlist(
                \core_user::get_user($student->id),
                'plagiarism_docguard',
                [$act['context']->id]
                )
        );
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sub'));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec'));
    }
}
