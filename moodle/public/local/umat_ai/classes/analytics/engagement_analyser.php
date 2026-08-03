<?php
/**
 * Platform engagement signals: recency of activity and login frequency.
 *
 * Everything here measures presence on the platform. None of it is attendance
 * — page views are not a substitute for BigBlueButton join records, and the
 * dashboard labels the two separately.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class engagement_analyser {

    /**
     * @return array
     */
    private static function config(): array {
        global $CFG;
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cfg;
    }

    /**
     * Count of course page views in the window.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $days
     * @return int
     */
    public static function get_login_frequency(int $userid, int $courseid, int $days = 14): int {
        global $DB;
        $since = time() - ($days * DAYSECS);
        return (int) $DB->get_field_sql(
            "SELECT COUNT(*) FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid AND timecreated > :since AND action = 'viewed'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $since]
        );
    }

    /**
     * Timestamp of the most recent activity of any tracked kind.
     *
     * @param int $userid
     * @param int $courseid
     * @return int|null Null when the student has no recorded activity at all.
     */
    public static function get_last_active_time(int $userid, int $courseid): ?int {
        global $DB;
        $maxtime = 0;

        $chatmax = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {umat_ai_chat_logs} WHERE userid = :uid AND courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        if ($chatmax && (int) $chatmax > $maxtime) {
            $maxtime = (int) $chatmax;
        }

        $quizmax = $DB->get_field_sql(
            "SELECT MAX(qg.timemodified) FROM {quiz_grades} qg
               JOIN {quiz} q ON q.id = qg.quiz
              WHERE qg.userid = :uid AND q.course = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        if ($quizmax && (int) $quizmax > $maxtime) {
            $maxtime = (int) $quizmax;
        }

        $logmax = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log} WHERE userid = :uid AND courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        if ($logmax && (int) $logmax > $maxtime) {
            $maxtime = (int) $logmax;
        }

        return $maxtime > 0 ? $maxtime : null;
    }

    /**
     * Days since the student was last active on this course.
     *
     * Returns null — not 999 — when there is no activity record at all. A
     * student who has never appeared in the logs is missing data, and missing
     * data must not raise a risk score.
     *
     * @param int $userid
     * @param int $courseid
     * @return int|null
     */
    public static function get_days_inactive(int $userid, int $courseid): ?int {
        $lastactive = self::get_last_active_time($userid, $courseid);
        if ($lastactive === null) {
            return null;
        }
        return (int) max(0, floor((time() - $lastactive) / DAYSECS));
    }

    /**
     * Which subsystem the student was last seen in.
     *
     * @param int $userid
     * @param int $courseid
     * @return string chat | quiz | platform | none
     */
    public static function get_last_active_source(int $userid, int $courseid): string {
        global $DB;
        $sources = [];

        $chatmax = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {umat_ai_chat_logs} WHERE userid = :uid AND courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        $sources['chat'] = (int) ($chatmax ?: 0);

        $quizmax = $DB->get_field_sql(
            "SELECT MAX(qg.timemodified) FROM {quiz_grades} qg
               JOIN {quiz} q ON q.id = qg.quiz
              WHERE qg.userid = :uid AND q.course = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        $sources['quiz'] = (int) ($quizmax ?: 0);

        $logmax = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log} WHERE userid = :uid AND courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
        $sources['platform'] = (int) ($logmax ?: 0);

        arsort($sources);
        $best = key($sources);

        return ($sources[$best] > 0) ? $best : 'none';
    }

    /**
     * Whether platform activity is rising or falling.
     *
     * More activity is "improving", so the raw direction from compute_trend is
     * used unchanged here.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function get_activity_trend(int $userid, int $courseid): array {
        $cfg = self::config();
        $days = $cfg['time_windows']['activity'] ?? 14;
        $threshold = $cfg['trend']['login_delta'] ?? 2.0;

        $current  = self::get_login_frequency($userid, $courseid, $days);
        $previous = self::get_login_frequency_offset($userid, $courseid, $days, $days);

        $result = trend_analyser::compute_trend((float) $current, (float) $previous, $threshold);
        $result['current_count']  = $current;
        $result['previous_count'] = $previous;

        return $result;
    }

    /**
     * Page views in the window immediately preceding the current one.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $days
     * @param int $offsetdays
     * @return int
     */
    private static function get_login_frequency_offset(int $userid, int $courseid, int $days, int $offsetdays): int {
        global $DB;
        $end   = time() - ($offsetdays * DAYSECS);
        $start = $end - ($days * DAYSECS);
        return (int) $DB->get_field_sql(
            "SELECT COUNT(*) FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid
                AND timecreated > :start AND timecreated <= :end AND action = 'viewed'",
            ['uid' => $userid, 'cid' => $courseid, 'start' => $start, 'end' => $end]
        );
    }
}
