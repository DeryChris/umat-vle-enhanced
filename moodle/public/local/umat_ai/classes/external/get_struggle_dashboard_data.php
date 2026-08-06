<?php
/**
 * @deprecated Since v2.2.0 — Use get_struggle_insights instead.
 * This file is retained for backward compatibility only.
 * Do not add new features here.
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

class get_struggle_dashboard_data extends \external_api {

    public static function get_struggle_dashboard_data_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 60),
        ]);
    }

    public static function get_struggle_dashboard_data($courseid, $days = 60) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::get_struggle_dashboard_data_parameters(), [
            'courseid' => $courseid,
            'days'     => $days,
        ]);
        $cid   = (int)$params['courseid'];
        $since = time() - ($params['days'] * DAYSECS);

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $kpis                 = self::compute_kpis($cid, $since);
        $scatter              = self::compute_scatter($cid, $since);
        $topicMastery         = self::compute_topic_mastery($cid, $since);
        $atRiskStudents       = self::compute_at_risk_students($cid);
        $materialHealth       = self::compute_material_health($cid);
        $commonQuestions      = self::compute_common_questions($cid, $since);

        $aiServiceUrl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');
        $courseHealth = self::compute_course_health($cid, $aiServiceUrl, $token);

        return [
            'kpis'             => $kpis,
            'scatter_plot_data' => $scatter,
            'topic_mastery'    => $topicMastery,
            'at_risk_students' => $atRiskStudents,
            'material_health'  => $materialHealth,
            'common_questions' => $commonQuestions,
            'course_health'    => $courseHealth,
        ];
    }

    private static function compute_kpis(int $cid, int $since): array {
        global $DB;

        $metrics = $DB->get_records('umat_ai_student_metrics', ['courseid' => $cid]);
        $total   = count($metrics);
        $highRisk = 0;
        $totalRisk = 0;
        $atRiskAvatars = [];

        foreach ($metrics as $m) {
            $totalRisk += $m->risk_score;
            if ($m->risk_score >= 60) {
                $highRisk++;
                if (count($atRiskAvatars) < 5) {
                    $user = $DB->get_record('user', ['id' => $m->userid], 'id, firstname, lastname');
                    if ($user) {
                        $atRiskAvatars[] = [
                            'id'   => (int)$user->id,
                            'name' => substr($user->firstname, 0, 1) . '.' . substr($user->lastname, 0, 1) . '.',
                            'avatar' => (string)\html_writer::empty_tag('img', [
                                'src' => (new \moodle_url('/user/pix.php/' . $user->id . '/f1'))->out(false),
                                'class' => 'sd-avatar-sm',
                                'alt'   => '',
                                'onerror' => "this.style.display='none'",
                            ]),
                        ];
                    }
                }
            }
        }

        $engagement = $total > 0 ? round(100 - ($totalRisk / $total)) : 0;
        $engagement = max(0, min(100, $engagement));

        // Trend from metric_trends
        $trendRows = $DB->get_records_sql(
            "SELECT engagement_score, snapshot_date
               FROM {umat_ai_metric_trends}
              WHERE courseid = :cid
           ORDER BY snapshot_date ASC",
            ['cid' => $cid]
        );
        $trend = [];
        foreach ($trendRows as $r) {
            $trend[] = (float)$r->engagement_score;
        }

        // Top topic — highest friction
        $topTopic = $DB->get_record_sql(
            "SELECT topic_label, friction_score
               FROM {umat_ai_topic_friction}
              WHERE courseid = :cid
           ORDER BY friction_score DESC
              LIMIT 1",
            ['cid' => $cid]
        );
        $topTopicName = $topTopic ? $topTopic->topic_label : '—';
        $topTopicGauge = $topTopic ? (int)$topTopic->friction_score : 0;

        // Top material — most questions
        $logs = $DB->get_records_sql(
            "SELECT id, question, sources, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );
        $matQuestionCount = [];
        foreach ($logs as $log) {
            if (!empty($log->sources)) {
                $srcs = json_decode($log->sources, true);
                if (is_array($srcs)) {
                    foreach ($srcs as $src) {
                        $name = is_string($src) ? $src : ($src['filename'] ?? $src['name'] ?? '');
                        if ($name) {
                            $matQuestionCount[$name] = ($matQuestionCount[$name] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        arsort($matQuestionCount);
        $topMatName = array_key_first($matQuestionCount) ?: '—';

        // Weekday volume for top material
        $weekdayVol = [0, 0, 0, 0, 0];
        if ($topMatName !== '—') {
            foreach ($logs as $log) {
                if (!empty($log->sources)) {
                    $srcs = json_decode($log->sources, true);
                    if (is_array($srcs)) {
                        foreach ($srcs as $src) {
                            $name = is_string($src) ? $src : ($src['filename'] ?? $src['name'] ?? '');
                            if ($name === $topMatName) {
                                $dow = (int)date('N', $log->timecreated);
                                if ($dow >= 1 && $dow <= 5) {
                                    $weekdayVol[$dow - 1]++;
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'engagement_score'  => $engagement,
            'engagement_trend'  => $trend,
            'at_risk_count'     => $highRisk,
            'at_risk_avatars'   => $atRiskAvatars,
            'top_topic'         => [
                'name'        => $topTopicName,
                'gauge_value' => $topTopicGauge,
                'ai_insight'  => '',
            ],
            'top_material'      => [
                'name'           => $topMatName,
                'weekday_volume' => $weekdayVol,
            ],
        ];
    }

    private static function compute_scatter(int $cid, int $since): array {
        global $DB;

        $topics = $DB->get_records('umat_ai_topic_friction', ['courseid' => $cid]);
        $points = [];
        foreach ($topics as $t) {
            $points[] = [
                'topic'       => $t->topic_label,
                'volume'      => (int)$t->question_volume,
                'friction'    => (float)$t->friction_score,
                'severity'    => $t->severity,
                'impact_size' => min(40, 5 + (int)$t->student_count * 2),
            ];
        }
        return $points;
    }

    private static function compute_topic_mastery(int $cid, int $since): array {
        global $DB;

        $topics = $DB->get_records('umat_ai_topic_friction', ['courseid' => $cid]);
        $enrolled = count(enrol_get_course_users($cid, true));
        $mastery = [];
        foreach ($topics as $t) {
            $volume = (int)$t->question_volume;
            $friction = (float)$t->friction_score;

            // Estimate students mastered: those not asking questions about this topic
            $studentsAsking = (int)$t->student_count;
            $studentsMastered = max(0, $enrolled - $studentsAsking);

            // Sample questions from chat_logs
            $sampleQ = $DB->get_fieldset_sql(
                "SELECT question FROM {umat_ai_chat_logs}
                  WHERE courseid = :cid AND timecreated > :since AND role = 'student'
               ORDER BY timecreated DESC LIMIT 5",
                ['cid' => $cid, 'since' => $since]
            );

            $mastery[] = [
                'topic'            => $t->topic_label,
                'students_mastered' => $studentsMastered,
                'total_students'    => $enrolled,
                'difficulty'        => $t->severity,
                'expand_questions'  => array_slice($sampleQ, 0, 3),
            ];
        }
        return $mastery;
    }

    private static function compute_at_risk_students(int $cid): array {
        global $DB;

        $metrics = $DB->get_records(
            'umat_ai_student_metrics',
            ['courseid' => $cid],
            'risk_score DESC',
            'userid, risk_score, last_active'
        );

        // Students only — the metrics table can still hold staff rows until
        // the aggregation task re-runs after the role filter was added there.
        $studentctx = \context_course::instance($cid, IGNORE_MISSING);
        if ($studentctx) {
            $valid = local_umat_ai_student_only(
                array_fill_keys(array_map('intval', array_column($metrics, 'userid')), 1),
                $studentctx
            );
            foreach ($metrics as $key => $m) {
                if (!isset($valid[(int) $m->userid])) {
                    unset($metrics[$key]);
                }
            }
        }

        $students = [];
        foreach ($metrics as $m) {
            if ($m->risk_score < 40) continue;

            $user = $DB->get_record('user', ['id' => $m->userid], 'id, firstname, lastname');
            if (!$user) continue;

            $lastActive = $m->last_active ? floor((time() - $m->last_active) / DAYSECS) . ' days' : 'Never';

            // Primary struggle area
            $struggle = $DB->get_field_sql(
                "SELECT topic_label FROM {umat_ai_student_context}
                  WHERE userid = :uid AND courseid = :cid
               ORDER BY struggle_score DESC LIMIT 1",
                ['uid' => $m->userid, 'cid' => $cid]
            );

            $students[] = [
                'id'           => (int)$user->id,
                'name'         => $user->firstname . ' ' . $user->lastname,
                'avatar'       => (string)\html_writer::empty_tag('img', [
                    'src' => (new \moodle_url('/user/pix.php/' . $user->id . '/f1'))->out(false),
                    'class' => 'sd-avatar-md',
                    'alt'   => '',
                    'onerror' => "this.style.display='none'",
                ]),
                'risk'         => $m->risk_score >= 70 ? 'Critical' : 'Amber',
                'struggle_area' => $struggle ?: 'General',
                'last_active'  => $lastActive,
            ];
        }

        return $students;
    }

    private static function compute_material_health(int $cid): array {
        global $DB;

        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid], '', 'id, filename');
        $grouped = [];

        foreach ($materials as $mat) {
            $fname = $mat->filename;
            if (!isset($grouped[$fname])) {
                $grouped[$fname] = [
                    'pct_complete_sum'  => 0,
                    'pct_questions_sum' => 0,
                    'pct_correct_sum'   => 0,
                    'count'             => 0,
                ];
            }

            // Completion % from material_progress
            $pctComplete = $DB->get_field_sql(
                "SELECT AVG(progress_pct) FROM {umat_ai_material_progress}
                  WHERE courseid = :cid AND materialid = :mid",
                ['cid' => $cid, 'mid' => $mat->id]
            );
            $pctComplete = $pctComplete !== false ? round((float)$pctComplete, 1) : 0.0;

            // Questions referencing this material
            $qCount = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cl.id) FROM {umat_ai_chat_logs} cl
                  WHERE cl.courseid = :cid AND cl.sources LIKE :pattern",
                ['cid' => $cid, 'pattern' => '%' . $DB->sql_like_escape($mat->filename) . '%']
            );

            // Correctness from helpfulness ratings
            $avgRating = $DB->get_field_sql(
                "SELECT AVG(h.rating) FROM {umat_ai_chat_log_helpfulness} h
                  JOIN {umat_ai_chat_logs} cl ON cl.id = h.chatlogid
                 WHERE cl.courseid = :cid AND cl.sources LIKE :pattern",
                ['cid' => $cid, 'pattern' => '%' . $DB->sql_like_escape($mat->filename) . '%']
            );
            $pctCorrect = $avgRating !== false ? round((float)$avgRating * 20, 1) : 0.0;

            $grouped[$fname]['pct_complete_sum']  += $pctComplete;
            $grouped[$fname]['pct_questions_sum'] += round($qCount > 0 ? min(100, $qCount * 5) : 0, 1);
            $grouped[$fname]['pct_correct_sum']   += $pctCorrect;
            $grouped[$fname]['count']++;
        }

        $health = [];
        foreach ($grouped as $fname => $g) {
            $health[] = [
                'name'          => $fname,
                'pct_complete'  => round($g['pct_complete_sum'] / $g['count'], 1),
                'pct_questions' => round($g['pct_questions_sum'] / $g['count'], 1),
                'pct_correct'   => round($g['pct_correct_sum'] / $g['count'], 1),
            ];
        }

        return $health;
    }

    private static function compute_common_questions(int $cid, int $since): array {
        global $DB;

        $logs = $DB->get_records_sql(
            "SELECT id, question, sources, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );

        $questions = [];
        foreach ($logs as $log) {
            $text = preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', trim($log->question));
            if (!$text) continue;
            $key = md5($text);
            if (!isset($questions[$key])) {
                $questions[$key] = [
                    'text'            => $text,
                    'count'           => 0,
                    'source_material' => '',
                    'topic'           => 'General',
                ];
            }
            $questions[$key]['count']++;

            if (empty($questions[$key]['source_material']) && !empty($log->sources)) {
                $srcs = json_decode($log->sources, true);
                if (is_array($srcs) && !empty($srcs[0])) {
                    $questions[$key]['source_material'] = is_string($srcs[0]) ? $srcs[0] : ($srcs[0]['filename'] ?? '');
                }
            }
        }

        usort($questions, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        return array_slice($questions, 0, 20);
    }

    private static function compute_course_health(int $cid, ?string $aiServiceUrl, ?string $token): array {
        global $DB;

        $totalStudents = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {umat_ai_chat_logs} WHERE courseid = :cid",
            ['cid' => $cid]
        );

        $atRisk = $DB->get_field_sql(
            "SELECT COUNT(*) FROM {umat_ai_student_metrics}
              WHERE courseid = :cid AND risk_score >= 60",
            ['cid' => $cid]
        );
        $atRisk = $atRisk ? (int)$atRisk : 0;

        $topics = $DB->get_records(
            'umat_ai_topic_friction',
            ['courseid' => $cid],
            'friction_score DESC',
            'topic_label, friction_score, severity'
        );

        $criticalTopics = [];
        foreach ($topics as $t) {
            if ($t->severity === 'critical') {
                $criticalTopics[] = $t->topic_label . ' (friction: ' . $t->friction_score . ')';
            }
        }

        $summary = sprintf(
            '%d students have engaged with the AI assistant. %d are at high risk. ',
            $totalStudents, $atRisk
        );

        if (!empty($criticalTopics)) {
            $summary .= 'Critical topics: ' . implode(', ', array_slice($criticalTopics, 0, 3)) . '. ';
        } else {
            $summary .= 'No critical friction topics detected. ';
        }

        $recommendations = [];
        if ($atRisk > 0) {
            $recommendations[] = 'Send encouragement messages to at-risk students';
        }
        if (!empty($criticalTopics)) {
            $recommendations[] = 'Create targeted review materials for critical topics';
        }
        $recommendations[] = 'Use the AI assistant to generate remedial quizzes';

        // Try AI-powered health summary if service is available
        if ($aiServiceUrl && $token) {
            try {
                require_once($CFG->libdir . '/filelib.php');
                $client = new \curl(['ignoresecurity' => true]);
                $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
                $payload = json_encode([
                    'health_data' => [
                        'course_id' => $cid,
                        'total_students' => $totalStudents,
                        'at_risk_count' => $atRisk,
                        'critical_topics' => $criticalTopics,
                    ],
                ]);
                $resp = $client->post(rtrim($aiServiceUrl, '/') . '/api/v1/analytics/course-health', $payload);
                $data = json_decode($resp, true);
                if ($data && !empty($data['summary'])) {
                    $summary = $data['summary'];
                    if (!empty($data['recommendations'])) {
                        $recommendations = array_merge($recommendations, $data['recommendations']);
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to default summary
            }
        }

        return [
            'summary'         => $summary,
            'recommendations' => $recommendations,
        ];
    }

    public static function get_struggle_dashboard_data_returns() {
        return new \external_single_structure([
            'kpis' => new \external_single_structure([
                'engagement_score'  => new \external_value(PARAM_INT, 'Class engagement 0-100'),
                'engagement_trend'  => new \external_multiple_structure(
                    new \external_value(PARAM_FLOAT, 'Trend data point')
                ),
                'at_risk_count'     => new \external_value(PARAM_INT, 'Students at risk'),
                'at_risk_avatars'   => new \external_multiple_structure(
                    new \external_single_structure([
                        'id'     => new \external_value(PARAM_INT, 'User ID'),
                        'name'   => new \external_value(PARAM_TEXT, 'Initials'),
                        'avatar' => new \external_value(PARAM_RAW, 'Avatar HTML'),
                    ])
                ),
                'top_topic' => new \external_single_structure([
                    'name'        => new \external_value(PARAM_TEXT, 'Topic name'),
                    'gauge_value' => new \external_value(PARAM_INT, 'Gauge 0-100'),
                    'ai_insight'  => new \external_value(PARAM_RAW, 'AI insight text'),
                ]),
                'top_material' => new \external_single_structure([
                    'name'           => new \external_value(PARAM_TEXT, 'Material name'),
                    'weekday_volume' => new \external_multiple_structure(
                        new \external_value(PARAM_INT, 'Questions per weekday')
                    ),
                ]),
            ]),
            'scatter_plot_data' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic'       => new \external_value(PARAM_TEXT, 'Topic label'),
                    'volume'      => new \external_value(PARAM_INT, 'Question volume'),
                    'friction'    => new \external_value(PARAM_FLOAT, 'Friction score 0-100'),
                    'severity'    => new \external_value(PARAM_ALPHA, 'critical/moderate/minor/healthy'),
                    'impact_size' => new \external_value(PARAM_INT, 'Bubble size'),
                ])
            ),
            'topic_mastery' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic'             => new \external_value(PARAM_TEXT, 'Topic label'),
                    'students_mastered' => new \external_value(PARAM_INT, 'Students who mastered'),
                    'total_students'    => new \external_value(PARAM_INT, 'Total enrolled'),
                    'difficulty'        => new \external_value(PARAM_ALPHA, 'Severity level'),
                    'expand_questions'  => new \external_multiple_structure(
                        new \external_value(PARAM_RAW, 'Sample question')
                    ),
                ])
            ),
            'at_risk_students' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT, 'User ID'),
                    'name'         => new \external_value(PARAM_TEXT, 'Full name'),
                    'avatar'       => new \external_value(PARAM_RAW, 'Avatar HTML'),
                    'risk'         => new \external_value(PARAM_ALPHA, 'Critical or Amber'),
                    'struggle_area' => new \external_value(PARAM_TEXT, 'Primary struggle'),
                    'last_active'  => new \external_value(PARAM_TEXT, 'Last active string'),
                ])
            ),
            'material_health' => new \external_multiple_structure(
                new \external_single_structure([
                    'name'          => new \external_value(PARAM_TEXT, 'Material filename'),
                    'pct_complete'  => new \external_value(PARAM_FLOAT, 'Completion %'),
                    'pct_questions' => new \external_value(PARAM_FLOAT, 'Questions %'),
                    'pct_correct'   => new \external_value(PARAM_FLOAT, 'Correctness %'),
                ])
            ),
            'common_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'text'            => new \external_value(PARAM_RAW, 'Question text'),
                    'count'           => new \external_value(PARAM_INT, 'Times asked'),
                    'source_material' => new \external_value(PARAM_TEXT, 'Related material'),
                    'topic'           => new \external_value(PARAM_TEXT, 'Topic'),
                ])
            ),
            'course_health' => new \external_single_structure([
                'summary'         => new \external_value(PARAM_RAW, 'Health summary'),
                'recommendations' => new \external_multiple_structure(
                    new \external_value(PARAM_RAW, 'Recommendation text')
                ),
            ]),
        ]);
    }
}
