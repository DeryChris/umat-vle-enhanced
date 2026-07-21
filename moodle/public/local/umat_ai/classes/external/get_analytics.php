<?php
/**
 * External API: course analytics for the lecturer.
 * Bug fixes: replaced count_records_sql + GROUP BY → get_field_sql with subquery.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class get_analytics extends \external_api {

    public static function get_course_analytics_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 30),
        ]);
    }

    public static function get_course_analytics($courseid, $days = 30) {
        global $DB;

        $params = self::validate_parameters(
            self::get_course_analytics_parameters(),
            ['courseid' => $courseid, 'days' => $days]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $since = time() - ($params['days'] * DAYSECS);
        $cid   = (int) $params['courseid'];

        // 1. Enrolled students count.
        $enrolledCount = (int) count_enrolled_users($context, '', 0, true);

        // 2. Active students (unique AI users in period).
        $activeStudents = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT userid) FROM {umat_ai_chat_logs}
             WHERE courseid = :cid AND timecreated > :since AND role = 'student'",
            ['cid' => $cid, 'since' => $since]
        ) ?: 0;

        // 3. Total AI interactions.
        $totalInteractions = (int) $DB->count_records_select(
            'umat_ai_chat_logs',
            'courseid = :cid AND timecreated > :since',
            ['cid' => $cid, 'since' => $since]
        );

        // 4. Pending approvals.
        $pendingApprovals = (int) $DB->get_field_sql(
            "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
             JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
             WHERE s.courseid = :cid AND o.is_approved = 0",
            ['cid' => $cid]
        ) ?: 0;

        // 5. Daily counts — last 14 days.
        $dailyCounts = [];
        $maxDaily    = 0;
        for ($i = 13; $i >= 0; $i--) {
            $dayStart = mktime(0, 0, 0) - ($i * DAYSECS);
            $dayEnd   = $dayStart + DAYSECS;
            $count    = (int) $DB->count_records_select(
                'umat_ai_chat_logs',
                'courseid = :cid AND timecreated >= :from AND timecreated < :to',
                ['cid' => $cid, 'from' => $dayStart, 'to' => $dayEnd]
            );
            $dailyCounts[] = ['label' => date('D', $dayStart), 'count' => $count];
            if ($count > $maxDaily) $maxDaily = $count;
        }

        // 6. Top 10 questions (grouped + ranked).
        $rawQuestions = $DB->get_records_sql(
            "SELECT question, COUNT(*) AS ask_count
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           GROUP BY question
           ORDER BY ask_count DESC",
            ['cid' => $cid, 'since' => $since],
            0, 10
        );

        $topQuestions = array_values(array_map(function ($q) {
            $cleanText = preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $q->question);
            $text = mb_strlen($cleanText) > 110
                ? mb_substr($cleanText, 0, 107) . '…'
                : $cleanText;
            return ['text' => $text, 'ask_count' => (int) $q->ask_count];
        }, (array) $rawQuestions));

        // 7. Struggle index — the course material students cite most in their
        //    questions (human-readable, unlike the old session-key fragment).
        $struggleIndex = 'N/A';
        $struggleCount = 0;
        $sourceRows = $DB->get_records_sql(
            "SELECT id, sources FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
                AND sources IS NOT NULL AND sources != '' AND sources != '[]'",
            ['cid' => $cid, 'since' => $since]
        );
        $sourceCounts = [];
        foreach ($sourceRows as $row) {
            $srcs = json_decode($row->sources, true);
            if (!is_array($srcs)) continue;
            foreach (array_unique(array_filter($srcs, 'is_string')) as $src) {
                $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
            }
        }
        if (!empty($sourceCounts)) {
            arsort($sourceCounts);
            $topSource = array_key_first($sourceCounts);
            // Strip the file extension for display.
            $struggleIndex = pathinfo($topSource, PATHINFO_FILENAME);
            $struggleCount = (int) $sourceCounts[$topSource];
        }

        // 8. Avg questions per session.
        $avgQPS = 0.0;
        $sessRows = $DB->get_records_sql(
            "SELECT session_key, COUNT(*) AS q_count
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
                AND session_key IS NOT NULL AND session_key != ''
           GROUP BY session_key",
            ['cid' => $cid, 'since' => $since]
        );
        if (!empty($sessRows)) {
            $totQs = array_sum(array_column((array)$sessRows, 'q_count'));
            $avgQPS = round($totQs / count($sessRows), 1);
        }

        // 9. High performers: users with >= 10 interactions.
        //    FIX: use get_field_sql with subquery instead of count_records_sql + GROUP BY.
        $highPerformers = (int) $DB->get_field_sql(
            "SELECT COUNT(*) FROM (
                SELECT userid
                  FROM {umat_ai_chat_logs}
                 WHERE courseid = :cid AND timecreated > :since AND role = 'student'
              GROUP BY userid
                HAVING COUNT(*) >= 10
             ) hp_subq",
            ['cid' => $cid, 'since' => $since]
        ) ?: 0;

        return [
            'enrolled_students'          => $enrolledCount,
            'active_students'            => $activeStudents,
            'total_interactions'         => $totalInteractions,
            'pending_approvals'          => $pendingApprovals,
            'struggle_index'             => $struggleIndex,
            'struggle_count'             => $struggleCount,
            'avg_questions_per_session'  => (float) $avgQPS,
            'high_performers'            => $highPerformers,
            'max_daily'                  => $maxDaily,
            'daily_counts'               => $dailyCounts,
            'top_questions'              => $topQuestions,
        ];
    }

    public static function get_course_analytics_returns() {
        return new \external_single_structure([
            'enrolled_students'         => new \external_value(PARAM_INT),
            'active_students'           => new \external_value(PARAM_INT),
            'total_interactions'        => new \external_value(PARAM_INT),
            'pending_approvals'         => new \external_value(PARAM_INT),
            'struggle_index'            => new \external_value(PARAM_TEXT),
            'struggle_count'            => new \external_value(PARAM_INT),
            'avg_questions_per_session' => new \external_value(PARAM_FLOAT),
            'high_performers'           => new \external_value(PARAM_INT),
            'max_daily'                 => new \external_value(PARAM_INT),
            'daily_counts'              => new \external_multiple_structure(
                new \external_single_structure([
                    'label' => new \external_value(PARAM_TEXT),
                    'count' => new \external_value(PARAM_INT),
                ])
            ),
            'top_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'text'      => new \external_value(PARAM_TEXT),
                    'ask_count' => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }
}
