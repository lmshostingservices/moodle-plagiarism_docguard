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
 * Forces a live unlock check against lms-labs.com and returns to the settings page.
 *
 * v1.0.80: this action used to live inside settings.php. settings.php is now the
 * standard admin settings fragment (see the note at the top of it), and that file is
 * included every time Moodle builds the admin tree — so an outbound HTTP call in it
 * would run on unrelated admin pages and on admin search. The action therefore needs a
 * page of its own; this is it. Site config capability plus sesskey, no rendering of its
 * own, straight back to the settings page with a notification.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/plagiarism/docguard/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

// Drop the cached verdict so check_unlock() really goes out to the vendor.
set_config('unlock_cache_result', '', 'plagiarism_docguard');
set_config('unlock_cache_time', 0, 'plagiarism_docguard');

$unlocked = plagiarism_docguard_check_unlock();

// V1.0.88 FIX-DG-TESTCONNECTION-RETURN-URL: this redirected to
// /admin/settings.php?section=plagiarismdocguard, which is not a page that exists.
//
// core\plugininfo\plagiarism::load_settings() registers each plagiarism plugin as an
// admin_externalpage pointing straight at /plagiarism/<name>/settings.php. admin/settings.php
// refuses anything that is not an admin_settingpage — line 22, `if (empty($settingspage) or
// !($settingspage instanceof admin_settingpage))` — and throws moodle_exception('sectionerror').
//
// So pressing "Test connection" ALWAYS ended on Moodle's "Section error" page, whatever the
// answer was: the administrator lost the notification that says whether the site is licensed,
// which is the entire output of this script, and was left looking at an error that had nothing
// to do with their credentials. This plugin already knew: the v1.0.83 note at the top of
// settings.php records that this exact URL returns "Section error", and settings.php's own save
// handler redirects to /plagiarism/docguard/settings.php. This file was simply never updated to
// match.
$returnurl = new moodle_url('/plagiarism/docguard/settings.php');
redirect(
    $returnurl,
    $unlocked
        ? get_string('testconnection_ok', 'plagiarism_docguard')
        : get_string('testconnection_locked', 'plagiarism_docguard'),
    null,
    $unlocked ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
);
