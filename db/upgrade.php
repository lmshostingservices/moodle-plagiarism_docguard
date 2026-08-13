<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_docguard_upgrade($oldversion) {
    if ($oldversion < 2026072300) {
        upgrade_plugin_savepoint(true, 2026072300, 'plagiarism', 'docguard');
    }
    return true;
}
