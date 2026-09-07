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
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Declare everything DocGuard stores or transmits about a user.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The same collection, with DocGuard's items added.
     */
    public static function get_metadata(collection $collection): collection {
        /*
         * V1.0.88 FIX-DG-PRIVACY-UNDERDECLARED: every column of both tables is now
         * declared, bar the auto-increment ids.
         *
         * The declaration used to list eight of the sixteen columns of
         * plagiarism_docguard_sub and five of the twelve of plagiarism_docguard_sec, while
         * observer::analyse_and_store() writes all of them for every analysed submission.
         * The omissions were not incidental fields:
         *
         *  - plagiarism_docguard_sec.userid is a direct user identifier, undeclared in a
         *    table the registry described only as "per-section analysis scores";
         *  - analysisjson and signalsjson contain the per-signal findings, which quote
         *    phrases lifted out of the student's own writing;
         *  - section_label is text taken verbatim from the student's document;
         *  - contenthash is derived from the submitted file, and errormsg can name it.
         *
         * What get_metadata() returns is not documentation. It is the content of the site's
         * own privacy registry at /admin/tool/dataprivacy — the page an institution prints
         * as its record of processing under Article 30 — and it is what a data subject is
         * shown when they ask what a plugin holds. Under-declaring there is a statement to
         * a regulator that is not true, and it was already inconsistent with the plugin's
         * own behaviour: export_user_data() below has exported filetype, errormsg,
         * section_label and wordcount since v1.0.82, none of which the registry mentioned.
         *
         * The declaration is now derived from the same list db/install.xml defines, so the
         * two cannot drift apart again without a test failing.
         */
        $collection->add_database_table(
            'plagiarism_docguard_sub',
            [
                'userid'            => 'privacy:metadata:docguard_sub:userid',
                'cmid'              => 'privacy:metadata:docguard_sub:cmid',
                'contextid'         => 'privacy:metadata:docguard_sub:contextid',
                'submissionid'      => 'privacy:metadata:docguard_sub:submissionid',
                'filename'          => 'privacy:metadata:docguard_sub:filename',
                'filetype'          => 'privacy:metadata:docguard_sub:filetype',
                'contenthash'       => 'privacy:metadata:docguard_sub:contenthash',
                'section_count'     => 'privacy:metadata:docguard_sub:section_count',
                'overall_riskscore' => 'privacy:metadata:docguard_sub:overall_riskscore',
                'overall_risklevel' => 'privacy:metadata:docguard_sub:overall_risklevel',
                'status'            => 'privacy:metadata:docguard_sub:status',
                'analysisjson'      => 'privacy:metadata:docguard_sub:analysisjson',
                'normtext'          => 'privacy:metadata:docguard_sub:normtext',
                'errormsg'          => 'privacy:metadata:docguard_sub:errormsg',
                'timecreated'       => 'privacy:metadata:docguard_sub:timecreated',
                'timemodified'      => 'privacy:metadata:docguard_sub:timemodified',
                ],
            'privacy:metadata:docguard_sub'
        );

        // FIX-DG-PRIVACY-COLUMNS (v1.0.78): the array keys must be real column
        // names. 'sectionnum'/'sectiontext' do not exist — db/install.xml declares
        // section_num and section_text. The lang string identifiers (the values)
        // are unchanged, so no language pack update is required.
        $collection->add_database_table(
            'plagiarism_docguard_sec',
            [
                'subid'         => 'privacy:metadata:docguard_sec:subid',
                'userid'        => 'privacy:metadata:docguard_sec:userid',
                'cmid'          => 'privacy:metadata:docguard_sec:cmid',
                'section_num'   => 'privacy:metadata:docguard_sec:sectionnum',
                'section_label' => 'privacy:metadata:docguard_sec:sectionlabel',
                'section_text'  => 'privacy:metadata:docguard_sec:sectiontext',
                'wordcount'     => 'privacy:metadata:docguard_sec:wordcount',
                'riskscore'     => 'privacy:metadata:docguard_sec:riskscore',
                'risklevel'     => 'privacy:metadata:docguard_sec:risklevel',
                'signalsjson'   => 'privacy:metadata:docguard_sec:signalsjson',
                'timemodified'  => 'privacy:metadata:docguard_sec:timemodified',
                ],
            'privacy:metadata:docguard_sec'
        );

        // V1.0.80: declare the external service. lib.php makes three outbound calls to
        // lms-labs.com on every site — plugin-unlock/verify, plugin-unlock and
        // plagiarism-settings — and the provider declared only the two local tables, so
        // the site's own privacy registry stated that DocGuard sends nothing anywhere.
        // That is a factual misstatement in the document institutions rely on for their
        // records of processing, and it is exactly what add_external_location_link()
        // exists to record. What actually crosses the wire is the site licence identity,
        // not user data — which is what the strings say, so the entry is accurate rather
        // than defensively vague.
        $collection->add_external_location_link(
            'lms_labs',
            [
                'siteid'   => 'privacy:metadata:lmslabs:siteid',
                'apikey'   => 'privacy:metadata:lmslabs:apikey',
                'pluginid' => 'privacy:metadata:lmslabs:pluginid',
                ],
            'privacy:metadata:lmslabs'
        );

        return $collection;
    }

    /**
     * List the contexts in which this user has DocGuard data.
     *
     * @param int $userid The user to look up.
     * @return contextlist The contexts holding submission or section records for them.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        /*
         * V1.0.88 FIX-DG-PRIVACY-SECTION-USERID: the second SELECT is new.
         *
         * plagiarism_docguard_sec carries its own userid column — it is declared in
         * db/install.xml, written by observer::analyse_and_store(), and now declared to the
         * privacy registry as a user identifier. This method looked only at the submission
         * table, so any section row whose userid did not match its parent submission's was
         * invisible to a subject access request and survived an erasure request: the rows
         * are deleted through their parent's id, and the parent belongs to somebody else.
         *
         * Those rows hold section_text — up to 65,000 characters of the student's verbatim
         * answer — so "invisible and undeletable" is not an acceptable resting state for
         * them however they arise. They should not arise from this plugin's own code, which
         * always writes the two userids the same, but a table with a user identifier in it
         * must be reachable by the user identifier that is in it; anything else relies on
         * an invariant no constraint enforces, across restores, imports, third-party tools
         * and every future change to this plugin.
         *
         * Scoped through {context} on the section's own cmid rather than through its parent
         * record, so it works even when the parent has gone.
         */
        $sql = "SELECT DISTINCT contextid
                  FROM {plagiarism_docguard_sub}
                 WHERE userid = :userid
                 UNION
                SELECT DISTINCT ctx.id AS contextid
                  FROM {plagiarism_docguard_sec} sec
                  JOIN {context} ctx ON ctx.instanceid = sec.cmid AND ctx.contextlevel = :ctxlevel
                 WHERE sec.userid = :secuserid";
        $contextlist->add_from_sql(
            $sql,
            [
                'userid'    => $userid,
                'secuserid' => $userid,
                'ctxlevel'  => CONTEXT_MODULE,
                ]
        );
        return $contextlist;
    }

    /**
     * List the users who have DocGuard data in one context.
     *
     * @param userlist $userlist The userlist to add matching users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        // V1.0.88 FIX-DG-PRIVACY-SECTION-USERID: section rows count too — see
        // get_contexts_for_userid(). A user who appears only there must still be offered
        // to a site-wide "delete data for these users in this context" request.
        $sql = "SELECT userid
                  FROM {plagiarism_docguard_sub}
                 WHERE contextid = :contextid
                 UNION
                SELECT userid
                  FROM {plagiarism_docguard_sec}
                 WHERE cmid = :cmid";
        $userlist->add_from_sql(
            'userid',
            $sql,
            [
                'contextid' => $context->id,
                'cmid'      => $context->instanceid,
                ]
        );
    }

    /**
     * Export this user's DocGuard submission and section records.
     *
     * @param approved_contextlist $contextlist The approved contexts to export from.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $subs = $DB->get_records(
                'plagiarism_docguard_sub',
                [
                    'userid'    => $userid,
                    'contextid' => $context->id,
                    ]
            );
            if (!$subs) {
                continue;
            }
            $export = [];
            foreach ($subs as $sub) {
                $sections = $DB->get_records('plagiarism_docguard_sec', ['subid' => $sub->id]);
                // V1.0.82: export the text columns get_metadata() declares.
                //
                // get_metadata() tells the site's privacy registry that DocGuard holds
                // plagiarism_docguard_sub.normtext and plagiarism_docguard_sec.section_text
                // - the extracted text the whole risk score is computed from. The export
                // returned scores and a filename and withheld both. A subject-access
                // request that under-discloses relative to the controller's own published
                // record of processing is exactly what this API exists to prevent, and it
                // matters most for the student who is contesting a misconduct referral.
                $export[] = [
                    'filename'          => $sub->filename,
                    'filetype'          => $sub->filetype,
                    'overall_riskscore' => $sub->overall_riskscore,
                    'overall_risklevel' => $sub->overall_risklevel,
                    'status'            => $sub->status,
                    'errormsg'          => $sub->errormsg ?? '',
                    'extracted_text'    => $sub->normtext,
                    'timecreated'       => transform::datetime($sub->timecreated),
                    'sections'          => array_values(
                        array_map(function ($sec) {
                            return [
                            // FIX-DG-PRIVACY-COLUMNS (v1.0.78): column is section_num.
                            'sectionnum'     => $sec->section_num,
                            'sectionlabel'   => $sec->section_label ?? '',
                            'wordcount'      => $sec->wordcount ?? null,
                            'riskscore'      => $sec->riskscore,
                            'risklevel'      => $sec->risklevel,
                            'extracted_text' => $sec->section_text,
                            ];
                            }, $sections)
                    ),
                ];
            }
            writer::with_context(
                $context)->export_data(
                    [get_string('pluginname', 'plagiarism_docguard')],
                (object)['submissions' => $export]
            );
        }
    }

    /**
     * Delete every user's DocGuard data in one context.
     *
     * @param \context $context The context being purged.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $subids = $DB->get_fieldset_select(
            'plagiarism_docguard_sub',
            'id',
            'contextid = :contextid',
            ['contextid' => $context->id]
        );
        if ($subids) {
            [$in, $params] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
            $DB->delete_records('plagiarism_docguard_sub', ['contextid' => $context->id]);
        }
        // V1.0.88 FIX-DG-PRIVACY-SECTION-USERID: and any section row that belongs to this
        // activity in its own right, whose parent record has gone or never matched.
        // "Delete everything in this context" has to mean everything.
        $DB->delete_records('plagiarism_docguard_sec', ['cmid' => $context->instanceid]);
    }

    /**
     * Delete one user's DocGuard data in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete from.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $subids = $DB->get_fieldset_select(
                'plagiarism_docguard_sub',
                'id',
                'userid = :userid AND contextid = :contextid',
                ['userid' => $userid, 'contextid' => $context->id]
            );
            if ($subids) {
                [$in, $params] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('plagiarism_docguard_sec', "subid $in", $params);
                $DB->delete_records_select(
                    'plagiarism_docguard_sub',
                    "id $in",
                    $params
                );
            }
            // V1.0.88 FIX-DG-PRIVACY-SECTION-USERID: section rows tagged with this user's
            // id in this activity, whatever record they hang off. Scoped by cmid because
            // that is the only activity key plagiarism_docguard_sec carries.
            if ($context->contextlevel == CONTEXT_MODULE) {
                $DB->delete_records(
                    'plagiarism_docguard_sec',
                    [
                        'userid' => $userid,
                        'cmid'   => $context->instanceid,
                        ]
                );
            }
        }
    }

    /**
     * Delete DocGuard data for the approved users in one context.
     *
     * @param approved_userlist $userlist The approved users and context.
     * @return void
     */
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
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $subids = $DB->get_fieldset_select(
            'plagiarism_docguard_sub',
            'id',
            "userid $in AND contextid = :contextid",
            $params
        );
        if ($subids) {
            [$in2, $params2] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('plagiarism_docguard_sec', "subid $in2", $params2);
            $DB->delete_records_select('plagiarism_docguard_sub', "id $in2", $params2);
        }
        // V1.0.88 FIX-DG-PRIVACY-SECTION-USERID: see delete_data_for_user().
        [$in3, $params3] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'su');
        $params3['seccmid'] = $context->instanceid;
        $DB->delete_records_select(
            'plagiarism_docguard_sec',
            "userid $in3 AND cmid = :seccmid",
            $params3
        );
    }
}
