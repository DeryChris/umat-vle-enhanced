<?php
/**
 * External API: Consolidated lecturer analytics data.
 *
 * Single source of truth for the redesigned lecturer analytics dashboard
 * (lecturer_analytics_redesign.mustache). Orchestrates the existing
 * analytics producers into one response shaped for the card-grid UI:
 *
 *   1. get_analytics                -> KPI counts + daily activity trend
 *   2. get_struggle_insights        -> executive summary, health grade,
 *                                      priority actions, at-risk students,
 *                                      topic struggle matrix, common questions
 *   3. get_teaching_intelligence    -> quiz / recording / resource analytics
 *   4. AI service /analytics/insights -> natural-language insights (NLG),
 *                                      graceful fallback to empty list
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

class analytics_data extends \external_api {

    public static function analytics_data_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 30),
        ]);
    }

    /**
     * Consolidated analytics payload for the redesigned dashboard.
     *
     * @param int $courseid
     * @param int $days
     * @return array
     */
    public static function analytics_data($courseid, $days = 30) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(
            self::analytics_data_parameters(),
            ['courseid' => $courseid, 'days' => $days]
        );
        $cid = (int) $params['courseid'];
        $window = (int) $params['days'];

        require_login();
        $context = \context_course::instance($cid);
        require_capability('local/umat_ai:viewanalytics', $context);

        // ---- Server-side cache (see db/cache.php) -------------------------
        // Payload is course-level (identical for every lecturer), so a short
        // shared TTL makes course switching effectively instant while still
        // picking up fresh data within a minute.
        $cache = \cache::make('local_umat_ai', 'analytics_data');
        // NOTE: keep the key filesystem-safe — the file cache store uses the
        // raw key as a directory name when simplekeys is enabled, and a ':'
        // is invalid on Windows (silently failing set()).
        $cachekey = $cid . '_' . $window;
        $cached = $cache->get($cachekey);
        if ($cached !== false && is_array($cached)) {
            $cached['meta']['cached'] = true;
            return $cached;
        }

        $generated = time();
        $sources = [];

        // ---- 1. KPI + activity counts -------------------------------------
        $courseAnalytics = [];
        try {
            $courseAnalytics = get_analytics::get_course_analytics($cid, $window);
            $sources[] = 'get_analytics';
        } catch (\Throwable $e) {
            $courseAnalytics = [];
        }

        // ---- 2. Struggle insights (exec summary, health, risk, topics) ----
        $struggle = [];
        try {
            $struggle = get_struggle_insights::get_struggle_insights($cid, max($window, 60));
            $sources[] = 'get_struggle_insights';
        } catch (\Throwable $e) {
            $struggle = [];
        }

        // ---- 3. Teaching intelligence (quiz/recording/resource) -----------
        $intel = [];
        try {
            $intel = get_teaching_intelligence::get_teaching_intelligence($cid, max($window, 60));
            $sources[] = 'get_teaching_intelligence';
        } catch (\Throwable $e) {
            $intel = [];
        }

        $pulse = $struggle['course_pulse'] ?? [];
        $kpis = self::build_kpis($courseAnalytics, $pulse);
        $riskDistribution = self::build_risk_distribution($pulse, $struggle['student_narratives'] ?? []);
        $topicStruggle = self::build_topic_struggle($struggle['struggle_areas'] ?? []);
        $trend = self::build_performance_trend($courseAnalytics, $pulse);

        // ---- 4. AI natural-language insights (best effort) ----------------
        $insights = self::fetch_ai_insights($cid, $window, $courseAnalytics, $struggle, $intel);
        if (!empty($insights)) {
            $sources[] = 'ai_insights';
        }

        // Health grade/label: prefer the AI-enriched grade; fall back to a
        // deterministic grade computed from the real KPIs when the AI service
        // was unavailable or rate-limited (health_grade arrives as '').
        $healthGrade = $struggle['health_grade'] ?? '';
        $healthLabel = $struggle['health_label'] ?? '';
        $execSummary = $struggle['executive_summary'] ?? '';
        $topReco     = $struggle['top_recommendation'] ?? '';
        if ($healthGrade === '') {
            $healthFallback = self::compute_health_fallback($kpis, $riskDistribution);
            $healthGrade = $healthFallback['grade'];
            $healthLabel = $healthFallback['label'];
            if ($execSummary === '') { $execSummary = $healthFallback['executive_summary']; }
            if ($topReco === '')     { $topReco = $healthFallback['top_recommendation']; }
        }

        $payload = [
            'kpis'                => $kpis,
            'health'              => [
                'grade'              => $healthGrade,
                'label'              => $healthLabel,
                'executive_summary'  => $execSummary,
                'going_well'         => $struggle['going_well'] ?? [],
                'needs_attention'    => $struggle['needs_attention'] ?? [],
                'top_recommendation' => $topReco,
            ],
            'priority_actions'    => $struggle['priority_actions'] ?? [],
            'performance_trend'   => $trend,
            'risk_distribution'   => $riskDistribution,
            'at_risk_students'    => $struggle['student_narratives'] ?? [],
            'topic_struggle'      => $topicStruggle,
            'common_questions'    => $struggle['common_questions'] ?? [],
            'quiz_analytics'      => $intel['quiz_analytics'] ?? [],
            'recording_analytics' => $intel['recording_analytics'] ?? [],
            'resource_analytics'  => $intel['resource_analytics'] ?? [],
            'insights'            => $insights,
            'meta' => [
                'courseid'      => $cid,
                'days'          => $window,
                'generated_at'  => $generated,
                'data_sources'  => $sources,
                'cached'        => false,
            ],
        ];

        // Cache the assembled payload so repeat loads (and prefetches) are
        // served instantly. Never let a cache write failure fail the request.
        try {
            $cache->set($cachekey, $payload);
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal.
        }

        return $payload;
    }

    /**
     * KPI cards: students, at-risk, quiz average, active, questions.
     * Each KPI carries a trend direction + percent for the "vs last week"
     * indicator on the card.
     */
    private static function build_kpis(array $courseAnalytics, array $pulse): array {
        $total = (int) ($pulse['total_students'] ?? $courseAnalytics['enrolled_students'] ?? 0);
        $atRisk = (int) ($pulse['at_risk_count'] ?? 0);
        $active = (int) ($pulse['active_this_week'] ?? $courseAnalytics['active_students'] ?? 0);
        $avgQuiz = (float) ($pulse['avg_quiz'] ?? 0);
        $questions = (int) ($pulse['questions_this_week'] ?? 0);

        return [
            'students' => [
                'value'      => $total,
                'label'      => 'Students',
                'icon'       => 'groups',
                'trend'      => '',
                'trend_pct'  => 0,
                'hint'       => 'Enrolled students in this course',
            ],
            'at_risk' => [
                'value'      => $atRisk,
                'label'      => 'At Risk',
                'icon'       => 'warning',
                'trend'      => $pulse['at_risk_trend'] ?? 'unknown',
                'trend_pct'  => (int) ($pulse['at_risk_trend_delta'] ?? 0),
                'hint'       => 'Students showing patterns of struggling or disengaging',
            ],
            'avg_quiz' => [
                'value'      => $avgQuiz,
                'label'      => 'Quiz Avg',
                'icon'       => 'quiz',
                'trend'      => $pulse['quiz_trend'] ?? 'unknown',
                'trend_pct'  => (int) ($pulse['quiz_trend_pct'] ?? 0),
                'hint'       => 'Average quiz score across assessments',
            ],
            'active' => [
                'value'      => $active,
                'label'      => 'Active This Week',
                'icon'       => 'activity',
                'trend'      => $pulse['questions_trend'] ?? 'unknown',
                'trend_pct'  => (int) ($pulse['questions_trend_pct'] ?? 0),
                'hint'       => 'Students who accessed materials, asked questions, or submitted work',
            ],
            'questions' => [
                'value'      => $questions,
                'label'      => 'Questions',
                'icon'       => 'chat',
                'trend'      => $pulse['questions_trend'] ?? 'unknown',
                'trend_pct'  => (int) ($pulse['questions_trend_pct'] ?? 0),
                'hint'       => 'Questions asked to the AI tutor this week',
            ],
        ];
    }

    /**
     * Risk distribution for the donut chart: good / warning / critical.
     * Derived from the per-student risk levels where available; otherwise
     * falls back to the course-pulse at-risk count split.
     */
    private static function build_risk_distribution(array $pulse, array $narratives): array {
        $critical = 0;
        $warning = 0;
        $good = 0;

        if (!empty($narratives)) {
            foreach ($narratives as $n) {
                $level = (string) ($n['risk_level'] ?? '');
                if ($level === 'critical' || $level === 'high') {
                    $critical++;
                } else if ($level === 'medium' || $level === 'low') {
                    $warning++;
                } else {
                    $good++;
                }
            }
            return [
                'good'     => $good,
                'warning'  => $warning,
                'critical' => $critical,
            ];
        }

        $total = (int) ($pulse['total_students'] ?? 0);
        $atRisk = (int) ($pulse['at_risk_count'] ?? 0);
        return [
            'good'     => max(0, $total - $atRisk),
            'warning'  => $atRisk,
            'critical' => 0,
        ];
    }

    /**
     * Deterministic health grade (A–F) computed from real course metrics.
     *
     * Used whenever the AI service is unavailable or rate-limited so the
     * dashboard health chip always has data (previously it showed a bare
     * "—" because get_struggle_insights left health_grade empty once the
     * AI calls were skipped).
     *
     * Rubric (0-100 weighted score):
     *   45% quiz average    – actual graded attempts (0-100)
     *   30% safety          – share of students NOT at risk
     *   15% risk severity   – share of students not critical/warning
     *   10% engagement      – active students this week
     *
     * Also produces a deterministic one-line summary + top recommendation so
     * the briefing block never shows "no data" placeholders.
     *
     * @return array{grade: string, label: string, executive_summary: string, top_recommendation: string}
     */
    private static function compute_health_fallback(array $kpis, array $risk): array {
        $total    = (int) ($kpis['students']['value'] ?? 0);
        $students = max(1, (float) $total);
        $atRisk   = (float) ($kpis['at_risk']['value'] ?? 0);
        $avgQuiz  = min(100, max(0, (float) ($kpis['avg_quiz']['value'] ?? 0)));
        $active   = (float) ($kpis['active']['value'] ?? 0);
        $critical = (float) ($risk['critical'] ?? 0);
        $warning  = (float) ($risk['warning'] ?? 0);

        $score = (0.45 * $avgQuiz)
               + (0.30 * (1 - min(1, $atRisk / $students)) * 100)
               + (0.15 * (1 - min(1, ($critical + $warning) / $students)) * 100)
               + (0.10 * min(1, $active / $students) * 100);

        $grade = 'F';
        $label = 'At Risk';
        if ($score >= 85)      { $grade = 'A'; $label = 'Excellent'; }
        else if ($score >= 70) { $grade = 'B'; $label = 'Good'; }
        else if ($score >= 55) { $grade = 'C'; $label = 'Fair'; }
        else if ($score >= 40) { $grade = 'D'; $label = 'Needs Attention'; }

        // Deterministic briefing text (shown when the AI service is offline).
        $atRiskPct = round($atRisk / $students * 100);
        $activePct = round($active / $students * 100);
        $summary = $total . ' student' . ($total === 1 ? '' : 's') . ', ' . (int) $atRisk
            . ' at risk (' . $atRiskPct . '%), quiz average ' . round($avgQuiz) . '%, '
            . (int) $active . ' active this week.';

        $reco = 'Overall trends look stable. Keep monitoring weekly.';
        if ($avgQuiz < 50) {
            $reco = 'Quiz performance is low — consider reviewing recent quiz material in class.';
        } else if ($atRisk / $students > 0.5) {
            $reco = 'More than half the class is at risk — follow up with the flagged students below.';
        } else if ($active / $students < 0.5) {
            $reco = 'Engagement is low — encourage students to use the AI tutor and course materials.';
        }

        return [
            'grade'               => $grade,
            'label'               => $label,
            'executive_summary'   => $summary,
            'top_recommendation'  => $reco,
        ];
    }

    /**
     * Topic struggle matrix for the heatmap: topic names + scored rows.
     */
    private static function build_topic_struggle(array $struggleAreas): array {
        $topics = [];
        $heatmap = [];
        $detail = [];

        foreach ($struggleAreas as $i => $area) {
            $name = (string) ($area['topic'] ?? 'Unknown');
            $topics[] = $name;
            $score = min(100, max(0, (int) ($area['struggle_score'] ?? 0)));
            // Heatmap rows: [topic index, series index (0), struggle score].
            $heatmap[] = [$i, 0, $score];
            $detail[] = [
                'topic'          => $name,
                'severity'       => $area['severity'] ?? 'watch',
                'struggle_score' => $score,
                'student_count'  => (int) ($area['student_count'] ?? 0),
                'total_students' => (int) ($area['total_students'] ?? 0),
                'trend'          => $area['trend'] ?? 'stable',
                'trend_pct'      => (int) ($area['trend_pct'] ?? 0),
                'sample_questions' => $area['sample_questions'] ?? [],
                'suggestion'     => $area['suggestion'] ?? '',
            ];
        }

        return [
            'topics'  => $topics,
            'heatmap' => $heatmap,
            'detail'  => $detail,
        ];
    }

    /**
     * Performance trend line: daily activity counts (7 days) as the primary
     * series, plus the quiz trend pct for the card caption.
     */
    private static function build_performance_trend(array $courseAnalytics, array $pulse): array {
        $daily = $courseAnalytics['daily_counts'] ?? [];
        return [
            'labels'       => array_map(function ($d) { return $d['label'] ?? ''; }, $daily),
            'values'       => array_map(function ($d) { return (int) ($d['count'] ?? 0); }, $daily),
            'quiz_trend'   => $pulse['quiz_trend'] ?? 'unknown',
            'quiz_trend_pct' => (int) ($pulse['quiz_trend_pct'] ?? 0),
            'questions_trend' => (int) ($pulse['questions_trend_pct'] ?? 0),
        ];
    }

    /**
     * Ask the AI service for natural-language insights. Best effort only:
     * any failure (service down, timeout, bad JSON) yields an empty list
     * so the dashboard still renders with the structured data.
     */
    private static function fetch_ai_insights(int $cid, int $window, array $courseAnalytics, array $struggle, array $intel): array {
        global $CFG;

        $cfg = \local_umat_ai_get_service_config();
        if (empty($cfg['url']) || empty($cfg['token'])) {
            return [];
        }

        $pulse = $struggle['course_pulse'] ?? [];
        $total = (int) ($pulse['total_students'] ?? $courseAnalytics['enrolled_students'] ?? 0);
        $active = (int) ($pulse['active_this_week'] ?? $courseAnalytics['active_students'] ?? 0);
        $engagement = $total > 0 ? round(($active / $total) * 100, 1) : 0.0;

        $strugglingTopics = [];
        foreach (($struggle['struggle_areas'] ?? []) as $area) {
            $strugglingTopics[] = [
                'name'          => (string) ($area['topic'] ?? ''),
                'struggle_score' => (int) ($area['struggle_score'] ?? 0),
                'affected'      => (int) ($area['student_count'] ?? 0),
            ];
        }

        $commonQuestions = [];
        foreach (($struggle['common_questions'] ?? []) as $q) {
            $commonQuestions[] = [
                'question'  => (string) ($q['text'] ?? ''),
                'ask_count' => (int) ($q['ask_count'] ?? 0),
            ];
        }

        $recordingCompletion = 0.0;
        $recordings = $intel['recording_analytics'] ?? [];
        if (!empty($recordings)) {
            $sum = 0.0;
            foreach ($recordings as $r) {
                $sum += (float) ($r['completion_rate'] ?? 0);
            }
            $recordingCompletion = round($sum / count($recordings), 1);
        }

        $payload = json_encode([
            'course_id'           => $cid,
            'days'                => $window,
            'course_name'         => (string) get_course($cid)->fullname,
            'enrolled_students'   => $total,
            'at_risk_count'       => (int) ($pulse['at_risk_count'] ?? 0),
            'failing_count'       => 0,
            'quiz_avg'            => (float) ($pulse['avg_quiz'] ?? 0),
            'quiz_trend_pct'      => (float) ($pulse['quiz_trend_pct'] ?? 0),
            'engagement_rate'     => $engagement,
            'active_students'     => $active,
            'struggling_topics'   => $strugglingTopics,
            'common_questions'    => $commonQuestions,
            'recording_completion' => $recordingCompletion,
            'insight_types'       => [],
        ]);

        try {
            require_once($CFG->libdir . '/filelib.php');
            $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
            $client->setHeader([
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['token'],
            ]);
            $client->setopt(['CURLOPT_TIMEOUT' => 15]);
            $raw = $client->post($cfg['url'] . '/api/v1/analytics/insights', $payload);
            $result = json_decode($raw, true);

            if (!is_array($result) || empty($result['insights']) || !is_array($result['insights'])) {
                return [];
            }

            // Sanitize each insight to the frontend contract.
            $clean = [];
            foreach ($result['insights'] as $insight) {
                $clean[] = [
                    'type'     => (string) ($insight['type'] ?? 'info'),
                    'priority' => (string) ($insight['priority'] ?? 'medium'),
                    'text'     => (string) ($insight['text'] ?? ''),
                    'action'   => isset($insight['action']) && is_array($insight['action']) ? [
                        'label' => (string) ($insight['action']['label'] ?? ''),
                        'url'   => (string) ($insight['action']['url'] ?? ''),
                    ] : null,
                ];
            }
            return $clean;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Response schema. Fields are shaped for the redesigned dashboard's
     * card-grid; most are optional so partial data never breaks rendering.
     */
    public static function analytics_data_returns() {
        return new \external_single_structure([

            'kpis' => new \external_single_structure([
                'students'  => self::kpi_structure(),
                'at_risk'   => self::kpi_structure(),
                'avg_quiz'  => self::kpi_structure(),
                'active'    => self::kpi_structure(),
                'questions' => self::kpi_structure(),
            ], '', VALUE_OPTIONAL),

            'health' => new \external_single_structure([
                'grade'              => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'label'              => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'executive_summary'  => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                'going_well'         => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                ),
                'needs_attention'    => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                ),
                'top_recommendation' => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),

            'priority_actions' => new \external_multiple_structure(
                new \external_single_structure([
                    'type'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'urgency'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'icon'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'title'        => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'text'         => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'items'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'name'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'students' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'pct'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'avg_quiz' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'trend'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'days'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'suggestion'   => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'action_label' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'performance_trend' => new \external_single_structure([
                'labels'       => new \external_multiple_structure(new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL),
                'values'       => new \external_multiple_structure(new \external_value(PARAM_INT), '', VALUE_OPTIONAL),
                'quiz_trend'   => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'quiz_trend_pct' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'questions_trend' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),

            'risk_distribution' => new \external_single_structure([
                'good'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'warning'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'critical' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),

            'at_risk_students' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'             => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'fullname'           => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'profileimageurl'    => new \external_value(PARAM_URL, '', VALUE_OPTIONAL),
                    'risk_score'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'risk_level'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'ai_narrative'       => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'summary'            => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'struggle_topics'    => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'last_active'        => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'days_since_last_login' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'question_count'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'avg_quiz'           => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'quiz_failures'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'suggestion'         => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'suggestion_type'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'reasons'            => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'evidence'           => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'confidence'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'recommendation'     => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'quick_actions'      => new \external_multiple_structure(
                        new \external_single_structure([
                            'action' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'label'  => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'icon'   => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'trend'              => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'topic_struggle' => new \external_single_structure([
                'topics'  => new \external_multiple_structure(new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL),
                'heatmap' => new \external_multiple_structure(
                    new \external_multiple_structure(new \external_value(PARAM_INT), '', VALUE_OPTIONAL),
                    '', VALUE_OPTIONAL
                ),
                'detail'  => new \external_multiple_structure(
                    new \external_single_structure([
                        'topic'          => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'severity'       => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'struggle_score' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        'student_count'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        'total_students' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        'trend'          => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'trend_pct'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        'sample_questions' => new \external_multiple_structure(
                            new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                        ),
                        'suggestion'     => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    ]), '', VALUE_OPTIONAL
                ),
            ], '', VALUE_OPTIONAL),

            'common_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'text'           => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'student_count'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'ask_count'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'topic'          => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'suggestion'     => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'interpretation' => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'quiz_analytics' => new \external_single_structure([
                'quiz_attempts'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'average_score'  => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'highest_score'  => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'lowest_score'   => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'median_score'   => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'pass_rate'      => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'distribution'   => new \external_multiple_structure(
                    new \external_single_structure([
                        'grade' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'count' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    ]), '', VALUE_OPTIONAL
                ),
                'most_failed_questions' => new \external_multiple_structure(
                    new \external_single_structure([
                        'question'  => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'wrong_pct' => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    ]), '', VALUE_OPTIONAL
                ),
            ], '', VALUE_OPTIONAL),

            'recording_analytics' => new \external_multiple_structure(
                new \external_single_structure([
                    'title'                 => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'recording_url'         => new \external_value(PARAM_URL, '', VALUE_OPTIONAL),
                    'views'                 => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'avg_watch_duration_min' => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'completion_rate'       => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'duration_min'          => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'never_watched_count'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'recommendation'        => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'resource_analytics' => new \external_multiple_structure(
                new \external_single_structure([
                    'filename'              => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'downloads'             => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'unique_viewers'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'avg_reading_time_min'  => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'students_never_opened' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'recommendation'        => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'insights' => new \external_multiple_structure(
                new \external_single_structure([
                    'type'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'priority' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'text'     => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'action'   => new \external_single_structure([
                        'label' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        'url'   => new \external_value(PARAM_URL, '', VALUE_OPTIONAL),
                    ], '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            'meta' => new \external_single_structure([
                'courseid'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'days'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'generated_at' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'cached'       => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
                'data_sources' => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                ),
            ], '', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Shape of one KPI card.
     */
    private static function kpi_structure(): \external_single_structure {
        return new \external_single_structure([
            'value'     => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
            'label'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
            'icon'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
            'trend'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
            'trend_pct' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'hint'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
        ], '', VALUE_OPTIONAL);
    }
}
