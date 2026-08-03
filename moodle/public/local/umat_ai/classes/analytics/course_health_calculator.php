<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class course_health_calculator {

    public static function compute(int $courseid): array {
        global $DB, $CFG;

        $studentids = self::get_student_ids($courseid);

        $riskresults = student_risk_calculator::compute_batch($studentids, $courseid);

        $distribution = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $totalscore = 0.0;
        $atriskscore = 0;
        $students_formatted = [];

        foreach ($riskresults as $result) {
            $level = $result['risk_level'];
            if (isset($distribution[$level])) {
                $distribution[$level]++;
            }
            $totalscore += $result['risk_score'];
            if (in_array($level, ['critical', 'high', 'medium'])) {
                $atriskscore++;
            }
            $students_formatted[] = [
                'userid' => $result['userid'],
                'fullname' => self::get_user_fullname($result['userid']),
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'confidence' => $result['confidence'],
                'classification' => $result['classification'],
                'risk_factors' => $result['evidence'] ?? [],
            ];
        }

        $totalstudents = count($studentids);
        $avgriskscore = $totalstudents > 0 ? round($totalscore / $totalstudents, 1) : 0.0;

        $topicsummary = topic_insight_builder::get_summary($courseid);
        $topicinsights = topic_insight_builder::build($courseid);

        $bbbraw = bbb_attendance_analyser::get_course_attendance_summary($courseid);
        $bbbformatted = [
            'total_sessions' => $bbbraw['total_sessions'],
            'avg_attendance_rate' => $bbbraw['avg_attendance_rate'],
            'students_who_attended' => $bbbraw['students_who_attended'],
            'students_who_never_attended' => $bbbraw['students_who_never_attended'],
            'low_attendance_students' => count($bbbraw['students_who_never_attended']),
        ];

        $assessraw = assessment_tracker::get_course_assessments($courseid, true);
        $totalassess = count($assessraw);
        $missedtotal = 0;
        foreach ($studentids as $uid) {
            $missedtotal += assessment_tracker::count_missed($courseid, $uid);
        }
        $assessformatted = [
            'total' => $totalassess,
            'missed_total' => $missedtotal,
        ];

        $coursetrend = self::compute_course_trend($courseid, $studentids, $riskresults);

        return [
            'total_students' => $totalstudents,
            'at_risk_count' => $atriskscore,
            'avg_risk_score' => $avgriskscore,
            'risk_distribution' => $distribution,
            'topic_insights' => $topicinsights,
            'bbb_attendance' => $bbbformatted,
            'assessments' => $assessformatted,
            'course_trend' => $coursetrend,
            'students' => $students_formatted,
            'student_risks' => $riskresults,
        ];
    }

    public static function get_summary(int $courseid): array {
        $studentids = self::get_student_ids($courseid);

        $riskresults = student_risk_calculator::compute_batch($studentids, $courseid);

        $distribution = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $totalscore = 0.0;
        $atriskscore = 0;

        foreach ($riskresults as $result) {
            $level = $result['risk_level'];
            if (isset($distribution[$level])) {
                $distribution[$level]++;
            }
            $totalscore += $result['risk_score'];
            if (in_array($level, ['critical', 'high', 'medium'])) {
                $atriskscore++;
            }
        }

        $totalstudents = count($studentids);
        $avgriskscore = $totalstudents > 0 ? round($totalscore / $totalstudents, 1) : 0.0;
        $coursetrend = self::compute_course_trend($courseid, $studentids, $riskresults);

        return [
            'total_students' => $totalstudents,
            'at_risk_count' => $atriskscore,
            'critical_count' => $distribution['critical'],
            'high_count' => $distribution['high'],
            'medium_count' => $distribution['medium'],
            'low_count' => $distribution['low'],
            'avg_risk_score' => $avgriskscore,
            'trend_direction' => $coursetrend['direction'],
        ];
    }

    public static function get_by_category(int $courseid): array {
        global $CFG;

        $studentids = self::get_student_ids($courseid);
        $riskresults = student_risk_calculator::compute_batch($studentids, $courseid);

        $categories = [];
        $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');

        foreach ($cfg['categories'] as $cat) {
            $categories[$cat['id']] = [
                'category_id' => $cat['id'],
                'category_label' => $cat['label'],
                'student_count' => 0,
                'avg_risk_score' => 0.0,
                'students' => [],
                '_total_score' => 0.0,
            ];
        }

        foreach ($riskresults as $result) {
            $catid = $result['classification'];
            if (!isset($categories[$catid])) {
                $catid = 'monitoring';
            }
            $categories[$catid]['student_count']++;
            $categories[$catid]['students'][] = [
                'userid' => $result['userid'],
                'fullname' => self::get_user_fullname($result['userid']),
                'risk_score' => $result['risk_score'],
            ];
            $categories[$catid]['_total_score'] += $result['risk_score'];
        }

        $output = [];
        foreach ($categories as $cat) {
            if ($cat['student_count'] > 0) {
                $cat['avg_risk_score'] = round($cat['_total_score'] / $cat['student_count'], 1);
            }
            unset($cat['_total_score']);
            $output[] = $cat;
        }

        usort($output, fn($a, $b) => $b['student_count'] <=> $a['student_count']);

        return $output;
    }

    private static function get_student_ids(int $courseid): array {
        global $DB, $CFG;

        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {context} c ON c.id = ra.contextid AND c.contextlevel = 50
                 WHERE c.instanceid = :cid AND ra.roleid = :rid";

        return $DB->get_fieldset_sql($sql, [
            'cid' => $courseid,
            'rid' => $CFG->defaultstudentroleid,
        ]);
    }

    private static function compute_course_trend(int $courseid, array $studentids, array $riskresults): array {
        global $DB, $CFG;

        if (empty($studentids)) {
            return [
                'direction' => 'stable',
                'delta' => 0.0,
                'current_avg' => 0.0,
                'previous_avg' => null,
            ];
        }

        $total = 0.0;
        foreach ($riskresults as $result) {
            $total += $result['risk_score'];
        }
        $currentavg = round($total / count($riskresults), 1);

        $now = time();
        $window = 14 * DAYSECS;
        $previousstart = $now - ($window * 2);
        $previousend = $now - $window;

        // umat_ai_student_metrics holds only the latest snapshot per student —
        // aggregate_student_metrics deletes and reinserts each course on every
        // run, and the table carries no computed-at column. There is therefore
        // no risk history to compare against yet, and claiming "stable" would
        // be asserting something we have not measured. Historical snapshots are
        // scheduled with the Phase 3 trend work.
        if (!$DB->get_manager()->table_exists('umat_ai_student_metrics')) {
            return [
                'direction'    => 'unknown',
                'delta'        => 0.0,
                'current_avg'  => $currentavg,
                'previous_avg' => null,
                'comparable'   => false,
            ];
        }

        $previousscores = [];

        if (empty($previousscores)) {
            return [
                'direction'    => 'unknown',
                'delta'        => 0.0,
                'current_avg'  => $currentavg,
                'previous_avg' => null,
                'comparable'   => false,
            ];
        }

        $previoustotal = 0.0;
        foreach ($previousscores as $row) {
            $previoustotal += (float) $row->risk_score;
        }
        $previousavg = round($previoustotal / count($previousscores), 1);

        $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        $threshold = $cfg['trend']['risk_delta'] ?? 5.0;

        $trend = trend_analyser::compute_trend($currentavg, $previousavg, $threshold);

        return [
            'direction' => $trend['direction'],
            'delta' => $trend['delta'],
            'current_avg' => $currentavg,
            'previous_avg' => $previousavg,
        ];
    }

    private static function get_user_fullname(int $userid): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'firstname,lastname');
        if ($user) {
            return fullname($user);
        }
        return 'Unknown';
    }
}
