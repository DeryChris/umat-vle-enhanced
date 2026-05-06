<?php
// ============================================================
// Scheduled task: picks up pending sessions and sends them to the AI service
// Runs every 5 minutes via Moodle cron
// ============================================================

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class process_recording extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_process_recording', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        $aiserviceurl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');

        // Get all sessions with status 'pending' that have a recording URL
        $pendings = $DB->get_records_select(
            'umat_ai_sessions',
            "status = 'pending' AND recording_url IS NOT NULL",
            [],
            'timecreated ASC',
            '*',
            0,
            10  // Process max 10 at a time
        );

        foreach ($pendings as $session) {
            mtrace("Processing session: " . $session->sessionid);

            // Get indexed course materials to pass as context hints
            $materials    = $DB->get_records('umat_ai_materials', [
                'courseid'   => $session->courseid,
                'is_indexed' => 1,
            ]);
            $material_ids = array_column((array)$materials, 'id');

            // Call Python AI service
            $client = new \curl();
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

            $response = $client->post($aiserviceurl . '/api/v1/process-recording', $payload);
            $result   = json_decode($response, true);

            if (!empty($result['job_id'])) {
                $DB->update_record('umat_ai_sessions', (object)[
                    'id'           => $session->id,
                    'status'       => 'processing',
                    'timemodified' => time(),
                ]);
                mtrace("Job submitted: " . $result['job_id']);
            } else {
                mtrace("ERROR: Failed to submit session " . $session->sessionid);
                $DB->update_record('umat_ai_sessions', (object)[
                    'id'           => $session->id,
                    'status'       => 'error',
                    'timemodified' => time(),
                ]);
            }
        }
    }
}