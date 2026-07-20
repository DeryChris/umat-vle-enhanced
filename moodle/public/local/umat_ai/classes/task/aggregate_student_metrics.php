<?php

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class aggregate_student_metrics extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_aggregate_student_metrics', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        $yesterday = time() - DAYSECS;
        $weekago   = time() - (7 * DAYSECS);
        $courses   = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {umat_ai_chat_logs} WHERE timecreated > :since", ['since' => $yesterday]);

        if (empty($courses)) {
            $courses = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {quiz_grades} WHERE timemodified > :since", ['since' => $weekago]);
        }

        if (empty($courses)) {
            return;
        }

        $aiserviceurl = get_config('local_umat_ai', 'ai_service_url');
        $token        = get_config('local_umat_ai', 'ai_service_token');

        foreach ($courses as $cid) {
            $enrolled = enrol_get_course_users($cid, true);
            if (empty($enrolled)) continue;

            $metrics = [];

            foreach ($enrolled as $user) {
                $uid = (int)$user->id;

                // Logins in last 24h — strict indexed query, only web + viewed events
                $logins = $DB->count_records_select(
                    'logstore_standard_log',
                    "userid = :uid AND courseid = :cid AND timecreated > :since AND action = 'viewed'",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $yesterday]
                );

                // Avg quiz grade last 7 days
                $avgquiz = $DB->get_field_sql(
                    "SELECT AVG(qg.grade) FROM {quiz_grades} qg
                      JOIN {quiz} q ON q.id = qg.quiz
                     WHERE qg.userid = :uid AND q.course = :cid AND qg.timemodified > :since",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $weekago]
                );
                $avgquiz = $avgquiz !== false ? (float)$avgquiz : 0.0;

                // AI questions asked in last 24h
                $aiq = $DB->count_records_select(
                    'umat_ai_chat_logs',
                    "userid = :uid AND courseid = :cid AND timecreated > :since",
                    ['uid' => $uid, 'cid' => $cid, 'since' => $yesterday]
                );

                // Last activity across all sources
                $lastactive = $DB->get_field_sql("
                    SELECT MAX(last) FROM (
                        SELECT MAX(timecreated) AS last FROM {umat_ai_chat_logs} WHERE userid = :uid1 AND courseid = :cid1
                        UNION ALL
                        SELECT MAX(qg2.timemodified) FROM {quiz_grades} qg2 JOIN {quiz} q2 ON q2.id = qg2.quiz WHERE qg2.userid = :uid2 AND q2.course = :cid2
                        UNION ALL
                        SELECT MAX(timecreated) FROM {logstore_standard_log} WHERE userid = :uid3 AND courseid = :cid3
                    ) AS t",
                    ['uid1' => $uid, 'cid1' => $cid, 'uid2' => $uid, 'cid2' => $cid, 'uid3' => $uid, 'cid3' => $cid]
                );
                $lastactive = $lastactive ? (int)$lastactive : $yesterday;

                $riskscore = round(max(0, min(100,
                    100 - ($logins * 2) - ($avgquiz * 0.5) + ($aiq * 3)
                )), 2);

                $metrics[] = [
                    'userid'           => $uid,
                    'courseid'         => $cid,
                    'logins'           => (int)$logins,
                    'avg_quiz_grade'   => $avgquiz,
                    'ai_questions_asked' => (int)$aiq,
                    'risk_score'       => $riskscore,
                    'last_active'      => $lastactive,
                ];
            }

            // Bulk upsert: delete existing for course then insert fresh
            $DB->delete_records('umat_ai_student_metrics', ['courseid' => $cid]);
            $DB->insert_records('umat_ai_student_metrics', $metrics);

            // Push snapshot to Python AI service
            if (!empty($aiserviceurl) && !empty($token)) {
                $this->push_snapshot($cid, $metrics, $aiserviceurl, $token);
            }
        }
    }

    private function push_snapshot(int $courseid, array $metrics, string $url, string $token): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $client = new \curl(['ignoresecurity' => true]);
        $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
        $payload = json_encode([
            'course_id' => $courseid,
            'snapshot_time' => time(),
            'students' => $metrics,
        ]);
        $client->post(rtrim($url, '/') . '/api/v1/analytics/snapshot', $payload);
    }
}
