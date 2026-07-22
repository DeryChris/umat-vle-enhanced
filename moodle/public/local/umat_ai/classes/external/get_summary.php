<?php
/**
 * External API: Get AI-generated session outputs (summary, notes, quiz).
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_summary extends \external_api {

    public static function get_session_outputs_parameters() {
        return new \external_function_parameters([
            'sessionid' => new \external_value(PARAM_INT, 'Session record ID (0 = latest)'),
            'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
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

        // Resolve session.
        if ($params['sessionid'] > 0) {
            $session = $DB->get_record('umat_ai_sessions', [
                'id'       => $params['sessionid'],
                'courseid' => $params['courseid'],
            ]);
        } else {
            $session = $DB->get_record_sql(
                "SELECT * FROM {umat_ai_sessions}
                  WHERE courseid = :cid AND status = 'completed'
                  ORDER BY timecreated DESC",
                ['cid' => $params['courseid']],
                IGNORE_MULTIPLE
            );
        }

        if (!$session) {
            return ['outputs' => []];
        }

        // Only return approved outputs to students.
        $outputs = $DB->get_records('umat_ai_outputs', [
            'sessionrecordid' => $session->id,
            'is_approved'     => 1,
        ]);

        $result = [];
        foreach ($outputs as $out) {
            $result[] = [
                'type'    => $out->output_type,
                'content' => $out->content ?? '',
            ];
        }

        return ['outputs' => $result];
    }

    public static function get_session_outputs_returns() {
        return new \external_single_structure([
            'outputs' => new \external_multiple_structure(
                new \external_single_structure([
                    'type'    => new \external_value(PARAM_TEXT),
                    'content' => new \external_value(PARAM_RAW),
                ])
            ),
        ]);
    }
}
