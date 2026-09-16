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

defined('MOODLE_INTERNAL') || die();

/**
 * Restore support for plagiarism_docguard.
 *
 * V1.0.92. Reads back the per-activity enablement flag written by
 * backup_plagiarism_docguard_plugin and re-applies it against the NEW course module id.
 *
 * The cmid remap is the whole point of this class. The setting is stored as a config key
 * whose name embeds the course module id — enabled_cm_<cmid> — so a restored or duplicated
 * activity has a different key from the one in the backup, and simply copying the value
 * would write a setting that belongs to nothing.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_plagiarism_docguard_plugin extends restore_plagiarism_plugin {
    /**
     * Paths this plugin handles inside a module's restore structure.
     *
     * @return array List of restore_path_element.
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element(
                'docguard_setting',
                $this->get_pathfor('docguard_settings/docguard_setting')
            ),
        ];
    }

    /**
     * Apply one backed-up setting to the restored activity.
     *
     * @param array|object $data One docguard_setting element from the backup.
     * @return void
     */
    public function process_docguard_setting($data) {
        $data = (object)$data;

        $cmid = $this->task->get_moduleid();
        if (empty($cmid)) {
            return;
        }

        // Only the enablement flag is recognised. An unknown name means the backup came
        // from a newer release that stores something this one does not understand;
        // ignoring it is correct and keeps old Moodle restoring new backups.
        if (($data->name ?? '') !== 'enabled') {
            return;
        }

        set_config(
            'enabled_cm_' . $cmid,
            (int)!empty($data->value),
            'plagiarism_docguard'
        );
    }
}
