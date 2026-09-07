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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => 'plagiarism_docguard\observer::on_assessable_submitted',
        'priority'  => 0,
        'internal'  => false,
    ],
    [
        // V1.0.88 FIX-DG-ORPHAN-ON-DELETE: DocGuard registered no deletion observer at
        // all, so deleting an activity left every submission and section record it held —
        // extracted document text included — in the database permanently AND beyond the
        // reach of the privacy provider, which keys on a contextid core has just deleted.
        // See observer::on_course_module_deleted() for the full reasoning.
        //
        // 'internal' => false so the callback runs after the surrounding transaction
        // commits: course_delete_module() has already removed the context and the
        // course_modules row by the time it triggers this event, and there is nothing to
        // gain from deleting our rows inside a transaction that may still roll back.
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => 'plagiarism_docguard\observer::on_course_module_deleted',
        'priority'  => 0,
        'internal'  => false,
    ],
];
