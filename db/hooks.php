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
 * Hook callbacks for plagiarism_docguard.
 *
 * Registers the before_standard_top_of_body_html_generation callback with
 * Moodle's hook system (Moodle 4.3+), replacing the legacy update_status()
 * class method (Moodle 4.0–4.2). The callback is a no-op for DocGuard —
 * it exists so Moodle's process_legacy_callbacks() never emits a deprecation
 * warning about a missing hook registration.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // FIX-DG-PRELOAD-PLAGIARISMLIB (v1.0.23): Pre-load plagiarismlib.php before
        // process_legacy_callbacks() includes lib.php via get_plugins_with_function().
        // This ensures lib.php is always loaded in Path B (extends plagiarism_plugin)
        // context, eliminating the update_status() deprecation on all pages.
        'hook'     => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \plagiarism_docguard\hook\before_standard_head_html_generation::class . '::callback',
        'priority' => 600,
    ],
    [
        'hook'     => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \plagiarism_docguard\hook\before_standard_top_of_body_html_generation::class . '::callback',
        'priority' => 500,
    ],
];
