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
 * Backup support for plagiarism_docguard.
 *
 * V1.0.92. The plugin adds a checkbox to the activity settings form via
 * plagiarism_docguard_coursemodule_standard_elements(), and stores the answer with
 * set_config('enabled_cm_<cmid>', ..., 'plagiarism_docguard'). That is a per-activity
 * setting living outside the activity's own tables, so nothing in core carried it through
 * a course backup: restoring or duplicating an activity produced one with no DocGuard
 * setting recorded at all.
 *
 * Only the enablement flag is backed up. Analysis results are deliberately NOT: they are
 * derived data about specific student submissions, they are keyed to file content hashes
 * that a restore does not reproduce, and copying extracted student text into a course
 * backup — which administrators routinely download, email and archive — would turn a
 * backup file into a data-protection problem. A restored activity re-analyses on
 * submission, or via the class report's scan button.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_plagiarism_docguard_plugin extends backup_plagiarism_plugin {
    /**
     * Declare the structure appended to each course module being backed up.
     *
     * @return backup_plugin_element The plugin element to attach to the module.
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $settings = new backup_nested_element('docguard_settings');
        $setting  = new backup_nested_element('docguard_setting', ['id'], ['name', 'value']);

        $pluginwrapper->add_child($settings);
        $settings->add_child($setting);

        $setting->set_source_array($this->get_docguard_settings());

        return $plugin;
    }

    /**
     * The per-activity settings to write into the backup.
     *
     * Returns an empty array when the activity has no stored setting, which is the normal
     * state for an activity nobody has ever opened the settings form on. Restoring then
     * leaves the destination at the site default rather than pinning it to a value the
     * source never actually held.
     *
     * @return array List of ['id' => int, 'name' => string, 'value' => string].
     */
    protected function get_docguard_settings(): array {
        $cmid = $this->task->get_moduleid();
        if (empty($cmid)) {
            return [];
        }

        $enabled = get_config('plagiarism_docguard', 'enabled_cm_' . $cmid);
        if ($enabled === false) {
            return [];
        }

        return [
            [
                'id'    => 1,
                'name'  => 'enabled',
                'value' => (string)(int)!empty($enabled),
            ],
        ];
    }
}
