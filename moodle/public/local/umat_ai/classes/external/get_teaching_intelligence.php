<?php
/**
 * @deprecated Since v2.2.0 — Use analytics/teaching_brief_builder instead.
 * This file is retained for backward compatibility only.
 * Do not add new features here.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

class get_teaching_intelligence extends \external_api {

    public static function get_teaching_intelligence_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 60),
        ]);
    }

    public static function get_teaching_intelligence($courseid, $days = 60) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(
            self::get_teaching_intelligence_parameters(),
            ['courseid' => $courseid, 'days' => $days]
        );
        $cid   = (int)$params['courseid'];
        $since = time() - ($params['days'] * DAYSECS);

        if ($cid === 0) {
            throw new \invalid_parameter_exception('Teaching intelligence requires a specific course ID.');
        }

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // ── Gather base data ──────────────────────────────────
        $struggleInsights = self::get_struggle_insights_data($cid, $since, $days);
        $dashboardData    = self::get_dashboard_data($cid, $since);
        $enrolledCount    = (int) count_enrolled_users($context, '', 0, true);

        // ── Build teaching intelligence payload ──────────────
        $priorityRecommendations = self::build_priority_recommendations(
            $struggleInsights, $dashboardData, $cid, $since
        );

        $studentsAtRisk = self::build_student_risk_details(
            $struggleInsights['student_narratives'] ?? [], $cid, $since, $enrolledCount
        );

        $topicStruggles = self::build_topic_struggle_details(
            $struggleInsights['struggle_areas'] ?? [], $cid, $since, $enrolledCount
        );

        $quizAnalytics = self::build_quiz_analytics($cid, $since);

        $recordingAnalytics = self::build_recording_analytics($cid, $since, $enrolledCount);

        $resourceAnalytics = self::build_resource_analytics($cid, $since, $enrolledCount);

        $commonQuestions = self::build_common_questions_insights(
            $struggleInsights['common_questions'] ?? []
        );

        $aiLearningAnalytics = self::build_ai_learning_analytics($cid, $since);

        return [
            'priority_recommendations' => $priorityRecommendations,
            'students_at_risk'         => $studentsAtRisk,
            'topic_struggles'          => $topicStruggles,
            'quiz_analytics'           => $quizAnalytics,
            'recording_analytics'      => $recordingAnalytics,
            'resource_analytics'       => $resourceAnalytics,
            'common_questions'         => $commonQuestions,
            'ai_learning_analytics'    => $aiLearningAnalytics,
            'course_pulse'             => $dashboardData['course_pulse'] ?? [],
            'meta' => [
                'courseid'      => $cid,
                'enrolled'      => $enrolledCount,
                'days_window'   => $days,
                'generated_at'  => time(),
                'data_sources'  => ['chat_logs', 'student_metrics', 'chat_log_helpfulness', 'material_progress', 'bbb_sessions'],
            ],
        ];
    }

    // ── Priority Recommendations ─────────────────────────

    private static function build_priority_recommendations($insights, $dashboard, $cid, $since): array {
        $recommendations = [];
        $priority = 1;

        // P1: Critical topics
        $criticalTopics = [];
        foreach (($insights['struggle_areas'] ?? []) as $area) {
            if (($area['severity'] ?? '') === 'critical' || ($area['struggle_score'] ?? 0) >= 75) {
                $criticalTopics[] = $area;
            }
        }
        if (!empty($criticalTopics)) {
            $topTopic = $criticalTopics[0];
            $recommendations[] = [
                'priority'     => $priority++,
                'type'         => 'topic_review',
                'title'        => 'Review ' . ($topTopic['topic'] ?? 'Critical Topic'),
                'confidence'   => min(99, round(($topTopic['struggle_score'] ?? 50) * 1.1, 1)),
                'evidence'     => sprintf(
                    '%d%% of students struggling. %d questions asked. %d students affected.',
                    $topTopic['struggle_score'] ?? 0,
                    $topTopic['question_count'] ?? 0,
                    $topTopic['student_count'] ?? 0
                ),
                'suggestion'   => $topTopic['suggestion'] ?? sprintf(
                    'Dedicate复习 time to %s next class with targeted practice.',
                    $topTopic['topic'] ?? 'this topic'
                ),
                'action'       => 'review_topic',
                'action_label' => 'Review Topic',
            ];
        }

        // P2: At-risk students with rapid deterioration
        $deteriorating = [];
        foreach (($insights['student_narratives'] ?? []) as $s) {
            if (($s['risk_level'] ?? '') === 'high' && ($s['trend'] ?? '') === 'up') {
                $deteriorating[] = $s;
            }
        }
        if (!empty($deteriorating)) {
            $student = $deteriorating[0];
            $recommendations[] = [
                'priority'     => $priority++,
                'type'         => 'student_contact',
                'title'        => 'Contact ' . ($student['fullname'] ?? 'Student'),
                'confidence'   => min(99, round($student['risk_score'] ?? 60, 1)),
                'evidence'     => sprintf(
                    'Risk increased to %d%%. Last active %s. %d questions asked, %d quiz failures.',
                    $student['risk_score'] ?? 0,
                    $student['last_active'] ?? 'unknown',
                    $student['question_count'] ?? 0,
                    $student['quiz_failures'] ?? 0
                ),
                'suggestion'   => $student['suggestion'] ?? sprintf(
                    'Reach out to %s. Their engagement has dropped significantly.',
                    $student['fullname'] ?? 'this student'
                ),
                'action'       => 'contact_student',
                'action_label' => 'Send Message',
                'userid'       => $student['userid'] ?? 0,
            ];
        }

        // P3: Lecture engagement drop-off
        $recordingData = $dashboard['recording_analytics'] ?? [];
        if (!empty($recordingData)) {
            foreach ($recordingData as $rec) {
                if (($rec['completion_rate'] ?? 100) < 40 && ($rec['views'] ?? 0) > 0) {
                    $recommendations[] = [
                        'priority'     => $priority++,
                        'type'         => 'lecture_split',
                        'title'        => 'Consider splitting ' . ($rec['title'] ?? 'this lecture'),
                        'confidence'   => 82.0,
                        'evidence'     => sprintf(
                            'Only %d%% completion rate. Average watch time %d min. %d students never watched.',
                            $rec['completion_rate'] ?? 0,
                            $rec['avg_watch_duration_min'] ?? 0,
                            $rec['never_watched_count'] ?? 0
                        ),
                        'suggestion'   => sprintf(
                            'Engagement drops sharply in this recording. Consider splitting into shorter segments or adding interactive checkpoints.',
                            $rec['title'] ?? 'this lecture'
                        ),
                        'action'       => 'view_recording',
                        'action_label' => 'View Recording',
                    ];
                    break;
                }
            }
        }

        // P4: Material with highest friction
        $topMat = $dashboard['top_material'] ?? null;
        if ($topMat && !empty($insights['struggle_areas'])) {
            $recommendations[] = [
                'priority'     => $priority++,
                'type'         => 'material_review',
                'title'        => 'Review material: ' . ($topMat['name'] ?? 'Course material'),
                'confidence'   => 75.0,
                'evidence'     => sprintf(
                    '%d questions asked about this material. Highest friction in the course.',
                    $topMat['question_count'] ?? 0
                ),
                'suggestion'   => 'Consider reworking this material or creating supplementary explanations for the most challenging sections.',
                'action'       => 'view_material',
                'action_label' => 'View Material',
            ];
        }

        return $recommendations;
    }

    // ── Student Risk Details ─────────────────────────────

    private static function build_student_risk_details($students, $cid, $since, $enrolledCount): array {
        global $DB;

        $result = [];
        foreach ($students as $s) {
            $uid = (int)($s['userid'] ?? 0);
            if (!$uid) continue;

            $riskScore = (int)($s['risk_score'] ?? 0);
            $riskLevel = $riskScore >= 60 ? 'high' : ($riskScore >= 30 ? 'medium' : 'low');

            // ── Fetch real avg_quiz from core quiz grades ──
            $avgQuiz = (float) $DB->get_field_sql(
                "SELECT AVG(qg.grade) FROM {quiz_grades} qg
                  JOIN {quiz} q ON q.id = qg.quiz
                 WHERE qg.userid = :uid AND q.course = :cid",
                ['uid' => $uid, 'cid' => $cid]
            ) ?: 0.0;

            // ── Fetch real academic AI question count (exclude greetings/commands) ──
            $allQuestions = $DB->get_records_sql(
                "SELECT question FROM {umat_ai_chat_logs}
                 WHERE userid = :uid AND courseid = :cid AND role = 'student' AND timecreated > :since",
                ['uid' => $uid, 'cid' => $cid, 'since' => $since]
            );
            $academicCount = 0;
            $greetingPatterns = '/^(hi|hello|hey|good\s*(morning|afternoon|evening)|thanks|thank\s*you|ok|okay|yes|no|sure|please|excuse|sorry|quiz\s*me|conduct\s*a\s*quiz|start\s*a\s*quiz|give\s*me\s*a\s*quiz)/i';
            foreach ($allQuestions as $q) {
                $text = trim($q->question ?? '');
                if (strlen($text) < 5) continue;
                if (preg_match($greetingPatterns, $text)) continue;
                $academicCount++;
            }

            // ── Fetch real login count ──
            $totalLogins = (int) $DB->get_field(
                'umat_ai_student_metrics', 'logins',
                ['courseid' => $cid, 'userid' => $uid]
            ) ?: 0;

            // ── Build risk factors breakdown ──
            $es = $s['event_sources'] ?? [];
            $evQuizFailures = (int)($es['quiz_failures'] ?? 0);
            $evAssignmentFails = (int)($es['assignment_failures'] ?? 0);
            $evRepeatedViews = (int)($es['repeated_views'] ?? 0);
            $evIssueReports = (int)($es['issue_reports'] ?? 0);
            $questionCount = (int)($s['question_count'] ?? 0);

            $daysInactive = ($s['last_active'] ?? '') !== 'Today' ? (int)($s['last_active'] ?? 0) : 0;

            $riskFactors = [];
            if ($evQuizFailures > 0) {
                $riskFactors[] = [
                    'name' => 'Quiz Failures',
                    'value' => $evQuizFailures,
                    'weight' => 8,
                    'contribution' => min(25, $evQuizFailures * 8),
                    'source' => 'student_context',
                    'threshold' => '1+',
                    'time_window' => '60 days',
                    'missing_data' => 'treated as 0 (no penalty)',
                ];
            }
            if ($evAssignmentFails > 0) {
                $riskFactors[] = [
                    'name' => 'Assignment Failures',
                    'value' => $evAssignmentFails,
                    'weight' => 6,
                    'contribution' => min(25, $evAssignmentFails * 6),
                    'source' => 'student_context',
                    'threshold' => '1+',
                    'time_window' => '60 days',
                    'missing_data' => 'treated as 0 (no penalty)',
                ];
            }
            if ($evRepeatedViews > 0) {
                $riskFactors[] = [
                    'name' => 'Repeated Material Views',
                    'value' => $evRepeatedViews,
                    'weight' => 3,
                    'contribution' => min(25, $evRepeatedViews * 3),
                    'source' => 'student_context',
                    'threshold' => '2+',
                    'time_window' => '60 days',
                    'missing_data' => 'treated as 0 (no penalty)',
                ];
            }
            if ($evIssueReports > 0) {
                $riskFactors[] = [
                    'name' => 'Issue Reports',
                    'value' => $evIssueReports,
                    'weight' => 5,
                    'contribution' => min(25, $evIssueReports * 5),
                    'source' => 'issue_reports',
                    'threshold' => '1+',
                    'time_window' => '60 days',
                    'missing_data' => 'treated as 0 (no penalty)',
                ];
            }
            if ($questionCount > 0) {
                $riskFactors[] = [
                    'name' => 'AI Questions Asked',
                    'value' => $questionCount,
                    'weight' => round(30 / 50, 2),
                    'contribution' => round(($questionCount / 50) * 30, 1),
                    'source' => 'chat_logs',
                    'threshold' => '5+',
                    'time_window' => '60 days',
                    'missing_data' => 'treated as 0 (no penalty)',
                ];
            }
            if ($daysInactive >= 7) {
                $riskFactors[] = [
                    'name' => 'Inactivity',
                    'value' => $daysInactive,
                    'weight' => 0,
                    'contribution' => 0,
                    'source' => 'chat_logs',
                    'threshold' => '7+ days',
                    'time_window' => 'current',
                    'missing_data' => 'N/A',
                ];
            }

            // ── Determine classification ──
            $classification = 'monitoring';
            $primaryReason = 'Elevated risk score based on overall engagement';

            if ($daysInactive >= 7 && $riskScore >= 40) {
                $classification = 'disengaged';
                $primaryReason = sprintf('No activity for %d days', $daysInactive);
            } elseif ($evQuizFailures > 0 && $avgQuiz < 50) {
                $classification = 'failing_assessments';
                $primaryReason = sprintf('Quiz average %.0f%% with %d failure(s)', $avgQuiz, $evQuizFailures);
            } elseif ($avgQuiz < 50 && $questionCount > 5) {
                $classification = 'academically_struggling';
                $primaryReason = sprintf('Low quiz average (%.0f%%) despite active AI usage', $avgQuiz);
            } elseif ($questionCount > 10 && $daysInactive < 3) {
                $classification = 'academically_struggling';
                $primaryReason = sprintf('High AI query volume (%d questions) indicates confusion', $questionCount);
            } elseif ($evAssignmentFails > 0) {
                $classification = 'failing_assessments';
                $primaryReason = sprintf('%d assignment failure(s)', $evAssignmentFails);
            } elseif ($daysInactive >= 3) {
                $classification = 'disengaged';
                $primaryReason = sprintf('No activity for %d days', $daysInactive);
            } elseif ($riskScore >= 60) {
                $classification = 'academically_struggling';
                $primaryReason = 'Multiple risk factors contributing to elevated score';
            }

            // ── Build reasons and evidence ──
            $reasons = [];
            $evidence = [];

            if ($classification === 'disengaged') {
                $reasons[] = sprintf('No activity for %d days', $daysInactive);
                $evidence[] = sprintf('Last login: %d days ago', $daysInactive);
            }
            if ($classification === 'failing_assessments') {
                $reasons[] = sprintf('Quiz average: %.0f%%', $avgQuiz);
                $evidence[] = sprintf('Average quiz grade: %.0f%%', $avgQuiz);
                if ($evQuizFailures > 0) {
                    $reasons[] = sprintf('%d quiz attempt(s) failed', $evQuizFailures);
                    $evidence[] = sprintf('Quiz failures recorded: %d', $evQuizFailures);
                }
            }
            if ($classification === 'academically_struggling') {
                if ($avgQuiz > 0) {
                    $reasons[] = sprintf('Quiz average: %.0f%%', $avgQuiz);
                    $evidence[] = sprintf('Average quiz grade: %.0f%%', $avgQuiz);
                }
                if ($questionCount > 5) {
                    $reasons[] = sprintf('Asked %d AI questions (high volume)', $questionCount);
                    $evidence[] = sprintf('Academic AI questions: %d', $academicCount);
                }
            }
            if ($evAssignmentFails > 0) {
                $reasons[] = sprintf('%d assignment failure(s)', $evAssignmentFails);
                $evidence[] = sprintf('Assignment failures: %d', $evAssignmentFails);
            }
            if ($evRepeatedViews > 0) {
                $reasons[] = sprintf('%d repeated material view(s)', $evRepeatedViews);
                $evidence[] = sprintf('Repeated views: %d', $evRepeatedViews);
            }
            if ($evIssueReports > 0) {
                $reasons[] = sprintf('%d issue report(s) filed', $evIssueReports);
                $evidence[] = sprintf('Issues reported: %d', $evIssueReports);
            }
            if (($s['struggle_topics'] ?? []) && count($s['struggle_topics']) >= 2) {
                $reasons[] = 'Struggling with: ' . implode(', ', array_slice($s['struggle_topics'], 0, 3));
                $evidence[] = 'Topics: ' . implode(', ', $s['struggle_topics']);
            }

            if (empty($reasons)) {
                $reasons[] = $primaryReason;
            }

            // ── Generate explanation ──
            $explanation = self::generate_student_explanation($s, $riskScore, $riskLevel, $avgQuiz, $classification, $daysInactive);

            // ── Generate recommendations ──
            $recommendation = self::generate_student_recommendation($s, $riskLevel, $avgQuiz, $classification, $daysInactive);

            // ── Data period ──
            $dataPeriod = sprintf('Last %d days', (int)($s['days_window'] ?? 60));

            $result[] = [
                'userid'          => $uid,
                'fullname'        => $s['fullname'] ?? 'Unknown',
                'profileimageurl' => $s['profileimageurl'] ?? '',
                'risk_score'      => $riskScore,
                'risk_level'      => $riskLevel,
                'classification'  => $classification,
                'primary_reason'  => $primaryReason,
                'reasons'         => $reasons,
                'evidence'        => $evidence,
                'explanation'     => $explanation,
                'confidence'      => min(99, max(50, $riskScore + 5)),
                'recommendation'  => $recommendation,
                'quick_actions'   => [
                    ['action' => 'view_activity', 'label' => 'View Activity', 'icon' => 'timeline'],
                    ['action' => 'view_quiz_history', 'label' => 'View Quiz History', 'icon' => 'quiz'],
                    ['action' => 'send_message', 'label' => 'Send Message', 'icon' => 'mail'],
                ],
                'risk_factors'    => $riskFactors,
                'struggle_topics' => $s['struggle_topics'] ?? [],
                'question_count'  => $questionCount,
                'academic_questions' => $academicCount,
                'ai_queries'      => $academicCount,
                'avg_quiz'        => round($avgQuiz, 1),
                'quiz_failures'   => $evQuizFailures,
                'total_logins'    => $totalLogins,
                'trend'           => $s['trend'] ?? 'stable',
                'last_active'     => $s['last_active'] ?? 'unknown',
                'days_inactive'   => $daysInactive,
                'data_period'     => $dataPeriod,
                'enrolled_count'  => $enrolledCount,
            ];
        }
        return $result;
    }

    private static function generate_student_explanation($student, $riskScore, $riskLevel, $avgQuiz = 0, $classification = '', $daysInactive = 0): string {
        $parts = [];

        if ($classification === 'disengaged') {
            $parts[] = sprintf('This student has been inactive for %d days, indicating disengagement from the course.', $daysInactive);
        } elseif ($classification === 'failing_assessments') {
            $parts[] = sprintf('This student is failing assessments with a quiz average of %.0f%%.', $avgQuiz);
            if (($student['event_sources']['quiz_failures'] ?? 0) > 0) {
                $parts[] = sprintf('They have %d quiz failure(s) in the current period.', $student['event_sources']['quiz_failures']);
            }
        } elseif ($classification === 'academically_struggling') {
            $parts[] = sprintf('This student is academically struggling. ');
            if ($avgQuiz > 0 && $avgQuiz < 50) {
                $parts[] = sprintf('Their quiz average is %.0f%%, which is below the passing threshold. ', $avgQuiz);
            }
            if (($student['question_count'] ?? 0) > 5) {
                $parts[] = sprintf('They have asked %d AI questions, suggesting active but confused engagement. ', $student['question_count']);
            }
        } else {
            $parts[] = sprintf('This student has a risk score of %d based on multiple engagement factors. ', $riskScore);
        }

        if (!empty($student['struggle_topics'])) {
            $parts[] = 'Struggling with: ' . implode(', ', array_slice($student['struggle_topics'], 0, 3)) . '.';
        }

        return implode('', $parts);
    }

    private static function generate_student_recommendation($student, $riskLevel, $avgQuiz = 0, $classification = '', $daysInactive = 0): array {
        $recs = [];

        if ($classification === 'disengaged') {
            $recs[] = 'Send a personal check-in message to re-engage the student.';
            $recs[] = 'Consider scheduling a brief 1:1 meeting.';
        } elseif ($classification === 'failing_assessments') {
            $recs[] = 'Review the student\'s recent quiz attempts for specific weak areas.';
            $recs[] = 'Provide targeted practice materials for their struggle topics.';
            if ($avgQuiz < 30) {
                $recs[] = 'Schedule an academic support meeting — performance is critically low.';
            }
        } elseif ($classification === 'academically_struggling') {
            $recs[] = 'Recommend specific lecture recordings for their struggle topics.';
            $recs[] = 'Consider creating a remedial quiz for reinforced practice.';
        } else {
            if ($riskLevel === 'high') {
                $recs[] = 'Contact this student as soon as possible to discuss their progress.';
            }
            $recs[] = 'Monitor this student for further changes in engagement.';
        }

        if (($student['event_sources']['quiz_failures'] ?? 0) > 0) {
            $recs[] = 'Schedule a remedial quiz or provide additional practice materials.';
        }

        if (empty($recs)) {
            $recs[] = 'Continue monitoring this student.';
        }

        return $recs;
    }

    // ── Topic Struggle Details ───────────────────────────

    private static function build_topic_struggle_details($areas, $cid, $since, $enrolledCount = 0): array {
        global $DB;
        $result = [];
        foreach ($areas as $area) {
            $struggleScore = (int)($area['struggle_score'] ?? 0);
            $severity = $struggleScore >= 75 ? 'critical' : ($struggleScore >= 50 ? 'attention' : 'watch');

            $studentsStruggling = [];
            $relatedQuizFails = [];
            $aiQuestions = [];
            $recordingEngagement = [];
            $resourcesAccessed = [];

            // Build student list for this topic
            if (!empty($area['affected_student_ids'])) {
                list($inSql, $inParams) = $DB->get_in_or_equal($area['affected_student_ids']);
                $studentRows = $DB->get_records_sql(
                    "SELECT u.id, u.firstname, u.lastname, u.picture
                       FROM {user} u
                      WHERE u.id $inSql",
                    $inParams
                );
                foreach ($studentRows as $usr) {
                    $studentsStruggling[] = [
                        'id' => (int)$usr->id,
                        'name' => fullname($usr),
                        'picture' => (new \moodle_url('/user/pix.php/' . $usr->id . '/f1'))->out(false),
                    ];
                }
            }

            // Find related quiz questions
            if (!empty($area['sample_questions'])) {
                foreach ($area['sample_questions'] as $sq) {
                    $relatedQuizFails[] = [
                        'question' => $sq,
                        'failure_rate' => null, // Would need quiz attempt data
                    ];
                }
            }

            // AI questions related to this topic
            $aiQuestionsRaw = $DB->get_records_sql(
                "SELECT question, COUNT(*) AS ask_count
                   FROM {umat_ai_chat_logs}
                  WHERE courseid = :cid AND timecreated > :since AND role = 'student'
                    AND (LOWER(question) LIKE :kw1 OR sources LIKE :kw2)
               GROUP BY question
               ORDER BY ask_count DESC
               LIMIT 5",
                [
                    'cid'    => $cid,
                    'since'  => $since,
                    'kw1'    => '%' . $DB->sql_like_escape(strtolower($area['topic'])) . '%',
                    'kw2'    => '%' . $DB->sql_like_escape($area['topic']) . '%',
                ]
            );
            foreach ($aiQuestionsRaw as $aq) {
                $aiQuestions[] = [
                    'text'      => preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $aq->question),
                    'ask_count' => (int)$aq->ask_count,
                ];
            }

            $result[] = [
                'topic'              => $area['topic'],
                'struggle_score'     => $struggleScore,
                'severity'           => $severity,
                'trend'              => $area['trend'] ?? 'stable',
                'trend_pct'          => (int)($area['trend_pct'] ?? 0),
                'student_count'      => (int)($area['student_count'] ?? 0),
                'total_students'     => $enrolledCount ?? 0,
                'question_count'     => (int)($area['question_count'] ?? 0),
                'students_struggling'=> $studentsStruggling,
                'related_quiz_fails' => $relatedQuizFails,
                'ai_questions'       => $aiQuestions,
                'recording_engagement'=> $recordingEngagement,
                'resources_accessed'  => $resourcesAccessed,
                'ai_explanation'      => $area['description'] ?? sprintf(
                    'Students are struggling with %s based on %d questions from %d students.',
                    $area['topic'],
                    $area['question_count'] ?? 0,
                    $area['student_count'] ?? 0
                ),
                'recommendation'      => $area['suggestion'] ?? sprintf(
                    'Spend approximately 20 minutes reviewing %s next class with targeted practice.',
                    $area['topic']
                ),
                'suggestion_type'     => $area['suggestion_type'] ?? 'recap',
                'evidence_sources'    => $area['event_sources'] ?? [],
            ];
        }
        return $result;
    }

    // ── Quiz Analytics ───────────────────────────────────

    private static function build_quiz_analytics($cid, $since): array {
        global $DB;

        // 1. Core Moodle quiz attempts (formal quizzes)
        $coreAttempts = $DB->get_records_sql(
            "SELECT qa.id, qa.userid, qa.sumgrades, q.grade AS maxgrade, q.sumgrades AS qsumgrades
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :cid AND qa.timefinish > :since AND qa.state = 'finished'
              ORDER BY qa.timefinish DESC",
            ['cid' => $cid, 'since' => $since]
        );

        // 2. Custom plugin quiz attempts (AI practice quizzes)
        $customAttempts = $DB->get_records_select(
            'umat_ai_quiz_attempts',
            "courseid = :cid AND status = 'completed' AND score IS NOT NULL AND total IS NOT NULL AND total > 0 AND timecreated > :since",
            ['cid' => $cid, 'since' => $since]
        );

        // 3. Compute aggregate scores from core attempts
        $coreScores = [];
        $corePassing = 0;
        $coreHighest = 0.0;
        $coreLowest = 100.0;

        foreach ($coreAttempts as $a) {
            $maxgrade = (float)($a->maxgrade ?: ($a->qsumgrades ?: 1));
            $pct = $maxgrade > 0 ? round(($a->sumgrades / $maxgrade) * 100, 1) : 0;
            $coreScores[] = $pct;
            if ($pct >= 50) $corePassing++;
            if ($pct > $coreHighest) $coreHighest = $pct;
            if ($pct < $coreLowest) $coreLowest = $pct;
        }

        // 4. Compute aggregate scores from custom attempts
        $customScores = [];
        $customPassing = 0;
        $customHighest = 0.0;
        $customLowest = 100.0;

        foreach ($customAttempts as $a) {
            $pct = round(($a->score / $a->total) * 100, 1);
            $customScores[] = $pct;
            if ($pct >= 50) $customPassing++;
            if ($pct > $customHighest) $customHighest = $pct;
            if ($pct < $customLowest) $customLowest = $pct;
        }

        // 5. Merge all scores
        $allScores = array_merge($coreScores, $customScores);
        $totalAttempts = count($allScores);
        $totalPassing = $corePassing + $customPassing;

        $avgScore = $totalAttempts > 0 ? round(array_sum($allScores) / $totalAttempts, 1) : 0.0;
        $highestScore = !empty($allScores) ? max(max($coreScores ?: [0]), max($customScores ?: [0])) : 0.0;
        $lowestScore = !empty($allScores) ? min(min($coreScores ?: [100]), min($customScores ?: [100])) : 0.0;

        // 6. Median score
        $medianScore = 0.0;
        if ($totalAttempts > 0) {
            sort($allScores);
            $mid = intdiv($totalAttempts, 2);
            $medianScore = ($totalAttempts % 2 === 0)
                ? round(($allScores[$mid - 1] + $allScores[$mid]) / 2, 1)
                : $allScores[$mid];
        }

        // 7. Pass rate
        $passRate = $totalAttempts > 0 ? round(($totalPassing / $totalAttempts) * 100, 1) : 0.0;

        // 8. Score distribution (0-20, 20-40, 40-60, 60-80, 80-100)
        $distribution = [
            ['grade' => '0-20%', 'count' => 0],
            ['grade' => '20-40%', 'count' => 0],
            ['grade' => '40-60%', 'count' => 0],
            ['grade' => '60-80%', 'count' => 0],
            ['grade' => '80-100%', 'count' => 0],
        ];
        foreach ($allScores as $s) {
            if ($s < 20) $distribution[0]['count']++;
            elseif ($s < 40) $distribution[1]['count']++;
            elseif ($s < 60) $distribution[2]['count']++;
            elseif ($s < 80) $distribution[3]['count']++;
            else $distribution[4]['count']++;
        }

        // 9. AI recommendation based on analytics
        $aiRec = '';
        if ($totalAttempts === 0) {
            $aiRec = 'No quiz attempts recorded yet. Consider creating practice quizzes to help students self-assess.';
        } elseif ($passRate < 50) {
            $aiRec = sprintf('Pass rate is only %.0f%%. Students are struggling with quiz content. Consider reviewing material before the next assessment.', $passRate);
        } elseif ($avgScore < 60) {
            $aiRec = sprintf('Average score is %.0f%%. Consider targeted review sessions for commonly missed topics.', $avgScore);
        } else {
            $aiRec = sprintf('Quiz performance is solid with %.0f%% average and %.0f%% pass rate. Keep up the current approach.', $avgScore, $passRate);
        }

        return [
            'quiz_attempts'         => $totalAttempts,
            'average_score'         => $avgScore,
            'highest_score'         => $highestScore,
            'lowest_score'          => $lowestScore,
            'median_score'          => $medianScore,
            'pass_rate'             => $passRate,
            'distribution'          => $distribution,
            'most_failed_questions' => [],
            'ambiguous_questions'   => [],
            'skipped_questions'     => [],
            'ai_recommendation'     => $aiRec,
        ];
    }

    // ── Recording Analytics ──────────────────────────────

    private static function build_recording_analytics($cid, $since, $enrolledCount = 0): array {
        global $DB;

        // NOTE: The legacy {umat_ai_bbb_sessions}/{umat_ai_bbb_views} tables
        // referenced by the original analytics suite were never part of the
        // schema. Fall back to the real {umat_ai_sessions} table so the
        // Teaching Intelligence dashboard never crashes; view tracking is
        // unavailable (no bbb_views table), so those figures report 0.
        $sessiontable = 'umat_ai_sessions';
        if (!$DB->get_manager()->table_exists('umat_ai_bbb_sessions')) {
            // Fallback: list recordings from the real sessions table. No view
            // tracking exists (legacy bbb_views table is absent), so views and
            // watch duration report 0 / NULL and completion uses the default.
            $sessions = $DB->get_records_sql(
                "SELECT s.id, s.sessionid, s.recording_url,
                        NULL AS duration_min,
                        0 AS view_count,
                        NULL AS avg_watch_duration,
                        0 AS completed_count
                   FROM {umat_ai_sessions} s
                  WHERE s.courseid = :cid AND s.timecreated > :since
                    AND s.recording_url IS NOT NULL AND s.recording_url <> ''
                  ORDER BY s.timecreated DESC",
                ['cid' => $cid, 'since' => $since]
            );
        } else {
            $sessions = $DB->get_records_sql(
                "SELECT s.id, s.sessionid, s.recording_url, s.title, s.duration_min,
                        COUNT(DISTINCT v.id) AS view_count,
                        AVG(v.watch_duration_min) AS avg_watch_duration,
                        SUM(CASE WHEN v.watch_duration_min >= s.duration_min THEN 1 ELSE 0 END) AS completed_count
                   FROM {umat_ai_bbb_sessions} s
                   LEFT JOIN {umat_ai_bbb_views} v ON v.sessionid = s.sessionid AND v.courseid = s.courseid
                  WHERE s.courseid = :cid AND s.timecreated > :since
                  GROUP BY s.id, s.sessionid, s.recording_url, s.title, s.duration_min
                  ORDER BY s.timecreated DESC",
                ['cid' => $cid, 'since' => $since]
            );
        }

        // Count distinct students who watched any recording in this course.
        // Only possible when the legacy view-tracking tables exist.
        $distinctWatchers = 0;
        if ($DB->get_manager()->table_exists('umat_ai_bbb_views')) {
            $distinctWatchers = (int) $DB->get_field_sql(
                "SELECT COUNT(DISTINCT v.userid)
                   FROM {umat_ai_bbb_views} v
                   JOIN {umat_ai_bbb_sessions} s ON s.sessionid = v.sessionid AND s.courseid = v.courseid
                  WHERE v.courseid = :cid AND s.timecreated > :since",
                ['cid' => $cid, 'since' => $since]
            );
        }

        $result = [];
        foreach ($sessions as $sess) {
            $views = (int)($sess->view_count ?? 0);
            $avgDuration = (float)($sess->avg_watch_duration ?? 0);
            $duration = (float)($sess->duration_min ?? 30);
            $completion = $duration > 0 ? round(($avgDuration / $duration) * 100, 1) : 0;
            $neverWatched = max(0, $enrolledCount - $views);

            $result[] = [
                'title'                => $sess->title ?? 'Recording',
                'recording_url'        => $sess->recording_url ?? '',
                'views'                => $views,
                'avg_watch_duration_min' => round($avgDuration, 1),
                'completion_rate'      => $completion,
                'duration_min'         => $duration,
                'never_watched_count'  => $neverWatched,
                'recommendation'       => $completion < 40 && $views > 0
                    ? sprintf('Only %d%% completion. Consider adding timestamps or interactive checkpoints.', $completion)
                    : ($views === 0 ? 'No views yet. Ensure students know this recording is available.' : 'Good engagement.'),
            ];
        }
        return $result;
    }

    // ── Resource Analytics ───────────────────────────────

    private static function build_resource_analytics($cid, $since, $enrolledCount = 0): array {
        global $DB;

        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid], '', 'id, filename, fileid');
        $result = [];

        foreach ($materials as $mat) {
            $downloads = (int) $DB->count_records_select(
                'umat_ai_material_progress',
                'courseid = :cid AND materialid = :mid AND downloads > 0',
                ['cid' => $cid, 'mid' => $mat->id]
            );
            $uniqueViewers = (int) $DB->get_field_sql(
                "SELECT COUNT(DISTINCT userid) FROM {umat_ai_material_progress}
                  WHERE courseid = :cid AND materialid = :mid",
                ['cid' => $cid, 'mid' => $mat->id]
            );
            $avgReadingTime = (float) $DB->get_field_sql(
                "SELECT AVG(reading_time_min) FROM {umat_ai_material_progress}
                  WHERE courseid = :cid AND materialid = :mid",
                ['cid' => $cid, 'mid' => $mat->id]
            ) ?: 0.0;

            $neverOpened = max(0, $enrolledCount - $uniqueViewers);

            $recommendation = '';
            if ($uniqueViewers === 0 && $enrolledCount > 0) {
                $recommendation = sprintf('No students have opened this material out of %d enrolled. Consider highlighting it in class or linking it in announcements.', $enrolledCount);
            } elseif ($avgReadingTime > 0 && $avgReadingTime < 2) {
                $recommendation = 'Students are spending very little time on this material. Consider making it more prominent or adding guided questions.';
            } elseif ($neverOpened > 0 && $enrolledCount > 0 && ($neverOpened / $enrolledCount) > 0.5) {
                $recommendation = sprintf('%d of %d students have not opened this material. Consider assigning it as required reading.', $neverOpened, $enrolledCount);
            } else {
                $recommendation = 'Normal engagement levels.';
            }

            $result[] = [
                'filename'           => $mat->filename,
                'downloads'          => $downloads,
                'unique_viewers'     => $uniqueViewers,
                'avg_reading_time_min' => round($avgReadingTime, 1),
                'students_never_opened' => $neverOpened,
                'related_quiz_performance' => [],
                'recommendation'     => $recommendation,
            ];
        }
        return $result;
    }

    // ── Common Questions Insights ────────────────────────

    private static function build_common_questions_insights($questions): array {
        $result = [];
        foreach ($questions as $q) {
            $result[] = [
                'question'      => $q['text'] ?? '',
                'frequency'     => (int)($q['count'] ?? 0),
                'related_topic' => $q['topic'] ?? 'General',
                'related_lecture' => '', // Would need section mapping
                'ai_explanation' => sprintf(
                    'This question has been asked %d times, indicating a common point of confusion for students.',
                    (int)($q['count'] ?? 0)
                ),
                'recommendation' => sprintf(
                    'Consider addressing this question in the next lecture or updating course materials to clarify this concept.',
                    (int)($q['count'] ?? 0)
                ),
            ];
        }
        return $result;
    }

    // ── AI Learning Analytics ────────────────────────────

    private static function build_ai_learning_analytics($cid, $since): array {
        global $DB;

        $logs = $DB->get_records_sql(
            "SELECT question, sources, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );

        $topicFrequency = [];
        $studentAiUsage = [];
        $misconceptions = [];

        foreach ($logs as $log) {
            $srcs = json_decode($log->sources ?? '[]', true) ?? [];
            $topics = [];
            foreach ($srcs as $src) {
                if (is_string($src)) {
                    $topics[] = pathinfo($src, PATHINFO_FILENAME);
                } elseif (is_array($src)) {
                    $topics[] = $src['name'] ?? $src['filename'] ?? '';
                }
            }
            foreach ($topics as $t) {
                if ($t) {
                    $topicFrequency[$t] = ($topicFrequency[$t] ?? 0) + 1;
                }
            }

            $uid = 0;
            // Try to extract user from question context
            $question = preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $log->question);
            $studentAiUsage[$uid] = ($studentAiUsage[$uid] ?? 0) + 1;
        }

        arsort($topicFrequency);
        arsort($studentAiUsage);

        return [
            'most_discussed_topics'     => array_slice($topicFrequency, 0, 10, true),
            'students_heavily_relying_on_ai' => array_slice(array_filter($studentAiUsage, fn($c) => $c > 10), 0, 10, true),
            'repeated_misconceptions'   => [], // Would need NLP analysis of questions
            'frequently_asked_concepts' => array_keys(array_slice($topicFrequency, 0, 5)),
            'topics_generating_confusion' => array_keys(array_slice($topicFrequency, 0, 5)),
        ];
    }

    // ── Helper: get struggle insights data ──────────────

    private static function get_struggle_insights_data($cid, $since, $days) {
        $cache = \cache::make('local_umat_ai', 'struggle_insights');
        $cachekey = "teaching_intel_{$cid}_{$days}";
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        // Delegate to the existing get_struggle_insights endpoint logic
        // by including and calling its internal functions
        $result = self::call_existing_insights($cid, $since);

        $cache->set($cachekey, $result);
        return $result;
    }

    private static function call_existing_insights($cid, $since) {
        global $DB;

        $logs = $DB->get_records_sql(
            "SELECT id, userid, question, sources, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );

        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid], '', 'id, cmid, filename, fileid');
        $matById = [];
        foreach ($materials as $m) {
            $matById[(int)$m->id] = $m;
        }

        $matAnalyses = [];
        if (!empty($materials)) {
            list($inSql, $inParams) = $DB->get_in_or_equal(array_keys($materials), SQL_PARAMS_NAMED);
            $inParams['atype'] = 'key_concepts';
            $conceptRows = $DB->get_records_sql(
                "SELECT materialid, analysis_type, summary
                   FROM {umat_ai_analysis}
                  WHERE materialid $inSql AND status = 'completed'
               ORDER BY timemodified DESC",
                $inParams
            );
            foreach ($conceptRows as $row) {
                $mid = (int)$row->materialid;
                if (!isset($matAnalyses[$mid])) {
                    $matAnalyses[$mid] = ['key_concepts' => [], 'difficulty' => 'intermediate'];
                }
                if ($row->analysis_type === 'key_concepts' && $row->summary) {
                    $parsed = json_decode($row->summary, true);
                    if (is_array($parsed) && isset($parsed['concepts'])) {
                        foreach ($parsed['concepts'] as $c) {
                            $matAnalyses[$mid]['key_concepts'][] = $c['term'] ?? $c['name'] ?? $c;
                        }
                    }
                }
            }
        }

        // Simple topic extraction from materials
        $topicScores = [];
        foreach ($matAnalyses as $mid => $ana) {
            foreach ($ana['key_concepts'] as $concept) {
                $lc = strtolower(trim($concept));
                if (strlen($lc) < 3) continue;
                if (!isset($topicScores[$lc])) {
                    $topicScores[$lc] = ['topic' => $concept, 'count' => 0, 'students' => []];
                }
                $topicScores[$lc]['count']++;
            }
        }

        $struggleAreas = [];
        foreach ($topicScores as $ts) {
            if ($ts['count'] >= 2) {
                $struggleAreas[] = [
                    'topic'           => $ts['topic'],
                    'question_count'  => $ts['count'],
                    'student_count'   => count($ts['students']),
                    'struggle_score'  => min(100, $ts['count'] * 15),
                    'severity'        => $ts['count'] >= 10 ? 'critical' : ($ts['count'] >= 5 ? 'attention' : 'watch'),
                    'trend'           => 'stable',
                    'trend_pct'       => 0,
                    'description'     => sprintf('Topic mentioned in %d questions from students.', $ts['count']),
                    'suggestion'      => sprintf('Consider reviewing %s in the next class session.', $ts['topic']),
                    'suggestion_type' => 'recap',
                    'materials'       => [],
                ];
            }
        }

        usort($struggleAreas, function($a, $b) { return ($b['struggle_score'] ?? 0) - ($a['struggle_score'] ?? 0); });

        return [
            'struggle_areas'  => $struggleAreas,
            'student_narratives' => [],
            'common_questions' => [],
            'course_pulse' => [],
        ];
    }

    // ── Helper: get dashboard data ──────────────────────

    private static function get_dashboard_data($cid, $since) {
        global $DB;

        $metrics = $DB->get_records('umat_ai_student_metrics', ['courseid' => $cid]);
        $total = count($metrics);
        $highRisk = 0;
        $totalRisk = 0;

        foreach ($metrics as $m) {
            $totalRisk += $m->risk_score;
            if ($m->risk_score >= 60) $highRisk++;
        }

        $engagement = $total > 0 ? round(100 - ($totalRisk / $total)) : 0;
        $engagement = max(0, min(100, $engagement));

        $topTopic = $DB->get_record_sql(
            "SELECT topic_label, friction_score FROM {umat_ai_topic_friction}
             WHERE courseid = :cid ORDER BY friction_score DESC LIMIT 1",
            ['cid' => $cid]
        );

        $topMaterial = $DB->get_field_sql(
            "SELECT sources FROM {umat_ai_chat_logs}
             WHERE courseid = :cid AND timecreated > :since AND sources IS NOT NULL
             LIMIT 1",
            ['cid' => $cid, 'since' => $since]
        );

        return [
            'course_pulse' => [
                'total_students'        => (int) $DB->count_records('user', ['deleted' => 0]),
                'at_risk_count'         => $highRisk,
                'avg_quiz'              => 0,
                'active_this_week'      => 0,
                'top_struggle_topic'    => $topTopic ? $topTopic->topic_label : '—',
                'top_struggle_trend'    => '',
            ],
            'top_material' => [
                'name' => $topMaterial ? basename($topMaterial) : '—',
            ],
        ];
    }

    // ── Returns schema ──────────────────────────────────

    public static function get_teaching_intelligence_returns() {
        return new \external_single_structure([
            'priority_recommendations' => new \external_multiple_structure(
                new \external_single_structure([
                    'priority'        => new \external_value(PARAM_INT),
                    'type'            => new \external_value(PARAM_TEXT),
                    'title'           => new \external_value(PARAM_TEXT),
                    'confidence'      => new \external_value(PARAM_FLOAT),
                    'evidence'        => new \external_value(PARAM_TEXT),
                    'suggestion'      => new \external_value(PARAM_TEXT),
                    'action'          => new \external_value(PARAM_TEXT),
                    'action_label'    => new \external_value(PARAM_TEXT),
                    'userid'          => new \external_value(PARAM_INT, VALUE_OPTIONAL),
                ])
            ),
            'students_at_risk' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'              => new \external_value(PARAM_INT),
                    'fullname'            => new \external_value(PARAM_TEXT),
                    'profileimageurl'     => new \external_value(PARAM_URL),
                    'risk_score'          => new \external_value(PARAM_INT),
                    'risk_level'          => new \external_value(PARAM_TEXT),
                    'classification'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'primary_reason'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'reasons'             => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                    'evidence'            => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                    'explanation'         => new \external_value(PARAM_RAW),
                    'confidence'          => new \external_value(PARAM_FLOAT),
                    'recommendation'      => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                    'quick_actions'       => new \external_multiple_structure(
                        new \external_single_structure([
                            'action'    => new \external_value(PARAM_TEXT),
                            'label'     => new \external_value(PARAM_TEXT),
                            'icon'      => new \external_value(PARAM_TEXT),
                        ])
                    ),
                    'risk_factors'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'name'           => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'value'          => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                            'weight'         => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                            'contribution'   => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                            'source'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'threshold'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'time_window'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'missing_data'   => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'struggle_topics'     => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                    'question_count'      => new \external_value(PARAM_INT),
                    'academic_questions'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'ai_queries'          => new \external_value(PARAM_INT),
                    'avg_quiz'            => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'quiz_failures'       => new \external_value(PARAM_INT),
                    'total_logins'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'trend'               => new \external_value(PARAM_TEXT),
                    'last_active'         => new \external_value(PARAM_TEXT),
                    'days_inactive'       => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'data_period'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'enrolled_count'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                ])
            ),
            'topic_struggles' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic'               => new \external_value(PARAM_TEXT),
                    'struggle_score'      => new \external_value(PARAM_INT),
                    'severity'            => new \external_value(PARAM_TEXT),
                    'trend'               => new \external_value(PARAM_TEXT),
                    'trend_pct'           => new \external_value(PARAM_INT),
                    'student_count'       => new \external_value(PARAM_INT),
                    'total_students'      => new \external_value(PARAM_INT),
                    'question_count'      => new \external_value(PARAM_INT),
                    'students_struggling' => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'       => new \external_value(PARAM_INT),
                            'name'     => new \external_value(PARAM_TEXT),
                            'picture'  => new \external_value(PARAM_URL),
                        ])
                    ),
                    'related_quiz_fails'  => new \external_multiple_structure(
                        new \external_single_structure([
                            'question'       => new \external_value(PARAM_TEXT),
                            'failure_rate'   => new \external_value(PARAM_FLOAT, VALUE_OPTIONAL),
                        ])
                    ),
                    'ai_questions'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'text'      => new \external_value(PARAM_TEXT),
                            'ask_count' => new \external_value(PARAM_INT),
                        ])
                    ),
                    'ai_explanation'      => new \external_value(PARAM_RAW),
                    'recommendation'      => new \external_value(PARAM_TEXT),
                    'suggestion_type'     => new \external_value(PARAM_TEXT),
                    'evidence_sources'    => new \external_multiple_structure(
                        new \external_value(PARAM_RAW)
                    ),
                ])
            ),
            'quiz_analytics' => new \external_single_structure([
                'quiz_attempts'         => new \external_value(PARAM_INT),
                'average_score'         => new \external_value(PARAM_FLOAT),
                'highest_score'         => new \external_value(PARAM_FLOAT),
                'lowest_score'          => new \external_value(PARAM_FLOAT),
                'median_score'          => new \external_value(PARAM_FLOAT),
                'pass_rate'             => new \external_value(PARAM_FLOAT),
                'distribution'          => new \external_multiple_structure(
                    new \external_single_structure([
                        'grade' => new \external_value(PARAM_TEXT),
                        'count' => new \external_value(PARAM_INT),
                    ])
                ),
                'most_failed_questions' => new \external_multiple_structure(
                    new \external_single_structure([
                        'question'    => new \external_value(PARAM_TEXT),
                        'wrong_pct'   => new \external_value(PARAM_FLOAT),
                        'ai_analysis' => new \external_value(PARAM_RAW, VALUE_OPTIONAL),
                    ])
                ),
                'ambiguous_questions'   => new \external_multiple_structure(
                    new \external_single_structure([
                        'question'    => new \external_value(PARAM_TEXT),
                        'reason'      => new \external_value(PARAM_TEXT),
                    ])
                ),
                'skipped_questions'     => new \external_multiple_structure(
                    new \external_single_structure([
                        'question' => new \external_value(PARAM_TEXT),
                        'skip_rate' => new \external_value(PARAM_FLOAT),
                    ])
                ),
                'ai_recommendation'     => new \external_value(PARAM_TEXT),
            ]),
            'recording_analytics' => new \external_multiple_structure(
                new \external_single_structure([
                    'title'                 => new \external_value(PARAM_TEXT),
                    'recording_url'         => new \external_value(PARAM_URL, VALUE_OPTIONAL),
                    'views'                 => new \external_value(PARAM_INT),
                    'avg_watch_duration_min'=> new \external_value(PARAM_FLOAT),
                    'completion_rate'       => new \external_value(PARAM_FLOAT),
                    'duration_min'          => new \external_value(PARAM_FLOAT),
                    'never_watched_count'   => new \external_value(PARAM_INT),
                    'recommendation'        => new \external_value(PARAM_TEXT),
                ])
            ),
            'resource_analytics' => new \external_multiple_structure(
                new \external_single_structure([
                    'filename'              => new \external_value(PARAM_TEXT),
                    'downloads'             => new \external_value(PARAM_INT),
                    'unique_viewers'        => new \external_value(PARAM_INT),
                    'avg_reading_time_min'  => new \external_value(PARAM_FLOAT),
                    'students_never_opened' => new \external_value(PARAM_INT),
                    'related_quiz_performance' => new \external_multiple_structure(
                        new \external_single_structure([
                            'quiz_name'  => new \external_value(PARAM_TEXT),
                            'avg_score'  => new \external_value(PARAM_FLOAT),
                        ])
                    ),
                    'recommendation'        => new \external_value(PARAM_TEXT),
                ])
            ),
            'common_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'question'         => new \external_value(PARAM_TEXT),
                    'frequency'        => new \external_value(PARAM_INT),
                    'related_topic'    => new \external_value(PARAM_TEXT),
                    'related_lecture'  => new \external_value(PARAM_TEXT),
                    'ai_explanation'   => new \external_value(PARAM_RAW),
                    'recommendation'   => new \external_value(PARAM_TEXT),
                ])
            ),
            'ai_learning_analytics' => new \external_single_structure([
                'most_discussed_topics'              => new \external_multiple_structure(
                    new \external_single_structure([
                        'topic'  => new \external_value(PARAM_TEXT),
                        'count'  => new \external_value(PARAM_INT),
                    ])
                ),
                'students_heavily_relying_on_ai'     => new \external_multiple_structure(
                    new \external_single_structure([
                        'user_id' => new \external_value(PARAM_INT),
                        'count'   => new \external_value(PARAM_INT),
                    ])
                ),
                'repeated_misconceptions'            => new \external_multiple_structure(
                    new \external_value(PARAM_RAW)
                ),
                'frequently_asked_concepts'          => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT)
                ),
                'topics_generating_confusion'        => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT)
                ),
            ]),
            'course_pulse' => new \external_single_structure([
                'total_students'   => new \external_value(PARAM_INT),
                'at_risk_count'    => new \external_value(PARAM_INT),
                'avg_quiz'         => new \external_value(PARAM_FLOAT),
                'active_this_week' => new \external_value(PARAM_INT),
                'top_struggle_topic' => new \external_value(PARAM_TEXT),
                'top_struggle_trend' => new \external_value(PARAM_TEXT),
            ]),
            'meta' => new \external_single_structure([
                'courseid'     => new \external_value(PARAM_INT),
                'enrolled'     => new \external_value(PARAM_INT),
                'days_window'  => new \external_value(PARAM_INT),
                'generated_at' => new \external_value(PARAM_INT),
                'data_sources' => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
            ]),
        ]);
    }
}