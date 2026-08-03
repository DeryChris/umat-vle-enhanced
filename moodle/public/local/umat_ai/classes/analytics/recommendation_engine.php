<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class recommendation_engine {

    private static function config(): array {
        global $CFG;
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cfg;
    }

    public static function generate(array $course_health): array {
        $cfg = self::config();
        $recs = $cfg['recommendations'];
        $recommendations = [];

        $risk = $course_health['risk_distribution'] ?? [];
        $critical = $risk['critical'] ?? 0;
        $high = $risk['high'] ?? 0;

        if ($critical > 0) {
            $recommendations[] = [
                'priority' => 'critical',
                'type' => 'urgent_intervention',
                'title' => 'Urgent: ' . $critical . ' student(s) at critical risk',
                'description' => $critical . ' student(s) have reached critical risk level and require immediate intervention. Review their performance and reach out directly.',
                'evidence' => [
                    ['detail' => 'Critical risk students detected', 'count' => $critical],
                ],
                'students_affected' => $critical,
                'estimated_impact' => 'high',
            ];
        }

        $topic_insights = $course_health['topic_insights'] ?? [];
        $min_struggle = $recs['min_topic_struggle'] ?? 40;
        $struggling_topics = [];
        $total_topic_students = 0;

        foreach ($topic_insights as $topic) {
            if (($topic['struggle_score'] ?? 0) > $min_struggle) {
                $struggling_topics[] = $topic;
                $total_topic_students += $topic['student_count'] ?? 0;
            }
        }

        if (!empty($struggling_topics)) {
            $topic_names = array_column($struggling_topics, 'topic_name');
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'topic_revisit',
                'title' => 'Review ' . count($struggling_topics) . ' struggling topic(s)',
                'description' => 'Topics with high struggle scores: ' . implode(', ', $topic_names) . '. Consider revisiting these in class or providing supplementary materials.',
                'evidence' => array_map(function ($t) {
                    return [
                        'detail' => $t['topic_name'] . ' (struggle: ' . $t['struggle_score'] . '%)',
                        'count' => $t['student_count'] ?? 0,
                    ];
                }, $struggling_topics),
                'students_affected' => $total_topic_students,
                'estimated_impact' => 'high',
            ];
        }

        $bbb = $course_health['bbb_attendance'] ?? [];
        $avg_attendance = $bbb['avg_attendance_rate'] ?? null;

        if ($avg_attendance !== null && $avg_attendance < 0.5) {
            $low_count = $bbb['low_attendance_students'] ?? 0;
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'engagement_boost',
                'title' => 'Low BBB attendance detected',
                'description' => 'Average attendance rate is ' . round($avg_attendance * 100, 1) . '%. Consider alternative engagement strategies or follow up with absent students.',
                'evidence' => [
                    ['detail' => 'Average attendance rate: ' . round($avg_attendance * 100, 1) . '%', 'count' => $low_count],
                ],
                'students_affected' => $low_count,
                'estimated_impact' => 'high',
            ];
        }

        $assessments = $course_health['assessments'] ?? [];
        $total_assessments = $assessments['total'] ?? 0;
        $missed_total = $assessments['missed_total'] ?? 0;

        if ($total_assessments > 0) {
            $missed_rate = $missed_total / $total_assessments;
            if ($missed_rate > 0.20) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type' => 'assessment_deadline',
                    'title' => 'High assessment miss rate',
                    'description' => round($missed_rate * 100, 1) . '% of assessments have been missed across students. Consider extending deadlines or sending reminders.',
                    'evidence' => [
                        ['detail' => $missed_total . '/' . $total_assessments . ' assessments missed', 'count' => $missed_total],
                    ],
                    'students_affected' => $missed_total,
                    'estimated_impact' => 'medium',
                ];
            }
        }

        $students = $course_health['students'] ?? [];
        $at_risk_count = 0;
        foreach ($students as $student) {
            $level = $student['risk_level'] ?? 'low';
            if (in_array($level, ['critical', 'high', 'medium'])) {
                $at_risk_count++;
            }
        }

        $min_affected = $recs['min_students_affected'] ?? 2;
        if ($at_risk_count >= $min_affected && empty($recommendations)) {
            $recommendations[] = [
                'priority' => 'low',
                'type' => 'student_outreach',
                'title' => $at_risk_count . ' student(s) flagged for monitoring',
                'description' => 'Several students show early risk indicators. Proactive outreach can prevent escalation.',
                'evidence' => [
                    ['detail' => 'Students at moderate or higher risk', 'count' => $at_risk_count],
                ],
                'students_affected' => $at_risk_count,
                'estimated_impact' => 'medium',
            ];
        }

        $recommendations = self::sort($recommendations);

        $max_count = $recs['max_count'] ?? 5;
        return array_slice($recommendations, 0, $max_count);
    }

    public static function generate_student_outreach(array $course_health): array {
        $students = $course_health['students'] ?? [];
        $outreach = [];

        foreach ($students as $student) {
            $level = $student['risk_level'] ?? 'low';
            if (!in_array($level, ['critical', 'high'])) {
                continue;
            }

            $factors = $student['risk_factors'] ?? [];
            $evidence = [];
            foreach ($factors as $factor) {
                $evidence[] = [
                    'detail' => $factor['detail'] ?? ($factor['factor'] ?? 'Unknown factor'),
                    'count' => 1,
                ];
            }

            $priority = ($level === 'critical') ? 'critical' : 'high';
            $impact = ($level === 'critical') ? 'high' : 'medium';
            $score = $student['risk_score'] ?? 0;
            $fullname = $student['fullname'] ?? 'Student #' . ($student['userid'] ?? 0);

            $outreach[] = [
                'priority' => $priority,
                'type' => 'student_outreach',
                'title' => 'Reach out to ' . $fullname,
                'description' => $fullname . ' is at ' . $level . ' risk (score: ' . $score . '). Top factors: ' . implode(', ', array_slice(array_column($evidence, 'detail'), 0, 3)) . '.',
                'evidence' => $evidence,
                'students_affected' => 1,
                'estimated_impact' => $impact,
                'student' => [
                    'userid' => $student['userid'] ?? 0,
                    'fullname' => $fullname,
                ],
            ];
        }

        return self::sort($outreach);
    }

    public static function sort(array $recommendations): array {
        $priority_order = [
            'critical' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
        ];
        $impact_order = [
            'high' => 1,
            'medium' => 2,
            'low' => 3,
        ];

        usort($recommendations, function ($a, $b) use ($priority_order, $impact_order) {
            $pa = $priority_order[$a['priority']] ?? 5;
            $pb = $priority_order[$b['priority']] ?? 5;

            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            $ia = $impact_order[$a['estimated_impact']] ?? 4;
            $ib = $impact_order[$b['estimated_impact']] ?? 4;

            return $ia <=> $ib;
        });

        return $recommendations;
    }
}
