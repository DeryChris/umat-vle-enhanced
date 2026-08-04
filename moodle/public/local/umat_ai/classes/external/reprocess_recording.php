<?php
/**
 * Web service: reprocess (re-transcribe) an existing recording with
 * optional provider / model overrides.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class reprocess_recording extends \external_api {

    /**
     * Parameter declaration.
     */
    public static function reprocess_parameters() {
        return new \external_function_parameters([
            'course_id'              => new \external_value(PARAM_INT, 'Moodle course ID'),
            'session_id'             => new \external_value(PARAM_RAW, 'Session key of the recording'),
            'recording_url'          => new \external_value(PARAM_RAW, 'URL to the BBB recording'),
            'title'                  => new \external_value(PARAM_TEXT, 'Display title', VALUE_DEFAULT, ''),
            'transcription_provider' => new \external_value(PARAM_TEXT, 'Override provider (openai|openrouter)', VALUE_DEFAULT, ''),
            'transcription_model'    => new \external_value(PARAM_TEXT, 'Override model name', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Re-transcribe a recording with optional provider/model overrides.
     */
    public static function reprocess(
        int    $course_id,
        string $session_id,
        string $recording_url,
        string $title = '',
        string $transcription_provider = '',
        string $transcription_model = '',
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::reprocess_parameters(), [
            'course_id'              => $course_id,
            'session_id'             => $session_id,
            'recording_url'          => $recording_url,
            'title'                  => $title,
            'transcription_provider' => $transcription_provider,
            'transcription_model'    => $transcription_model,
        ]);

        $context = \context_course::instance($params['course_id']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $cfg = local_umat_ai_get_service_config();

        $payload = [
            'session_id'              => $params['session_id'],
            'recording_url'           => $params['recording_url'],
            'course_id'               => $params['course_id'],
            'material_ids'            => [],
            'title'                   => $params['title'],
        ];

        // Only pass overrides if they are actually provided.
        if (!empty($params['transcription_provider'])) {
            $payload['transcription_provider'] = $params['transcription_provider'];
        }
        if (!empty($params['transcription_model'])) {
            $payload['transcription_model'] = $params['transcription_model'];
        }

        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $raw = $client->post(
            $cfg['url'] . '/api/v1/recording/reprocess',
            json_encode($payload)
        );

        $result = json_decode($raw, true);

        if (empty($result) || !empty($result['error'])) {
            $err = $result['message'] ?? $result['error'] ?? 'Unknown error from AI service';
            if ($client->get_errno()) {
                $err = 'cURL error ' . $client->get_errno() . ': ' . $client->error;
            }
            return [
                'success'    => false,
                'job_id'     => '',
                'status'     => 'error',
                'transcription_provider' => '',
                'transcription_model'    => '',
                'message'    => $err,
            ];
        }

        return [
            'success'    => true,
            'job_id'     => $result['job_id'] ?? '',
            'status'     => $result['status'] ?? 'queued',
            'transcription_provider' => $result['transcription_provider'] ?? '',
            'transcription_model'    => $result['transcription_model'] ?? '',
            'message'    => $result['message'] ?? 'Re-transcription job queued',
        ];
    }

    /**
     * Return structure.
     */
    public static function reprocess_returns() {
        return new \external_single_structure([
            'success'    => new \external_value(PARAM_BOOL, 'Whether the request was accepted'),
            'job_id'     => new \external_value(PARAM_RAW, 'New processing job ID'),
            'status'     => new \external_value(PARAM_RAW, 'Job status (usually "queued")'),
            'transcription_provider' => new \external_value(PARAM_TEXT, 'Provider that will be used'),
            'transcription_model'    => new \external_value(PARAM_TEXT, 'Model that will be used'),
            'message'    => new \external_value(PARAM_RAW, 'Status message'),
        ]);
    }
}
