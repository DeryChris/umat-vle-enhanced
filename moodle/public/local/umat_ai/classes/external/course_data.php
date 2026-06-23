<?php
/**
 * External API: course data — get_my_courses, get_course_materials,
 *               get_course_recordings, get_ai_sessions
 * v1.4 — adds page_count (PDF) and time_ago to materials & recordings.
 *
 * @package    local_umat_ai
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class course_data extends \external_api {

    // ------------------------------------------------------------------ //
    // Helpers                                                              //
    // ------------------------------------------------------------------ //

    /** Human-readable "X days/months/years ago" label. */
    private static function time_ago(int $ts): string {
        $diff = time() - $ts;
        if ($diff < 3600)    return round($diff / 60)  . ' minutes ago';
        if ($diff < 86400)   return round($diff / 3600) . ' hours ago';
        if ($diff < 604800)  return round($diff / 86400) . ' days ago';
        if ($diff < 2592000) return round($diff / 604800) . ' weeks ago';
        if ($diff < 31536000)return round($diff / 2592000) . ' months ago';
        return round($diff / 31536000) . ' years ago';
    }

    /** Count pages in a PDF by scanning its binary content.
     *  Works for most PDFs; returns 0 if unreadable. */
    private static function pdf_page_count(\stored_file $file): int {
        try {
            // Only attempt for files ≤ 50 MB to avoid memory issues
            if ($file->get_filesize() > 52428800) return 0;
            $content = $file->get_content();
            // Match /Type /Page (not /Pages)
            preg_match_all('/\/Type[\s]*\/Page[^s]/m', $content, $m);
            $count = count($m[0]);
            // Fallback: count /Pages dict (single value = total pages)
            if ($count === 0) {
                preg_match('/\/Count\s+(\d+)/', $content, $cm);
                $count = isset($cm[1]) ? (int)$cm[1] : 0;
            }
            return max($count, 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** Approximate page count for Office formats based on file size. */
    private static function estimate_doc_pages(\stored_file $file): int {
        $mime = $file->get_mimetype();
        $size = $file->get_filesize();
        if (!$size) return 0;
        // Very rough: ~2 KB per page for DOCX, ~15 KB per slide for PPTX
        if (str_contains($mime, 'wordprocessing') || str_contains($mime, 'msword')) {
            return max(1, (int)round($size / 2048));
        }
        if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) {
            return max(1, (int)round($size / 15360));
        }
        if (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel')) {
            return 0; // sheets don't have "pages" meaningfully
        }
        return 0;
    }

    // ------------------------------------------------------------------ //
    // get_my_courses                                                        //
    // ------------------------------------------------------------------ //
    public static function get_my_courses_parameters() {
        return new \external_function_parameters([
            'role' => new \external_value(PARAM_ALPHA, 'student or lecturer', VALUE_DEFAULT, 'student'),
        ]);
    }

    public static function get_my_courses($role = 'student') {
        global $USER, $DB;
        self::validate_parameters(self::get_my_courses_parameters(), ['role' => $role]);
        $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
        $list = [];
        foreach ($courses as $c) {
            $ctx       = \context_course::instance($c->id);
            $isTeacher = has_capability('local/umat_ai:viewanalytics', $ctx);
            if ($role === 'lecturer' && !$isTeacher) continue;
            if ($role === 'student'  &&  $isTeacher) continue;

            $enrolled = $isTeacher ? count_enrolled_users($ctx, '', 0, true) : 0;

            // Pending outputs count (lecturer only).
            $pending = 0;
            if ($isTeacher) {
                $pending = (int)($DB->get_field_sql(
                    "SELECT COUNT(o.id) FROM {umat_ai_outputs} o
                     JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
                     WHERE s.courseid = :cid AND o.is_approved = 0",
                    ['cid' => $c->id]) ?: 0);
            }

            // Recent session count.
            $sessionCount = (int)($DB->get_field_sql(
                "SELECT COUNT(DISTINCT session_key) FROM {umat_ai_chat_logs}
                 WHERE courseid = :cid AND userid = :uid AND role = 'student'
                   AND timecreated > :since",
                ['cid' => $c->id, 'uid' => $USER->id, 'since' => time() - 30 * DAYSECS]) ?: 0);

            $list[] = [
                'id'            => (int)$c->id,
                'fullname'      => format_string($c->fullname),
                'shortname'     => $c->shortname,
                'enrolled_count'=> (int)$enrolled,
                'pending_count' => (int)$pending,
                'session_count' => (int)$sessionCount,
            ];
        }
        return ['courses' => $list];
    }

    public static function get_my_courses_returns() {
        return new \external_single_structure(['courses' => new \external_multiple_structure(
            new \external_single_structure([
                'id'             => new \external_value(PARAM_INT),
                'fullname'       => new \external_value(PARAM_TEXT),
                'shortname'      => new \external_value(PARAM_TEXT),
                'enrolled_count' => new \external_value(PARAM_INT),
                'pending_count'  => new \external_value(PARAM_INT),
                'session_count'  => new \external_value(PARAM_INT),
            ])
        )]);
    }

    // ------------------------------------------------------------------ //
    // get_course_materials                                                  //
    // ------------------------------------------------------------------ //
    public static function get_course_materials_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all enrolled)'),
        ]);
    }

    public static function get_course_materials($courseid) {
        global $USER, $DB;
        $params = self::validate_parameters(
            self::get_course_materials_parameters(), ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        // Collect context IDs and build context→CM map for access filtering.
        $contextIds = [];
        $ctxToCm = []; // context_id → cm_id (0 = course context)
        $modinfo = null;
        $isStudent = false;

        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:chatwithai', $ctx);
            $contextIds[] = (int)$ctx->id;
            $ctxToCm[(int)$ctx->id] = 0;

            $moduleCtxRows = $DB->get_records_sql(
                "SELECT ctx.id, cm.id AS cmid FROM {context} ctx
                 JOIN {course_modules} cm ON cm.id = ctx.instanceid
                 WHERE ctx.contextlevel = :lv AND cm.course = :cid",
                ['lv' => CONTEXT_MODULE, 'cid' => $cid]);
            foreach ($moduleCtxRows as $row) {
                $contextIds[] = (int)$row->id;
                $ctxToCm[(int)$row->id] = (int)$row->cmid;
            }

            // Filter materials for students only (lecturers see all).
            if (!has_capability('local/umat_ai:viewanalytics', $ctx)) {
                $isStudent = true;
                $course = get_course($cid);
                $modinfo = get_fast_modinfo($course, $USER->id);
            }
        } else {
            $courses = enrol_get_users_courses($USER->id, true, 'id');
            foreach ($courses as $c) {
                $ctx = \context_course::instance($c->id);
                if (!has_capability('local/umat_ai:chatwithai', $ctx)) continue;
                $contextIds[] = (int)$ctx->id;
            }
        }

        $fs    = get_file_storage();
        $mats  = [];
        $seen  = [];
        $areas = [
            ['mod_resource', 'content'],
            ['mod_folder',   'content'],
            ['course',       'legacy'],
            ['local_umat_ai','materials'],
        ];

        foreach ($contextIds as $ctxId) {
            // For students: skip files from inaccessible course modules.
            if ($isStudent) {
                $cmId = $ctxToCm[$ctxId] ?? 0;
                if ($cmId > 0) {
                    try {
                        $cm = $modinfo->get_cm($cmId);
                        if (!$cm->uservisible) continue;
                    } catch (\moodle_exception $e) {
                        continue;
                    }
                }
            }

            foreach ($areas as [$component, $filearea]) {
                $files = $fs->get_area_files($ctxId, $component, $filearea,
                                              false, 'timemodified DESC', false);
                foreach ($files as $f) {
                    if ($f->get_filesize() === 0) continue;
                    $hash = $f->get_pathnamehash();
                    if (isset($seen[$hash])) continue;
                    $seen[$hash] = true;

                    $mime = $f->get_mimetype() ?: 'application/octet-stream';

                    // Page / slide count.
                    $pageCount = 0;
                    if (str_contains($mime, 'pdf')) {
                        $pageCount = self::pdf_page_count($f);
                    } elseif (str_contains($mime, 'word') || str_contains($mime, 'document')
                           || str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) {
                        $pageCount = self::estimate_doc_pages($f);
                    }

                    $url = \moodle_url::make_pluginfile_url(
                        $f->get_contextid(), $f->get_component(),
                        $f->get_filearea(), $f->get_itemid(),
                        $f->get_filepath(), $f->get_filename()
                    );

                    $mats[] = [
                        'id'           => (int)$f->get_id(),
                        'filename'     => $f->get_filename(),
                        'mimetype'     => $mime,
                        'filesize'     => (int)$f->get_filesize(),
                        'url'          => $url->out(false),
                        'timemodified' => (int)$f->get_timemodified(),
                        'time_ago'     => self::time_ago($f->get_timemodified()),
                        'page_count'   => $pageCount,
                    ];
                    if (count($mats) >= 100) break 3;
                }
            }
        }

        return ['materials' => $mats];
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
                'time_ago'     => new \external_value(PARAM_TEXT),
                'page_count'   => new \external_value(PARAM_INT),
            ])
        )]);
    }

    // ------------------------------------------------------------------ //
    // get_course_recordings                                                 //
    // ------------------------------------------------------------------ //
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
              WHERE courseid $inSql AND status = 'completed'
                AND recording_url IS NOT NULL AND recording_url != ''
           ORDER BY timecreated DESC",
            $inParams, 0, 60);

        $recs = [];
        foreach ($sessions as $sess) {
            // AI-generated title from approved summary output.
            $sumOut = $DB->get_record('umat_ai_outputs', [
                'sessionrecordid' => $sess->id,
                'output_type'     => 'summary',
                'is_approved'     => 1,
            ]);
            $title = 'Lecture Session — ' . date('d M Y', $sess->timecreated);
            $desc  = '';
            if ($sumOut && $sumOut->content) {
                $lines = array_values(array_filter(explode("\n", $sumOut->content)));
                if (!empty($lines)) {
                    $title = mb_substr($lines[0], 0, 80);
                    $desc  = mb_substr(implode(' ', array_slice($lines, 1, 3)), 0, 160);
                }
            }

            // Parse transcript segments.
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

            $recs[] = [
                'id'          => (int)$sess->id,
                'session_key' => $sess->sessionid,
                'courseid'    => (int)$sess->courseid,
                'title'       => $title,
                'description' => $desc,
                'url'         => $sess->recording_url,
                'date'        => date('d M Y', $sess->timecreated),
                'time_ago'    => self::time_ago($sess->timecreated),
                'duration'    => '',
                'page_count'  => 0,
                'segments'    => $segments,
            ];
        }
        return ['recordings' => $recs];
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
                'date'        => new \external_value(PARAM_TEXT),
                'time_ago'    => new \external_value(PARAM_TEXT),
                'duration'    => new \external_value(PARAM_TEXT),
                'page_count'  => new \external_value(PARAM_INT),
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

    // ------------------------------------------------------------------ //
    // get_ai_sessions                                                       //
    // ------------------------------------------------------------------ //
    public static function get_ai_sessions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)', VALUE_DEFAULT, 0),
            'limit'    => new \external_value(PARAM_INT, 'Max sessions',        VALUE_DEFAULT, 20),
        ]);
    }

    public static function get_ai_sessions($courseid = 0, $limit = 20) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_ai_sessions_parameters(),
            ['courseid' => $courseid, 'limit' => $limit]);

        $cid   = (int)$params['courseid'];
        $limit = min((int)$params['limit'], 50);

        $where = "userid=:uid AND role='student'"
               . " AND session_key IS NOT NULL AND session_key!=''";
        $wargs = ['uid' => $USER->id];
        if ($cid > 0) { $where .= ' AND courseid=:cid'; $wargs['cid'] = $cid; }

        $rawSessions = $DB->get_records_sql(
            "SELECT session_key, MAX(courseid) AS courseid, MAX(timecreated) AS lastactive,
                    COUNT(*) AS msg_count, MIN(question) AS first_q
               FROM {umat_ai_chat_logs}
              WHERE $where
           GROUP BY session_key ORDER BY lastactive DESC",
            $wargs, 0, $limit);

        $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
        $cMap = [];
        foreach ($courses as $c) $cMap[$c->id] = $c;

        $sessions = [];
        foreach ($rawSessions as $s) {
            $cName = $cShort = '';
            if (isset($cMap[$s->courseid])) {
                $cName  = format_string($cMap[$s->courseid]->fullname);
                $cShort = $cMap[$s->courseid]->shortname;
            }
            $sessions[] = [
                'session_key'  => $s->session_key,
                'courseid'     => (int)$s->courseid,
                'course_name'  => $cName,
                'course_short' => $cShort,
                'time_label'   => self::time_ago($s->lastactive),
                'msg_count'    => (int)$s->msg_count,
                'preview'      => mb_strlen($s->first_q) > 110
                    ? mb_substr($s->first_q, 0, 107) . '…'
                    : $s->first_q,
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
}
