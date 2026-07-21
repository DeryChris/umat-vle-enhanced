<?php
/**
 * Public web services for login-page issue reporting.
 *
 * These services require NO login — they are used from the login page
 * so that students can report issues (e.g. trouble logging in) to their
 * lecturers before authenticating.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class login_issue extends \external_api {

    // ------------------------------------------------------------------ //
    // Rate limiting — 5 lookups per minute per IP                         //
    // ------------------------------------------------------------------ //
    private static function check_rate_limit(): void {
        global $DB;
        $ip   = getremoteaddr();
        $now  = time();
        $window = $now - 60;

        // Clean old entries.
        $DB->delete_records_select('umat_ai_login_lookup_log', 'timecreated < ?', [$window]);

        // Count recent lookups from this IP.
        $count = $DB->count_records('umat_ai_login_lookup_log', [
            'ip_address' => $ip,
        ]);
        if ($count >= 5) {
            throw new \moodle_exception('ratelimit', 'local_umat_ai', '',
                'Too many lookups. Please wait a minute and try again.');
        }

        // Log this lookup.
        $DB->insert_record('umat_ai_login_lookup_log', (object)[
            'ip_address'  => $ip,
            'timecreated' => $now,
        ]);
    }

    // ------------------------------------------------------------------ //
    // lookup_courses — find a user by username / idnumber / email         //
    // ------------------------------------------------------------------ //
    public static function lookup_courses_parameters() {
        return new \external_function_parameters([
            'username' => new \external_value(PARAM_TEXT, 'Student username, ID number, or email'),
        ]);
    }

    public static function lookup_courses_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'True if the user was found'),
            'message' => new \external_value(PARAM_RAW, 'Status message'),
            'courses' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'        => new \external_value(PARAM_INT, 'Course ID'),
                    'shortname' => new \external_value(PARAM_TEXT, 'Course short name'),
                    'fullname'  => new \external_value(PARAM_TEXT, 'Course full name'),
                ])
            ),
        ]);
    }

    public static function lookup_courses($username) {
        global $DB;
        try {
            $params = self::validate_parameters(self::lookup_courses_parameters(), [
                'username' => $username,
            ]);
            $username = trim($params['username']);

            if (strlen($username) < 2) {
                return [
                    'success' => false,
                    'message' => 'Please enter a valid student ID or username.',
                    'courses' => [],
                ];
            }

            // Rate limit.
            self::check_rate_limit();

            // Look up user by username, idnumber, or email.
            $user = $DB->get_record_select('user', 
                '(username = ? OR idnumber = ? OR email = ?) AND deleted = 0 AND suspended = 0',
                [$username, $username, $username],
                'id, firstname, lastname, username'
            );

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'No student found with that identifier.',
                    'courses' => [],
                ];
            }

            // Get courses where the user is enrolled as a student.
            $sql = "
                SELECT DISTINCT c.id, c.shortname, c.fullname
                FROM {course} c
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = ?
                WHERE c.id != ?
                ORDER BY c.fullname ASC
            ";
            $courses = $DB->get_records_sql($sql, [$user->id, SITEID]);

            if (empty($courses)) {
                return [
                    'success' => true,
                    'message' => 'No courses found for this student.',
                    'courses' => [],
                ];
            }

            return [
                'success' => true,
                'message' => count($courses) . ' course(s) found.',
                'courses' => array_values(array_map(function($c) {
                    return [
                        'id'        => (int)$c->id,
                        'shortname' => $c->shortname,
                        'fullname'  => $c->fullname,
                    ];
                }, $courses)),
            ];

        } catch (\moodle_exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'courses' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'An error occurred. Please try again later.',
                'courses' => [],
            ];
        }
    }

    // ------------------------------------------------------------------ //
    // submit_issue — submit a login issue report (no auth required)       //
    // ------------------------------------------------------------------ //
    public static function submit_issue_parameters() {
        return new \external_function_parameters([
            'username'    => new \external_value(PARAM_TEXT, 'Student username/ID'),
            'courseid'    => new \external_value(PARAM_INT, 'Course ID to report about'),
            'description' => new \external_value(PARAM_RAW, 'Description of the issue'),
            'name'        => new \external_value(PARAM_TEXT, 'Optional student name', VALUE_DEFAULT, ''),
        ]);
    }

    public static function submit_issue_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'True if the report was submitted'),
            'message' => new \external_value(PARAM_RAW, 'Status message'),
            'issue_id' => new \external_value(PARAM_INT, 'The new issue ID'),
        ]);
    }

    public static function submit_issue($username, $courseid, $description, $name = '') {
        global $DB;
        try {
            $params = self::validate_parameters(self::submit_issue_parameters(), [
                'username'    => $username,
                'courseid'    => $courseid,
                'description' => $description,
                'name'        => $name,
            ]);
            $username    = trim($params['username']);
            $courseid    = (int)$params['courseid'];
            $description = trim($params['description']);
            $name        = trim($params['name']);

            if (strlen($description) < 10) {
                return [
                    'success' => false,
                    'message' => 'Please describe the issue in more detail (at least 10 characters).',
                    'issue_id' => 0,
                ];
            }

            if (!$courseid) {
                return [
                    'success' => false,
                    'message' => 'Please select a course.',
                    'issue_id' => 0,
                ];
            }

            // Find the user to link the report (if user exists).
            $userid = 0;
            $user = $DB->get_record_select('user',
                '(username = ? OR idnumber = ? OR email = ?) AND deleted = 0',
                [$username, $username, $username],
                'id'
            );
            if ($user) {
                $userid = (int)$user->id;
            }

            // Create the report (userid=0 for unknown/guest users).
            $now = time();
            $id = $DB->insert_record('umat_ai_issue_reports', (object)[
                'userid'          => $userid,
                'courseid'        => $courseid,
                'category'        => 'login_issue',
                'topic'           => 'Login Issue Report',
                'description'     => $description,
                'status'          => 'open',
                'lecturer_notes'  => null,
                'reporter_name'   => $name ?: null,
                'reporter_username' => $username,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);

            return [
                'success' => true,
                'message' => 'Your issue has been submitted. Your lecturer will review it shortly.',
                'issue_id' => (int)$id,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to submit your issue. Please try again later.',
                'issue_id' => 0,
            ];
        }
    }
}
