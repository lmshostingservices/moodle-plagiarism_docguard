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
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/db/upgrade.php');
require_once(__DIR__ . '/fixtures/docguard_test_helper.php');

/**
 * Integration tests for the v1.0.88 upgrade step.
 *
 * The step repairs rows that previous releases stored wrongly, so it is run here against a
 * real database with real rows rather than reasoned about. Both halves are also written to
 * be idempotent, which is asserted, because an administrator who re-runs an upgrade — or a
 * site that took an intermediate release — must not get a different answer the second time.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_plagiarism_docguard_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    use \docguard_test_helper;

    /**
     * Run the upgrade from the version immediately before this release.
     *
     * The recorded plugin version is put back to the previous savepoint first, so
     * upgrade_plugin_savepoint() sees a genuine upgrade rather than a downgrade.
     *
     * @return void
     */
    private function run_upgrade(): void {
        set_config('version', 2026082800, 'plagiarism_docguard');
        \core_upgrade_time::record_start();
        // Upgrade_plugin_savepoint() prints its own progress line; captured so the test
        // is not marked risky for output it does not own.
        ob_start();
        xmldb_plagiarism_docguard_upgrade(2026082800);
        ob_end_clean();
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-ORPHAN-ON-DELETE): the upgrade removes the records left
     * behind by activities deleted before the observer existed.
     *
     * Those rows hold normtext and section_text — the student's extracted document text and
     * their verbatim answers — and are unreachable by every privacy path, because both key
     * on a contextid core deleted along with the activity. Records belonging to activities
     * that still exist, and records with cmid = 0, are left alone.
     *
     * @return void
     */
    public function test_upgrade_purges_orphaned_records(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $live    = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $orphan = $this->create_sub(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id]);
        $this->create_sec($orphan, ['userid' => $student->id, 'cmid' => $act['cm']->id]);
        $stray = $this->create_sec(0, ['userid' => $student->id, 'cmid' => $act['cm']->id]);

        $kept = $this->create_sub(['userid' => $student->id, 'cmid' => $live['cm']->id,
            'contextid' => $live['context']->id]);
        $this->create_sec($kept, ['userid' => $student->id, 'cmid' => $live['cm']->id]);

        $malformed = $this->create_sub(['userid' => $student->id, 'cmid' => 0, 'contextid' => 0]);

        // Delete the activity the way a pre-1.0.88 site would have: the rows are simply
        // left behind, because nothing observed the deletion.
        $DB->delete_records('course_modules', ['id' => $act['cm']->id]);

        $this->run_upgrade();

        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $orphan]));
        $this->assertSame(0, $DB->count_records('plagiarism_docguard_sec', ['subid' => $orphan]));
        $this->assertFalse($DB->record_exists('plagiarism_docguard_sec', ['id' => $stray]));

        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $kept]));
        $this->assertSame(1, $DB->count_records('plagiarism_docguard_sec', ['subid' => $kept]));
        $this->assertTrue(
            $DB->record_exists('plagiarism_docguard_sub', ['id' => $malformed]),
            'A cmid of 0 is malformed, not orphaned — the step must not delete it.'
        );

        // Idempotent.
        $this->run_upgrade();
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $kept]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $malformed]));
    }

    /**
     * REGRESSION (V1.0.88 FIX-DG-BACKFILL-FILE-GRANULARITY): the upgrade clears the legacy
     * submission-wide "unsupported" markers.
     *
     * Those rows carry an empty contenthash, which was the only thing that could satisfy
     * the backfill's old (cmid, userid) exclusion. The exclusion is now keyed on the
     * content hash, against which an empty hash matches nothing — so left in place they
     * would suppress nothing while sitting in the table for ever. Deleting them lets the
     * backfill re-derive one marker per file, with the file's real name and hash.
     *
     * They carry no analysis, no score and no text, so the deletion loses nothing. Markers
     * written by the new code, and every real analysis record, are untouched.
     *
     * @return void
     */
    public function test_upgrade_clears_legacy_unsupported_markers(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_docguard();

        $act     = $this->create_docguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $base    = ['userid' => $student->id, 'cmid' => $act['cm']->id, 'contextid' => $act['context']->id];

        $legacy = $this->create_sub(
            $base + [
                'status'      => 'unsupported',
                'filename'    => '',
                'filetype'    => 'unsupported',
                'contenthash' => '',
                'normtext'    => null,
                ]
        );
        $perfile = $this->create_sub(
            $base + [
                'status'      => 'unsupported',
                'filename'    => 'notes.txt',
                'filetype'    => 'unsupported',
                'contenthash' => sha1('notes.txt'),
                'normtext'    => null,
                ]
        );
        $analysed = $this->create_sub($base);

        $this->run_upgrade();

        $this->assertFalse($DB->record_exists('plagiarism_docguard_sub', ['id' => $legacy]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $perfile]));
        $this->assertTrue($DB->record_exists('plagiarism_docguard_sub', ['id' => $analysed]));

        // Idempotent.
        $this->run_upgrade();
        $this->assertSame(2, $DB->count_records('plagiarism_docguard_sub'));
    }

    /**
     * The version integer in version.php must never be lower than the highest savepoint in
     * db/upgrade.php, and this release's step must be reachable from it.
     *
     * v1.0.87 shipped 2026082905 with no savepoint above 2026082800, which produced an
     * upgrade that ran no steps at all — the mistake FIX-DG-VERSION-FREEZE exists to
     * prevent and which the version.php comment block warns about.
     *
     * @return void
     */
    public function test_version_is_not_below_the_highest_savepoint(): void {
        global $CFG;
        $this->resetAfterTest();

        $plugin = new \stdClass();
        include($CFG->dirroot . '/plagiarism/docguard/version.php');

        $source = file_get_contents($CFG->dirroot . '/plagiarism/docguard/db/upgrade.php');
        preg_match_all("/upgrade_plugin_savepoint\(true, (\d+),/", $source, $matches);
        $this->assertNotEmpty($matches[1]);
        $highest = max(array_map('intval', $matches[1]));

        $this->assertGreaterThanOrEqual($highest, (int)$plugin->version);

        /*
         * V1.0.92: this used to assert a hard-coded literal, which meant every release that
         * added a savepoint had to remember to edit a test whose subject is "the version and
         * the savepoints agree" — and a release that forgot turned four CI jobs red for a
         * reason unrelated to the change being made. The invariant worth testing is the
         * relationship, not the number, and it is asserted above and below.
         */
        $this->assertSame(
            (int)$plugin->version,
            $highest,
            'The newest savepoint must match $plugin->version exactly: a version bump with no '
                . 'savepoint above the site\'s recorded version produces an upgrade that runs '
                . 'no steps, and a savepoint above $plugin->version can never be reached.'
        );
    }
}
