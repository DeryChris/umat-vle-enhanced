<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class topic_insight_builder {

    private static function config(): array {
        global $CFG;
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cfg;
    }

    /**
     * Fetch student-role chat logs for a course within the analytics window.
     *
     * Issue reports live in umat_ai_issue_reports and are deliberately never
     * read here — a login problem is not a course topic.
     *
     * @param int      $courseid
     * @param int|null $days Null uses the configured academic query window.
     * @return array
     */
    private static function fetch_course_logs(int $courseid, ?int $days = null): array {
        global $DB;

        $cfg = self::config();
        $days = $days ?? ($cfg['time_windows']['academic_queries'] ?? 14);
        $since = time() - ($days * DAYSECS);

        return $DB->get_records_select(
            'umat_ai_chat_logs',
            'courseid = :cid AND timecreated > :since AND role = :role',
            ['cid' => $courseid, 'since' => $since, 'role' => 'student'],
            'timecreated DESC',
            'id, userid, question, session_key, role, timecreated'
        );
    }

    /**
     * Cluster the course's academic questions into recurring lines of enquiry.
     *
     * @param int      $courseid
     * @param int|null $days
     * @return array
     */
    public static function build(int $courseid, ?int $days = null): array {
        global $DB;

        $logs = self::fetch_course_logs($courseid, $days);

        $academic = academic_query_classifier::filter_academic($logs);

        if (empty($academic)) {
            return [];
        }

        $map = academic_query_classifier::build_question_map($academic);

        if (empty($map)) {
            return [];
        }

        // How many distinct students asked anything academic at all — the only
        // honest denominator for "how widespread is this confusion".
        $askers = [];
        foreach ($academic as $log) {
            $askers[(int) $log->userid] = true;
        }
        $total_askers = max(1, count($askers));

        $insights = [];

        foreach ($map as $entry) {
            $question_count = (int) $entry['count'];
            $student_count = (int) $entry['student_count'];
            $studentids = $entry['studentids'];

            // A cluster nobody actually struggled with is not an insight.
            if ($student_count < 1 || $question_count < 1) {
                continue;
            }

            // Score combines breadth (how many students) with persistence (how
            // often they came back). Both are bounded ratios, so the result is
            // a genuine 0–100 figure rather than an arbitrary weighted sum.
            $breadth = $student_count / $total_askers;
            $repeats = $question_count - $student_count; // asks beyond the first each
            $persistence = min(1.0, $repeats / max(1, $student_count * 2));

            $score = safe_percentage::clamp_score((($breadth * 0.7) + ($persistence * 0.3)) * 100);

            $top_students = array_slice($studentids, 0, 5);

            $names = [];
            if (!empty($top_students)) {
                list($insql, $inparams) = $DB->get_in_or_equal($top_students, SQL_PARAMS_NAMED);
                $userrows = $DB->get_records_sql(
                    "SELECT id, firstname, lastname FROM {user} WHERE id $insql",
                    $inparams
                );
                foreach ($userrows as $u) {
                    $names[(int) $u->id] = trim($u->firstname . ' ' . $u->lastname);
                }
            }

            $insights[] = [
                // Until Phase 3 adds material-backed topic extraction, the
                // label is the fullest phrasing students actually used. It is
                // an observed line of enquiry, not a curriculum topic, and the
                // UI labels it as such.
                'topic_name'     => $entry['question'],
                'question_count' => $question_count,
                'student_count'  => $student_count,
                'total_askers'   => $total_askers,
                'struggle_score' => $score,
                'top_students'   => $top_students,
                'student_names'  => $names,
                'first_asked'    => $entry['first_asked'],
                'last_asked'     => $entry['last_asked'],
            ];
        }

        usort($insights, function ($a, $b) {
            return $b['struggle_score'] <=> $a['struggle_score'];
        });

        return array_slice($insights, 0, 15);
    }

    /**
     * Headline counts for the course's academic question activity.
     *
     * @param int      $courseid
     * @param int|null $days
     * @return array
     */
    public static function get_summary(int $courseid, ?int $days = null): array {
        $logs = self::fetch_course_logs($courseid, $days);

        $academic = academic_query_classifier::filter_academic($logs);
        $total_academic = count($academic);

        if ($total_academic === 0) {
            return [
                'total_academic_questions' => 0,
                'unique_topics' => 0,
                'students_who_asked' => 0,
                'most_confused_topic' => null,
            ];
        }

        $map = academic_query_classifier::build_question_map($academic);

        $student_set = [];
        foreach ($academic as $log) {
            $student_set[(int) $log->userid] = true;
        }

        $most_confused = null;
        if (!empty($map)) {
            $most_confused = $map[0]['question'];
        }

        return [
            'total_academic_questions' => $total_academic,
            'unique_topics' => count($map),
            'students_who_asked' => count($student_set),
            'most_confused_topic' => $most_confused,
        ];
    }

    /**
     * Per-student breakdown of which lines of enquiry they returned to.
     *
     * @param int      $courseid
     * @param int|null $days
     * @return array
     */
    public static function get_student_topics(int $courseid, ?int $days = null): array {
        global $DB;

        $logs = self::fetch_course_logs($courseid, $days);

        $academic = academic_query_classifier::filter_academic($logs);

        if (empty($academic)) {
            return [];
        }

        $map = academic_query_classifier::build_question_map($academic);

        $student_topic_map = [];

        foreach ($map as $entry) {
            foreach ($entry['studentids'] as $uid) {
                if (!isset($student_topic_map[$uid])) {
                    $student_topic_map[$uid] = [
                        'userid' => $uid,
                        'fullname' => '',
                        'topics' => [],
                    ];
                }
                $student_topic_map[$uid]['topics'][] = [
                    'topic' => $entry['question'],
                    'question_count' => (int) $entry['count'],
                ];
            }
        }

        foreach ($student_topic_map as &$st) {
            usort($st['topics'], function ($a, $b) {
                return $b['question_count'] <=> $a['question_count'];
            });
        }
        unset($st);

        $userids = array_keys($student_topic_map);
        if (!empty($userids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $userrows = $DB->get_records_sql(
                "SELECT id, firstname, lastname FROM {user} WHERE id $insql",
                $inparams
            );
            foreach ($userrows as $u) {
                $uid = (int) $u->id;
                if (isset($student_topic_map[$uid])) {
                    $student_topic_map[$uid]['fullname'] = trim($u->firstname . ' ' . $u->lastname);
                }
            }
        }

        $result = array_values($student_topic_map);

        usort($result, function ($a, $b) {
            $a_total = 0;
            foreach ($a['topics'] as $t) {
                $a_total += $t['question_count'];
            }
            $b_total = 0;
            foreach ($b['topics'] as $t) {
                $b_total += $t['question_count'];
            }
            return $b_total <=> $a_total;
        });

        return $result;
    }

    public static function get_topic_trends(int $courseid): array {
        global $DB;

        $now = time();
        $current_start = $now - (7 * DAYSECS);
        $previous_start = $current_start - (7 * DAYSECS);

        $fields = 'id, userid, question, session_key, role, timecreated';
        $where  = 'courseid = :cid AND timecreated > :since AND timecreated <= :until AND role = :role';

        $current_logs = $DB->get_records_select('umat_ai_chat_logs', $where,
            ['cid' => $courseid, 'since' => $current_start, 'until' => $now, 'role' => 'student'],
            'timecreated DESC', $fields);

        $previous_logs = $DB->get_records_select('umat_ai_chat_logs', $where,
            ['cid' => $courseid, 'since' => $previous_start, 'until' => $current_start, 'role' => 'student'],
            'timecreated DESC', $fields);

        $current_academic = academic_query_classifier::filter_academic($current_logs);
        $previous_academic = academic_query_classifier::filter_academic($previous_logs);

        $current_map = academic_query_classifier::build_question_map($current_academic);
        $previous_map = academic_query_classifier::build_question_map($previous_academic);

        $current_counts = [];
        foreach ($current_map as $entry) {
            $current_counts[$entry['question']] = (int) $entry['count'];
        }

        $previous_counts = [];
        foreach ($previous_map as $entry) {
            $previous_counts[$entry['question']] = (int) $entry['count'];
        }

        $all_topics = array_unique(array_merge(array_keys($current_counts), array_keys($previous_counts)));

        $trends = [];
        foreach ($all_topics as $topic) {
            $cc = $current_counts[$topic] ?? 0;
            $pc = $previous_counts[$topic] ?? 0;

            if ($cc > $pc) {
                $direction = 'increasing';
            } elseif ($cc < $pc) {
                $direction = 'decreasing';
            } else {
                $direction = 'stable';
            }

            $trends[] = [
                'topic' => $topic,
                'direction' => $direction,
                'current_count' => $cc,
                'previous_count' => $pc,
            ];
        }

        usort($trends, function ($a, $b) {
            return $b['current_count'] <=> $a['current_count'];
        });

        return $trends;
    }
}
