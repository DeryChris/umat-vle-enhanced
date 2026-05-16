<?php
/**
 * Scheduled task: Poll the AI service for completed recording jobs.
 *
 * This task is intentionally lightweight: it only checks BBB sessions
 * whose status is 'pending' or 'processing' and polls the AI backend
 * for completion. The actual processing happens asynchronously on the
 * Python/AI service side.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class process_recordings extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('pluginname', 'local_umat_ai') . ': Process Recordings';
    }

    public function execute(): void {
        global $DB;

        $cfg    = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => true]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 20]);

        // Fetch sessions awaiting processing.
        $pending = $DB->get_records_select(
            'umat_ai_sessions',
            "status IN ('pending', 'processing')",
            [],
            'timecreated ASC',
            '*',
            0,
            20
        );

        foreach ($pending as $sess) {
            // Ask the AI service for the status of this session.
            $raw    = $client->get($cfg['url'] . '/api/v1/session/' . urlencode($sess->sessionid) . '/status');
            $result = json_decode($raw, true);

            if (empty($result['status'])) {
                mtrace("  [umat_ai] No status response for session {$sess->sessionid}");
                continue;
            }

            $aiStatus = $result['status'];

            if ($aiStatus === 'complete') {
                // Update session status.
                $DB->set_field('umat_ai_sessions', 'status',       'complete',   ['id' => $sess->id]);
                $DB->set_field('umat_ai_sessions', 'timemodified', time(),       ['id' => $sess->id]);

                // Save AI outputs returned by the service.
                foreach (['summary', 'notes', 'quiz'] as $outputType) {
                    $content = $result['outputs'][$outputType] ?? null;
                    if (!$content) continue;

                    // Avoid duplicates.
                    if ($DB->record_exists('umat_ai_outputs', [
                        'sessionrecordid' => $sess->id,
                        'output_type'     => $outputType,
                    ])) {
                        continue;
                    }

                    $DB->insert_record('umat_ai_outputs', (object) [
                        'sessionrecordid' => $sess->id,
                        'courseid'        => $sess->courseid,
                        'output_type'     => $outputType,
                        'content'         => $content,
                        'is_approved'     => 0,
                        'approved_by'     => null,
                        'timecreated'     => time(),
                        'timepublished'   => null,
                    ]);
                }

                mtrace("  [umat_ai] Session {$sess->sessionid} processed → outputs saved.");

            } elseif ($aiStatus === 'processing') {
                $DB->set_field('umat_ai_sessions', 'status',       'processing', ['id' => $sess->id]);
                $DB->set_field('umat_ai_sessions', 'timemodified', time(),       ['id' => $sess->id]);
                mtrace("  [umat_ai] Session {$sess->sessionid} still processing.");

            } elseif ($aiStatus === 'failed') {
                $DB->set_field('umat_ai_sessions', 'status',       'failed',     ['id' => $sess->id]);
                $DB->set_field('umat_ai_sessions', 'timemodified', time(),       ['id' => $sess->id]);
                mtrace("  [umat_ai] Session {$sess->sessionid} FAILED on AI service.");
            }
        }
    }
}
