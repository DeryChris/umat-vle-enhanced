<?php
/**
 * External API: student issue/complaint reporting + lecturer management.
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

use local_umat_ai\issue_manager;

class issue_report extends \external_api {

    // ------------------------------------------------------------------ //
    // submit_issue — student submits a new issue report                   //
    // ------------------------------------------------------------------ //
    public static function submit_issue_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT, 'Course ID'),
            'category'    => new \external_value(PARAM_ALPHAEXT, 'Category: concept_confusion|material_error|technical_issue|suggestion|other'),
            'topic'       => new \external_value(PARAM_TEXT, 'Optional topic label', VALUE_DEFAULT, ''),
            'description' => new \external_value(PARAM_RAW, 'Description of the issue'),
        ]);
    }

    public static function submit_issue($courseid, $category, $topic, $description) {
        global $USER;

        try {
            $params = self::validate_parameters(self::submit_issue_parameters(), [
                'courseid'    => $courseid,
                'category'    => $category,
                'topic'       => $topic,
                'description' => $description,
            ]);
            $courseid    = (int)$params['courseid'];
            $category    = $params['category'];
            $topic       = trim($params['topic'] ?? '');
            $description = trim($params['description']);
            if (!$courseid) {
                return ['success' => false, 'issue_id' => 0, 'message' => 'Please open a course page to submit an issue report.'];
            }
            $categorymap = [
                'concept_confusion' => 'course_material',
                'material_error' => 'course_material',
                'technical_issue' => 'technical_problem',
                'suggestion' => 'other',
                'other' => 'other',
            ];
            $title = $topic !== '' ? $topic : ucwords(str_replace('_', ' ', $category));
            $clientid = 'legacyapi_' . sha1(implode('|', [
                $USER->id,
                $courseid,
                $category,
                $title,
                $description,
                sesskey(),
            ]));
            $created = issue_conversation::create_conversation(
                $courseid,
                $title,
                $categorymap[$category] ?? 'other',
                $description,
                $clientid
            );
            return [
                'success' => true,
                'issue_id' => $created['conversationid'],
                'message' => $created['notice'],
            ];
        } catch (\Throwable $e) {
            debugging('Legacy Student Issues submission failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['success' => false, 'issue_id' => 0, 'message' => 'Message not sent. Please try again.'];
        }
    }

    public static function submit_issue_returns() {
        return new \external_single_structure([
            'success'  => new \external_value(PARAM_BOOL, 'Success flag'),
            'issue_id' => new \external_value(PARAM_INT, 'New issue ID'),
            'message'  => new \external_value(PARAM_TEXT, 'Status message'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_student_issues — student views their own reports                //
    // ------------------------------------------------------------------ //
    public static function get_student_issues_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_student_issues($courseid = 0) {
        global $DB, $USER;
        try {
            $params = self::validate_parameters(self::get_student_issues_parameters(), ['courseid' => $courseid]);
            $userid = (int)$USER->id;

            $sql  = 'SELECT * FROM {umat_ai_issue_reports} WHERE userid = ?';
            $args = [$userid];
            if (!empty($params['courseid'])) {
                $sql   .= ' AND courseid = ?';
                $args[] = (int)$params['courseid'];
            }
            $sql .= ' ORDER BY timecreated DESC';

            $rows = $DB->get_records_sql($sql, $args);
            return array_values(array_map(function($r) {
                return [
                    'id'                => (int)$r->id,
                    'courseid'          => (int)$r->courseid,
                    'category'          => $r->category,
                    'topic'             => $r->topic ?? '',
                    'description'       => $r->description,
                    'status'            => $r->status,
                    'lecturer_response' => $r->lecturer_response ?? '',
                    'timecreated'       => (int)$r->timecreated,
                    'timemodified'      => (int)$r->timemodified,
                ];
            }, $rows ?: []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function get_student_issues_returns() {
        return new \external_multiple_structure(
            new \external_single_structure([
                'id'                => new \external_value(PARAM_INT, 'Issue ID'),
                'courseid'          => new \external_value(PARAM_INT, 'Course ID'),
                'category'          => new \external_value(PARAM_ALPHAEXT, 'Category'),
                'topic'             => new \external_value(PARAM_TEXT, 'Optional topic'),
                'description'       => new \external_value(PARAM_RAW, 'Issue description'),
                'status'            => new \external_value(PARAM_ALPHAEXT, 'Status: open|in_review|resolved|closed'),
                'lecturer_response' => new \external_value(PARAM_RAW, 'Lecturer public response to student'),
                'timecreated'       => new \external_value(PARAM_INT, 'Created timestamp'),
                'timemodified'      => new \external_value(PARAM_INT, 'Modified timestamp'),
            ])
        );
    }

    // ------------------------------------------------------------------ //
    // get_course_issues — lecturer views all issues for a course          //
    // ------------------------------------------------------------------ //
    public static function get_course_issues_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'status'   => new \external_value(PARAM_ALPHAEXT, 'Filter by status (open|in_review|resolved|closed, empty=all)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_course_issues($courseid, $status = '') {
        global $DB, $PAGE;
        $params = self::validate_parameters(self::get_course_issues_parameters(), [
            'courseid' => $courseid,
            'status'   => $status,
        ]);
        $courseid = (int)$params['courseid'];
        $status   = trim($params['status']);

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        issue_manager::require_manage_course($courseid);

        $sql  = 'SELECT r.*, u.firstname, u.lastname, u.picture, u.imagealt, u.email
                 FROM {umat_ai_issue_reports} r
                 JOIN {user} u ON u.id = r.userid
                 WHERE r.courseid = ?';
        $args = [$courseid];
        if ($status !== '') {
            $sql   .= ' AND r.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY r.status ASC, r.timecreated DESC';

        $rows = $DB->get_records_sql($sql, $args);
        $result = [];
        foreach ($rows as $r) {
            $userpic = new \user_picture((object)['id' => $r->userid, 'picture' => $r->picture, 'imagealt' => $r->imagealt, 'firstname' => $r->firstname, 'lastname' => $r->lastname, 'email' => $r->email]);
            $result[] = [
                'id'                => (int)$r->id,
                'userid'            => (int)$r->userid,
                'fullname'          => fullname($r),
                'userpicture'       => $userpic->get_url($PAGE)->out(false),
                'category'          => $r->category,
                'topic'             => $r->topic ?? '',
                'description'       => $r->description,
                'status'            => $r->status,
                'lecturer_notes'    => $r->lecturer_notes ?? '',
                'lecturer_response' => $r->lecturer_response ?? '',
                'timereplied'       => (int)($r->timereplied ?? 0),
                'timecreated'       => (int)$r->timecreated,
                'timemodified'      => (int)$r->timemodified,
            ];
        }
        return ['issues' => $result, 'total' => count($result)];
    }

    public static function get_course_issues_returns() {
        return new \external_single_structure([
            'issues' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'                => new \external_value(PARAM_INT, 'Issue ID'),
                    'userid'            => new \external_value(PARAM_INT, 'Student user ID'),
                    'fullname'          => new \external_value(PARAM_TEXT, 'Student full name'),
                    'userpicture'       => new \external_value(PARAM_URL, 'Student avatar URL'),
                    'category'          => new \external_value(PARAM_ALPHAEXT, 'Category'),
                    'topic'             => new \external_value(PARAM_TEXT, 'Optional topic'),
                    'description'       => new \external_value(PARAM_RAW, 'Issue description'),
                    'status'            => new \external_value(PARAM_ALPHAEXT, 'Status'),
                    'lecturer_notes'    => new \external_value(PARAM_RAW, 'Lecturer private notes'),
                    'lecturer_response' => new \external_value(PARAM_RAW, 'Lecturer public response to student'),
                    'timereplied'       => new \external_value(PARAM_INT, 'Timestamp when lecturer replied', VALUE_OPTIONAL),
                    'timecreated'       => new \external_value(PARAM_INT, 'Created timestamp'),
                    'timemodified'      => new \external_value(PARAM_INT, 'Modified timestamp'),
                ])
            ),
            'total' => new \external_value(PARAM_INT, 'Total count'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // update_issue_status — lecturer updates status + notes               //
    // ------------------------------------------------------------------ //
    public static function update_issue_status_parameters() {
        return new \external_function_parameters([
            'issue_id'      => new \external_value(PARAM_INT, 'Issue ID'),
            'status'        => new \external_value(PARAM_ALPHAEXT, 'New status: open|in_review|resolved|closed'),
            'lecturer_notes' => new \external_value(PARAM_RAW, 'Optional lecturer notes', VALUE_DEFAULT, ''),
        ]);
    }

    public static function update_issue_status($issue_id, $status, $lecturer_notes = '') {
        global $DB;
        $params = self::validate_parameters(self::update_issue_status_parameters(), [
            'issue_id'       => $issue_id,
            'status'         => $status,
            'lecturer_notes' => $lecturer_notes,
        ]);
        $issue_id = (int)$params['issue_id'];
        $status   = $params['status'];

        $record = $DB->get_record('umat_ai_issue_reports', ['id' => $issue_id]);
        if (!$record) {
            throw new \moodle_exception('Issue not found.');
        }

        $context = \context_course::instance($record->courseid);
        self::validate_context($context);
        issue_manager::require_manage_course((int)$record->courseid);

        $update = (object)[
            'id'            => $issue_id,
            'status'        => $status,
            'lecturer_notes' => $params['lecturer_notes'] !== '' ? $params['lecturer_notes'] : $record->lecturer_notes,
            'timemodified'  => time(),
        ];
        $DB->update_record('umat_ai_issue_reports', $update);

        return ['success' => true, 'message' => 'Issue status updated.'];
    }

    public static function update_issue_status_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success flag'),
            'message' => new \external_value(PARAM_TEXT, 'Status message'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // update_issue_response — lecturer posts a public response to student //
    // ------------------------------------------------------------------ //
    public static function update_issue_response_parameters() {
        return new \external_function_parameters([
            'issue_id' => new \external_value(PARAM_INT, 'Issue ID'),
            'response' => new \external_value(PARAM_RAW, 'Public response text to show to student'),
        ]);
    }

    public static function update_issue_response($issue_id, $response) {
        global $DB;
        $params = self::validate_parameters(self::update_issue_response_parameters(), [
            'issue_id' => $issue_id,
            'response' => $response,
        ]);
        $issue_id = (int)$params['issue_id'];
        $response = trim((string)$params['response']);

        $record = $DB->get_record('umat_ai_issue_reports', ['id' => $issue_id]);
        if (!$record) {
            throw new \moodle_exception('Issue not found.');
        }

        $context = \context_course::instance($record->courseid);
        self::validate_context($context);
        issue_manager::require_manage_course((int)$record->courseid);

        $now = time();
        $DB->update_record('umat_ai_issue_reports', (object)[
            'id'                => $issue_id,
            'lecturer_response' => $response !== '' ? $response : null,
            'status'            => $response !== '' ? 'resolved' : $record->status,
            'timereplied'       => $response !== '' ? $now : null,
            'response_seen'     => 0,
            'timemodified'      => $now,
        ]);

        return ['success' => true, 'message' => 'Response saved.'];
    }

    public static function update_issue_response_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success flag'),
            'message' => new \external_value(PARAM_TEXT, 'Status message'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_unread_response_count — student checks for new lecturer replies //
    // ------------------------------------------------------------------ //
    public static function get_unread_response_count_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_unread_response_count($courseid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(self::get_unread_response_count_parameters(), ['courseid' => $courseid]);
        $userid = (int)$USER->id;
        $sql = 'SELECT COUNT(*) FROM {umat_ai_issue_reports} WHERE userid = ? AND lecturer_response IS NOT NULL AND response_seen = 0';
        $args = [$userid];
        if (!empty($params['courseid'])) {
            $sql .= ' AND courseid = ?';
            $args[] = (int)$params['courseid'];
        }
        return ['count' => (int)$DB->get_field_sql($sql, $args)];
    }

    public static function get_unread_response_count_returns() {
        return new \external_single_structure([
            'count' => new \external_value(PARAM_INT, 'Unread response count'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // mark_responses_read — student marks all responses as seen          //
    // ------------------------------------------------------------------ //
    public static function mark_responses_read_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function mark_responses_read($courseid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(self::mark_responses_read_parameters(), ['courseid' => $courseid]);
        $userid = (int)$USER->id;
        $sql = 'UPDATE {umat_ai_issue_reports} SET response_seen = 1 WHERE userid = ? AND lecturer_response IS NOT NULL AND response_seen = 0';
        $args = [$userid];
        if (!empty($params['courseid'])) {
            $sql .= ' AND courseid = ?';
            $args[] = (int)$params['courseid'];
        }
        $DB->execute($sql, $args);
        return ['success' => true];
    }

    public static function mark_responses_read_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success flag'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_unresponded_issues_count — lecturer counts unresolved issues needing reply //
    // ------------------------------------------------------------------ //
    public static function get_unresponded_issues_count_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0=all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_unresponded_issues_count($courseid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(self::get_unresponded_issues_count_parameters(), ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        $ctx = $cid > 0 ? \context_course::instance($cid) : \context_system::instance();
        self::validate_context($ctx);
        require_capability('local/umat_ai:viewanalytics', $ctx);

        $sql = 'SELECT COUNT(*) FROM {umat_ai_issue_reports} WHERE status IN (?,?)';
        $args = ['open', 'in_review'];
        if ($cid > 0) {
            $sql .= ' AND courseid = ?';
            $args[] = $cid;
        }
        return ['count' => (int)$DB->get_field_sql($sql, $args)];
    }

    public static function get_unresponded_issues_count_returns() {
        return new \external_single_structure([
            'count' => new \external_value(PARAM_INT, 'Total issues count'),
        ]);
    }
}
