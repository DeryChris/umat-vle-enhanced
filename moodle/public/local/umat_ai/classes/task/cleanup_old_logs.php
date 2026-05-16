<?php
/**
 * Scheduled task: Prune old chat logs to keep the database lean.
 * Retains the last 90 days of chat_logs by default.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_old_logs extends \core\task\scheduled_task {

    /** Retain logs newer than this many days. */
    const RETAIN_DAYS = 90;

    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Clean Up Old Logs';
    }

    public function execute(): void {
        global $DB;

        $cutoff  = time() - (self::RETAIN_DAYS * DAYSECS);
        $deleted = $DB->delete_records_select(
            'umat_ai_chat_logs',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        mtrace("  [umat_ai] Deleted {$deleted} chat log entries older than " . self::RETAIN_DAYS . " days.");
    }
}
