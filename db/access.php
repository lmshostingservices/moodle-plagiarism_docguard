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

$capabilities = [
    'plagiarism/docguard:viewreport' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'read',
        'contextlevel' => CONTEXT_MODULE,
        // FIX-DG-CAP-ARCHETYPES (v1.0.78): Added the 'teacher' (non-editing
        // teacher) archetype. lib.php::plagiarism_docguard_get_links() renders the
        // badge and the "View DocGuard Report" link to anyone holding
        // mod/assign:grade — which Moodle core grants to non-editing teachers —
        // but report.php and student_report.php required this capability, which
        // non-editing teachers did not have. The result was a visible report link
        // that always answered "Sorry, but you do not currently have permissions
        // to do that". Markers, tutors and lecturers on non-editing roles are
        // exactly the people who need the breakdown.
        //
        // IMPORTANT: archetypes are applied by update_capabilities() only when the
        // capability row is FIRST created. On a site where this capability already
        // exists, adding an archetype here changes nothing — Moodle re-syncs only
        // captype, contextlevel and riskbitmask for existing capabilities. The
        // upgrade step in db/upgrade.php therefore grants it explicitly to existing
        // teacher-archetype roles, and lib.php's runtime check is what actually
        // guarantees markers can open the report either way.
        //
        // 'clonepermissionsfrom' => 'mod/assign:grade' was considered and rejected:
        // it silently overrides this archetypes block on new installs and copies
        // every role_capabilities row for the source capability, including
        // context-specific overrides, which would hand a RISK_PERSONAL capability to
        // any custom or peer-marking role granted mod/assign:grade in one course.
        //
        // Roles created with Archetype = None (custom "Marker"/"Assessor" roles)
        // receive nothing from archetype defaults and must still be granted this
        // capability explicitly by an administrator.
        'archetypes'  => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
