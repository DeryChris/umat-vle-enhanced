<?php

namespace local_umat_ai;

class user_data {

    private static $preloadCache = null;

    public static function preload_user_data(int $userId, string $wwwroot): string {
        if (self::$preloadCache !== null) return self::$preloadCache;
        global $DB;

        $courses = enrol_get_users_courses($userId, true, 'id,fullname,shortname');
        $courseList = [];
        foreach ($courses as $c) {
            $courseList[] = [
                'id' => (int)$c->id,
                'fullname'  => format_string($c->fullname),
                'shortname' => $c->shortname,
                'url' => $wwwroot . '/course/view.php?id=' . $c->id,
            ];
        }

        $weekSince     = time() - 7 * DAYSECS;
        $weekSessions  = (int)($DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs}
             WHERE userid=:uid AND timecreated>:s AND role='student'",
            ['uid' => $userId, 's' => $weekSince]) ?: 0);
        $weekQuestions = (int)$DB->count_records_select(
            'umat_ai_chat_logs',
            "userid=:uid AND timecreated>:s AND role='student'",
            ['uid' => $userId, 's' => $weekSince]);

        $rawSessions = $DB->get_records_sql(
            "SELECT session_key, MAX(courseid) AS courseid, MIN(timecreated) AS started,
                    MAX(timecreated) AS lastactive, COUNT(*) AS msg_count, MIN(question) AS first_q
               FROM {umat_ai_chat_logs}
              WHERE userid=:uid AND role='student'
                AND session_key IS NOT NULL AND session_key != ''
           GROUP BY session_key ORDER BY lastactive DESC",
            ['uid' => $userId], 0, 12
        );

        $sessions = [];
        foreach ($rawSessions as $s) {
            $cName = $cShort = '';
            foreach ($courses as $c) {
                if ($c->id == $s->courseid) { $cName = format_string($c->fullname); $cShort = $c->shortname; break; }
            }
            $e = time() - $s->lastactive;
            $t = $e < 3600 ? round($e/60).'m ago'
               : ($e < 86400 ? round($e/3600).'h ago'
               : ($e < 604800 ? round($e/86400).' days ago' : date('d M', $s->lastactive)));
            $sessions[] = [
                'session_key'  => $s->session_key,
                'courseid'     => (int)$s->courseid,
                'course_name'  => $cName,
                'course_short' => $cShort,
                'time_label'   => $t,
                'msg_count'    => (int)$s->msg_count,
                'preview'      => mb_strlen($s->first_q) > 110 ? mb_substr($s->first_q,0,107).'…' : $s->first_q,
            ];
        }

        $topTopics = [];
        $topCourses = $DB->get_records_sql(
            "SELECT courseid, COUNT(*) AS cnt FROM {umat_ai_chat_logs}
             WHERE userid=:uid AND role='student' AND timecreated>:s
             GROUP BY courseid ORDER BY cnt DESC",
            ['uid' => $userId, 's' => time()-30*DAYSECS], 0, 5
        );
        foreach ($topCourses as $t) {
            foreach ($courses as $c) {
                if ($c->id == $t->courseid) { $topTopics[] = ['label'=>$c->shortname,'count'=>(int)$t->cnt]; break; }
            }
        }

        $isLecturer = $DB->record_exists_sql(
            "SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id=ra.roleid
              WHERE ra.userid=:uid AND r.shortname IN ('editingteacher','teacher','manager')",
            ['uid' => $userId]
        );

        return json_encode([
            'courses'        => $courseList,
            'is_lecturer'    => (bool)$isLecturer,
            'week_sessions'  => $weekSessions,
            'week_questions' => $weekQuestions,
            'sessions'       => $sessions,
            'pulse_topics'   => $topTopics,
            'goal_progress'  => min(100, round($weekQuestions/20*100)),
        ]);
    }
}
