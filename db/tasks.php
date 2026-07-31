<?php

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
