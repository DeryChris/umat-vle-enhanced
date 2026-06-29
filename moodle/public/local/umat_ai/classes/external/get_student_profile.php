<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_student_profile extends \external_api {

    public static function get_student_profile_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'userid'   => new \external_value(PARAM_INT, 'Student user ID'),
        ]);
    }

    public static function get_student_profile($courseid, $userid) {
        global $DB;

        $params = self::validate_parameters(self::get_student_profile_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);
        $cid = (int)$params['courseid'];
        $uid = (int)$params['userid'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $metric = $DB->get_record('umat_ai_student_metrics', [
            'courseid' => $cid,
            'userid'   => $uid,
        ]);
        if (!$metric) {
            throw new \moodle_exception('No metrics found for this student');
        }

        $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname, email');

        $interventions = $DB->get_records('umat_ai_interventions', [
            'courseid' => $cid,
            'userid'   => $uid,
        ], 'timecreated DESC', '*', 0, 10);

        $interventionList = [];
        foreach ($interventions as $inv) {
            $interventionList[] = [
                'action'      => $inv->action,
                'status'      => $inv->status,
                'timecreated' => (int)$inv->timecreated,
            ];
        }

        return [
            'userid'       => (int)$metric->userid,
            'firstname'    => $user ? $user->firstname : '',
            'lastname'     => $user ? $user->lastname : '',
            'risk_score'   => (int)$metric->risk_score,
            'total_logins' => (int)$metric->total_logins,
            'avg_quiz'     => (float)$metric->avg_quiz_grade,
            'ai_queries'   => (int)$metric->ai_queries,
            'interventions'=> $interventionList,
        ];
    }

    public static function get_student_profile_returns() {
        return new \external_single_structure([
            'userid'       => new \external_value(PARAM_INT, 'User ID'),
            'firstname'    => new \external_value(PARAM_TEXT, 'First name'),
            'lastname'     => new \external_value(PARAM_TEXT, 'Last name'),
            'risk_score'   => new \external_value(PARAM_INT, 'Risk score 0-100'),
            'total_logins' => new \external_value(PARAM_INT, 'Login count'),
            'avg_quiz'     => new \external_value(PARAM_FLOAT, 'Average quiz grade'),
            'ai_queries'   => new \external_value(PARAM_INT, 'AI query count'),
            'interventions'=> new \external_multiple_structure(
                new \external_single_structure([
                    'action'      => new \external_value(PARAM_TEXT, 'Intervention action type'),
                    'status'      => new \external_value(PARAM_TEXT, 'Status: sent|pending|failed'),
                    'timecreated' => new \external_value(PARAM_INT, 'Unix timestamp'),
                ])
            ),
        ]);
    }
}
