<?php
/**
 * External API: Student-facing "My Progress" dashboard.
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class student_progress extends \external_api {

    // ------------------------------------------------------------------ //
    // get_my_progress — personal struggle analytics for the student       //
    // ------------------------------------------------------------------ //
    public static function get_my_progress_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)', VALUE_DEFAULT, 0),
            'days'     => new \external_value(PARAM_INT, 'Lookback window in days', VALUE_DEFAULT, 60),
        ]);
    }

    public static function get_my_progress($courseid = 0, $days = 60) {
        global $DB, $USER;
        try {
            $params = self::validate_parameters(self::get_my_progress_parameters(), [
                'courseid' => $courseid,
                'days'     => $days,
            ]);
            $cid    = (int)$params['courseid'];
            $days   = max(7, min(365, (int)$params['days']));
            $since  = time() - ($days * 86400);
            $uid    = (int)$USER->id;
            $now    = time();
            $weekAgo = $now - 604800;

            // ── Moodle cache: check for cached response ──
            $cache    = \cache::make('local_umat_ai', 'student_progress');
            $cachekey = "prog_{$uid}_{$cid}_{$params['days']}";
            $cached   = $cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }

            $courseCond = $cid > 0 ? ' AND courseid = :cid' : '';
            $courseBind = $cid > 0 ? ['cid' => $cid] : [];

            // ── Question counts per course ──
            $sql = "SELECT courseid, COUNT(*) AS qcount
                      FROM {umat_ai_chat_logs}
                     WHERE userid = :uid AND role = 'student' AND timecreated > :since
                           $courseCond
                  GROUP BY courseid";
            $qRows = $DB->get_records_sql($sql, array_merge(
                ['uid' => $uid, 'since' => $since], $courseBind
            ));
            $totalQuestions = array_sum(array_column($qRows, 'qcount'));
            $perCourse      = [];
            foreach ($qRows as $r) {
                $perCourse[(int)$r->courseid] = (int)$r->qcount;
            }

            // ── Weekly question count (last 7 days) ──
            $sqlWeek = "SELECT COUNT(*) FROM {umat_ai_chat_logs}
                         WHERE userid = :uid AND role = 'student' AND timecreated > :week
                               $courseCond";
            $weekQuestions = (int)$DB->get_field_sql($sqlWeek, array_merge(
                ['uid' => $uid, 'week' => $weekAgo], $courseBind
            ));

            // ── Session count ──
            $sqlSess = "SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs}
                         WHERE userid = :uid AND role = 'student' AND timecreated > :since
                               $courseCond";
            $totalSessions = (int)$DB->get_field_sql($sqlSess, array_merge(
                ['uid' => $uid, 'since' => $since], $courseBind
            ));

            // ── Struggle topics (from student_context) ──
            $sqlStru = "SELECT sc.*, c.shortname AS course_short
                          FROM {umat_ai_student_context} sc
                          JOIN {course} c ON c.id = sc.courseid
                         WHERE sc.userid = :uid AND sc.timecreated > :since
                               $courseCond
                      ORDER BY sc.struggle_score DESC";
            $struRows = $DB->get_records_sql($sqlStru, array_merge(
                ['uid' => $uid, 'since' => $since], $courseBind
            ));
            $struggleTopics = [];
            foreach ($struRows as $r) {
                $struggleTopics[] = [
                    'course_id'       => (int)$r->courseid,
                    'course_short'    => $r->course_short,
                    'topic_label'     => $r->topic_label ?? '',
                    'struggle_reason' => $r->struggle_reason,
                    'struggle_score'  => min(100, (int)$r->struggle_score),
                    'is_struggle'     => (bool)$r->is_struggle,
                    'timecreated'     => (int)$r->timecreated,
                ];
            }

            // ── Issues summary ──
            $sqlIssues = "SELECT status, COUNT(*) AS cnt
                            FROM {umat_ai_issue_reports}
                           WHERE userid = :uid $courseCond
                        GROUP BY status";
            $issueRows = $DB->get_records_sql($sqlIssues, array_merge(
                ['uid' => $uid], $courseBind
            ));
            $totalIssues = 0;
            $issueStatusCounts = ['open' => 0, 'in_review' => 0, 'resolved' => 0, 'closed' => 0];
            foreach ($issueRows as $r) {
                $cnt = (int)$r->cnt;
                $totalIssues += $cnt;
                if (isset($issueStatusCounts[$r->status])) {
                    $issueStatusCounts[$r->status] = $cnt;
                }
            }

            // ── Course names ──
            $courseIds = array_keys($perCourse);
            foreach ($struggleTopics as $st) {
                if (!in_array($st['course_id'], $courseIds)) {
                    $courseIds[] = $st['course_id'];
                }
            }
            $courseNames = [];
            if (!empty($courseIds)) {
                list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
                $cRows = $DB->get_records_sql(
                    "SELECT id, shortname, fullname FROM {course} WHERE id $inSql",
                    $inParams
                );
                foreach ($cRows as $cr) {
                    $courseNames[(int)$cr->id] = $cr->shortname ?: $cr->fullname;
                }
            }

            // ── Weekly activity trend (questions per day, last 7 days) ──
            $dailyCounts = [];
            $dayLabels   = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $now - ($i * 86400);
                $dayStart = strtotime('midnight', $dayStart);
                $dayEnd   = $dayStart + 86400;
                $dayLabel = date('D', $dayStart);
                $dayLabels[] = $dayLabel;
                $sqlDay = "SELECT COUNT(*) FROM {umat_ai_chat_logs}
                            WHERE userid = :uid AND role = 'student'
                                  AND timecreated >= :ds AND timecreated < :de
                                  $courseCond";
                $cnt = (int)$DB->get_field_sql($sqlDay, array_merge(
                    ['uid' => $uid, 'ds' => $dayStart, 'de' => $dayEnd], $courseBind
                ));
                $dailyCounts[] = $cnt;
            }

            // ── Overall struggle score (composite) ──
            $avgStruggle = 0;
            if (!empty($struggleTopics)) {
                $scores = array_column($struggleTopics, 'struggle_score');
                $avgStruggle = (int)round(array_sum($scores) / count($scores));
            }

            // ── Optional AI enrichment ──
            $aiRecommendation = '';
            $aiServiceUsed = false;
            $cfg = \local_umat_ai_get_service_config();
            if (!empty($cfg['token'])) {
                try {
                    $client = new \local_umat_ai\ai_client($cfg['url'], $cfg['token']);
                    $payload = json_encode([
                        'user_id'         => $uid,
                        'course_id'       => $cid ?: null,
                        'total_questions' => $totalQuestions,
                        'week_questions'  => $weekQuestions,
                        'total_sessions'  => $totalSessions,
                        'total_issues'    => $totalIssues,
                        'struggle_topics' => $struggleTopics,
                        'daily_activity'  => $dailyCounts,
                        'day_labels'      => $dayLabels,
                    ]);
                    $raw = $client->post($cfg['url'] . '/api/v1/analytics/student-progress', $payload);
                    $result = json_decode($raw, true);
                    if ($result && isset($result['recommendation'])) {
                        $aiRecommendation = $result['recommendation'];
                        $aiServiceUsed = true;
                    }
                } catch (\Throwable $e) {
                    // AI service not available — fall back silently
                }
            }

            $result = [
                'total_questions'   => $totalQuestions,
                'week_questions'    => $weekQuestions,
                'total_sessions'    => $totalSessions,
                'total_issues'      => $totalIssues,
                'issue_statuses'    => $issueStatusCounts,
                'struggle_topics'   => $struggleTopics,
                'struggle_score'    => $avgStruggle,
                'per_course'        => json_encode($perCourse),
                'course_names'      => json_encode($courseNames),
                'daily_questions'   => json_encode($dailyCounts),
                'day_labels'        => json_encode($dayLabels),
                'ai_recommendation' => $aiRecommendation,
                'ai_service_used'   => $aiServiceUsed,
            ];
            $cache->set($cachekey, $result);
            return $result;
        } catch (\Throwable $e) {
            return [
                'total_questions'   => 0,
                'week_questions'    => 0,
                'total_sessions'    => 0,
                'total_issues'      => 0,
                'issue_statuses'    => ['open' => 0, 'in_review' => 0, 'resolved' => 0, 'closed' => 0],
                'struggle_topics'   => [],
                'struggle_score'    => 0,
                'per_course'        => '[]',
                'course_names'      => '[]',
                'daily_questions'   => '[]',
                'day_labels'        => '[]',
                'ai_recommendation' => '',
                'ai_service_used'   => false,
            ];
        }
    }

    public static function get_my_progress_returns() {
        return new \external_single_structure([
            'total_questions'   => new \external_value(PARAM_INT, 'Total questions asked'),
            'week_questions'    => new \external_value(PARAM_INT, 'Questions in last 7 days'),
            'total_sessions'    => new \external_value(PARAM_INT, 'Total AI chat sessions'),
            'total_issues'      => new \external_value(PARAM_INT, 'Total issues reported'),
            'issue_statuses'    => new \external_single_structure([
                'open'      => new \external_value(PARAM_INT),
                'in_review' => new \external_value(PARAM_INT),
                'resolved'  => new \external_value(PARAM_INT),
                'closed'    => new \external_value(PARAM_INT),
            ]),
            'struggle_topics'   => new \external_multiple_structure(
                new \external_single_structure([
                    'course_id'       => new \external_value(PARAM_INT),
                    'course_short'    => new \external_value(PARAM_TEXT),
                    'topic_label'     => new \external_value(PARAM_TEXT),
                    'struggle_reason' => new \external_value(PARAM_ALPHAEXT),
                    'struggle_score'  => new \external_value(PARAM_INT),
                    'is_struggle'     => new \external_value(PARAM_BOOL),
                    'timecreated'     => new \external_value(PARAM_INT),
                ])
            ),
            'struggle_score'    => new \external_value(PARAM_INT, 'Average struggle score 0-100'),
            'per_course'        => new \external_value(PARAM_RAW, 'JSON object: courseid => question count'),
            'course_names'      => new \external_value(PARAM_RAW, 'JSON object: courseid => shortname'),
            'daily_questions'   => new \external_value(PARAM_RAW, 'JSON array: questions per day last 7 days'),
            'day_labels'        => new \external_value(PARAM_RAW, 'JSON array: day labels'),
            'ai_recommendation' => new \external_value(PARAM_TEXT, 'AI personalized recommendation', VALUE_OPTIONAL),
            'ai_service_used'   => new \external_value(PARAM_BOOL, 'Whether AI enrichment was applied'),
        ]);
    }
}
