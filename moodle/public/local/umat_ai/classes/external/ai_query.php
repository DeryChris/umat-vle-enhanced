<?php
// ============================================================
// External API function — called from AMD JavaScript via AJAX
// ============================================================

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class ai_query extends \external_api {

    public static function ask_question_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT,  'Course ID'),
            'question' => new \external_value(PARAM_TEXT, 'The question text'),
        ]);
    }

    public static function ask_question($courseid, $question) {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(
            self::ask_question_parameters(),
            ['courseid' => $courseid, 'question' => $question]
        );

        // Validate context and capabilities
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Rate limiting: max 10 questions per minute per user
        $recent = $DB->count_records_select(
            'umat_ai_chat_logs',
            'userid = :userid AND timecreated > :since',
            ['userid' => $USER->id, 'since' => time() - 60]
        );

        if ($recent >= 10) {
            return [
                'success' => false,
                'answer'  => 'Rate limit reached. Please wait before asking another question.',
                'sources' => [],
            ];
        }

        // Call AI service
        $aiserviceurl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');

        // Bypass URL security check for local AI service
        $client = new \curl(['ignoresecurity' => true]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $payload = json_encode([
            'question'  => $params['question'],
            'course_id' => $params['courseid'],
            'user_id'   => $USER->id,
        ]);

        $response = $client->post($aiserviceurl . '/api/v1/query', $payload);
        $result   = json_decode($response, true);

        if (!empty($result['answer'])) {
            // Log the interaction
            $log = (object)[
                'userid'      => $USER->id,
                'courseid'    => $params['courseid'],
                'question'    => $params['question'],
                'answer'      => $result['answer'],
                'sources'     => json_encode($result['sources'] ?? []),
                'timecreated' => time(),
            ];
            $DB->insert_record('umat_ai_chat_logs', $log);

            return [
                'success' => true,
                'answer'  => $result['answer'],
                'sources' => $result['sources'] ?? [],
            ];
        }

        return [
            'success' => false,
            'answer'  => 'AI service unavailable. Please try again later.',
            'sources' => [],
        ];
    }

    public static function ask_question_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the call succeeded'),
            'answer'  => new \external_value(PARAM_RAW,  'The AI answer'),
            'sources' => new \external_multiple_structure(
                new \external_value(PARAM_TEXT, 'Source document name')
            ),
        ]);
    }
}