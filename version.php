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
 * plagiarism_docguard file.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// FIX-DG-VERSION-FREEZE (v1.0.78): Releases 1.0.74 to 1.0.77 all shipped the same
// version integer, and db/upgrade.php still carries three separate savepoint blocks
// guarded on it. Moodle only runs a plugin's install/upgrade — and therefore only
// re-syncs db/access.php capabilities via update_capabilities() — when the integer
// below INCREASES. On a site already holding that value, replacing the plugin files
// had no effect whatsoever on capabilities or schema, producing "upgrades" that
// silently changed nothing.
//
// Any future release that touches db/access.php or db/install.xml MUST raise this
// number, and it must always be greater than or equal to the highest savepoint in
// db/upgrade.php (currently 2026081500).
$plugin->component = 'plagiarism_docguard';
$plugin->version   = 2026081500;
$plugin->release   = '1.0.78';
$plugin->requires  = 2022041900;
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [400, 501];
