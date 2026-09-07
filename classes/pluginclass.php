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
 * DocGuard plagiarism plugin class — Path B (extends plagiarism_plugin).
 *
 * Loaded by the spl_autoload_register in lib.php on first use, by which point
 * plagiarism_plugin is always defined. update_status() is INHERITED from the
 * parent class — getDeclaringClass()->getName() === 'plagiarism_plugin' — so
 * Moodle's plagiarism_update_status() ReflectionMethod check never fires the
 * deprecation notice, regardless of debug level.
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_plugin_docguard extends plagiarism_plugin {
    /**
     * Return the DocGuard badge and report links for one submitted file.
     *
     * @param array $linkarray Moodle plagiarism link data: cmid, userid, file and,
     *                         where present, component and area.
     * @return string HTML for the badge, or the empty string when nothing is shown.
     */
    public function get_links($linkarray) {
        return plagiarism_docguard_get_links($linkarray);
    }

    // V1.0.80: was `return '';` — students were never told their document text is
    // extracted, stored and compared with their classmates'. See
    // plagiarism_docguard_print_disclosure() in lib.php for what is now said and why.
    /**
     * Return the student-facing disclosure shown on the submission form.
     *
     * @param int $cmid The course module the student is submitting to.
     * @return string HTML disclosure, or the empty string when DocGuard is not active here.
     */
    public function print_disclosure($cmid) {
        return plagiarism_docguard_print_disclosure((int)$cmid);
    }

    /**
     * Persist the per-activity "Enable DocGuard" checkbox when an activity is saved.
     *
     * @param object $data The submitted course module form data.
     * @return void
     */
    public function save_form_elements($data) {
        if (!empty($data->coursemodule)) {
            set_config(
                'enabled_cm_' . $data->coursemodule,
                !empty($data->docguard_enabled) ? 1 : 0,
                'plagiarism_docguard'
            );
        }
    }

    /**
     * Legacy per-module form hook.
     *
     * DocGuard adds its checkbox from plagiarism_docguard_coursemodule_standard_elements()
     * in lib.php instead, so nothing is added here.
     *
     * @param object $mform      The course module form.
     * @param object $context    The context the form is being built for.
     * @param string $modulename The activity type, e.g. "assign".
     * @return bool Always false.
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
        return false;
    }
}
