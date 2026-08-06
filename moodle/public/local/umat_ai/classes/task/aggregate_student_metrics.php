<?php

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\student_risk_calculator;

/**
 * Hourly scheduled task: refresh the cached per-student metrics table.
 *
 * This task does NOT define a risk formula. It calls
 * \local_umat_ai\analytics\student_risk_calculator, the same code the live
 * insights API and the student detail view use, so the stored risk_score,
 * risk_level, confidence and classification always agree with what a lecturer
 * sees on screen. The weights live in analytics/risk_config.php.
 *
 * The other columns written here (logins, avg_quiz_grade, ai_questions_asked,
 * last_active) are descriptive counters for display, not risk inputs.
 *
 * @package    local_umat_ai
 */
class aggregate_student_metrics extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_aggregate_student_metrics', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        // Use wider windows for more stable, meaningful metrics.
        $weekago     = time() - (7 * DAYSECS);
        $twoweeksago = time() - (14 * DAYSECS);

        // Identify active courses from recent AI activity or quiz attempts.
        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {umat_ai_chat_logs} WHERE timecreated > :since",
            ['since' => $weekago]
        );
        if (empty($courses)) {
            $courses = $DB->get_fieldset_sql(
                "SELECT DISTINCT courseid FROM {quiz_grades} WHERE timemodified > :since",
                ['since' => $twoweeksago]
            );
        }
        if (empty($courses)) {
            return;
        }

        $aiserviceurl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');

        foreach ($courses as $cid) {
            $enrolled = enrol_get_course_users($cid, true);
            if (empty($enrolled)) {
                continue;
            }

            // Students only — enrol_get_course_users() also returns admins
            // and lecturers enrolled in the course, which polluted the
            // metrics table (and every UI reading it: at-risk lists, NLQ,
            // struggle dashboard) with staff rows.
            $studentctx = \context_course::instance($cid, IGNORE_MISSING);
            if ($studentctx) {
                $enrolled = local_umat_ai_student_only($enrolled, $studentctx);
                if (empty($enrolled)) {
                    continue;
                }
            }

            $metrics = [];

            // Shared course facts, fetched once instead of once per student.
            try {
                $coursecontext = student_risk_calculator::build_course_context((int) $cid);
            } catch (\Throwable $e) {
                mtrace('local_umat_ai: could not build risk context for course ' . $cid
                    . ' — ' . $e->getMessage());
                continue;
            }

            foreach ($enrolled as $user) {
                $uid = (int) $user->id;

                // â”€â”€ Page-view logins in the last 7 days â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                $logins = (int) $DB->count_records_select(
                    'logstore_standard_log',
                    "userid = :uid AND courseid = :cid AND timecreated > :since AND action = 'viewed'",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $weekago]
                );

                // â”€â”€ Average quiz grade over the last 14 days â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // Returns -1 sentinel when the student has taken no quizzes in window.
                $avgquizRaw = $DB->get_field_sql(
                    "SELECT AVG(qg.grade) FROM {quiz_grades} qg
                      JOIN {quiz} q ON q.id = qg.quiz
                     WHERE qg.userid = :uid AND q.course = :cid AND qg.timemodified > :since",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $twoweeksago]
                );
                $avgquiz = ($avgquizRaw !== null && $avgquizRaw !== false)
                    ? (float) $avgquizRaw
                    : -1.0; // -1 = no quiz data

                // â”€â”€ AI questions asked in the last 7 days (student role only) â”€â”€â”€â”€â”€
                $aiq = (int) $DB->count_records_select(
                    'umat_ai_chat_logs',
                    "userid = :uid AND courseid = :cid AND timecreated > :since AND role = 'student'",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $weekago]
                );

                // â”€â”€ Most recent activity across all tracked sources â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                $lastactive = $DB->get_field_sql(
                    "SELECT MAX(last_ts) FROM (
                        SELECT MAX(timecreated) AS last_ts
                          FROM {umat_ai_chat_logs}
                         WHERE userid = :uid1 AND courseid = :cid1
                        UNION ALL
                        SELECT MAX(qg2.timemodified) AS last_ts
                          FROM {quiz_grades} qg2
                          JOIN {quiz} q2 ON q2.id = qg2.quiz
                         WHERE qg2.userid = :uid2 AND q2.course = :cid2
                        UNION ALL
                        SELECT MAX(timecreated) AS last_ts
                          FROM {logstore_standard_log}
                         WHERE userid = :uid3 AND courseid = :cid3
                    ) AS combined_activity",
                    [
                        'uid1' => $uid, 'cid1' => $cid,
                        'uid2' => $uid, 'cid2' => $cid,
                        'uid3' => $uid, 'cid3' => $cid,
                    ]
                );
                $lastactive = $lastactive ? (int) $lastactive : $weekago;

                // ── The one authoritative risk score ─────────────────────────
                // There is no fallback formula. The previous fallback used
                // different weights and different thresholds from the live API,
                // so a student could be "high risk" on the dashboard and
                // "medium" in the stored metrics at the same moment. If the
                // calculator cannot score a student, the row is skipped and the
                // failure is logged rather than papered over with a second,
                // incompatible model.
                try {
                    $v2Result = student_risk_calculator::compute($uid, (int) $cid, $coursecontext);
                } catch (\Throwable $e) {
                    mtrace('local_umat_ai: risk computation failed for user ' . $uid
                        . ' in course ' . $cid . ' — ' . $e->getMessage());
                    continue;
                }

                $riskscore = $v2Result['risk_score'];

                // Store 0.0 for avg_quiz_grade when the sentinel is -1 (no quiz).
                $avgquizStored = $avgquiz < 0.0 ? 0.0 : $avgquiz;

                $metrics[] = [
                    'userid'             => $uid,
                    'courseid'           => (int) $cid,
                    'logins'             => $logins,
                    'avg_quiz_grade'     => $avgquizStored,
                    'ai_questions_asked' => $aiq,
                    'risk_score'         => $riskscore,
                    'last_active'        => $lastactive,
                    // Levels and thresholds come from risk_config alone — no
                    // second set of cut-offs is applied here.
                    'risk_level'         => $v2Result['risk_level'],
                    'confidence'         => $v2Result['confidence'],
                    'classification'     => $v2Result['classification'],
                ];
            }

            // Bulk replace for this course: drop stale records, insert fresh.
            $DB->delete_records('umat_ai_student_metrics', ['courseid' => $cid]);
            $DB->insert_records('umat_ai_student_metrics', $metrics);

            // Push snapshot to the Python AI service for LLM-based analysis.
            if (!empty($aiserviceurl) && !empty($token)) {
                $this->push_snapshot((int) $cid, $metrics, $aiserviceurl, $token);
            }
        }
    }

    /**
     * Sends a student metrics snapshot to the Python AI analytics service.
     *
     * @param int    $courseid  Moodle course ID.
     * @param array  $metrics   Array of per-student metric records.
     * @param string $url       AI service base URL.
     * @param string $token     Bearer authentication token.
     */
    private function push_snapshot(int $courseid, array $metrics, string $url, string $token): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $client = new \curl(['ignoresecurity' => true]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $payload = json_encode([
            'course_id'     => $courseid,
            'snapshot_time' => time(),
            'students'      => $metrics,
        ]);
        $client->post(rtrim($url, '/') . '/api/v1/analytics/snapshot', $payload);
    }
}
