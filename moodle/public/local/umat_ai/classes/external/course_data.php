<?php
/**
 * External API: course data for overlays.
 * get_my_courses, get_course_materials, get_course_recordings, get_ai_sessions
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class course_data extends \external_api {

    /**
     * Compute relative time string from a Unix timestamp.
     */
    private static function time_ago(int $ts): string {
        if (!$ts) return '';
        $now = time();
        $diff = $now - $ts;
        if ($diff < 60)  return 'just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        $days = floor($diff / 86400);
        if ($days < 30)   return $days . 'd ago';
        $months = floor($days / 30);
        if ($months < 12) return $months . 'mo ago';
        return floor($months / 12) . 'y ago';
    }

    /* ------------------------------------------------------------------ */
    /* get_my_courses                                                       */
    /* ------------------------------------------------------------------ */
    public static function get_my_courses_parameters() {
        return new \external_function_parameters([
            'role' => new \external_value(PARAM_ALPHA, 'student or lecturer', VALUE_DEFAULT, 'student'),
        ]);
    }

    public static function get_my_courses($role = 'student') {
        global $USER, $DB;
        self::validate_parameters(self::get_my_courses_parameters(), ['role' => $role]);
        $courses = enrol_get_users_courses($USER->id, false, 'id,fullname,shortname');
        if ($role === 'lecturer' && empty($courses)) {
            $courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.fullname, c.shortname
                   FROM {course} c
                   JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                   JOIN {role_assignments} ra ON ra.contextid = ctx.id
                   JOIN {role} r ON r.id = ra.roleid
                  WHERE ra.userid = :uid AND r.shortname IN ('editingteacher','teacher','manager')",
                ['uid' => $USER->id]
            );
        }
        $list = [];
        $courseIds = [];
        foreach ($courses as $c) {
            $ctx = \context_course::instance($c->id);
            $isTeacher = has_capability('local/umat_ai:viewanalytics', $ctx);
            if ($role === 'lecturer' && !$isTeacher) continue;
            if ($role === 'student'  &&  $isTeacher) continue;
            $courseIds[] = (int)$c->id;
            $enrolled = 0;
            if ($isTeacher) {
                $enrolled = count_enrolled_users($ctx, '', 0, true);
            }
            $list[(int)$c->id] = [
                'id'            => (int)$c->id,
                'fullname'      => format_string($c->fullname),
                'shortname'     => $c->shortname,
                'enrolled_count'=> (int)$enrolled,
            ];
        }

        // Bulk load aggregate stats for all courses at once.
        if (!empty($courseIds)) {
            list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);

            // Pending outputs per course.
            $pendingRows = $DB->get_records_sql(
                "SELECT s.courseid, COUNT(o.id) AS cnt
                   FROM {umat_ai_sessions} s
                   JOIN {umat_ai_outputs} o ON o.sessionrecordid = s.id
                  WHERE s.courseid $inSql AND o.is_approved = 0
               GROUP BY s.courseid",
                $inParams
            );
            foreach ($pendingRows as $row) {
                $cid = (int)$row->courseid;
                if (isset($list[$cid])) $list[$cid]['pending_count'] = (int)$row->cnt;
            }

            // Completed sessions per course.
            $sessRows = $DB->get_records_sql(
                "SELECT courseid, COUNT(*) AS cnt
                   FROM {umat_ai_sessions}
                  WHERE courseid $inSql AND status = 'completed'
               GROUP BY courseid",
                $inParams
            );
            foreach ($sessRows as $row) {
                $cid = (int)$row->courseid;
                if (isset($list[$cid])) $list[$cid]['session_count'] = (int)$row->cnt;
            }

            // Latest activity timestamp per course (from chat_logs).
            $actRows = $DB->get_records_sql(
                "SELECT courseid, MAX(timecreated) AS last_active
                   FROM {umat_ai_chat_logs}
                  WHERE courseid $inSql
               GROUP BY courseid",
                $inParams
            );
            foreach ($actRows as $row) {
                $cid = (int)$row->courseid;
                if (isset($list[$cid])) $list[$cid]['last_active'] = (int)$row->last_active;
            }
        }

        return ['courses' => array_values($list)];
    }

    public static function get_my_courses_returns() {
        return new \external_single_structure(['courses' => new \external_multiple_structure(
            new \external_single_structure([
                'id'            => new \external_value(PARAM_INT),
                'fullname'      => new \external_value(PARAM_TEXT),
                'shortname'     => new \external_value(PARAM_TEXT),
                'enrolled_count'=> new \external_value(PARAM_INT),
                'pending_count' => new \external_value(PARAM_INT, 'Unapproved AI outputs', VALUE_OPTIONAL, 0),
                'session_count' => new \external_value(PARAM_INT, 'Processed BBB sessions', VALUE_OPTIONAL, 0),
                'last_active'   => new \external_value(PARAM_INT, 'Latest chat timestamp', VALUE_OPTIONAL, 0),
            ])
        )]);
    }

    /* ------------------------------------------------------------------ */
    /* get_course_materials                                                 */
    /* ------------------------------------------------------------------ */
    public static function get_course_materials_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all enrolled)'),
        ]);
    }

    public static function get_course_materials($courseid) {
        global $USER;
        $params = self::validate_parameters(
            self::get_course_materials_parameters(), ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        // Collect context IDs to search.
        $contextIds = [];
        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:chatwithai', $ctx);
            $contextIds[] = (int)$ctx->id;
            // Module contexts.
            global $DB;
            $modCtxIds = $DB->get_fieldset_sql(
                "SELECT ctx.id FROM {context} ctx
                 JOIN {course_modules} cm ON cm.id = ctx.instanceid
                 WHERE ctx.contextlevel = :lv AND cm.course = :cid",
                ['lv' => CONTEXT_MODULE, 'cid' => $cid]
            );
            foreach ($modCtxIds as $mid) $contextIds[] = (int)$mid;
        } else {
            // All enrolled courses.
            $courses = enrol_get_users_courses($USER->id, true, 'id');
            foreach ($courses as $c) {
                $ctx = \context_course::instance($c->id);
                if (!has_capability('local/umat_ai:chatwithai', $ctx)) continue;
                $contextIds[] = (int)$ctx->id;
            }
        }

        $fs = get_file_storage();
        $materials = [];
        $seen = [];

        // File areas that hold course materials.
        $areas = [
            ['mod_resource', 'content'],
            ['mod_folder',   'content'],
            ['course',       'legacy'],
            ['local_umat_ai','materials'],
        ];

        foreach ($contextIds as $ctxId) {
            foreach ($areas as [$component, $filearea]) {
                $files = $fs->get_area_files($ctxId, $component, $filearea, false, 'timemodified DESC', false);
                foreach ($files as $f) {
                    if ($f->get_filesize() === 0) continue;
                    $key = $f->get_pathnamehash();
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $url = \moodle_url::make_pluginfile_url(
                        $f->get_contextid(), $f->get_component(),
                        $f->get_filearea(), $f->get_itemid(),
                        $f->get_filepath(), $f->get_filename()
                    );
                    $materials[] = [
                        'id'       => (int)$f->get_id(),
                        'filename' => $f->get_filename(),
                        'mimetype' => $f->get_mimetype() ?: 'application/octet-stream',
                        'filesize' => (int)$f->get_filesize(),
                        'url'      => $url->out(false),
                        'timemodified' => (int)$f->get_timemodified(),
                        'time_ago' => self::time_ago((int)$f->get_timemodified()),
                    ];
                    if (count($materials) >= 100) break 3;
                }
            }
        }

        return ['materials' => $materials];
    }

    public static function get_course_materials_returns() {
        return new \external_single_structure(['materials' => new \external_multiple_structure(
            new \external_single_structure([
                'id'           => new \external_value(PARAM_INT),
                'filename'     => new \external_value(PARAM_TEXT),
                'mimetype'     => new \external_value(PARAM_TEXT),
                'filesize'     => new \external_value(PARAM_INT),
                'url'          => new \external_value(PARAM_URL),
                'timemodified' => new \external_value(PARAM_INT),
                'time_ago'     => new \external_value(PARAM_TEXT, 'Relative time string'),
            ])
        )]);
    }

    /* ------------------------------------------------------------------ */
    /* get_course_recordings                                                */
    /* ------------------------------------------------------------------ */
    public static function get_course_recordings_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all enrolled)'),
        ]);
    }

    public static function get_course_recordings($courseid) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_course_recordings_parameters(), ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        // Gather course IDs to query.
        $courseIds = [];
        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:chatwithai', $ctx);
            $courseIds[] = $cid;
        } else {
            $courses = enrol_get_users_courses($USER->id, true, 'id');
            foreach ($courses as $c) $courseIds[] = (int)$c->id;
        }

        if (empty($courseIds)) return ['recordings' => []];

        list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
        $sessions = $DB->get_records_sql(
            "SELECT * FROM {umat_ai_sessions}
              WHERE courseid $inSql AND status = 'completed' AND recording_url IS NOT NULL
           ORDER BY timecreated DESC",
            $inParams, 0, 50
        );

        $recordings = [];
        foreach ($sessions as $sess) {
            // Parse AI-generated title from outputs if available.
            $summaryOut = $DB->get_record('umat_ai_outputs', [
                'sessionrecordid' => $sess->id,
                'output_type'     => 'summary',
                'is_approved'     => 1,
            ]);

            // Extract first line of summary as title.
            $title = 'Lecture Session — ' . date('d M Y', $sess->timecreated);
            $desc  = '';
            if ($summaryOut && $summaryOut->content) {
                $lines = array_filter(explode("\n", $summaryOut->content));
                if (!empty($lines)) {
                    $title = mb_substr(array_values($lines)[0], 0, 80);
                    $desc  = mb_substr(implode(' ', array_slice(array_values($lines), 1, 3)), 0, 160);
                }
            }

            // Parse transcript for segments.
            $segments = [];
            if (!empty($sess->transcript_json)) {
                $raw = json_decode($sess->transcript_json, true);
                if (is_array($raw)) {
                    foreach ($raw as $seg) {
                        $start = $seg['start'] ?? 0;
                        $m = floor($start / 60); $s = floor($start % 60);
                        $segments[] = [
                            'timestamp' => $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT),
                            'start'     => (float)$start,
                            'end'       => (float)($seg['end'] ?? $start + 30),
                            'text'      => $seg['text'] ?? '',
                        ];
                    }
                }
            }

            // Compute duration from segments (max end time).
            $duration = '';
            if (!empty($segments)) {
                $maxEnd = 0;
                foreach ($segments as $seg) {
                    if (($seg['end'] ?? 0) > $maxEnd) $maxEnd = $seg['end'];
                }
                if ($maxEnd > 0) {
                    $m = floor($maxEnd / 60);
                    $s = floor($maxEnd % 60);
                    $duration = $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
                }
            }

            $recordings[] = [
                'id'          => (int)$sess->id,
                'session_key' => $sess->sessionid,
                'courseid'    => (int)$sess->courseid,
                'title'       => $title,
                'description' => $desc,
                'url'         => $sess->recording_url,
                'timecreated' => (int)$sess->timecreated,
                'date'        => date('d M Y', $sess->timecreated),
                'duration'    => $duration,
                'time_ago'    => self::time_ago((int)$sess->timecreated),
                'segments'    => $segments,
            ];
        }

        return ['recordings' => $recordings];
    }

    public static function get_course_recordings_returns() {
        return new \external_single_structure(['recordings' => new \external_multiple_structure(
            new \external_single_structure([
                'id'          => new \external_value(PARAM_INT),
                'session_key' => new \external_value(PARAM_TEXT),
                'courseid'    => new \external_value(PARAM_INT),
                'title'       => new \external_value(PARAM_TEXT),
                'description' => new \external_value(PARAM_TEXT),
                'url'         => new \external_value(PARAM_URL),
                'timecreated' => new \external_value(PARAM_INT, 'Unix timestamp'),
                'date'        => new \external_value(PARAM_TEXT),
                'duration'    => new \external_value(PARAM_TEXT),
                'time_ago'    => new \external_value(PARAM_TEXT, 'Relative time string'),
                'segments'    => new \external_multiple_structure(
                    new \external_single_structure([
                        'timestamp' => new \external_value(PARAM_TEXT),
                        'start'     => new \external_value(PARAM_FLOAT),
                        'end'       => new \external_value(PARAM_FLOAT),
                        'text'      => new \external_value(PARAM_TEXT),
                    ])
                ),
            ])
        )]);
    }

    /* ------------------------------------------------------------------ */
    /* get_ai_sessions                                                      */
    /* ------------------------------------------------------------------ */
    public static function get_ai_sessions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)', VALUE_DEFAULT, 0),
            'limit'    => new \external_value(PARAM_INT, 'Max sessions',         VALUE_DEFAULT, 20),
        ]);
    }

    public static function get_ai_sessions($courseid = 0, $limit = 20) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_ai_sessions_parameters(),
            ['courseid' => $courseid, 'limit' => $limit]);

        $cid   = (int)$params['courseid'];
        $limit = min((int)$params['limit'], 50);

        $where  = 'userid = :uid AND role = :role AND session_key IS NOT NULL AND session_key != :empty';
        $wargs  = ['uid' => $USER->id, 'role' => 'student', 'empty' => ''];
        if ($cid > 0) { $where .= ' AND courseid = :cid'; $wargs['cid'] = $cid; }

        $rawSessions = $DB->get_records_sql(
            "SELECT session_key, MAX(courseid) AS courseid, MIN(timecreated) AS started,
                    MAX(timecreated) AS lastactive, COUNT(*) AS msg_count, MIN(question) AS first_q
               FROM {umat_ai_chat_logs}
              WHERE $where
           GROUP BY session_key ORDER BY lastactive DESC",
            $wargs, 0, $limit
        );

        $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
        $courseMap = [];
        foreach ($courses as $c) $courseMap[$c->id] = $c;

        $sessions = [];
        foreach ($rawSessions as $s) {
            $cName = $cShort = '';
            if (isset($courseMap[$s->courseid])) {
                $cName  = format_string($courseMap[$s->courseid]->fullname);
                $cShort = $courseMap[$s->courseid]->shortname;
            }
            $elapsed = time() - $s->lastactive;
            $tLabel  = $elapsed < 3600   ? round($elapsed/60).'m ago'
                     : ($elapsed < 86400  ? round($elapsed/3600).'h ago'
                     : ($elapsed < 604800 ? round($elapsed/86400).' days ago'
                     : date('d M Y', $s->lastactive)));
            $preview = mb_strlen($s->first_q) > 110
                ? mb_substr($s->first_q, 0, 107) . '…'
                : $s->first_q;

            $sessions[] = [
                'session_key'  => $s->session_key,
                'courseid'     => (int)$s->courseid,
                'course_name'  => $cName,
                'course_short' => $cShort,
                'time_label'   => $tLabel,
                'msg_count'    => (int)$s->msg_count,
                'preview'      => $preview,
            ];
        }

        return ['sessions' => $sessions];
    }

    public static function get_ai_sessions_returns() {
        return new \external_single_structure(['sessions' => new \external_multiple_structure(
            new \external_single_structure([
                'session_key'  => new \external_value(PARAM_TEXT),
                'courseid'     => new \external_value(PARAM_INT),
                'course_name'  => new \external_value(PARAM_TEXT),
                'course_short' => new \external_value(PARAM_TEXT),
                'time_label'   => new \external_value(PARAM_TEXT),
                'msg_count'    => new \external_value(PARAM_INT),
                'preview'      => new \external_value(PARAM_TEXT),
            ])
        )]);
    }

    /* ------------------------------------------------------------------ */
    /* get_pending_outputs — lecturer-facing, returns all unapproved AI    */
    /* outputs (summary, notes, quiz) for one or all courses.              */
    /* ------------------------------------------------------------------ */
    public static function get_pending_outputs_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all teaching)'),
        ]);
    }

    public static function get_pending_outputs($courseid) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_pending_outputs_parameters(), ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        $courseIds = [];
        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:approveoutput', $ctx);
            $courseIds[] = $cid;
        } else {
            $courses = enrol_get_users_courses($USER->id, true, 'id');
            foreach ($courses as $c) {
                $ctx = \context_course::instance($c->id);
                if (has_capability('local/umat_ai:approveoutput', $ctx)) {
                    $courseIds[] = (int)$c->id;
                }
            }
        }

        if (empty($courseIds)) return ['sessions' => [], 'total_pending' => 0];

        list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);

        $sessions = $DB->get_records_sql(
            "SELECT s.id, s.sessionid, s.courseid, s.timecreated, COUNT(o.id) AS pending_count
               FROM {umat_ai_sessions} s
               JOIN {umat_ai_outputs} o ON o.sessionrecordid = s.id
              WHERE s.courseid $inSql AND o.is_approved = 0
           GROUP BY s.id ORDER BY s.timecreated DESC",
            $inParams
        );

        $courseNames = [];
        foreach ($courseIds as $c) {
            $cname = $DB->get_field('course', 'fullname', ['id' => $c]);
            if ($cname) $courseNames[$c] = $cname;
        }

        $sessionIds = array_keys($sessions);
        $outputsBySession = [];
        if (!empty($sessionIds)) {
            list($oInSql, $oInParams) = $DB->get_in_or_equal($sessionIds, SQL_PARAMS_NAMED);
            $records = $DB->get_records_sql(
                "SELECT id, sessionrecordid, output_type, content, timecreated
                   FROM {umat_ai_outputs}
                  WHERE sessionrecordid $oInSql AND is_approved = 0
               ORDER BY output_type ASC, timecreated ASC",
                $oInParams
            );
            foreach ($records as $r) {
                $sid = (int)$r->sessionrecordid;
                if (!isset($outputsBySession[$sid])) $outputsBySession[$sid] = [];
                $outputsBySession[$sid][] = [
                    'id'          => (int)$r->id,
                    'type'        => $r->output_type,
                    'content'     => $r->content,
                    'timecreated' => (int)$r->timecreated,
                ];
            }
        }

        $result = [];
        foreach ($sessions as $s) {
            $sid = (int)$s->id;
            if (empty($outputsBySession[$sid])) continue;
            $result[] = [
                'session_id'    => $sid,
                'session_label' => $s->sessionid,
                'courseid'      => (int)$s->courseid,
                'course_name'   => format_string($courseNames[(int)$s->courseid] ?? ''),
                'timecreated'   => (int)$s->timecreated,
                'pending_count' => (int)$s->pending_count,
                'outputs'       => $outputsBySession[$sid],
            ];
        }

        $total = 0;
        foreach ($result as $r) $total += $r['pending_count'];

        return ['sessions' => $result, 'total_pending' => $total];
    }

    public static function get_pending_outputs_returns() {
        return new \external_single_structure([
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'session_id'    => new \external_value(PARAM_INT),
                    'session_label' => new \external_value(PARAM_TEXT),
                    'courseid'      => new \external_value(PARAM_INT),
                    'course_name'   => new \external_value(PARAM_TEXT),
                    'timecreated'   => new \external_value(PARAM_INT),
                    'pending_count' => new \external_value(PARAM_INT),
                    'outputs'       => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'          => new \external_value(PARAM_INT),
                            'type'        => new \external_value(PARAM_TEXT),
                            'content'     => new \external_value(PARAM_RAW),
                            'timecreated' => new \external_value(PARAM_INT),
                        ])
                    ),
                ])
            ),
            'total_pending' => new \external_value(PARAM_INT),
        ]);
    }
}
