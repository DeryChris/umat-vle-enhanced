<?php
// ============================================================
// External API: get pending AI outputs for a course
// Called by the approval page to load the review panel
// ============================================================

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_summary extends \external_api {

    public static function get_session_outputs_parameters() {
        return new \external_function_parameters([
            'sessionid'  => new \external_value(PARAM_INT,  'umat_ai_sessions record id'),
            'courseid'   => new \external_value(PARAM_INT,  'Moodle course id'),
        ]);
    }

    public static function get_session_outputs($sessionid, $courseid) {
        global $DB;

        $params = self::validate_parameters(
            self::get_session_outputs_parameters(),
            ['sessionid' => $sessionid, 'courseid' => $courseid]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewsummary', $context);

        $outputs = $DB->get_records('umat_ai_outputs', [
            'sessionrecordid' => $params['sessionid'],
            'courseid'        => $params['courseid'],
        ]);

        $items = [];
        foreach ($outputs as $out) {
            $items[] = [
                'id'           => $out->id,
                'output_type'  => $out->output_type,
                'content'       => $out->content,
                'is_approved'   => (bool) $out->is_approved,
                'timecreated'   => $out->timecreated,
            ];
        }

        return ['outputs' => $items];
    }

    public static function get_session_outputs_returns() {
        return new \external_single_structure([
            'outputs' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'output_type' => new \external_value(PARAM_TEXT),
                    'content'     => new \external_value(PARAM_RAW),
                    'is_approved'  => new \external_value(PARAM_BOOL),
                    'timecreated'  => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    public static function get_pending_approvals_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Moodle course id'),
        ]);
    }

    public static function get_pending_approvals($courseid) {
        global $DB;

        $params = self::validate_parameters(
            self::get_pending_approvals_parameters(),
            ['courseid' => $courseid]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:approveoutput', $context);

        $requireapproval = get_config('local_umat_ai', 'require_approval');

        // Get sessions with outputs pending approval.
        $sql = "SELECT DISTINCT s.id AS session_id, s.sessionid, s.timecreated,
                       o.id AS output_id, o.output_type, o.content
                  FROM {umat_ai_sessions} s
                  JOIN {umat_ai_outputs} o ON o.sessionrecordid = s.id
                 WHERE s.courseid = :courseid
                   AND (:approval = 0 OR o.is_approved = 0)
              ORDER BY s.timecreated DESC";

        $records = $DB->get_records_sql($sql, [
            'courseid'  => $params['courseid'],
            'approval'  => $requireapproval,
        ]);

        // Group by session.
        $sessions = [];
        foreach ($records as $row) {
            $sid = $row->session_id;
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'session_id'   => $sid,
                    'bbb_sessionid' => $row->sessionid,
                    'timecreated'  => $row->timecreated,
                    'outputs'      => [],
                ];
            }
            $sessions[$sid]['outputs'][] = [
                'output_id'   => $row->output_id,
                'output_type' => $row->output_type,
                'content'     => $row->content,
            ];
        }

        return ['sessions' => array_values($sessions)];
    }

    public static function get_pending_approvals_returns() {
        return new \external_single_structure([
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'session_id'    => new \external_value(PARAM_INT),
                    'bbb_sessionid'  => new \external_value(PARAM_TEXT),
                    'timecreated'   => new \external_value(PARAM_INT),
                    'outputs'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'output_id'   => new \external_value(PARAM_INT),
                            'output_type' => new \external_value(PARAM_TEXT),
                            'content'     => new \external_value(PARAM_RAW),
                        ])
                    ),
                ])
            ),
        ]);
    }
}