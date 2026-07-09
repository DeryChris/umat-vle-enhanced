<?php
/**
 * External API: Student AI query + session transcript retrieval.
 * NOTE: QueryRequest on the AI service does NOT have session_key — we only use
 * it internally in the Moodle DB for conversation grouping.
 *
 * @package    local_umat_ai
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
            'courseid'     => new \external_value(PARAM_INT,  'Course ID'),
            'question'     => new \external_value(PARAM_TEXT, 'Question text'),
            'session_key'  => new \external_value(PARAM_ALPHANUMEXT, 'Client session UUID', VALUE_DEFAULT, ''),
            'material_ids' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Material ID'),
                'Material IDs to restrict RAG search to — empty means all materials',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function ask_question($courseid, $question, $session_key = '', $material_ids = []) {
        global $DB, $USER;

        $params = self::validate_parameters(self::ask_question_parameters(), [
            'courseid'     => $courseid,
            'question'     => $question,
            'session_key'  => $session_key,
            'material_ids' => $material_ids,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Moodle-side rate limit (secondary guard — AI service also enforces its own).
        $rateLimit = (int) get_config('local_umat_ai', 'rate_limit') ?: 10;
        $remaining = local_umat_ai_questions_remaining($USER->id, $rateLimit);
        if ($remaining <= 0) {
            return ['success' => false, 'answer' => get_string('rate_limit_hit', 'local_umat_ai'),
                    'sources' => [], 'error' => 'rate_limit', 'remaining' => 0];
        }

        // Filter materials by access restrictions (security boundary).
        // When the student explicitly selected materials, always pass the accessible
        // subset (even if empty) so the AI never searches unrestricted materials.
        // When no selection was made, pass all accessible materials.
        $mids = $params['material_ids'] ?? [];
        $hadSelection = !empty($mids);
        $accessible = local_umat_ai_filter_accessible_materials(
            $params['courseid'], $USER->id,
            $hadSelection ? array_map('intval', $mids) : null
        );

        // Call AI service — only pass accessible material IDs.
        $cfg    = \local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . \local_umat_ai_request_id()]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $req = [
            'question'    => $params['question'],
            'course_id'   => (int) $params['courseid'],
            'user_id'     => (int) $USER->id,
            'session_key' => $params['session_key'] ?: '',
        ];
        if (!empty($accessible)) {
            $req['material_ids'] = $accessible;
        } elseif ($hadSelection) {
            $req['material_ids'] = [];
        }
        $raw = $client->post($cfg['url'] . '/api/v1/query', json_encode($req));
        $result = json_decode($raw, true);

        if (!empty($result['answer'])) {
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

            // Purge struggle-insights cache so lecturer dashboard picks up the new question.
            try {
                \cache::make('local_umat_ai', 'struggle_insights')->delete("struggle_{$params['courseid']}_60");
            } catch (\Throwable $e) {
                // Best-effort.
            }

            return ['success' => true, 'answer' => $result['answer'], 'sources' => $result['sources'] ?? [],
                    'error' => '', 'remaining' => $remaining - 1];
        }

        // AI service returned no answer — return graceful error.
        // Failed questions are not logged, so they do not consume the rate-limit window.
        $msg = $result['detail']['message'] ?? get_string('ai_unavailable', 'local_umat_ai');
        return ['success' => false, 'answer' => $msg, 'sources' => [], 'error' => 'service_error', 'remaining' => $remaining];
    }

    public static function ask_question_returns() {
        return new \external_single_structure([
            'success'   => new \external_value(PARAM_BOOL),
            'answer'    => new \external_value(PARAM_RAW),
            'sources'   => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
            'error'     => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
            'remaining' => new \external_value(PARAM_INT, 'Questions remaining in the current rate-limit window', VALUE_DEFAULT, -1),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_chat_history                                                     //
    // ------------------------------------------------------------------ //

    public static function get_chat_history_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT),
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, '', VALUE_DEFAULT, ''),
            'limit'       => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 50),
        ]);
    }

    public static function get_chat_history($courseid, $session_key = '', $limit = 50) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_chat_history_parameters(), [
            'courseid' => $courseid, 'session_key' => $session_key, 'limit' => $limit,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $conditions = ['userid' => $USER->id, 'courseid' => $params['courseid']];
        if (!empty($params['session_key'])) $conditions['session_key'] = $params['session_key'];

        $records = $DB->get_records('umat_ai_chat_logs', $conditions, 'timecreated ASC', '*', 0, $params['limit']);

        return ['messages' => array_values(array_map(function($r) {
            return [
                'id'          => (int)$r->id,
                'question'    => $r->question,
                'answer'      => $r->answer ?? '',
                'sources'     => json_decode($r->sources ?? '[]', true) ?? [],
                'timecreated' => (int)$r->timecreated,
            ];
        }, (array)$records))];
    }

    public static function get_chat_history_returns() {
        return new \external_single_structure(['messages' => new \external_multiple_structure(
            new \external_single_structure([
                'id'          => new \external_value(PARAM_INT),
                'question'    => new \external_value(PARAM_TEXT),
                'answer'      => new \external_value(PARAM_RAW),
                'sources'     => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                'timecreated' => new \external_value(PARAM_INT),
            ])
        )]);
    }

    // ------------------------------------------------------------------ //
    // get_session_transcript — returns recording URL + transcript segments //
    // ------------------------------------------------------------------ //

    public static function get_session_transcript_parameters() {
        return new \external_function_parameters([
            'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
            'sessionid' => new \external_value(PARAM_INT, 'Session record ID (0 = latest)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_session_transcript($courseid, $sessionid = 0) {
        global $DB;

        $params = self::validate_parameters(self::get_session_transcript_parameters(), [
            'courseid' => $courseid, 'sessionid' => $sessionid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        // Resolve session.
        if ($params['sessionid'] > 0) {
            $session = $DB->get_record('umat_ai_sessions', ['id' => $params['sessionid'], 'courseid' => $params['courseid']]);
        } else {
            $session = $DB->get_record_sql(
                "SELECT * FROM {umat_ai_sessions} WHERE courseid = :cid AND status = 'completed' ORDER BY timecreated DESC",
                ['cid' => $params['courseid']], IGNORE_MULTIPLE
            );
        }

        if (!$session) {
            return ['recording_url' => '', 'session_title' => '', 'session_date' => '', 'segments' => []];
        }

        // Parse transcript JSON stored in the session record.
        $segments = [];
        if (!empty($session->transcript_json)) {
            $raw = json_decode($session->transcript_json, true);
            if (is_array($raw)) {
                foreach ($raw as $seg) {
                    $start = $seg['start'] ?? 0;
                    $m = floor($start / 60); $s = floor($start % 60);
                    $segments[] = [
                        'timestamp' => $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT),
                        'start'     => (float)$start,
                        'end'       => (float)($seg['end'] ?? $start + 30),
                        'text'      => $seg['text'] ?? '',
                    ];
                }
            }
        }

        return [
            'recording_url' => $session->recording_url ?? '',
            'session_title' => 'Session ' . date('d M Y', $session->timecreated),
            'session_date'  => date('D, d M Y', $session->timecreated),
            'segments'      => $segments,
        ];
    }

    public static function get_session_transcript_returns() {
        return new \external_single_structure([
            'recording_url' => new \external_value(PARAM_URL),
            'session_title' => new \external_value(PARAM_TEXT),
            'session_date'  => new \external_value(PARAM_TEXT),
            'segments'      => new \external_multiple_structure(
                new \external_single_structure([
                    'timestamp' => new \external_value(PARAM_TEXT),
                    'start'     => new \external_value(PARAM_FLOAT),
                    'end'       => new \external_value(PARAM_FLOAT),
                    'text'      => new \external_value(PARAM_TEXT),
                ])
            ),
        ]);
    }
}
