<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_dashboard_summary extends \external_api {

    public static function get_dashboard_summary_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_dashboard_summary($courseid) {
        global $DB;

        $params = self::validate_parameters(self::get_dashboard_summary_parameters(), [
            'courseid' => $courseid,
        ]);
        $cid = (int)$params['courseid'];
        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $metrics = $DB->get_records('umat_ai_student_metrics', ['courseid' => $cid]);
        $total = count($metrics);
        $highRisk = 0;
        $totalRisk = 0;
        $engagementScore = 0;

        foreach ($metrics as $m) {
            $totalRisk += $m->risk_score;
            if ($m->risk_score >= 60) {
                $highRisk++;
            }
        }

        if ($total > 0) {
            $avgRisk = $totalRisk / $total;
            $engagementScore = round(100 - $avgRisk);
        }

        return [
            'engagement_score' => max(0, min(100, $engagementScore)),
            'at_risk_count'    => $highRisk,
            'total_students'   => $total,
        ];
    }

    public static function get_dashboard_summary_returns() {
        return new \external_single_structure([
            'engagement_score' => new \external_value(PARAM_INT, 'Class engagement score 0-100'),
            'at_risk_count'    => new \external_value(PARAM_INT, 'Number of high-risk students'),
            'total_students'   => new \external_value(PARAM_INT, 'Total tracked students'),
        ]);
    }
}
