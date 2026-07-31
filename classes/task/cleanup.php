<?php

namespace plagiarism_docguard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled cleanup task for plagiarism_docguard.
 *
 * Prunes extracted normalised text (normtext) from old submission records
 * beyond the configured retention window. Score records and section-level
 * risk scores are retained permanently so teachers can review historical
 * analysis results. Only the raw extracted text is pruned.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('cleanup_task', 'plagiarism_docguard');
    }

    public function execute() {
        global $DB;

        $cfg = (array) get_config('plagiarism_docguard');
        $retentiondays = (int) ($cfg['retentiondays'] ?? 90);

        if ($retentiondays <= 0) {
            mtrace('DocGuard cleanup: retention set to 0 — pruning disabled.');
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        // Null out the extracted text in old submission records to free up
        // database space. The risk scores and section analysis JSON are kept
        // so historical reports remain viewable.
        $count = $DB->count_records_select(
            'plagiarism_docguard_sub',
            'timecreated < :cutoff AND normtext IS NOT NULL',
            ['cutoff' => $cutoff]
        );
        if ($count > 0) {
            $DB->set_field_select(
                'plagiarism_docguard_sub',
                'normtext',
                null,
                'timecreated < :cutoff AND normtext IS NOT NULL',
                ['cutoff' => $cutoff]
            );
        }

        mtrace("DocGuard cleanup: cleared extracted text from {$count} submission record(s) older than {$retentiondays} days.");
    }
}
