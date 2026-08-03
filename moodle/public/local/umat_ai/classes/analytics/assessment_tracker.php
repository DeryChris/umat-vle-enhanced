<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class assessment_tracker {

    public static function get_course_assessments(int $courseid, bool $includeupcoming = false): array {
        global $DB;

        $now = time();
        $results = [];

        $assignments = $DB->get_records_sql(
            "SELECT id, name, duedate, cutoffdate, grade
               FROM {assign}
              WHERE course = :cid AND duedate > 0",
            ['cid' => $courseid]
        );

        foreach ($assignments as $a) {
            $duedate = (int) $a->duedate;
            $is_past_due = $duedate < $now;

            if (!$includeupcoming && !$is_past_due) {
                continue;
            }

            $results[] = [
                'id' => (int) $a->id,
                'name' => $a->name,
                'type' => 'assignment',
                'duedate' => $duedate,
                'max_grade' => (float) $a->grade,
                'is_past_due' => $is_past_due,
            ];
        }

        $quizzes = $DB->get_records_sql(
            "SELECT id, name, timeclose, grade
               FROM {quiz}
              WHERE course = :cid AND timeclose > 0",
            ['cid' => $courseid]
        );

        foreach ($quizzes as $q) {
            $duedate = (int) $q->timeclose;
            $is_past_due = $duedate < $now;

            if (!$includeupcoming && !$is_past_due) {
                continue;
            }

            $results[] = [
                'id' => (int) $q->id,
                'name' => $q->name,
                'type' => 'quiz',
                'duedate' => $duedate,
                'max_grade' => (float) $q->grade,
                'is_past_due' => $is_past_due,
            ];
        }

        usort($results, function ($a, $b) {
            return $a['duedate'] <=> $b['duedate'];
        });

        return $results;
    }

    public static function get_student_submissions(int $courseid, int $userid): array {
        global $DB;

        $now = time();
        $results = [];

        $assignments = $DB->get_records_sql(
            "SELECT a.id AS assignment, a.name, a.duedate, a.grade AS max_grade,
                    asub.timemodified, asub.status
               FROM {assign} a
               LEFT JOIN {assign_submission} asub
                 ON asub.assignment = a.id AND asub.userid = :uid
              WHERE a.course = :cid AND a.duedate > 0",
            ['uid' => $userid, 'cid' => $courseid]
        );

        foreach ($assignments as $a) {
            $submitted = !empty($a->timemodified);
            $timemodified = $submitted ? (int) $a->timemodified : null;
            $duedate = (int) $a->duedate;
            $max_grade = (float) $a->max_grade;

            $timediff = null;
            $status = 'not_attempted';

            if ($submitted) {
                $timediff = $timemodified - $duedate;
                if ($timediff > 0) {
                    $status = 'late';
                } else {
                    $status = 'submitted';
                }
            } elseif ($now > $duedate) {
                $status = 'missed';
            }

            $results[] = [
                'id' => (int) $a->assignment,
                'name' => $a->name,
                'type' => 'assignment',
                'duedate' => $duedate,
                'submitted' => $submitted,
                'timediff' => $submitted ? $timediff : null,
                'grade' => null,
                'status' => $status,
                'max_grade' => $max_grade,
            ];
        }

        $quizsql = "SELECT q.id AS quiz, q.name, q.timeclose, q.grade AS max_grade,
                           qa.timemodified, qa.state, qa.sumgrades
                      FROM {quiz} q
                      LEFT JOIN (
                          SELECT quiz, userid, timemodified, state, sumgrades,
                                 ROW_NUMBER() OVER (PARTITION BY quiz ORDER BY attempt DESC) AS rn
                            FROM {quiz_attempts}
                           WHERE userid = :uid AND preview = 0
                      ) qa ON qa.quiz = q.id AND qa.userid = :uid2 AND qa.rn = 1
                     WHERE q.course = :cid AND q.timeclose > 0";

        $quizrows = $DB->get_records_sql($quizsql, [
            'uid' => $userid,
            'uid2' => $userid,
            'cid' => $courseid,
        ]);

        foreach ($quizrows as $q) {
            $submitted = !empty($q->timemodified) && $q->state === 'finished';
            $timemodified = $submitted ? (int) $q->timemodified : null;
            $duedate = (int) $q->timeclose;
            $max_grade = (float) $q->max_grade;

            $timediff = null;
            $status = 'not_attempted';
            $grade = null;

            if ($submitted) {
                $timediff = $timemodified - $duedate;
                if ($timediff > 0) {
                    $status = 'late';
                } else {
                    $status = 'submitted';
                }
                $grade = isset($q->sumgrades) ? (float) $q->sumgrades : null;
            } elseif ($now > $duedate) {
                $status = 'missed';
            }

            $results[] = [
                'id' => (int) $q->quiz,
                'name' => $q->name,
                'type' => 'quiz',
                'duedate' => $duedate,
                'submitted' => $submitted,
                'timediff' => $submitted ? $timediff : null,
                'grade' => $grade,
                'status' => $status,
                'max_grade' => $max_grade,
            ];
        }

        usort($results, function ($a, $b) {
            return $a['duedate'] <=> $b['duedate'];
        });

        return $results;
    }

    public static function find_missed(int $courseid, int $userid): array {
        $submissions = self::get_student_submissions($courseid, $userid);
        $now = time();
        $missed = [];

        foreach ($submissions as $item) {
            if ($item['duedate'] >= $now) {
                continue;
            }
            if ($item['status'] === 'missed' || $item['status'] === 'not_attempted') {
                $missed[] = [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'due_date' => $item['duedate'],
                    'status' => $item['status'],
                ];
            }
        }

        return $missed;
    }

    public static function find_failed(int $courseid, int $userid): array {
        $submissions = self::get_student_submissions($courseid, $userid);
        $failed = [];

        foreach ($submissions as $item) {
            if ($item['grade'] === null || $item['max_grade'] <= 0) {
                continue;
            }
            $pct = ($item['grade'] / $item['max_grade']) * 100;
            if ($pct < 50) {
                $failed[] = [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'grade' => $item['grade'],
                    'max_grade' => $item['max_grade'],
                    'pct' => round($pct, 2),
                ];
            }
        }

        return $failed;
    }

    public static function count_missed(int $courseid, int $userid): int {
        return count(self::find_missed($courseid, $userid));
    }

    public static function count_total_past_due(int $courseid): int {
        $assessments = self::get_course_assessments($courseid, false);
        return count($assessments);
    }
}
