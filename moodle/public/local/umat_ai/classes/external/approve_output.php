<?php
// ============================================================
// External API: approve or reject an AI-generated output
// ============================================================

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class approve_output extends \external_api {

    public static function approve_parameters() {
        return new \external_function_parameters([
            'outputid'    => new \external_value(PARAM_INT,  'umat_ai_outputs record id'),
            'courseid'    => new \external_value(PARAM_INT,  'Moodle course id'),
            'action'      => new \external_value(PARAM_ALPHA, 'approve or reject'),
            'comment'     => new \external_value(PARAM_TEXT, 'Optional rejection comment', VALUE_DEFAULT, ''),
        ]);
    }

    public static function approve($outputid, $courseid, $action, $comment = '') {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::approve_parameters(),
            [
                'outputid'  => $outputid,
                'courseid'  => $courseid,
                'action'    => $action,
                'comment'   => $comment,
            ]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:approveoutput', $context);

        if (!in_array($params['action'], ['approve', 'reject'])) {
            throw new \invalid_parameter_exception('Action must be "approve" or "reject"');
        }

        $output = $DB->get_record('umat_ai_outputs', ['id' => $params['outputid']], '*', MUST_EXIST);

        if ($params['action'] === 'approve') {
            $DB->update_record('umat_ai_outputs', (object)[
                'id'          => $output->id,
                'is_approved'  => 1,
                'approved_by'  => $USER->id,
                'timepublished' => time(),
            ]);

            return [
                'success' => true,
                'message' => 'Output approved and published successfully.',
            ];
        } else {
            // Rejection: store a log entry in chat_logs for debugging/audit.
            $DB->insert_record('umat_ai_chat_logs', (object)[
                'userid'      => $USER->id,
                'courseid'    => $params['courseid'],
                'question'    => "REJECTED output #{$output->id} ({$output->output_type})",
                'answer'      => $params['comment'],
                'sources'     => 'rejection_log',
                'timecreated' => time(),
            ]);

            return [
                'success' => true,
                'message' => 'Output rejected. A comment has been recorded.',
            ];
        }
    }

    public static function approve_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL),
            'message' => new \external_value(PARAM_TEXT),
        ]);
    }
}