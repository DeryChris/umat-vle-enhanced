<?php
// ============================================================
// Event observers: capture student learning activity for analytics
// Pushes struggle signals to the Python AI engine via signed webhook
// ============================================================

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

class observer {

    /** Minimum quiz grade (percent) before flagging a struggle area. */
    const STRUGGLE_GRADE_THRESHOLD = 50;

    /** Resource views without a quiz attempt that indicate struggle. */
    const STRUGGLE_VIEW_THRESHOLD = 5;

    /**
     * Quiz attempt submitted — flag low scores as struggle areas.
     */
    public static function quiz_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        $data     = $event->get_data();
        $userid   = (int) $data['userid'];
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);

        if (!$userid || !$courseid) {
            return;
        }

        $attemptid = (int) ($data['objectid'] ?? 0);
        $gradepercent = self::get_quiz_attempt_grade_percent($attemptid);

        self::log_activity($userid, $courseid, $cmid, 'quiz_submitted', [
            'attemptid'    => $attemptid,
            'gradepercent' => $gradepercent,
        ]);

        if ($gradepercent !== null && $gradepercent < self::STRUGGLE_GRADE_THRESHOLD) {
            $topic = self::resolve_module_label($cmid);
            self::upsert_struggle_context($userid, $courseid, $cmid, $topic, 'quiz_failure', $gradepercent);
            self::purge_struggle_cache($courseid);
            self::push_analytics_update($userid, $courseid, 'quiz_submitted', [
                'cmid'           => $cmid,
                'topic'          => $topic,
                'grade_percent'  => $gradepercent,
                'is_struggle'    => true,
                'struggle_score' => max(0, 100 - $gradepercent),
            ]);
        }
    }

    /**
     * Course module viewed — repeated views without quiz attempts suggest struggle.
     */
    public static function resource_viewed(\core\event\course_module_viewed $event): void {
        global $DB;

        $data     = $event->get_data();
        $userid   = (int) $data['userid'];
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);

        if (!$userid || !$courseid || !$cmid) {
            return;
        }

        // Only track resource-like modules (not every page view in the course).
        $modname = $data['other']['modulename'] ?? '';
        if (!in_array($modname, ['resource', 'page', 'book', 'url', 'folder'], true)) {
            return;
        }

        self::log_activity($userid, $courseid, $cmid, 'resource_viewed', [
            'modulename' => $modname,
        ]);

        $viewcount = self::count_resource_views($userid, $courseid, $cmid);
        $hasquiz   = self::user_has_quiz_attempt_in_course($userid, $courseid);

        if ($viewcount > self::STRUGGLE_VIEW_THRESHOLD && !$hasquiz) {
            $topic = self::resolve_module_label($cmid);
            $score = min(100, ($viewcount - self::STRUGGLE_VIEW_THRESHOLD) * 15 + 40);
            self::upsert_struggle_context($userid, $courseid, $cmid, $topic, 'repeated_views', $score);
            self::purge_struggle_cache($courseid);
            self::push_analytics_update($userid, $courseid, 'resource_viewed', [
                'cmid'           => $cmid,
                'topic'          => $topic,
                'view_count'     => $viewcount,
                'has_quiz'       => false,
                'is_struggle'    => true,
                'struggle_score' => $score,
            ]);
        }
    }

    /**
     * Assignment submission graded — flag low grades as struggle areas.
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        global $DB;

        $data     = $event->get_data();
        $userid   = (int) ($data['relateduserid'] ?? $data['userid'] ?? 0);
        $courseid = (int) $data['courseid'];
        $cmid     = (int) ($data['contextinstanceid'] ?? 0);

        if (!$userid || !$courseid) {
            return;
        }

        $gradepercent = self::get_assign_grade_percent($cmid, $userid);

        self::log_activity($userid, $courseid, $cmid, 'submission_graded', [
            'gradepercent' => $gradepercent,
        ]);

        if ($gradepercent !== null && $gradepercent < self::STRUGGLE_GRADE_THRESHOLD) {
            $topic = self::resolve_module_label($cmid);
            self::upsert_struggle_context($userid, $courseid, $cmid, $topic, 'assignment_failure', $gradepercent);
            self::purge_struggle_cache($courseid);
            self::push_analytics_update($userid, $courseid, 'submission_graded', [
                'cmid'           => $cmid,
                'topic'          => $topic,
                'grade_percent'  => $gradepercent,
                'is_struggle'    => true,
                'struggle_score' => max(0, 100 - $gradepercent),
            ]);
        }
    }

    // ── Internal helpers ────────────────────────────────────────

    /**
     * Purge the Moodle struggle-insights cache for a course so the
     * lecturer dashboard picks up the new data on next load.
     */
    private static function purge_struggle_cache(int $courseid): void {
        try {
            $cache = \cache::make('local_umat_ai', 'struggle_insights');
            foreach ([30, 60] as $days) {
                $cache->delete("struggle_{$courseid}_{$days}");
            }
        } catch (\Throwable $e) {
            // Cache purging is best-effort.
        }
    }

    private static function log_activity(int $userid, int $courseid, int $cmid,
                                         string $eventtype, array $payload): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('umat_ai_activity_log')) {
            return;
        }

        $DB->insert_record('umat_ai_activity_log', (object) [
            'userid'     => $userid,
            'courseid'   => $courseid,
            'cmid'       => $cmid ?: null,
            'event_type' => $eventtype,
            'event_data' => json_encode($payload),
            'timecreated'=> time(),
        ]);
    }

    private static function upsert_struggle_context(int $userid, int $courseid, int $cmid,
                                                    string $topic, string $reason, float $score): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('umat_ai_student_context')) {
            return;
        }

        $now = time();
        $existing = $DB->get_record('umat_ai_student_context', [
            'userid'   => $userid,
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);

        if ($existing) {
            $existing->topic_label    = $topic;
            $existing->struggle_reason = $reason;
            $existing->struggle_score = max((float) $existing->struggle_score, $score);
            $existing->is_struggle    = 1;
            $existing->timemodified   = $now;
            $DB->update_record('umat_ai_student_context', $existing);
        } else {
            $DB->insert_record('umat_ai_student_context', (object) [
                'userid'          => $userid,
                'courseid'        => $courseid,
                'cmid'            => $cmid ?: null,
                'topic_label'     => $topic,
                'struggle_reason' => $reason,
                'struggle_score'  => $score,
                'is_struggle'     => 1,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);
        }
    }

    private static function push_analytics_update(int $userid, int $courseid,
                                                  string $eventtype, array $payload): void {
        $profile = self::build_student_profile($userid, $courseid);

        local_umat_ai_push_analytics([
            'user_id'    => $userid,
            'course_id'  => $courseid,
            'event_type' => $eventtype,
            'payload'    => $payload,
            'profile'    => $profile,
        ]);
    }

    private static function build_student_profile(int $userid, int $courseid): array {
        global $DB;

        $struggletopics = [];
        if ($DB->get_manager()->table_exists('umat_ai_student_context')) {
            $records = $DB->get_records('umat_ai_student_context', [
                'userid'      => $userid,
                'courseid'    => $courseid,
                'is_struggle' => 1,
            ], 'struggle_score DESC', 'topic_label, struggle_score, struggle_reason', 0, 10);
            foreach ($records as $r) {
                $struggletopics[] = [
                    'topic'  => $r->topic_label,
                    'score'  => (float) $r->struggle_score,
                    'reason' => $r->struggle_reason,
                ];
            }
        }

        $recentevents = [];
        if ($DB->get_manager()->table_exists('umat_ai_activity_log')) {
            $logs = $DB->get_records('umat_ai_activity_log', [
                'userid'   => $userid,
                'courseid' => $courseid,
            ], 'timecreated DESC', 'event_type, event_data, timecreated', 0, 5);
            foreach ($logs as $log) {
                $recentevents[] = [
                    'type' => $log->event_type,
                    'data' => json_decode($log->event_data ?? '{}', true),
                    'at'   => (int) $log->timecreated,
                ];
            }
        }

        return [
            'current_grade'   => self::estimate_course_grade($userid, $courseid),
            'struggle_topics' => $struggletopics,
            'recent_events'   => $recentevents,
            'learning_style'  => count($struggletopics) >= 3 ? 'needs_scaffolding' : 'standard',
        ];
    }

    private static function get_quiz_attempt_grade_percent(int $attemptid): ?float {
        global $DB;
        if (!$attemptid) {
            return null;
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, sumgrades', IGNORE_MISSING);
        if (!$attempt || $attempt->sumgrades === null) {
            return null;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'grade, sumgrades', IGNORE_MISSING);
        if (!$quiz || (float) $quiz->sumgrades <= 0) {
            return null;
        }

        return round(((float) $attempt->sumgrades / (float) $quiz->sumgrades) * 100, 1);
    }

    private static function get_assign_grade_percent(int $cmid, int $userid): ?float {
        global $DB;
        if (!$cmid) {
            return null;
        }

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'grade', IGNORE_MISSING);
        if (!$assign || (float) $assign->grade <= 0) {
            return null;
        }

        $grade = $DB->get_record('assign_grades', [
            'assignment' => $cm->instance,
            'userid'     => $userid,
        ], 'grade', IGNORE_MISSING);

        if (!$grade || $grade->grade < 0) {
            return null;
        }

        return round(((float) $grade->grade / (float) $assign->grade) * 100, 1);
    }

    private static function count_resource_views(int $userid, int $courseid, int $cmid): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('umat_ai_activity_log')) {
            return 1;
        }

        return (int) $DB->count_records('umat_ai_activity_log', [
            'userid'     => $userid,
            'courseid'   => $courseid,
            'cmid'       => $cmid,
            'event_type' => 'resource_viewed',
        ]);
    }

    private static function user_has_quiz_attempt_in_course(int $userid, int $courseid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                 WHERE qa.userid = :userid AND q.course = :courseid
                 LIMIT 1";

        return $DB->record_exists_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    }

    private static function resolve_module_label(int $cmid): string {
        if (!$cmid) {
            return 'Unknown topic';
        }

        $cm = get_coursemodule_from_id(false, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 'Module ' . $cmid;
        }

        $modinfo = get_fast_modinfo($cm->course);
        $cminfo  = $modinfo->get_cm($cmid);
        return $cminfo->get_formatted_name();
    }

    private static function estimate_course_grade(int $userid, int $courseid): ?float {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = grade_item::fetch_course_item($courseid);
        if (!$gradeitem) {
            return null;
        }

        $grades = grade_grade::fetch_users_grades($gradeitem, [$userid]);
        if (empty($grades[$userid]) || $grades[$userid]->finalgrade === null) {
            return null;
        }

        $grademax = (float) $gradeitem->grademax;
        if ($grademax <= 0) {
            return null;
        }

        return round(((float) $grades[$userid]->finalgrade / $grademax) * 100, 1);
    }
}
