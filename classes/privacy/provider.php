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

namespace plagiarism_docguard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\writer;
// FIX-DG-PRIVACY-TRANSFORM (v1.0.78): export_user_data() calls transform::datetime()
// but this class was never imported. Inside namespace plagiarism_docguard\privacy the
// bare name resolved to \plagiarism_docguard\privacy\transform, which does not exist,
// so any GDPR data export including DocGuard data died with "Class not found".
use core_privacy\local\request\transform;

/**
 * Privacy provider for plagiarism_docguard.
 *
 * DocGuard stores per-file submission records (plagiarism_docguard_sub) and
 * per-section analysis records (plagiarism_docguard_sec). Both tables contain
 * userid references and extracted/analysed text so they are subject to GDPR
 * data subject access and erasure requests.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements metadata_provider, plugin_provider, core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('plagiarism_docguard_sub', [
            'userid'            => 'privacy:metadata:docguard_sub:userid',
            'cmid'              => 'privacy:metadata:docguard_sub:cmid',
            'filename'          => 'privacy:metadata:docguard_sub:filename',
            'overall_riskscore' => 'privacy:metadata:docguard_sub:overall_riskscore',
            'overall_risklevel' => 'privacy:metadata:docguard_sub:overall_risklevel',
            'status'            => 'privacy:metadata:docguard_sub:status',
            'normtext'          => 'privacy:metadata:docguard_sub:normtext',
            'timecreated'       => 'privacy:metadata:docguard_sub:timecreated',
        ], 'privacy:metadata:docguard_sub');

        // FIX-DG-PRIVACY-COLUMNS (v1.0.78): the array keys must be real column
        // names. 'sectionnum'/'sectiontext' do not exist — db/install.xml declares
        // section_num and section_text. The lang string identifiers (the values)
        // are unchanged, so no language pack update is required.
        $collection->add_database_table('plagiarism_docguard_sec', [
            'subid'        => 'privacy:metadata:docguard_sec:subid',
            'section_num'  => 'privacy:metadata:docguard_sec:sectionnum',
            'riskscore'    => 'privacy:metadata:docguard_sec:riskscore',
            'risklevel'    => 'privacy:metadata:docguard_sec:risklevel',
            'section_text' => 'privacy:metadata:docguard_sec:sectiontext',
        ], 'privacy:metadata:docguard_sec');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT contextid
                  FROM {plagiarism_docguard_sub}
                 WHERE userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT userid
                  FROM {plagiarism_docguard_sub}
                 WHERE contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $subs = $DB->get_records('plagiarism_docguard_sub', [
                'userid'    => $userid,
                'contextid' => $context->id,
            ]);
            if (!$subs) {
                continue;
            }
            $export = [];
            foreach ($subs as $sub) {
                $sections = $DB->get_records('plagiarism_docguard_sec', ['subid' => $sub->id]);
                $export[] = [
                    'filename'          => $sub->filename,
                    'filetype'          => $sub->filetype,
                    'overall_riskscore' => $sub->overall_riskscore,
                    'overall_risklevel' => $sub->overall_risklevel,
                    'status'            => $sub->status,
                    'timecreated'       => transform::datetime($sub->timecreated),
                    'sections'          => array_values(array_map(function ($sec) {
                        return [
                            // FIX-DG-PRIVACY-COLUMNS (v1.0.78): column is section_num.
                            'sectionnum' => $sec->section_num,
                            'riskscore'  => $sec->riskscore,
                            'risklevel'  => $sec->risklevel,
                        ];
                    }, $sections)),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'plagiarism_docguard')],
                (object)['submissions' => $export]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $subids = $DB->get_fieldset_select(
            'plagiarism_docguard_sub', 'id',
            'contextid = :contextid',
            ['contextid' => $context->id]
        );
        if ($subids) {
            list($in, $params) = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
            $DB->delete_records('plagiarism_docguard_sub', ['contextid' => $context->id]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $subids = $DB->get_fieldset_select(
                'plagiarism_docguard_sub', 'id',
                'userid = :userid AND contextid = :contextid',
                ['userid' => $userid, 'contextid' => $context->id]
            );
            if ($subids) {
                list($in, $params) = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
                $DB->delete_records_select('plagiarism_docguard_sub',
                    "id $in", $params);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($in, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $subids = $DB->get_fieldset_select(
            'plagiarism_docguard_sub', 'id',
            "userid $in AND contextid = :contextid",
            $params
        );
        if ($subids) {
            list($in2, $params2) = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in2", $params2);
            $DB->delete_records_select('plagiarism_docguard_sub', "id $in2", $params2);
        }
    }
}
