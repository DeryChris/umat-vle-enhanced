<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class grade_theory extends \external_api {

    public static function grade_theory_answer_parameters() {
        return new \external_function_parameters([
            'courseid'       => new \external_value(PARAM_INT, 'Course ID'),
            'question_text'  => new \external_value(PARAM_RAW, 'The question text'),
            'answer_hint'    => new \external_value(PARAM_RAW, 'Expected answer key points', VALUE_DEFAULT, ''),
            'student_answer' => new \external_value(PARAM_RAW, 'The student\'s answer'),
        ]);
    }

    public static function grade_theory_answer($courseid, $question_text, $answer_hint = '', $student_answer = '') {
        global $USER;

        $params = self::validate_parameters(self::grade_theory_answer_parameters(), [
            'courseid'       => $courseid,
            'question_text'  => $question_text,
            'answer_hint'    => $answer_hint,
            'student_answer' => $student_answer,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $cfg = \local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
            'X-Request-Id: ' . \local_umat_ai_request_id(),
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 15]);

        $req = [
            'question'       => $params['question_text'],
            'answer_hint'    => $params['answer_hint'],
            'student_answer' => $params['student_answer'],
        ];

        $raw = $client->post($cfg['url'] . '/api/v1/quiz/grade', json_encode($req));
        $result = json_decode($raw, true);

        if (!empty($result['explanation'])) {
            return [
                'correct'     => !empty($result['correct']),
                'score'       => (int)($result['score'] ?? 0),
                'explanation' => $result['explanation'],
            ];
        }

        // AI service error — return graceful fallback
        $msg = $result['detail']['message'] ?? get_string('quiz_grade_error', 'local_umat_ai');
        return [
            'correct'     => false,
            'score'       => 0,
            'explanation' => $msg,
        ];
    }

    public static function grade_theory_answer_returns() {
        return new \external_single_structure([
            'correct'     => new \external_value(PARAM_BOOL),
            'score'       => new \external_value(PARAM_INT),
            'explanation' => new \external_value(PARAM_RAW),
        ]);
    }
}
