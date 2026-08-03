<?php
// ============================================================
// Scheduled task: picks up pending BBB sessions, fetches the recording URL,
// waits if the recording isn't ready yet, then sends to the AI service.
// Runs every 5 minutes via Moodle cron.
// ============================================================

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

class process_recording extends \core\task\scheduled_task {

    /** How long to wait before retrying sessions with no recording yet (seconds). */
    private const RECORDING_WAIT_INTERVAL = 600;

    /** Maximum retries when recording is not available yet. */
    private const MAX_RETRIES = 12;

    public function get_name() {
        return get_string('task_process_recording', 'local_umat_ai');
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $aiserviceurl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');

        // Step 1: Pick up sessions that have a recording URL — send to AI service.
        $ready = $DB->get_records_select(
            'umat_ai_sessions',
            "status = 'pending' AND recording_url IS NOT NULL",
            [],
            'timecreated ASC',
            '*',
            0,
            10
        );

        foreach ($ready as $session) {
            $this->submit_to_ai_service($session, $aiserviceurl, $token);
        }

        // Step 2: Pick up sessions still waiting for recording to become available.
        $waiting = $DB->get_records_select(
            'umat_ai_sessions',
            "status = 'waiting_recording'",
            [],
            'timecreated ASC',
            '*',
            0,
            10
        );

        foreach ($waiting as $session) {
            $this->fetch_and_process($session, $aiserviceurl, $token);
        }
    }

    /**
     * Attempt to fetch the recording URL and submit to AI service.
     *
     * @param \stdClass $session
     * @param string    $aiserviceurl
     * @param string    $token
     */
    private function fetch_and_process(\stdClass $session, string $aiserviceurl, string $token): void {
        global $DB;

        require_once(__DIR__ . '/../../local/bbb_recording_helper.php');

        $url = \local_umat_ai\local\bbb_recording_helper::get_recording_url(
            $session->sessionid,
            $session->cmid
        );

        if ($url !== null) {
            $storeurl = $url;

            // Store the URL (BBB playback or local) and submit to AI service.
            $DB->update_record('umat_ai_sessions', (object)[
                'id'             => $session->id,
                'recording_url'  => $storeurl,
                'status'         => 'transcribing',
                'timemodified'   => time(),
            ]);
            mtrace("Recording URL obtained for session: {$session->sessionid}");

            // Re-fetch the updated session.
            $session = $DB->get_record('umat_ai_sessions', ['id' => $session->id]);
            $this->submit_to_ai_service($session, $aiserviceurl, $token);
        } else {
            // Recording not available yet — increment retry count or mark failed.
            $elapsed = time() - $session->timecreated;

            if ($elapsed >= self::RECORDING_WAIT_INTERVAL * self::MAX_RETRIES) {
                $DB->update_record('umat_ai_sessions', (object)[
                    'id'           => $session->id,
                    'status'       => 'failed',
                    'timemodified' => time(),
                ]);
                mtrace("Recording unavailable after max retries: {$session->sessionid}");
            } else {
                // Keep waiting — update timemodified to track retry window.
                $DB->update_record('umat_ai_sessions', (object)[
                    'id'           => $session->id,
                    'timemodified' => time(),
                ]);
                mtrace("Recording not yet available for session: {$session->sessionid}, will retry later.");
            }
        }
    }

    /**
     * Call the Python AI service with the session data.
     *
     * @param \stdClass $session
     * @param string    $aiserviceurl
     * @param string    $token
     */
    private function submit_to_ai_service(\stdClass $session, string $aiserviceurl, string $token): void {
        global $DB;

        mtrace("Submitting session to AI service: " . $session->sessionid);

        // Get indexed course materials to pass as context hints.
        $materials    = $DB->get_records('umat_ai_materials', [
            'courseid'   => $session->courseid,
            'is_indexed' => 1,
        ]);
        $material_ids = array_column((array)$materials, 'id');

        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($aiserviceurl)]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        $payload = json_encode([
            'session_id'    => $session->sessionid,
            'recording_url' => $session->recording_url,
            'course_id'     => $session->courseid,
            'material_ids'  => $material_ids,
        ]);

        $response = $client->post($aiserviceurl . '/api/v1/recording/process', $payload);
        $result   = json_decode($response, true);

        if (!empty($result['job_id'])) {
            $DB->update_record('umat_ai_sessions', (object)[
                'id'             => $session->id,
                'status'         => 'transcribing',
                'timemodified'   => time(),
            ]);
            mtrace("Job submitted: " . $result['job_id']);
        } else {
            mtrace("ERROR: Failed to submit session " . $session->sessionid);
            $DB->update_record('umat_ai_sessions', (object)[
                'id'             => $session->id,
                'status'         => 'failed',
                'timemodified'   => time(),
            ]);
        }
    }
}