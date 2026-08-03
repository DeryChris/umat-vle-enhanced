<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use local_umat_ai\analytics\bbb_attendance_analyser;

/**
 * External API: per-session attendance breakdown for a course.
 *
 * Returns each BBB session with lists of present/absent students,
 * per-student duration, and course-level summary stats.
 * Called by struggle_dashboard.js::openAttendancePanel().
 *
 * @package    local_umat_ai
 */
class get_session_attendance extends \external_api {

    public static function get_session_attendance_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_session_attendance($courseid) {
        $params = self::validate_parameters(self::get_session_attendance_parameters(), [
            'courseid' => $courseid,
        ]);
        $cid = (int) $params['courseid'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $result = bbb_attendance_analyser::get_session_attendance($cid);
        $result['available'] = bbb_attendance_analyser::is_available($cid);
        return $result;
    }

    public static function get_session_attendance_returns() {
        return new \external_single_structure([
            'available'            => new \external_value(PARAM_BOOL, 'BBB available for this course', VALUE_OPTIONAL),
            'total_sessions'       => new \external_value(PARAM_INT, 'Number of BBB sessions held'),
            'avg_attendance_rate'  => new \external_value(PARAM_FLOAT, 'Average attendance rate 0-1'),
            'never_attended_count' => new \external_value(PARAM_INT, 'Students who never attended any session'),
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'session_id'    => new \external_value(PARAM_INT, 'BBB session ID'),
                    'activity_name' => new \external_value(PARAM_TEXT, 'BBB activity name'),
                    'activity_id'   => new \external_value(PARAM_INT, 'BBB activity instance ID'),
                    'start_time'    => new \external_value(PARAM_INT, 'Unix timestamp of session start'),
                    'present_count' => new \external_value(PARAM_INT, 'Number of students present'),
                    'absent_count'  => new \external_value(PARAM_INT, 'Number of students absent'),
                    'present_students' => new \external_multiple_structure(
                        new \external_single_structure([
                            'userid'       => new \external_value(PARAM_INT, 'User ID'),
                            'fullname'     => new \external_value(PARAM_TEXT, 'Full name'),
                            'email'        => new \external_value(PARAM_TEXT, 'Email'),
                            'duration_min' => new \external_value(PARAM_FLOAT, 'Minutes in session', VALUE_OPTIONAL),
                        ])
                    ),
                    'absent_students' => new \external_multiple_structure(
                        new \external_single_structure([
                            'userid'   => new \external_value(PARAM_INT, 'User ID'),
                            'fullname' => new \external_value(PARAM_TEXT, 'Full name'),
                            'email'    => new \external_value(PARAM_TEXT, 'Email'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
