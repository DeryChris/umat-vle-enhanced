<?php
/**
 * External API: Lecturer approves or rejects an AI-generated output.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class approve_output extends \external_api {

    public static function approve_parameters() {
        return new \external_function_parameters([
            'outputid' => new \external_value(PARAM_INT,  'Output record ID'),
            'courseid' => new \external_value(PARAM_INT,  'Course ID for context verification'),
            'action'   => new \external_value(PARAM_ALPHA, 'approve or reject'),
            'comment'  => new \external_value(PARAM_TEXT, 'Optional rejection comment', VALUE_DEFAULT, ''),
        ]);
    }

    public static function approve($outputid, $courseid, $action, $comment = '') {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::approve_parameters(),
            ['outputid' => $outputid, 'courseid' => $courseid, 'action' => $action, 'comment' => $comment]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:approveoutput', $context);

        // Verify the output belongs to this course.
        $output = $DB->get_record_sql(
            "SELECT o.* FROM {umat_ai_outputs} o
             JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
             WHERE o.id = :oid AND s.courseid = :cid",
            ['oid' => $params['outputid'], 'cid' => $params['courseid']]
        );

        if (!$output) {
            return [
                'success' => false,
                'message' => 'Output not found or does not belong to this course.',
            ];
        }

        if ($params['action'] === 'approve') {
            $DB->set_field('umat_ai_outputs', 'is_approved',   1,      ['id' => $output->id]);
            $DB->set_field('umat_ai_outputs', 'approved_by',   $USER->id, ['id' => $output->id]);
            $DB->set_field('umat_ai_outputs', 'timepublished', time(), ['id' => $output->id]);
            $message = get_string('approved_message', 'local_umat_ai');

        } elseif ($params['action'] === 'reject') {
            // Soft-delete: mark as approved = -1 so it's excluded from student queries
            // without losing the record for audit purposes.
            $DB->set_field('umat_ai_outputs', 'is_approved',   -1,     ['id' => $output->id]);
            $DB->set_field('umat_ai_outputs', 'approved_by',   $USER->id, ['id' => $output->id]);
            $message = get_string('rejected_message', 'local_umat_ai');

        } else {
            return ['success' => false, 'message' => 'Invalid action.'];
        }

        return ['success' => true, 'message' => $message];
    }

    public static function approve_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the action succeeded'),
            'message' => new \external_value(PARAM_TEXT, 'Feedback message'),
        ]);
    }
}
