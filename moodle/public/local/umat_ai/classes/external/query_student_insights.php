<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class query_student_insights extends \external_api {

    public static function query_student_insights_parameters() {
        return new \external_function_parameters([
            'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
            'query'     => new \external_value(PARAM_TEXT, 'Natural language question about student performance'),
            'risklevel' => new \external_value(PARAM_TEXT, 'Risk filter: all|at_risk|struggling|engaged', VALUE_DEFAULT, 'all'),
            'limit'     => new \external_value(PARAM_INT, 'Max students to return', VALUE_DEFAULT, 50),
        ]);
    }

    public static function query_student_insights($courseid, $query, $risklevel = 'all', $limit = 50) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::query_student_insights_parameters(), [
            'courseid'  => $courseid,
            'query'     => $query,
            'risklevel' => $risklevel,
            'limit'     => $limit,
        ]);
        $cid   = (int)$params['courseid'];
        $q     = $params['query'];
        $risk  = $params['risklevel'];
        $limit = (int)$params['limit'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $students = \get_enrolled_users($context, 'local/umat_ai:chatwithai', 0, 'u.id, u.firstname, u.lastname, u.email');

        $riskFilter = '';
        if ($risk === 'at_risk') {
            $riskFilter = 'AND risk_score >= 60';
        } elseif ($risk === 'struggling') {
            $riskFilter = 'AND risk_score >= 40 AND risk_score < 60';
        } elseif ($risk === 'engaged') {
            $riskFilter = 'AND risk_score < 40';
        }

        $metrics = $DB->get_records_select('umat_ai_student_metrics',
            "courseid = ? $riskFilter", [$cid], 'risk_score DESC', '*', 0, $limit);

        $data = [];
        foreach ($metrics as $m) {
            if (!isset($students[$m->userid])) {
                continue;
            }
            $u = $students[$m->userid];
            $data[] = [
                'userid'      => (int)$m->userid,
                'firstname'   => $u->firstname,
                'lastname'    => $u->lastname,
                'risk_score'  => (int)$m->risk_score,
                'total_logins'=> (int)$m->total_logins,
                'avg_quiz'    => (float)$m->avg_quiz_grade,
                'ai_queries'  => (int)$m->ai_queries,
            ];
        }

        $token = get_config('local_umat_ai', 'ai_service_token');
        $base  = get_config('local_umat_ai', 'ai_service_url');
        if (empty($base)) {
            $base = 'http://localhost:8000';
        }
        $aiResponse = '';

        if (!empty($q) && !empty($token)) {
            $payload = json_encode([
                'course_id' => $cid,
                'query'     => $q,
                'students'  => $data,
            ]);
            $ch = curl_init("$base/api/v1/analytics/natural-language-query");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "Authorization: Bearer $token",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200) {
                $body = json_decode($resp, true);
                if ($body && isset($body['response'])) {
                    $aiResponse = $body['response'];
                }
            }
        }

        return [
            'students' => $data,
            'ai_insight' => $aiResponse,
        ];
    }

    public static function query_student_insights_returns() {
        return new \external_single_structure([
            'students' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'      => new \external_value(PARAM_INT, 'User ID'),
                    'firstname'   => new \external_value(PARAM_TEXT, 'First name'),
                    'lastname'    => new \external_value(PARAM_TEXT, 'Last name'),
                    'risk_score'  => new \external_value(PARAM_INT, 'Risk score 0-100'),
                    'total_logins'=> new \external_value(PARAM_INT, 'Login count'),
                    'avg_quiz'    => new \external_value(PARAM_FLOAT, 'Average quiz grade'),
                    'ai_queries'  => new \external_value(PARAM_INT, 'AI query count'),
                ])
            ),
            'ai_insight' => new \external_value(PARAM_RAW, 'Natural language analysis from AI'),
        ]);
    }
}
