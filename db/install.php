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
 * Post-installation hook for plagiarism_docguard.
 *
 * v1.0.80: REMOVED set_config('enableplagiarism', 1).
 *
 * What was wrong: that writes a CORE site setting — the master switch for Moodle's whole
 * plagiarism subsystem, which affects every plagiarism plugin on the site, not just this
 * one. Installing a plugin silently turned on a site-wide feature the administrator had
 * not asked for and would not be told about, and it did so with no way to know the site
 * had deliberately left it off (some institutions disable it for legal reasons). A plugin
 * may write only its own configuration namespace; core settings belong to the
 * administrator.
 *
 * What replaces it: settings.php shows a prominent notice, with a direct link to Advanced
 * features, whenever $CFG->enableplagiarism is off — so the administrator is told exactly
 * what to switch on, and chooses to. plagiarism_docguard_is_enabled() also refuses to
 * process anything while that core switch is off, so nothing runs half-configured.
 *
 * The function is retained (rather than deleting the file) so that Moodle's installer has
 * the hook it expects and any future install-time work has a home.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Post-installation hook for plagiarism_docguard.
 *
 * DocGuard ships switched off and stores student document text, so it deliberately
 * writes no configuration here: an administrator enables it at
 * Site administration > Plugins > Plagiarism > DocGuard.
 *
 * @return bool Always true.
 */
function xmldb_plagiarism_docguard_install() {
    // Nothing to do. DocGuard ships switched OFF and is configured by an administrator
    // at Site administration → Plugins → Plagiarism → DocGuard.
    return true;
}
