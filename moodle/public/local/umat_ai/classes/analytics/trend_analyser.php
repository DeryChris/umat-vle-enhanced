<?php
/**
 * Period-over-period trend computation.
 *
 * Two rules apply throughout:
 *
 *  1. "improving" always means better for the student. For metrics where a
 *     larger number is worse (days inactive), the direction is inverted before
 *     it is returned — the previous implementation reported a student who had
 *     gone from 2 to 12 days inactive as "improving".
 *  2. A percentage change is only reported when the prior period is large
 *     enough to divide by; otherwise pct_change is null and comparable is
 *     false. Callers show the raw counts instead.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class trend_analyser {

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
     * Compare two periods of a metric where higher is better.
     *
     * @param float $current
     * @param float $previous
     * @param float $threshold  Change smaller than this is "stable".
     * @param bool  $higherisbetter Set false for metrics like days-inactive.
     * @return array
     */
    public static function compute_trend(float $current, float $previous, float $threshold, bool $higherisbetter = true): array {
        $delta = $current - $previous;

        if (abs($delta) <= $threshold) {
            $direction = 'stable';
        } else if ($delta > 0) {
            $direction = $higherisbetter ? 'improving' : 'declining';
        } else {
            $direction = $higherisbetter ? 'declining' : 'improving';
        }

        $cfg = self::config();
        $mindenominator = $cfg['min_denominator']['trend_previous_period'] ?? 5;
        $change = safe_percentage::change($current, $previous, $mindenominator);

        return [
            'direction'  => $direction,
            'delta'      => round($delta, 4),
            'pct_change' => $change['pct_change'],
            'comparable' => $change['comparable'],
        ];
    }

    /**
     * Quiz average this window versus the previous window, as percentages.
     *
     * Averages are normalised against each quiz's maximum grade. The previous
     * implementation averaged raw grades, so a 10-point quiz and a 100-point
     * quiz were added together as if they were comparable.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function quiz_trend(int $userid, int $courseid): array {
        $config = self::config();
        $windowdays = $config['time_windows']['quiz_grades'] ?? 14;
        $threshold  = $config['trend']['quiz_delta'] ?? 5.0;

        $now = time();
        $currentstart  = $now - ($windowdays * DAYSECS);
        $previousstart = $currentstart - ($windowdays * DAYSECS);

        [$currentavg, $currentcount]   = self::get_quiz_avg_pct($userid, $courseid, $currentstart, $now);
        [$previousavg, $previouscount] = self::get_quiz_avg_pct($userid, $courseid, $previousstart, $currentstart);

        // Without a graded attempt in both windows there is no trend to report.
        if ($currentcount === 0 || $previouscount === 0) {
            return [
                'direction'    => 'unknown',
                'delta'        => 0.0,
                'pct_change'   => null,
                'comparable'   => false,
                'current_avg'  => $currentavg,
                'previous_avg' => $previousavg,
                'current_count'  => $currentcount,
                'previous_count' => $previouscount,
            ];
        }

        $result = self::compute_trend($currentavg, $previousavg, $threshold, true);
        // Percentage-point movement is the meaningful figure for grades, and it
        // is always valid; the ratio-style pct_change is not.
        $result['pct_change']     = null;
        $result['comparable']     = true;
        $result['current_avg']    = round($currentavg, 1);
        $result['previous_avg']   = round($previousavg, 1);
        $result['current_count']  = $currentcount;
        $result['previous_count'] = $previouscount;

        return $result;
    }

    /**
     * Average grade as a percentage of each quiz's maximum.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $since
     * @param int $until
     * @return array [float avgPct, int attemptCount]
     */
    private static function get_quiz_avg_pct(int $userid, int $courseid, int $since, int $until): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT qg.id, qg.grade, q.grade AS maxgrade
               FROM {quiz_grades} qg
               JOIN {quiz} q ON q.id = qg.quiz
              WHERE qg.userid = :uid AND q.course = :cid
                AND qg.timemodified >= :since AND qg.timemodified < :until
                AND q.grade > 0",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $since, 'until' => $until]
        );

        if (empty($rows)) {
            return [0.0, 0];
        }

        $total = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $maxg = (float) $r->maxgrade;
            if ($maxg > 0) {
                $total += ((float) $r->grade / $maxg) * 100;
                $count++;
            }
        }

        return $count > 0 ? [$total / $count, $count] : [0.0, 0];
    }

    /**
     * Whether the gap since last activity is growing.
     *
     * More days inactive is worse, so higherisbetter is false.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function inactivity_trend(int $userid, int $courseid): array {
        $config = self::config();
        $threshold = $config['trend']['inactivity_delta'] ?? 2.0;

        $now = time();
        $windowdays = 14;
        $currentstart  = $now - ($windowdays * DAYSECS);
        $previousstart = $currentstart - ($windowdays * DAYSECS);

        $currentlast  = self::get_last_active($userid, $courseid, $currentstart, $now);
        $previouslast = self::get_last_active($userid, $courseid, $previousstart, $currentstart);

        // No activity in a window means the student was inactive for all of it.
        $currentdays = $currentlast !== null
            ? (int) floor(($now - $currentlast) / DAYSECS)
            : $windowdays;

        $previousdays = $previouslast !== null
            ? (int) floor(($currentstart - $previouslast) / DAYSECS)
            : $windowdays;

        $result = self::compute_trend((float) $currentdays, (float) $previousdays, $threshold, false);
        $result['current_days']  = $currentdays;
        $result['previous_days'] = $previousdays;

        return $result;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @param int $since
     * @param int $until
     * @return int|null
     */
    private static function get_last_active(int $userid, int $courseid, int $since, int $until): ?int {
        global $DB;

        $result = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid
                AND timecreated >= :since AND timecreated < :until",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $since, 'until' => $until]
        );

        return $result ? (int) $result : null;
    }

    /**
     * Movement in the student's own risk score over time.
     *
     * There is no per-student risk history table in this plugin — the
     * umat_ai_metric_trends snapshots are course-level only — so this reports
     * "unknown" rather than claiming the score is stable. Populating it is
     * scheduled with the Phase 3 trend work.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function risk_trend(int $userid, int $courseid): array {
        return [
            'direction'      => 'unknown',
            'delta'          => 0.0,
            'pct_change'     => null,
            'comparable'     => false,
            'current_score'  => null,
            'previous_score' => null,
        ];
    }
}
