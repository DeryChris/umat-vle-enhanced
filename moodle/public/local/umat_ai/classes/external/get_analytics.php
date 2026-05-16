<?php
/**
 * External API: Get course analytics for the lecturer dashboard.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

        $since = time() - ($params['days'] * 86400);

        // ---- 1. Total enrolled students --------------------------------- //
        $enrolled = count_enrolled_users($context, '', 0, true);

        // ---- 2. Active students in period (unique users who asked AI) --- //
        $activeStudents = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {umat_ai_chat_logs}
              WHERE courseid = :courseid AND timecreated > :since AND role = :role",
            ['courseid' => $params['courseid'], 'since' => $since, 'role' => 'student']
        );

        // ---- 3. Total AI interactions in period ------------------------- //
        $totalInteractions = $DB->count_records_select(
            'umat_ai_chat_logs',
            'courseid = :courseid AND timecreated > :since',
            ['courseid' => $params['courseid'], 'since' => $since]
        );

        // ---- 4. Pending approvals --------------------------------------- //
        $pendingApprovals = $DB->count_records_sql(
            "SELECT COUNT(o.id)
               FROM {umat_ai_outputs} o
               JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
              WHERE s.courseid = :courseid AND o.is_approved = 0",
            ['courseid' => $params['courseid']]
        );

        // ---- 5. Daily interaction counts (last 7 days for chart) -------- //
        $dailyCounts = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime('today') - ($i * 86400);
            $dayEnd   = $dayStart + 86400;
            $count    = $DB->count_records_select(
                'umat_ai_chat_logs',
                'courseid = :courseid AND timecreated >= :from AND timecreated < :to',
                ['courseid' => $params['courseid'], 'from' => $dayStart, 'to' => $dayEnd]
            );
            $dailyCounts[] = [
                'label' => date('D', $dayStart),
                'count' => (int) $count,
            ];
        }

        // ---- 6. Top 5 most asked questions (by similarity grouping) ----- //
        // Simple approach: return the 5 most recent unique questions.
        $topQuestions = $DB->get_records_sql(
            "SELECT question, COUNT(*) AS ask_count
               FROM {umat_ai_chat_logs}
              WHERE courseid = :courseid AND timecreated > :since AND role = :role
           GROUP BY question
           ORDER BY ask_count DESC",
            ['courseid' => $params['courseid'], 'since' => $since, 'role' => 'student'],
            0,
            5
        );

        $questions = [];
        foreach ($topQuestions as $q) {
            $text = strlen($q->question) > 100
                ? substr($q->question, 0, 97) . '...'
                : $q->question;
            $questions[] = [
                'text'      => $text,
                'ask_count' => (int) $q->ask_count,
            ];
        }

        // ---- 7. Struggle index — lecture with most questions ------------ //
        // For now: which unique session_key had the highest question count.
        $struggleIndex = 'N/A';
        $struggleRaw   = $DB->get_records_sql(
            "SELECT session_key, COUNT(*) AS cnt
               FROM {umat_ai_chat_logs}
              WHERE courseid = :courseid AND timecreated > :since AND role = :role
                AND session_key IS NOT NULL AND session_key != ''
           GROUP BY session_key
           ORDER BY cnt DESC",
            ['courseid' => $params['courseid'], 'since' => $since, 'role' => 'student'],
            0, 1
        );
        if (!empty($struggleRaw)) {
            $top = array_values($struggleRaw)[0];
            // Use a trimmed label from the session key.
            $struggleIndex = 'Session ' . strtoupper(substr($top->session_key, 0, 6));
        }

        // ---- 8. Average session length (rough: questions per session) --- //
        $sessionsWithCount = $DB->get_records_sql(
            "SELECT session_key, COUNT(*) AS q_count
               FROM {umat_ai_chat_logs}
              WHERE courseid = :courseid AND timecreated > :since AND role = :role
                AND session_key IS NOT NULL AND session_key != ''
           GROUP BY session_key",
            ['courseid' => $params['courseid'], 'since' => $since, 'role' => 'student']
        );
        $avgQuestionsPerSession = 0;
        if (!empty($sessionsWithCount)) {
            $total = array_sum(array_column((array) $sessionsWithCount, 'q_count'));
            $avgQuestionsPerSession = round($total / count($sessionsWithCount), 1);
        }

        return [
            'enrolled_students'       => (int) $enrolled,
            'active_students'         => (int) $activeStudents,
            'total_interactions'      => (int) $totalInteractions,
            'pending_approvals'       => (int) $pendingApprovals,
            'struggle_index'          => $struggleIndex,
            'avg_questions_per_session' => (float) $avgQuestionsPerSession,
            'daily_counts'            => $dailyCounts,
            'top_questions'           => $questions,
        ];
    }

    public static function get_course_analytics_returns() {
        return new \external_single_structure([
            'enrolled_students'         => new \external_value(PARAM_INT),
            'active_students'           => new \external_value(PARAM_INT),
            'total_interactions'        => new \external_value(PARAM_INT),
            'pending_approvals'         => new \external_value(PARAM_INT),
            'struggle_index'            => new \external_value(PARAM_TEXT),
            'avg_questions_per_session' => new \external_value(PARAM_FLOAT),
            'daily_counts'              => new \external_multiple_structure(
                new \external_single_structure([
                    'label' => new \external_value(PARAM_TEXT),
                    'count' => new \external_value(PARAM_INT),
                ])
            ),
            'top_questions'             => new \external_multiple_structure(
                new \external_single_structure([
                    'text'      => new \external_value(PARAM_TEXT),
                    'ask_count' => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }
}
