<?php
namespace local_umat_ai\analytics;
defined('MOODLE_INTERNAL') || die();

class bbb_attendance_analyser {

    /**
     * True only when this course has real BigBlueButton join/leave records to
     * read. Both tables are checked — the activity module may be uninstalled,
     * in which case {bigbluebuttonbn} does not exist either and querying it
     * would throw.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_available(int $courseid): bool {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('bigbluebuttonbn_log') || !$dbman->table_exists('bigbluebuttonbn')) {
            return false;
        }
        $count = $DB->get_field_sql(
            "SELECT COUNT(*) FROM {bigbluebuttonbn} WHERE course = :cid",
            ['cid' => $courseid]
        );
        return $count > 0;
    }

    public static function get_attendance(int $userid, int $courseid): ?array {
        global $DB;
        if (!self::is_available($courseid)) {
            return null;
        }

        $bbbids = $DB->get_fieldset_sql(
            "SELECT id FROM {bigbluebuttonbn} WHERE course = :cid",
            ['cid' => $courseid]
        );
        if (empty($bbbids)) {
            return null;
        }

        list($bidsql, $biparams) = $DB->get_in_or_equal($bbbids, SQL_PARAMS_NAMED, 'bbb');

        $sessionsheld = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
             AND event IN ('join', 'leave', 'presenter joined')",
            $biparams
        );

        $sessionsattended = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
             AND userid = :uid AND event = 'join'",
            array_merge($biparams, ['uid' => $userid])
        );

        // A course that has the activity configured but has never actually run
        // a session yields no attendance evidence. Returning a 0% rate here
        // would hand every student full attendance-risk points for something
        // that never happened.
        if ($sessionsheld === 0) {
            return null;
        }

        $rate = $sessionsattended / $sessionsheld;

        $joins = $DB->get_records_sql_menu(
            "SELECT session_id, MIN(timecreated) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql AND userid = :uid AND event = 'join'
             GROUP BY session_id",
            array_merge($biparams, ['uid' => $userid])
        );

        $leaves = $DB->get_records_sql_menu(
            "SELECT session_id, MAX(timecreated) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql AND userid = :uid AND event = 'leave'
             GROUP BY session_id",
            array_merge($biparams, ['uid' => $userid])
        );

        $totalduration = 0;
        $counted = 0;
        foreach ($joins as $sid => $jointime) {
            if (isset($leaves[$sid]) && $leaves[$sid] > $jointime) {
                $totalduration += $leaves[$sid] - $jointime;
                $counted++;
            }
        }
        $avgduration = ($counted > 0) ? round($totalduration / $counted / 60, 1) : 0.0;

        return [
            'sessions_held' => $sessionsheld,
            'sessions_attended' => $sessionsattended,
            'attendance_rate' => round($rate, 4),
            'avg_duration_min' => $avgduration,
        ];
    }

    public static function get_course_attendance_summary(int $courseid): array {
        global $DB;
        if (!self::is_available($courseid)) {
            return [
                'total_sessions' => 0,
                'students_who_attended' => [],
                'students_who_never_attended' => [],
                'avg_attendance_rate' => 0.0,
            ];
        }

        $bbbids = $DB->get_fieldset_sql(
            "SELECT id FROM {bigbluebuttonbn} WHERE course = :cid",
            ['cid' => $courseid]
        );
        if (empty($bbbids)) {
            return [
                'total_sessions' => 0,
                'students_who_attended' => [],
                'students_who_never_attended' => [],
                'avg_attendance_rate' => 0.0,
            ];
        }

        list($bidsql, $biparams) = $DB->get_in_or_equal($bbbids, SQL_PARAMS_NAMED, 'bbb');

        $totalsessions = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
             AND event IN ('join', 'leave', 'presenter joined')",
            $biparams
        );

        $enrolled = enrol_get_course_users($courseid);
        $attended = [];
        $never = [];

        foreach ($enrolled as $user) {
            $count = (int) $DB->get_field_sql(
                "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
                 WHERE bigbluebuttonbnid $bidsql AND userid = :uid AND event = 'join'",
                array_merge($biparams, ['uid' => $user->id])
            );
            $rate = ($totalsessions > 0) ? round($count / $totalsessions, 4) : 0.0;
            $name = fullname($user);

            if ($count > 0) {
                $attended[] = ['userid' => $user->id, 'name' => $name, 'rate' => $rate];
            } else {
                $never[] = ['userid' => $user->id, 'name' => $name];
            }
        }

        $avg = 0.0;
        if (count($attended) > 0) {
            $sum = array_sum(array_column($attended, 'rate'));
            $avg = round($sum / count($attended), 4);
        }

        return [
            'total_sessions' => $totalsessions,
            'students_who_attended' => $attended,
            'students_who_never_attended' => $never,
            'avg_attendance_rate' => $avg,
        ];
    }

    /**
     * Per-session attendance breakdown for a course.
     * Returns each BBB session with lists of present/absent students.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_session_attendance(int $courseid): array {
        global $DB;

        if (!self::is_available($courseid)) {
            return [
                'sessions' => [],
                'total_sessions' => 0,
                'avg_attendance_rate' => 0.0,
                'never_attended_count' => 0,
            ];
        }

        $bbbids = $DB->get_fieldset_sql(
            "SELECT id FROM {bigbluebuttonbn} WHERE course = :cid",
            ['cid' => $courseid]
        );
        if (empty($bbbids)) {
            return [
                'sessions' => [],
                'total_sessions' => 0,
                'avg_attendance_rate' => 0.0,
                'never_attended_count' => 0,
            ];
        }

        list($bidsql, $biparams) = $DB->get_in_or_equal($bbbids, SQL_PARAMS_NAMED, 'bbb');

        $sessions = $DB->get_records_sql(
            "SELECT l.session_id,
                    b.id AS activity_id,
                    b.name AS activity_name,
                    MIN(l.timecreated) AS start_time
             FROM {bigbluebuttonbn_log} l
             JOIN {bigbluebuttonbn} b ON b.id = l.bigbluebuttonbnid
             WHERE l.bigbluebuttonbnid $bidsql
               AND l.event IN ('join', 'leave')
             GROUP BY l.session_id, b.id, b.name
             ORDER BY start_time DESC",
            $biparams
        );

        if (empty($sessions)) {
            return [
                'sessions' => [],
                'total_sessions' => 0,
                'avg_attendance_rate' => 0.0,
                'never_attended_count' => 0,
            ];
        }

        $sessionIds = array_keys($sessions);
        list($sessql, $separams) = $DB->get_in_or_equal($sessionIds, SQL_PARAMS_NAMED, 'sess');
        $allparams = array_merge($biparams, $separams);

        // Bulk fetch join events: composite key = sessionId_userId
        $joinkey = $DB->sql_concat('session_id', "'_'", 'userid');
        $joinRecords = $DB->get_records_sql(
            "SELECT $joinkey AS ck, session_id, userid, MIN(timecreated) AS join_time
             FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
               AND session_id $sessql
               AND event = 'join'
             GROUP BY session_id, userid",
            $allparams
        );

        // Bulk fetch leave events for duration
        $leaveRecords = $DB->get_records_sql(
            "SELECT $joinkey AS ck, session_id, userid, MAX(timecreated) AS leave_time
             FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
               AND session_id $sessql
               AND event = 'leave'
             GROUP BY session_id, userid",
            $allparams
        );

        $enrolled = enrol_get_course_users($courseid);
        if (empty($enrolled)) {
            return [
                'sessions' => [],
                'total_sessions' => 0,
                'avg_attendance_rate' => 0.0,
                'never_attended_count' => 0,
            ];
        }

        $resultSessions = [];
        $totalPresent = 0;
        $totalSlots = 0;
        $studentsWhoEverAttended = [];

        foreach ($sessions as $sess) {
            $sid = (int) $sess->session_id;
            $present = [];
            $absent = [];

            foreach ($enrolled as $user) {
                $ck = $sid . '_' . $user->id;
                $hasJoin = isset($joinRecords[$ck]);

                if ($hasJoin) {
                    $duration = null;
                    if (isset($leaveRecords[$ck])
                        && $leaveRecords[$ck]->leave_time > $joinRecords[$ck]->join_time
                    ) {
                        $duration = round(
                            ($leaveRecords[$ck]->leave_time - $joinRecords[$ck]->join_time) / 60,
                            1
                        );
                    }
                    $present[] = [
                        'userid'       => (int) $user->id,
                        'fullname'     => fullname($user),
                        'email'        => $user->email,
                        'duration_min' => $duration,
                    ];
                    $studentsWhoEverAttended[$user->id] = true;
                } else {
                    $absent[] = [
                        'userid'   => (int) $user->id,
                        'fullname' => fullname($user),
                        'email'    => $user->email,
                    ];
                }
            }

            $totalPresent += count($present);
            $totalSlots += count($enrolled);

            $resultSessions[] = [
                'session_id'       => $sid,
                'activity_name'    => $sess->activity_name ?: 'Session #' . $sid,
                'activity_id'      => (int) $sess->activity_id,
                'start_time'       => (int) $sess->start_time,
                'present_count'    => count($present),
                'absent_count'     => count($absent),
                'present_students' => $present,
                'absent_students'  => $absent,
            ];
        }

        $totalSessions = count($sessions);
        $avgRate = $totalSlots > 0 ? round($totalPresent / $totalSlots, 4) : 0.0;
        $neverAttendedCount = count($enrolled) - count($studentsWhoEverAttended);

        return [
            'sessions'             => $resultSessions,
            'total_sessions'       => $totalSessions,
            'avg_attendance_rate'  => $avgRate,
            'never_attended_count' => $neverAttendedCount,
        ];
    }

    public static function get_trend(int $userid, int $courseid): ?array {
        global $DB;
        if (!self::is_available($courseid)) {
            return null;
        }

        $bbbids = $DB->get_fieldset_sql(
            "SELECT id FROM {bigbluebuttonbn} WHERE course = :cid",
            ['cid' => $courseid]
        );
        if (empty($bbbids)) {
            return null;
        }

        list($bidsql, $biparams) = $DB->get_in_or_equal($bbbids, SQL_PARAMS_NAMED, 'bbb');

        $allsessions = $DB->get_records_sql(
            "SELECT session_id, MIN(timecreated) AS sessionstart FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql
             AND event IN ('join', 'leave', 'presenter joined')
             GROUP BY session_id ORDER BY sessionstart DESC",
            $biparams
        );

        if (count($allsessions) < 2) {
            return null;
        }

        $sessionids = array_keys($allsessions);
        $midpoint = (int) ceil(count($sessionids) / 2);
        $currentids = array_slice($sessionids, 0, $midpoint);
        $previousids = array_slice($sessionids, $midpoint);

        $currentrate = self::compute_period_rate($bidsql, $biparams, $userid, $currentids);
        $previousrate = self::compute_period_rate($bidsql, $biparams, $userid, $previousids);

        $cfg = self::config();
        $threshold = $cfg['trend']['attendance_delta'] ?? 10.0;
        $delta = $currentrate - $previousrate;

        if ($delta > $threshold) {
            $direction = 'improving';
        } elseif ($delta < -$threshold) {
            $direction = 'declining';
        } else {
            $direction = 'stable';
        }

        return [
            'direction' => $direction,
            'delta' => round($delta, 2),
            'current_rate' => round($currentrate, 4),
            'previous_rate' => round($previousrate, 4),
        ];
    }

    private static function compute_period_rate(string $bidsql, array $biparams, int $userid, array $sessionids): float {
        global $DB;
        if (empty($sessionids)) {
            return 0.0;
        }

        list($sessql, $separams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sess');

        $total = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql AND session_id $sessql
             AND event IN ('join', 'leave', 'presenter joined')",
            array_merge($biparams, $separams)
        );

        $attended = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT session_id) FROM {bigbluebuttonbn_log}
             WHERE bigbluebuttonbnid $bidsql AND session_id $sessql
             AND userid = :uid AND event = 'join'",
            array_merge($biparams, $separams, ['uid' => $userid])
        );

        return ($total > 0) ? $attended / $total : 0.0;
    }

    private static function config(): array {
        global $CFG;
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cfg;
    }
}
