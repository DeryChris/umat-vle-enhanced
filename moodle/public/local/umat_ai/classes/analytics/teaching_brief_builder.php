<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class teaching_brief_builder {

    private static function config(): array {
        global $CFG;
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cfg;
    }

    public static function build(int $courseid): array {
        $health = course_health_calculator::compute($courseid);
        $recommendations = recommendation_engine::generate($health);
        $outreach = recommendation_engine::generate_student_outreach($health);

        $all_recommendations = array_merge($recommendations, $outreach);
        $all_recommendations = recommendation_engine::sort($all_recommendations);

        $cfg = self::config();
        $max = $cfg['recommendations']['max_count'] ?? 5;
        $trimmed = array_slice($all_recommendations, 0, $max);

        $briefing_text = evidence_formatter::format_briefing($trimmed);

        $health_summary = course_health_calculator::get_summary($courseid);

        return [
            'courseid'              => $courseid,
            'generated_at'          => time(),
            'health'                => $health_summary,
            'risk_distribution'     => $health['risk_distribution'] ?? [],
            'at_risk_students'      => $health['at_risk_students'] ?? [],
            'topic_insights'        => $health['topic_insights'] ?? [],
            'attendance_summary'    => $health['attendance_summary'] ?? [],
            'assessment_stats'      => $health['assessment_stats'] ?? [],
            'recommendations'       => $trimmed,
            'briefing_text'         => $briefing_text,
            'course_trend'          => $health['course_trend'] ?? null,
        ];
    }

    public static function build_summary(int $courseid): array {
        $health = course_health_calculator::get_summary($courseid);
        $recommendations = recommendation_engine::generate(course_health_calculator::compute($courseid));

        $cfg = self::config();
        $max = $cfg['recommendations']['max_count'] ?? 5;
        $trimmed = array_slice($recommendations, 0, $max);

        return [
            'courseid'          => $courseid,
            'generated_at'      => time(),
            'health'            => $health,
            'recommendation_count' => count($trimmed),
            'top_recommendation' => $trimmed[0] ?? null,
        ];
    }

    public static function build_for_student(int $courseid, int $userid): array {
        $risk = student_risk_calculator::compute($userid, $courseid);
        $summary = evidence_formatter::format_summary($risk);
        $evidence = evidence_formatter::format_evidence_list($risk['factors']);
        $trends = evidence_formatter::format_trends($risk['trends']);

        $outreach = recommendation_engine::generate_student_outreach(
            course_health_calculator::compute($courseid)
        );

        $student_recs = array_filter($outreach, function ($r) use ($userid) {
            return isset($r['student']['userid']) && $r['student']['userid'] === $userid;
        });

        return [
            'userid'            => $userid,
            'courseid'          => $courseid,
            'risk'              => $risk,
            'summary'           => $summary,
            'evidence'          => $evidence,
            'trends_text'       => $trends,
            'recommendations'   => array_values($student_recs),
        ];
    }
}
