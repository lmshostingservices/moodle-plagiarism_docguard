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

// The class name below is shared with classes/pluginclass.php on purpose: the
// spl_autoload_register in lib.php loads exactly one of the two per request, whichever
// the presence of the plagiarism_plugin base class selects, so the two declarations can
// never collide at runtime. Only a static scan that reads both files at once sees a
// duplicate, so the check is switched off for this file.
// phpcs:disable Generic.Classes.DuplicateClassName.Found

/**
 * DocGuard plagiarism plugin class — Path A standalone fallback.
 *
 * Loaded by the spl_autoload_register in lib.php only when plagiarism_plugin
 * base class is unavailable (edge case: very early CLI scripts or unit tests
 * that instantiate the class before Moodle's plagiarism stack is bootstrapped).
 *
 * update_status() stub prevents ReflectionException crash on Moodle versions
 * that call new ReflectionMethod() without a try/catch guard. The stub will
 * cause getDeclaringClass() = 'plagiarism_plugin_docguard', but this path
 * should never be reached on a normal web request.
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plagiarism_plugin_docguard {
    /**
     * Legacy plagiarism API hook, retained so Moodle has something to call.
     *
     * DocGuard analyses submissions from the event observer and cron, so there is no
     * per-view status work to do here.
     *
     * @param object $course The course record.
     * @param object $cm     The course module record.
     * @return bool Always true.
     */
    public function update_status($course, $cm) {
        return true;
    }

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

    // V1.0.80: real disclosure, same as the Path B class — see
    // plagiarism_docguard_print_disclosure() in lib.php.
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
