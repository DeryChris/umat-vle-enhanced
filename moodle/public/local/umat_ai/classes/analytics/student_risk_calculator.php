<?php
/**
 * The one authoritative student risk calculation.
 *
 * Consumed by the live insights API (get_struggle_insights), the hourly
 * aggregation task (aggregate_student_metrics), and the student detail view
 * (get_student_profile). No other risk formula may exist.
 *
 * Scoring contract
 * ----------------
 *  - Each factor returns points_earned out of points_max, or null when the
 *    underlying data does not exist.
 *  - The score is renormalised over only the factors that returned data:
 *        risk = sum(points_earned) / sum(points_max) * 100
 *    A student with no quiz data is therefore not penalised for it; the factor
 *    simply leaves the equation and confidence drops instead.
 *  - AI question volume is NOT a risk input. Repeated confusion about the same
 *    topic is, because that is evidence of a specific misconception.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class student_risk_calculator {

    /**
     * Shared risk configuration.
     *
     * Must be `require`, not `require_once` — see the note in
     * academic_query_classifier::config().
     *
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
     * Course-level facts that are identical for every student. Computed once
     * per course and reused across a batch so that scoring N students does not
     * issue N copies of the same query.
     *
     * @param int $courseid
     * @return array
     */
    public static function build_course_context(int $courseid): array {
        global $DB;

        $cfg = self::config();
        $window = $cfg['time_windows']['academic_queries'] ?? 14;
        $since  = time() - ($window * DAYSECS);

        // Published course materials — the denominator for resource engagement.
        $materialcount = (int) $DB->count_records('umat_ai_materials', ['courseid' => $courseid]);

        // Academic chat questions for the whole course in one query, then
        // bucketed per student. Only student-role rows are considered.
        $logs = $DB->get_records_select(
            'umat_ai_chat_logs',
            'courseid = :cid AND timecreated > :since AND role = :role',
            ['cid' => $courseid, 'since' => $since, 'role' => 'student'],
            'timecreated ASC',
            'id, userid, question, session_key, role, timecreated'
        );

        $academicbyuser = [];
        foreach (academic_query_classifier::filter_academic($logs) as $log) {
            $academicbyuser[(int) $log->userid][] = $log;
        }

        return [
            'courseid'         => $courseid,
            'total_past_due'   => assessment_tracker::count_total_past_due($courseid),
            'material_count'   => $materialcount,
            'bbb_available'    => bbb_attendance_analyser::is_available($courseid),
            'academic_by_user' => $academicbyuser,
            'window_days'      => $window,
            'window_start'     => $since,
        ];
    }

    /**
     * Compute risk for a single student.
     *
     * @param int        $userid
     * @param int        $courseid
     * @param array|null $coursecontext Result of build_course_context(); built
     *                                  on demand when omitted.
     * @return array
     */
    public static function compute(int $userid, int $courseid, ?array $coursecontext = null): array {
        $cfg = self::config();
        $ctx = $coursecontext ?? self::build_course_context($courseid);

        // ── Gather factors. Null means "no data" and is dropped entirely. ────
        $factors = array_filter([
            'quiz_performance'       => self::factor_quiz_performance($userid, $courseid),
            'quiz_trend'             => self::factor_quiz_trend($userid, $courseid),
            'missed_assessments'     => self::factor_missed_assessments($userid, $courseid, $ctx),
            'inactivity'             => self::factor_inactivity($userid, $courseid),
            'bbb_attendance'         => self::factor_bbb_attendance($userid, $courseid, $ctx),
            'resource_engagement'    => self::factor_resource_engagement($userid, $courseid, $ctx),
            'repeated_misconception' => self::factor_repeated_misconception($userid, $ctx),
        ], function ($f) {
            return $f !== null;
        });

        // ── Renormalised aggregate ───────────────────────────────────────────
        $earned = 0.0;
        $max    = 0.0;
        $evidence = [];

        foreach ($factors as $name => $factor) {
            $earned += $factor['points_earned'];
            $max    += $factor['points_max'];
            $evidence[] = [
                'factor'        => $name,
                'label'         => $cfg['factors'][$name]['label'] ?? ucfirst(str_replace('_', ' ', $name)),
                'detail'        => $factor['detail'],
                'points_earned' => round($factor['points_earned'], 1),
                'points_max'    => (int) $factor['points_max'],
            ];
        }

        $riskscore = ($max <= 0) ? 0.0 : safe_percentage::clamp_score(($earned / $max) * 100);
        $risklevel = self::score_to_level($riskscore, $cfg['thresholds']);

        // ── Confidence reflects evidence completeness, not severity ──────────
        $withdata   = count($factors);
        $confidence = self::compute_confidence($withdata, $cfg);

        // ── Trends ───────────────────────────────────────────────────────────
        $trends = self::gather_trends($userid, $courseid, $ctx);

        // ── Classification ───────────────────────────────────────────────────
        $signals = self::extract_signals($factors, $ctx, $userid);
        [$classification, $categorylabel, $categoryreason] =
            self::classify_student($riskscore, $signals, $cfg);

        $primaryreason = self::primary_reason($factors, $signals, $categoryreason);

        return [
            'userid'          => $userid,
            'courseid'        => $courseid,
            'risk_score'      => $riskscore,
            'risk_level'      => $risklevel,
            'confidence'      => $confidence,
            'confidence_pct'  => (int) round($confidence * 100),
            'classification'  => $classification,
            'category_label'  => $categorylabel,
            'primary_reason'  => $primaryreason,
            'factors'         => $factors,
            'evidence'        => $evidence,
            'factors_with_data' => $withdata,
            'factors_possible'  => count(array_filter($cfg['factors'], function ($f) {
                return !empty($f['enabled']);
            })),
            'trends'          => $trends,
            'summary'         => self::build_summary($riskscore, $risklevel, $classification, $signals, $trends),
            'date_range'      => [
                'from' => $ctx['window_start'],
                'to'   => time(),
                'days' => $ctx['window_days'],
            ],
            'calculated_at'   => time(),
        ];
    }

    /**
     * Compute risk for many students, sharing the course-level query work.
     *
     * @param array      $userids
     * @param int        $courseid
     * @param array|null $coursecontext
     * @return array userid => result
     */
    public static function compute_batch(array $userids, int $courseid, ?array $coursecontext = null): array {
        $ctx = $coursecontext ?? self::build_course_context($courseid);
        $results = [];
        foreach ($userids as $uid) {
            $uid = (int) $uid;
            $results[$uid] = self::compute($uid, $courseid, $ctx);
        }
        return $results;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Factors
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Quiz performance. Low average → high risk.
     *
     * The previous implementation had this inverted — it awarded full risk
     * points for a 100% average — which is why strong students appeared at the
     * top of the at-risk list.
     *
     * @return array|null Null when the student has no graded attempt in window.
     */
    private static function factor_quiz_performance(int $userid, int $courseid): ?array {
        global $DB;
        $cfg = self::config();
        $maxpoints = $cfg['factors']['quiz_performance']['max_points'];
        $days      = $cfg['time_windows']['quiz_grades'] ?? 14;
        $since     = time() - ($days * DAYSECS);

        $grades = $DB->get_records_sql(
            "SELECT qg.id, qg.grade, q.grade AS maxgrade
               FROM {quiz_grades} qg
               JOIN {quiz} q ON q.id = qg.quiz
              WHERE qg.userid = :uid AND q.course = :cid
                AND qg.timemodified >= :since AND q.grade > 0",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $since]
        );

        if (empty($grades)) {
            return null;
        }

        $totalpct = 0.0;
        $count = 0;
        foreach ($grades as $g) {
            $maxg = (float) $g->maxgrade;
            if ($maxg > 0) {
                $totalpct += ((float) $g->grade / $maxg) * 100;
                $count++;
            }
        }
        if ($count === 0) {
            return null;
        }

        $avgpct = $totalpct / $count;
        // 0% average → full risk points; 100% average → zero risk points.
        $points = round((1 - ($avgpct / 100)) * $maxpoints, 1);
        $points = max(0.0, min((float) $maxpoints, $points));

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => sprintf('Quiz average %d%% across %d attempt%s (last %d days)',
                round($avgpct), $count, $count === 1 ? '' : 's', $days),
            'raw'           => ['avg_pct' => round($avgpct, 1), 'attempt_count' => $count],
        ];
    }

    /**
     * Direction of travel in quiz results. A declining student is at more risk
     * than a steady one at the same average.
     *
     * @return array|null Null when there are too few attempts to see a trend.
     */
    private static function factor_quiz_trend(int $userid, int $courseid): ?array {
        $cfg = self::config();
        $maxpoints = $cfg['factors']['quiz_trend']['max_points'];

        $trend = trend_analyser::quiz_trend($userid, $courseid);
        if (empty($trend['comparable'])) {
            return null;
        }

        if ($trend['direction'] === 'declining') {
            $points = (float) $maxpoints;
        } else if ($trend['direction'] === 'improving') {
            $points = 0.0;
        } else {
            $points = round($maxpoints * 0.3, 1);
        }

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => sprintf('Quiz average moved from %d%% to %d%% (%s)',
                round($trend['previous_avg']), round($trend['current_avg']), $trend['direction']),
            'raw'           => $trend,
        ];
    }

    /**
     * Past-due assessments with no submission.
     *
     * @return array|null Null when the course has no past-due assessment.
     */
    private static function factor_missed_assessments(int $userid, int $courseid, array $ctx): ?array {
        $cfg = self::config();
        $maxpoints = $cfg['factors']['missed_assessments']['max_points'];

        $totalpastdue = (int) $ctx['total_past_due'];
        if ($totalpastdue === 0) {
            return null;
        }

        $missed = assessment_tracker::find_missed($courseid, $userid);
        $missedcount = count($missed);
        $rate = $missedcount / $totalpastdue;
        $points = round($rate * $maxpoints, 1);

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => sprintf('%d of %d past-due assessment%s not submitted',
                $missedcount, $totalpastdue, $totalpastdue === 1 ? '' : 's'),
            'raw'           => [
                'missed_count'   => $missedcount,
                'total_past_due' => $totalpastdue,
                'missed_list'    => $missed,
            ],
        ];
    }

    /**
     * Days since any recorded course activity.
     *
     * @return array|null Null when the student has no activity record at all,
     *                    which is absence of data rather than evidence of risk.
     */
    private static function factor_inactivity(int $userid, int $courseid): ?array {
        $cfg = self::config();
        $maxpoints  = $cfg['factors']['inactivity']['max_points'];
        $fullpoints = $cfg['factors']['inactivity']['days_for_full_points'] ?? 14;

        $daysinactive = engagement_analyser::get_days_inactive($userid, $courseid);
        if ($daysinactive === null) {
            return null;
        }

        $lastsource = engagement_analyser::get_last_active_source($userid, $courseid);
        $rate = min(1.0, $daysinactive / max(1, $fullpoints));
        $points = round($rate * $maxpoints, 1);

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => $daysinactive === 0
                ? 'Active today'
                : sprintf('%d day%s since last activity (%s)',
                    $daysinactive, $daysinactive === 1 ? '' : 's', $lastsource),
            'raw'           => ['days_inactive' => $daysinactive, 'last_source' => $lastsource],
        ];
    }

    /**
     * Live class attendance, only where BigBlueButton actually recorded joins.
     *
     * @return array|null Null when no reliable attendance data exists. Page
     *                    views are never treated as attendance.
     */
    private static function factor_bbb_attendance(int $userid, int $courseid, array $ctx): ?array {
        $cfg = self::config();
        $maxpoints = $cfg['factors']['bbb_attendance']['max_points'];

        if (empty($ctx['bbb_available'])) {
            return null;
        }

        $attendance = bbb_attendance_analyser::get_attendance($userid, $courseid);
        if ($attendance === null || (int) $attendance['sessions_held'] === 0) {
            return null;
        }

        $rate = (float) $attendance['attendance_rate']; // 0.0 – 1.0
        $points = round((1.0 - $rate) * $maxpoints, 1);

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => sprintf('Attended %d of %d live session%s (%d%%)',
                $attendance['sessions_attended'], $attendance['sessions_held'],
                $attendance['sessions_held'] === 1 ? '' : 's', round($rate * 100)),
            'raw'           => $attendance,
        ];
    }

    /**
     * Share of published course materials the student has opened.
     *
     * @return array|null Null when the course has published nothing.
     */
    private static function factor_resource_engagement(int $userid, int $courseid, array $ctx): ?array {
        global $DB;
        $cfg = self::config();
        $maxpoints = $cfg['factors']['resource_engagement']['max_points'];

        $published = (int) $ctx['material_count'];
        if ($published === 0) {
            return null;
        }

        $accessed = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT materialid) FROM {umat_ai_material_progress}
              WHERE userid = :uid AND courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );

        $rate = min(1.0, $accessed / $published);
        $points = round((1.0 - $rate) * $maxpoints, 1);

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => sprintf('Opened %d of %d course material%s (%d%%)',
                $accessed, $published, $published === 1 ? '' : 's', round($rate * 100)),
            'raw'           => [
                'accessed'    => $accessed,
                'published'   => $published,
                'access_rate' => round($rate, 4),
            ],
        ];
    }

    /**
     * Repeated academic questions about the SAME topic.
     *
     * This is the only way AI usage influences risk, and it is deliberately
     * narrow: asking many different questions is productive engagement; asking
     * the same thing repeatedly is an unresolved misconception.
     *
     * @return array|null Null when the student asked no academic questions.
     */
    private static function factor_repeated_misconception(int $userid, array $ctx): ?array {
        $cfg = self::config();
        $maxpoints  = $cfg['factors']['repeated_misconception']['max_points'];
        $fullpoints = $cfg['factors']['repeated_misconception']['repeats_for_full_points'] ?? 3;

        $logs = $ctx['academic_by_user'][$userid] ?? [];
        if (empty($logs)) {
            return null;
        }

        $grouped = academic_query_classifier::build_question_map($logs);
        $maxrepeat = 0;
        $topic = '';
        foreach ($grouped as $entry) {
            if ($entry['count'] > $maxrepeat) {
                $maxrepeat = (int) $entry['count'];
                $topic = $entry['question'];
            }
        }

        // A single ask of each distinct question is healthy curiosity, not risk.
        $repeats = max(0, $maxrepeat - 1);
        $rate = min(1.0, $repeats / max(1, $fullpoints));
        $points = round($rate * $maxpoints, 1);

        $detail = $repeats === 0
            ? sprintf('%d academic question%s, none repeated', count($logs), count($logs) === 1 ? '' : 's')
            : sprintf('Asked about "%s" %d times', self::truncate($topic, 60), $maxrepeat);

        return [
            'points_earned' => $points,
            'points_max'    => $maxpoints,
            'detail'        => $detail,
            'raw'           => [
                'academic_count'  => count($logs),
                'max_repeat'      => $maxrepeat,
                'repeated_topic'  => $topic,
                'distinct_topics' => count($grouped),
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // Aggregation helpers
    // ════════════════════════════════════════════════════════════════════════

    /**
     * @param float $score
     * @param array $thresholds
     * @return string
     */
    private static function score_to_level(float $score, array $thresholds): string {
        $sorted = $thresholds;
        arsort($sorted);
        foreach ($sorted as $level => $threshold) {
            if ($score >= $threshold) {
                return $level;
            }
        }
        return 'low';
    }

    /**
     * @param int   $withdata Number of factors that produced evidence.
     * @param array $cfg
     * @return float 0.3 – 1.0
     */
    private static function compute_confidence(int $withdata, array $cfg): float {
        $minrequired = $cfg['confidence']['min_factors_required'];
        $full        = max(1, $cfg['confidence']['full_confidence_factors']);
        $floor       = $cfg['confidence']['floor'];

        if ($withdata < $minrequired) {
            return $floor;
        }
        return round(max($floor, min(1.0, $withdata / $full)), 2);
    }

    /**
     * @return array
     */
    private static function gather_trends(int $userid, int $courseid, array $ctx): array {
        $trends = [
            'quiz'     => trend_analyser::quiz_trend($userid, $courseid),
            'activity' => engagement_analyser::get_activity_trend($userid, $courseid),
        ];
        if (!empty($ctx['bbb_available'])) {
            $bbb = bbb_attendance_analyser::get_trend($userid, $courseid);
            if ($bbb !== null) {
                $trends['attendance'] = $bbb;
            }
        }
        return $trends;
    }

    /**
     * Flatten the factor set into the plain signals the classification rules
     * are written against.
     *
     * @return array
     */
    private static function extract_signals(array $factors, array $ctx, int $userid): array {
        return [
            'quiz_avg'            => $factors['quiz_performance']['raw']['avg_pct'] ?? null,
            'quiz_attempts'       => $factors['quiz_performance']['raw']['attempt_count'] ?? 0,
            'days_inactive'       => $factors['inactivity']['raw']['days_inactive'] ?? null,
            'missed_count'        => $factors['missed_assessments']['raw']['missed_count'] ?? 0,
            'attendance_rate'     => $factors['bbb_attendance']['raw']['attendance_rate'] ?? null,
            'has_bbb_data'        => isset($factors['bbb_attendance']),
            'resource_rate'       => $factors['resource_engagement']['raw']['access_rate'] ?? null,
            'has_resource_data'   => isset($factors['resource_engagement']),
            'academic_questions'  => $factors['repeated_misconception']['raw']['academic_count'] ?? 0,
            'max_repeat'          => $factors['repeated_misconception']['raw']['max_repeat'] ?? 0,
            'repeated_topic'      => $factors['repeated_misconception']['raw']['repeated_topic'] ?? '',
        ];
    }

    /**
     * Apply the ordered classification rules. Every condition in a rule's "all"
     * block must hold; the first rule that matches wins.
     *
     * @return array [id, label, reason]
     */
    private static function classify_student(float $riskscore, array $s, array $cfg): array {
        foreach ($cfg['categories'] as $cat) {
            $conditions = $cat['all'] ?? [];
            $match = true;

            foreach ($conditions as $key => $value) {
                switch ($key) {
                    case 'quiz_avg_below':
                        $match = $s['quiz_avg'] !== null && $s['quiz_avg'] < $value;
                        break;
                    case 'max_days_inactive':
                        $match = $s['days_inactive'] !== null && $s['days_inactive'] <= $value;
                        break;
                    case 'min_days_inactive':
                        $match = $s['days_inactive'] !== null && $s['days_inactive'] >= $value;
                        break;
                    case 'min_missed':
                        $match = $s['missed_count'] >= $value;
                        break;
                    case 'requires_bbb_data':
                        $match = $s['has_bbb_data'] === (bool) $value;
                        break;
                    case 'max_attendance_rate':
                        $match = $s['attendance_rate'] !== null && $s['attendance_rate'] <= $value;
                        break;
                    case 'max_academic_questions':
                        $match = $s['academic_questions'] <= $value;
                        break;
                    case 'requires_resource_data':
                        $match = $s['has_resource_data'] === (bool) $value;
                        break;
                    case 'max_resource_access_rate':
                        $match = $s['resource_rate'] !== null && $s['resource_rate'] <= $value;
                        break;
                    case 'min_risk_score':
                        $match = $riskscore >= $value;
                        break;
                    default:
                        $match = false;
                }
                if (!$match) {
                    break;
                }
            }

            if ($match) {
                return [$cat['id'], $cat['label'], $cat['reason'] ?? ''];
            }
        }

        return ['low_risk', 'Low risk', 'No risk signals above threshold.'];
    }

    /**
     * The single sentence a lecturer reads in the collapsed row: the
     * highest-scoring factor, stated in plain language.
     *
     * @return string
     */
    private static function primary_reason(array $factors, array $signals, string $fallback): string {
        $top = null;
        $topshare = 0.0;
        foreach ($factors as $name => $factor) {
            if ($factor['points_max'] <= 0) {
                continue;
            }
            $share = $factor['points_earned'] / $factor['points_max'];
            if ($share > $topshare) {
                $topshare = $share;
                $top = $factor;
            }
        }

        // Nothing is meaningfully wrong — say so rather than inventing a reason.
        if ($top === null || $topshare < 0.34) {
            return $fallback !== '' ? $fallback : 'No dominant risk factor.';
        }

        return $top['detail'];
    }

    /**
     * Plain-language interpretation distinguishing struggle from disengagement.
     *
     * @return string
     */
    private static function build_summary(float $score, string $level, string $classification, array $s, array $trends): string {
        $quizpart = $s['quiz_avg'] !== null
            ? sprintf('quiz average is %d%%', round($s['quiz_avg']))
            : 'there is no quiz data yet';

        switch ($classification) {
            case 'academically_struggling':
                $summary = sprintf(
                    'The student remains active on the course but %s. This points to conceptual difficulty rather than disengagement.',
                    $quizpart
                );
                if ($s['max_repeat'] >= 2 && $s['repeated_topic'] !== '') {
                    $summary .= sprintf(' They have returned to "%s" %d times.',
                        self::truncate($s['repeated_topic'], 60), $s['max_repeat']);
                }
                return $summary;

            case 'assessment_risk':
                return sprintf(
                    '%d past-due assessment%s remain unsubmitted. Engagement elsewhere may look normal, so this is unlikely to surface without checking submissions.',
                    $s['missed_count'], $s['missed_count'] === 1 ? '' : 's'
                );

            case 'attendance_risk':
                return sprintf(
                    'Live session attendance is %d%%. Coursework activity is not the concern here; presence is.',
                    round(($s['attendance_rate'] ?? 0) * 100)
                );

            case 'disengaged':
                return sprintf(
                    'No recorded activity for %d days and almost no questions asked. This is withdrawal rather than academic difficulty.',
                    (int) $s['days_inactive']
                );

            case 'resource_engagement_risk':
                return sprintf(
                    'Only %d%% of published materials have been opened. The student may not know what is available.',
                    round(($s['resource_rate'] ?? 0) * 100)
                );

            case 'monitoring':
                return sprintf(
                    'Some signals are elevated but none is decisive — %s. Worth watching rather than acting on today.',
                    $quizpart
                );

            default:
                return 'No risk signals above threshold on the evidence available.';
        }
    }

    /**
     * @param string $text
     * @param int    $length
     * @return string
     */
    private static function truncate(string $text, int $length): string {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 1) . '…';
    }
}
