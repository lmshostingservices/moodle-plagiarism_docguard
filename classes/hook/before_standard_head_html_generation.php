<?php

namespace plagiarism_docguard\hook;

defined('MOODLE_INTERNAL') || die();

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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {

    /**
     * Pre-load plagiarismlib.php so lib.php is always included in Path B context.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
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
