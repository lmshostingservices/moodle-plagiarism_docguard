<?php

/**
 * Post-installation hook for plagiarism_docguard.
 *
 * Automatically enables Moodle's global plagiarism support so admins do not
 * need to manually visit Site Administration → Advanced features and tick
 * "Enable plagiarism plugins" before DocGuard becomes accessible.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_docguard_install() {
    set_config('enableplagiarism', 1);
    return true;
}
