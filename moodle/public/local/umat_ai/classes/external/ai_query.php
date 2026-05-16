<?php
/**
 * External API: Student asks the AI a question.
 * Supports session grouping and chat history retrieval.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class ai_query extends \external_api {

    // ------------------------------------------------------------------ //
    // ask_question                                                         //
    // ------------------------------------------------------------------ //

    public static function ask_question_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT,  'Course ID'),
            'question'    => new \external_value(PARAM_TEXT, 'The question text'),
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, 'Client-generated session UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function ask_question($courseid, $question, $session_key = '') {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::ask_question_parameters(),
            ['courseid' => $courseid, 'question' => $question, 'session_key' => $session_key]
        );

        // Validate context and capabilities.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Rate limiting: max 10 questions per minute per user.
        $recent = $DB->count_records_select(
            'umat_ai_chat_logs',
            'userid = :userid AND timecreated > :since AND role = :role',
            ['userid' => $USER->id, 'since' => time() - 60, 'role' => 'student']
        );

        if ($recent >= 10) {
            return [
                'success' => false,
                'answer'  => get_string('rate_limit_hit', 'local_umat_ai'),
                'sources' => [],
                'error'   => 'rate_limit',
            ];
        }

        // Call AI service.
        $cfg     = local_umat_ai_get_service_config();
        $client  = new \curl(['ignoresecurity' => true]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $payload = json_encode([
            'question'    => $params['question'],
            'course_id'   => $params['courseid'],
            'user_id'     => $USER->id,
            'session_key' => $params['session_key'],
        ]);

        $rawResponse = $client->post($cfg['url'] . '/api/v1/query', $payload);
        $result      = json_decode($rawResponse, true);

        if (!empty($result['answer'])) {
            // Log the interaction.
            $DB->insert_record('umat_ai_chat_logs', (object)[
                'userid'      => $USER->id,
                'courseid'    => $params['courseid'],
                'session_key' => $params['session_key'],
                'role'        => 'student',
                'question'    => $params['question'],
                'answer'      => $result['answer'],
                'sources'     => json_encode($result['sources'] ?? []),
                'timecreated' => time(),
            ]);

            return [
                'success' => true,
                'answer'  => $result['answer'],
                'sources' => $result['sources'] ?? [],
                'error'   => '',
            ];
        }

        return [
            'success' => false,
            'answer'  => get_string('ai_unavailable', 'local_umat_ai'),
            'sources' => [],
            'error'   => 'service_unavailable',
        ];
    }

    public static function ask_question_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the call succeeded'),
            'answer'  => new \external_value(PARAM_RAW,  'The AI answer'),
            'sources' => new \external_multiple_structure(
                new \external_value(PARAM_TEXT, 'Source document name')
            ),
            'error'   => new \external_value(PARAM_TEXT, 'Error code if failed', VALUE_DEFAULT, ''),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_chat_history                                                     //
    // ------------------------------------------------------------------ //

    public static function get_chat_history_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT, 'Course ID'),
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, 'Session UUID', VALUE_DEFAULT, ''),
            'limit'       => new \external_value(PARAM_INT, 'Max messages to return', VALUE_DEFAULT, 50),
        ]);
    }

    public static function get_chat_history($courseid, $session_key = '', $limit = 50) {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::get_chat_history_parameters(),
            ['courseid' => $courseid, 'session_key' => $session_key, 'limit' => $limit]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $conditions = ['userid' => $USER->id, 'courseid' => $params['courseid']];
        if (!empty($params['session_key'])) {
            $conditions['session_key'] = $params['session_key'];
        }

        $records = $DB->get_records(
            'umat_ai_chat_logs',
            $conditions,
            'timecreated ASC',
            '*',
            0,
            $params['limit']
        );

        $messages = [];
        foreach ($records as $r) {
            $messages[] = [
                'id'          => (int) $r->id,
                'question'    => $r->question,
                'answer'      => $r->answer ?? '',
                'sources'     => json_decode($r->sources ?? '[]', true) ?? [],
                'timecreated' => (int) $r->timecreated,
            ];
        }

        return ['messages' => $messages];
    }

    public static function get_chat_history_returns() {
        return new \external_single_structure([
            'messages' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'question'    => new \external_value(PARAM_TEXT),
                    'answer'      => new \external_value(PARAM_RAW),
                    'sources'     => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT)
                    ),
                    'timecreated' => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }
}
