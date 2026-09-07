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

namespace plagiarism_docguard\hook;

/**
 * Hook callback for core\hook\output\before_standard_head_html_generation.
 *
 * FIX-DG-PRELOAD-PLAGIARISMLIB (v1.0.23): Pre-loads plagiarismlib.php so that
 * plagiarism_plugin IS in memory before process_legacy_callbacks() calls
 * get_plugins_with_function() and include_once(lib.php).
 *
 * Hook callbacks (registered in db/hooks.php) are dispatched BEFORE
 * process_legacy_callbacks() runs. By requiring plagiarismlib.php here we
 * ensure class_exists('plagiarism_plugin', false) = TRUE when lib.php is
 * subsequently included → Path B (extends plagiarism_plugin) is selected →
 * update_status() is inherited → getDeclaringClass() = 'plagiarism_plugin' →
 * plagiarism_update_status() else-branch emits no deprecation notice.
 *
 * DocGuard injects nothing into the page head itself; this callback exists
 * purely for the pre-load side-effect.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {
    /**
     * Pre-load plagiarismlib.php so lib.php is always included in Path B context.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook The dispatched
     *              hook instance. DocGuard adds nothing to the page head, so it is not read;
     *              the parameter is required by the hook callback signature.
     * @return void The callback's whole effect is the two require_once() side-effects.
     */
    public static function callback(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $CFG;
        require_once($CFG->libdir . '/plagiarismlib.php');
        // FIX-DG-PRELOAD-LIB (v1.0.30): Also preload lib.php so that
        // plagiarism_docguard_before_standard_top_of_body_html() is in memory before
        // plagiarism_update_status() calls function_exists(). Mirrors EssayGuard
        // FIX-EG-PRELOAD-LIB (v1.2.183). When function_exists() returns TRUE,
        // Moodle calls the function directly and never reaches the ReflectionMethod /
        // update_status() branch → no "update_status() is deprecated" notice.
        require_once(__DIR__ . '/../../lib.php');
    }
}
