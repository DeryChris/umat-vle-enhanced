<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class quiz_attempt extends \external_api {

    // ------------------------------------------------------------------ //
    // save_quiz_attempt — UPSERT quiz state to server                    //
    // ------------------------------------------------------------------ //

    public static function save_quiz_attempt_parameters() {
        return new \external_function_parameters([
            'attempt_id'     => new \external_value(PARAM_INT, 'Existing attempt ID (0 = create new)', VALUE_DEFAULT, 0),
            'courseid'       => new \external_value(PARAM_INT, 'Course ID'),
            'session_key'    => new \external_value(PARAM_ALPHANUMEXT, 'Chat session key', VALUE_DEFAULT, ''),
            'quiz_title'     => new \external_value(PARAM_TEXT, 'Quiz title', VALUE_DEFAULT, ''),
            'questions_json' => new \external_value(PARAM_RAW, 'JSON of quiz questions', VALUE_DEFAULT, ''),
            'answers_json'   => new \external_value(PARAM_RAW, 'JSON of user answers', VALUE_DEFAULT, ''),
            'graded_json'    => new \external_value(PARAM_RAW, 'JSON of grading results', VALUE_DEFAULT, ''),
            'score'          => new \external_value(PARAM_INT, 'Number of correct answers', VALUE_DEFAULT, null),
            'total'          => new \external_value(PARAM_INT, 'Total questions', VALUE_DEFAULT, null),
            'status'         => new \external_value(PARAM_ALPHA, 'in_progress or completed', VALUE_DEFAULT, 'in_progress'),
        ]);
    }

    public static function save_quiz_attempt(
        $attempt_id = 0,
        $courseid = 0,
        $session_key = '',
        $quiz_title = '',
        $questions_json = '',
        $answers_json = '',
        $graded_json = '',
        $score = null,
        $total = null,
        $status = 'in_progress'
    ) {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_quiz_attempt_parameters(), [
            'attempt_id'     => $attempt_id,
            'courseid'       => $courseid,
            'session_key'    => $session_key,
            'quiz_title'     => $quiz_title,
            'questions_json' => $questions_json,
            'answers_json'   => $answers_json,
            'graded_json'    => $graded_json,
            'score'          => $score,
            'total'          => $total,
            'status'         => $status,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $now = time();

        if ($params['attempt_id'] > 0) {
            $existing = $DB->get_record('umat_ai_quiz_attempts', [
                'id' => $params['attempt_id'],
                'userid' => $USER->id,
            ]);
            if (!$existing) {
                throw new \moodle_exception('invalidattempt', 'local_umat_ai', '', null, 'Attempt not found or not owned by user');
            }
            $record = (object)[
                'id'             => $existing->id,
                'courseid'       => (int)$params['courseid'],
                'session_key'    => $params['session_key'] ?: $existing->session_key,
                'quiz_title'     => $params['quiz_title'] ?: $existing->quiz_title,
                'questions_json' => $params['questions_json'] ?: $existing->questions_json,
                'answers_json'   => $params['answers_json'] ?: $existing->answers_json,
                'graded_json'    => $params['graded_json'] ?: $existing->graded_json,
                'score'          => $params['score'] !== null ? (int)$params['score'] : $existing->score,
                'total'          => $params['total'] !== null ? (int)$params['total'] : $existing->total,
                'status'         => $params['status'],
                'timemodified'   => $now,
            ];
            $DB->update_record('umat_ai_quiz_attempts', $record);
            return ['attempt_id' => (int)$existing->id];
        }

        $record = (object)[
            'userid'         => (int)$USER->id,
            'courseid'       => (int)$params['courseid'],
            'session_key'    => $params['session_key'] ?: '',
            'quiz_title'     => $params['quiz_title'],
            'questions_json' => $params['questions_json'],
            'answers_json'   => $params['answers_json'],
            'graded_json'    => $params['graded_json'],
            'score'          => $params['score'] !== null ? (int)$params['score'] : null,
            'total'          => $params['total'] !== null ? (int)$params['total'] : null,
            'status'         => $params['status'],
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];
        $id = $DB->insert_record('umat_ai_quiz_attempts', $record);
        return ['attempt_id' => (int)$id];
    }

    public static function save_quiz_attempt_returns() {
        return new \external_single_structure([
            'attempt_id' => new \external_value(PARAM_INT, 'The attempt ID'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_quiz_attempts — list current user's quiz attempts               //
    // ------------------------------------------------------------------ //

    public static function get_quiz_attempts_parameters() {
        return new \external_function_parameters([
            'courseid'   => new \external_value(PARAM_INT, 'Filter by course (0 = all)', VALUE_DEFAULT, 0),
            'status'     => new \external_value(PARAM_ALPHA, 'Filter by status', VALUE_DEFAULT, ''),
            'attempt_id' => new \external_value(PARAM_INT, 'Get single attempt (0 = list all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_quiz_attempts($courseid = 0, $status = '', $attempt_id = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_quiz_attempts_parameters(), [
            'courseid'   => $courseid,
            'status'     => $status,
            'attempt_id' => $attempt_id,
        ]);

        $conditions = ['userid' => $USER->id];
        if ($params['courseid'] > 0) {
            $conditions['courseid'] = $params['courseid'];
            $context = \context_course::instance($params['courseid']);
            self::validate_context($context);
        }
        if (!empty($params['status'])) {
            $conditions['status'] = $params['status'];
        }
        if ($params['attempt_id'] > 0) {
            $conditions['id'] = $params['attempt_id'];
        }

        $records = $DB->get_records('umat_ai_quiz_attempts', $conditions, 'timemodified DESC');

        $attempts = [];
        foreach ($records as $r) {
            $questions = json_decode($r->questions_json, true);
            $answers   = json_decode($r->answers_json, true);
            $graded    = json_decode($r->graded_json, true);
            $qcount    = is_array($questions) ? count($questions) : 0;
            $gcount    = is_array($graded) ? count($graded) : 0;

            $attempts[] = [
                'attempt_id'     => (int)$r->id,
                'courseid'       => (int)$r->courseid,
                'session_key'    => $r->session_key ?? '',
                'quiz_title'     => $r->quiz_title ?? 'Practice Quiz',
                'questions_json' => $r->questions_json ?? '[]',
                'answers_json'   => $r->answers_json ?? '{}',
                'graded_json'    => $r->graded_json ?? '{}',
                'score'          => $r->score !== null ? (int)$r->score : null,
                'total'          => $r->total !== null ? (int)$r->total : $qcount,
                'question_count' => $qcount,
                'graded_count'   => $gcount,
                'status'         => $r->status,
                'timecreated'    => (int)$r->timecreated,
                'timemodified'   => (int)$r->timemodified,
            ];
        }

        // If single attempt requested, return it directly (not wrapped in array)
        if ($params['attempt_id'] > 0 && !empty($attempts)) {
            return $attempts[0];
        }

        return ['attempts' => $attempts];
    }

    public static function get_quiz_attempts_returns() {
        return new \external_single_structure([
            'attempts' => new \external_multiple_structure(
                new \external_single_structure([
                    'attempt_id'     => new \external_value(PARAM_INT),
                    'courseid'       => new \external_value(PARAM_INT),
                    'session_key'    => new \external_value(PARAM_ALPHANUMEXT),
                    'quiz_title'     => new \external_value(PARAM_TEXT),
                    'questions_json' => new \external_value(PARAM_RAW),
                    'answers_json'   => new \external_value(PARAM_RAW),
                    'graded_json'    => new \external_value(PARAM_RAW),
                    'score'          => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'total'          => new \external_value(PARAM_INT),
                    'question_count' => new \external_value(PARAM_INT),
                    'graded_count'   => new \external_value(PARAM_INT),
                    'status'         => new \external_value(PARAM_ALPHA),
                    'timecreated'    => new \external_value(PARAM_INT),
                    'timemodified'   => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // delete_quiz_attempt — remove a quiz attempt                         //
    // ------------------------------------------------------------------ //

    public static function delete_quiz_attempt_parameters() {
        return new \external_function_parameters([
            'attempt_id' => new \external_value(PARAM_INT, 'Attempt ID to delete'),
        ]);
    }

    public static function delete_quiz_attempt($attempt_id) {
        global $DB, $USER;

        $params = self::validate_parameters(self::delete_quiz_attempt_parameters(), [
            'attempt_id' => $attempt_id,
        ]);

        $record = $DB->get_record('umat_ai_quiz_attempts', ['id' => $params['attempt_id']], '*', MUST_EXIST);
        if ($record->userid != $USER->id) {
            throw new \moodle_exception('invalidattempt', 'local_umat_ai');
        }

        $context = \context_course::instance($record->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);

        $DB->delete_records('umat_ai_quiz_attempts', ['id' => $record->id]);
        return ['deleted' => true];
    }

    public static function delete_quiz_attempt_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_BOOL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_course_quiz_attempts — lecturer review: all attempts in course  //
    // ------------------------------------------------------------------ //

    public static function get_course_quiz_attempts_parameters() {
        return new \external_function_parameters([
            'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
            'userid'    => new \external_value(PARAM_INT, 'Filter by student (0 = all)', VALUE_DEFAULT, 0),
            'status'    => new \external_value(PARAM_ALPHA, 'Filter by status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_course_quiz_attempts($courseid, $userid = 0, $status = '') {
        global $DB;

        $params = self::validate_parameters(self::get_course_quiz_attempts_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
            'status'   => $status,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $sql = "SELECT qa.*, u.firstname, u.lastname, u.email
                FROM {umat_ai_quiz_attempts} qa
                JOIN {user} u ON u.id = qa.userid
                WHERE qa.courseid = :courseid";
        $sqlparams = ['courseid' => $params['courseid']];

        if ($params['userid'] > 0) {
            $sql .= " AND qa.userid = :userid";
            $sqlparams['userid'] = $params['userid'];
        }
        if (!empty($params['status'])) {
            $sql .= " AND qa.status = :status";
            $sqlparams['status'] = $params['status'];
        }
        $sql .= " ORDER BY qa.timemodified DESC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $attempts = [];
        foreach ($records as $r) {
            $questions = json_decode($r->questions_json, true);
            $qcount = is_array($questions) ? count($questions) : 0;
            $attempts[] = [
                'attempt_id'     => (int)$r->id,
                'userid'         => (int)$r->userid,
                'fullname'       => fullname($r),
                'email'          => $r->email ?? '',
                'courseid'       => (int)$r->courseid,
                'session_key'    => $r->session_key ?? '',
                'quiz_title'     => $r->quiz_title ?? 'Practice Quiz',
                'questions_json' => $r->questions_json ?? '[]',
                'answers_json'   => $r->answers_json ?? '{}',
                'graded_json'    => $r->graded_json ?? '{}',
                'score'          => $r->score !== null ? (int)$r->score : null,
                'total'          => $r->total !== null ? (int)$r->total : $qcount,
                'question_count' => $qcount,
                'status'         => $r->status,
                'timecreated'    => (int)$r->timecreated,
                'timemodified'   => (int)$r->timemodified,
            ];
        }

        return ['attempts' => $attempts];
    }

    public static function get_course_quiz_attempts_returns() {
        return new \external_single_structure([
            'attempts' => new \external_multiple_structure(
                new \external_single_structure([
                    'attempt_id'     => new \external_value(PARAM_INT),
                    'userid'         => new \external_value(PARAM_INT),
                    'fullname'       => new \external_value(PARAM_TEXT),
                    'email'          => new \external_value(PARAM_TEXT),
                    'courseid'       => new \external_value(PARAM_INT),
                    'session_key'    => new \external_value(PARAM_ALPHANUMEXT),
                    'quiz_title'     => new \external_value(PARAM_TEXT),
                    'questions_json' => new \external_value(PARAM_RAW),
                    'answers_json'   => new \external_value(PARAM_RAW),
                    'graded_json'    => new \external_value(PARAM_RAW),
                    'score'          => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'total'          => new \external_value(PARAM_INT),
                    'question_count' => new \external_value(PARAM_INT),
                    'status'         => new \external_value(PARAM_ALPHA),
                    'timecreated'    => new \external_value(PARAM_INT),
                    'timemodified'   => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_quiz_analytics — aggregate quiz stats for a course (lecturer)   //
    // ------------------------------------------------------------------ //

    public static function get_quiz_analytics_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_quiz_analytics($courseid) {
        global $DB;

        $params = self::validate_parameters(self::get_quiz_analytics_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $total = $DB->count_records('umat_ai_quiz_attempts', ['courseid' => $params['courseid']]);
        $completed = $DB->count_records('umat_ai_quiz_attempts', [
            'courseid' => $params['courseid'],
            'status' => 'completed',
        ]);
        $inprogress = $DB->count_records('umat_ai_quiz_attempts', [
            'courseid' => $params['courseid'],
            'status' => 'in_progress',
        ]);

        $avgScore = 0;
        $passing = 0;
        $scoredRecords = $DB->get_records_select('umat_ai_quiz_attempts',
            "courseid = :cid AND status = 'completed' AND score IS NOT NULL AND total IS NOT NULL AND total > 0",
            ['cid' => $params['courseid']]
        );
        $scoredCount = count($scoredRecords);
        if ($scoredCount > 0) {
            $totalScore = 0;
            foreach ($scoredRecords as $r) {
                $pct = ($r->score / $r->total) * 100;
                $totalScore += $pct;
                if ($pct >= 50) {
                    $passing++;
                }
            }
            $avgScore = round($totalScore / $scoredCount, 1);
        }

        $uniqueStudents = $DB->count_records_select('umat_ai_quiz_attempts',
            "courseid = :cid", ['cid' => $params['courseid']], "COUNT(DISTINCT userid)");

        return [
            'total_attempts'        => (int)$total,
            'completed_attempts'    => (int)$completed,
            'in_progress_attempts'  => (int)$inprogress,
            'average_score_pct'     => (float)$avgScore,
            'passing_attempts'      => (int)$passing,
            'unique_students'       => (int)$uniqueStudents,
        ];
    }

    public static function get_quiz_analytics_returns() {
        return new \external_single_structure([
            'total_attempts'       => new \external_value(PARAM_INT),
            'completed_attempts'   => new \external_value(PARAM_INT),
            'in_progress_attempts' => new \external_value(PARAM_INT),
            'average_score_pct'    => new \external_value(PARAM_FLOAT),
            'passing_attempts'     => new \external_value(PARAM_INT),
            'unique_students'      => new \external_value(PARAM_INT),
        ]);
    }
}
