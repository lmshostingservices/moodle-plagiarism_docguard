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
 * Scheduled tasks for plagiarism_docguard.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'  => 'plagiarism_docguard\\task\\cleanup',
        'blocking'   => 0,
        'minute'     => 'R',
        'hour'       => '4',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
    [
        // ADD-DG-PROCESS-PENDING (v1.0.69): Re-analyses stuck pending records and
        // scans for submitted files with no DB record (e.g. submissions made before
        // the plugin was installed). Runs every 15 minutes, capped at 15 files/run.
        'classname'  => 'plagiarism_docguard\\task\\process_pending',
        'blocking'   => 0,
        'minute'     => 'R',
        'hour'       => '*',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
];
