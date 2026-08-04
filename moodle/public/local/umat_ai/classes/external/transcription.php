<?php
/**
 * Web service: upload audio/video for transcription, get transcripts,
 * generate study tools (flashcards, glossary, chapters).
 *
 * @package local_umat_ai
 */

namespace local_umat_ai\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_course;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/../../lib.php');

class transcription extends external_api {

    /**
     * Parameters for upload_recording.
     */
    public static function upload_recording_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course ID'),
            'title'    => new external_value(PARAM_TEXT, 'Display title for the recording', false, ''),
        ]);
    }

    /**
     * Upload an audio/video file to the AI service for transcription.
     * The file is read from the uploaded file storage and streamed to the AI service.
     */
    public static function upload_recording(int $courseid, string $title = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::upload_recording_parameters(), [
            'courseid' => $courseid,
            'title'    => $title,
        ]);
        $courseid = $params['courseid'];
        $title    = $params['title'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // The actual file upload must come via a direct HTTP POST to a PHP endpoint,
        // not through the web service (which doesn't support multipart).
        // We create a session record and return upload instructions.
        $session_id = 'upload_' . bin2hex(random_bytes(6));

        // Create a pending session record.
        $record = new \stdClass();
        $record->sessionid    = $session_id;
        $record->courseid     = $courseid;
        $record->userid       = $USER->id;
        $record->cmid         = 0;
        $record->recording_url = null;
        $record->status       = 'pending_upload';
        $record->timecreated  = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('umat_ai_sessions', $record);

        $config = get_config('local_umat_ai');
        $upload_url = rtrim($config->ai_service_url ?? 'http://localhost:8000', '/') . '/api/v1/transcription/upload';

        return [
            'success'    => true,
            'session_id' => $session_id,
            'upload_url' => $upload_url,
            'course_id'  => $courseid,
            'message'    => 'Ready for file upload',
        ];
    }

    /**
     * Return format for upload_recording.
     */
    public static function upload_recording_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, 'Whether successful'),
            'session_id' => new external_value(PARAM_RAW, 'Session identifier'),
            'upload_url' => new external_value(PARAM_RAW, 'URL to POST file to'),
            'course_id'  => new external_value(PARAM_INT, 'Course ID'),
            'message'    => new external_value(PARAM_RAW, 'Status message'),
        ]);
    }

    /**
     * Parameters for get_transcription.
     */
    public static function get_transcription_parameters(): external_function_parameters {
        return new external_function_parameters([
            'job_id' => new external_value(PARAM_RAW, 'Job ID or session ID'),
        ]);
    }

    /**
     * Get transcription status and content from the AI service.
     */
    public static function get_transcription(string $job_id): array {
        global $DB;

        $params = self::validate_parameters(self::get_transcription_parameters(), ['job_id' => $job_id]);
        $job_id = $params['job_id'];

        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $raw = $client->get($cfg['url'] . '/api/v1/transcription/' . urlencode($job_id));
        $result = json_decode($raw, true);

        if (empty($result) || !is_array($result)) {
            return ['success' => false, 'message' => 'Could not fetch transcription'];
        }

        // Also check local DB for transcript (cached from process_recordings task).
        if (empty($result['transcript'])) {
            $session = $DB->get_record('umat_ai_sessions', ['sessionid' => $job_id]);
            if ($session && !empty($session->transcript_json)) {
                $result['transcript'] = $session->transcript_json;
            }
        }

        // Parse transcription metadata.
        $transMeta = $result['transcription'] ?? [];
        $segments = $result['segments'] ?? [];

        // If local DB has segments_json but AI response doesn't, decode from local.
        if (empty($segments) && !empty($session->transcript_json)) {
            $decoded = json_decode($session->transcript_json, true);
            if (is_array($decoded)) $segments = $decoded;
        }

        return [
            'success'    => true,
            'job_id'     => $result['job_id'] ?? $job_id,
            'status'     => $result['status'] ?? 'unknown',
            'transcript' => $result['transcript'] ?? '',
            'segments'   => json_encode($segments),
            'summary'    => $result['outputs']['summary'] ?? '',
            'notes'      => $result['outputs']['notes'] ?? '',
            'error'      => $result['error'] ?? '',
            'created_at' => $result['created_at'] ?? '',
            'transcription_provider' => $transMeta['provider'] ?? ($session->transcription_provider ?? ''),
            'transcription_model'    => $transMeta['model'] ?? ($session->transcription_model ?? ''),
            'transcription_cost'     => (float)($transMeta['cost'] ?? $session->transcription_cost ?? 0),
            'audio_duration_secs'    => (float)($transMeta['duration_secs'] ?? $session->audio_duration_secs ?? 0),
        ];
    }

    /**
     * Return format for get_transcription.
     */
    public static function get_transcription_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL),
            'job_id'     => new external_value(PARAM_RAW),
            'status'     => new external_value(PARAM_RAW),
            'transcript' => new external_value(PARAM_RAW),
            'segments'   => new external_value(PARAM_RAW, 'JSON-encoded array of transcript segments', VALUE_OPTIONAL, ''),
            'summary'    => new external_value(PARAM_RAW),
            'notes'      => new external_value(PARAM_RAW),
            'error'      => new external_value(PARAM_RAW),
            'created_at' => new external_value(PARAM_RAW),
            'transcription_provider' => new external_value(PARAM_TEXT, 'openai|openrouter|local', VALUE_OPTIONAL, ''),
            'transcription_model'    => new external_value(PARAM_TEXT, 'Model used (e.g. gpt-4o-mini-transcribe)', VALUE_OPTIONAL, ''),
            'transcription_cost'     => new external_value(PARAM_FLOAT, 'Transcription cost in USD', VALUE_OPTIONAL, 0),
            'audio_duration_secs'    => new external_value(PARAM_FLOAT, 'Audio duration in seconds', VALUE_OPTIONAL, 0),
        ]);
    }

    /**
     * Parameters for get_study_tools.
     */
    public static function get_study_tools_parameters(): external_function_parameters {
        return new external_function_parameters([
            'job_id' => new external_value(PARAM_RAW, 'Job ID'),
            'tool'   => new external_value(PARAM_ALPHA, 'Tool type: flashcards|glossary|chapters'),
        ]);
    }

    /**
     * Get AI-generated study tools from a transcript.
     */
    public static function get_study_tools(string $job_id, string $tool): array {
        $params = self::validate_parameters(self::get_study_tools_parameters(), [
            'job_id' => $job_id,
            'tool'   => $tool,
        ]);
        $job_id = $params['job_id'];
        $tool   = $params['tool'];

        $allowed_tools = ['flashcards', 'glossary', 'chapters'];
        if (!in_array($tool, $allowed_tools)) {
            return ['success' => false, 'message' => 'Invalid tool type', 'data' => []];
        }

        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 120]);

        $raw = $client->get($cfg['url'] . '/api/v1/transcription/' . urlencode($job_id) . '/' . $tool);
        $result = json_decode($raw, true);

        if (empty($result)) {
            return ['success' => false, 'message' => "Failed to generate $tool", 'data' => []];
        }

        return [
            'success' => true,
            'tool'    => $tool,
            'count'   => $result['count'] ?? 0,
            'data'    => $result[$tool] ?? [],
        ];
    }

    /**
     * Return format for get_study_tools.
     */
    public static function get_study_tools_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL),
            'tool'    => new external_value(PARAM_RAW),
            'count'   => new external_value(PARAM_INT),
            'data'    => new external_value(PARAM_RAW, 'JSON-encoded tool data'),
        ]);
    }

    /**
     * Parameters for list_transcriptions.
     */
    public static function list_transcriptions_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * List recent transcription jobs for a course.
     */
    public static function list_transcriptions(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::list_transcriptions_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $cfg = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 15]);

        $raw = $client->get($cfg['url'] . '/api/v1/transcription/list/' . $courseid);
        $result = json_decode($raw, true);

        $jobs = $result['jobs'] ?? [];

        return [
            'success' => true,
            'jobs'    => json_encode($jobs),
            'count'   => $result['count'] ?? 0,
        ];
    }

    /**
     * Return format for list_transcriptions.
     */
    public static function list_transcriptions_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL),
            'jobs'    => new external_value(PARAM_RAW, 'JSON-encoded job list'),
            'count'   => new external_value(PARAM_INT),
        ]);
    }

    /**
     * Parameters for upload_via_direct_post.
     */
    public static function direct_upload_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'title'    => new external_value(PARAM_TEXT, 'Display title', false, ''),
            'filename' => new external_value(PARAM_FILE, 'Original filename'),
        ]);
    }

    /**
     * Handle direct file upload and forward to AI service.
     * Called by upload.php after file is saved to Moodle file area.
     */
    public static function direct_upload(int $courseid, string $title = '', string $filename = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::direct_upload_parameters(), [
            'courseid' => $courseid,
            'title'    => $title,
            'filename' => $filename,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $session_id = 'upload_' . bin2hex(random_bytes(6));

        $record = new \stdClass();
        $record->sessionid     = $session_id;
        $record->courseid      = $params['courseid'];
        $record->userid        = $USER->id;
        $record->cmid          = 0;
        $record->recording_url = null;
        $record->status        = 'pending';
        $record->timecreated   = time();
        $record->timemodified  = time();
        $record->id = $DB->insert_record('umat_ai_sessions', $record);

        return [
            'success'    => true,
            'session_id' => $session_id,
            'course_id'  => $params['courseid'],
        ];
    }

    /**
     * Return format for direct_upload.
     */
    public static function direct_upload_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL),
            'session_id' => new external_value(PARAM_RAW),
            'course_id'  => new external_value(PARAM_INT),
        ]);
    }
}
