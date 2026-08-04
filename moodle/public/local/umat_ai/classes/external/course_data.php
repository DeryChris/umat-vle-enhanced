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
        try {
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
            // Allow lecturers (viewanalytics) OR students (chatwithai).
            $hasChatWithAI = has_capability('local/umat_ai:chatwithai', $ctx);
            $hasViewAnalytics = has_capability('local/umat_ai:viewanalytics', $ctx);
            if (!$hasChatWithAI && !$hasViewAnalytics) {
                // Allow access for enrolled users who can access the course
                // The external service already validates login; check course enrollment
                if (!is_enrolled($ctx, $USER->id)) {
                    require_capability('local/umat_ai:chatwithai', $ctx);
                }
            }
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
            ['local_umat_ai','recordings'],
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
                        'resource_type'=> 'document',
                        'status'       => 'indexed',
                        'studentvisible'=> 1,
                    ];
                    if (count($mats) >= 100) break 3;
                }
            }
        }

        // --- FETCH BBB RECORDINGS FOR THIS COURSE ---
        $recordings = [];
        if ($cid > 0) {
            $recs = $DB->get_records('umat_ai_sessions', [
                'courseid' => $cid,
            ], 'timecreated DESC', 'id,sessionid,courseid,title,recording_url,timecreated,status,studentvisible,resource_type,timemodified,transcript_json');
            foreach ($recs as $rec) {
                // Skip recordings without a URL.
                if (empty($rec->recording_url)) {
                    continue;
                }
                // Students see only published recordings; lecturers see all.
                $isStudent = !$hasViewAnalytics;
                if ($isStudent && !$rec->studentvisible) {
                    continue;
                }
                $statusMap = [
                    'pending' => 'pending',
                    'waiting_recording' => 'waiting_recording',
                    'transcribing' => 'transcribing',
                    'indexing' => 'indexing',
                    'ready' => 'ready',
                    'completed' => 'ready',
                    'error' => 'failed',
                    'failed' => 'failed',
                ];
                $status = $statusMap[$rec->status] ?? $rec->status;
                $duration = '';
                if ($rec->transcript_json) {
                    $raw = json_decode($rec->transcript_json, true);
                    if (is_array($raw)) {
                        $lastSeg = end($raw);
                        if ($lastSeg && isset($lastSeg['end'])) {
                            $secs = (int)$lastSeg['end'];
                            $h = floor($secs / 3600);
                            $m = floor(($secs % 3600) / 60);
                            $s = $secs % 60;
                            $duration = ($h ? $h . 'h ' : '') . $m . 'm ' . $s . 's';
                        }
                    }
                }
                // Check AI outputs for this recording
                $outputTypes = ['transcript', 'summary', 'notes', 'quiz'];
                $aiOutputs = [];
                if ($rec->status === 'completed' || $rec->status === 'ready') {
                    $outputs = $DB->get_records('umat_ai_outputs', [
                        'sessionrecordid' => $rec->id,
                        'is_approved' => 1,
                    ]);
                    foreach ($outputs as $out) {
                        if (in_array($out->output_type, $outputTypes)) {
                            $aiOutputs[$out->output_type] = true;
                        }
                    }
                }
                // Skip recordings whose URL fails Moodle external API validation.
                $recordingurl = trim((string)$rec->recording_url);
                if ($recordingurl === '' || !filter_var($recordingurl, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $recordings[] = [
                    'id'            => (int)$rec->id,
                    'filename'      => 'BBB Recording — ' . date('d M Y', $rec->timecreated),
                    'mimetype'      => 'video/webm',
                    'filesize'      => 0,
                    'url'           => $recordingurl,
                    'timemodified'  => (int)$rec->timemodified,
                    'time_ago'      => self::time_ago((int)$rec->timemodified),
                    'page_count'    => 0,
                    'resource_type' => 'bbb_recording',
                    'status'        => $status,
                    'studentvisible'=> (int)$rec->studentvisible,
                    'duration'      => $duration,
                    'ai_outputs'    => json_encode($aiOutputs ?: new \stdClass()),
                    'material_id'   => 0,
                ];
            }
        }

        // Cross-reference indexing status from umat_ai_materials table.
        if ($DB->get_manager()->table_exists('umat_ai_materials') && !empty($mats)) {
            $fileIds = array_column($mats, 'id');
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
            $indexRows = $DB->get_records_sql(
                "SELECT fileid, id AS material_id, is_indexed FROM {umat_ai_materials}
                 WHERE fileid IN ($placeholders)", $fileIds);
            foreach ($mats as &$mat) {
                $idx = $indexRows[$mat['id']] ?? null;
                if ($idx) {
                    $mat['status']       = $idx->is_indexed ? 'indexed' : 'pending';
                    $mat['material_id']  = (int)$idx->material_id;
                } else {
                    $mat['status']       = 'not_indexed';
                    $mat['material_id']  = 0;
                }
            }
            unset($mat);
        } else {
            foreach ($mats as &$mat) {
                $mat['status']      = 'not_indexed';
                $mat['material_id'] = 0;
            }
            unset($mat);
        }

        // Add resource_type and default fields to documents for unified list
        foreach ($mats as &$mat) {
            $mat['resource_type']  = 'document';
            $mat['studentvisible'] = (int)($mat['material_id'] ? ($DB->get_field('umat_ai_materials', 'studentvisible', ['id' => $mat['material_id']]) ?? 1) : 1);
            $mat['ai_outputs']     = json_encode(new \stdClass());
            $mat['duration']       = '';
        }
        unset($mat);

        return ['materials' => $mats, 'recordings' => $recordings];
            } catch (\Throwable $e) {
                error_log('local_umat_ai get_course_materials error: ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine());
                // Return empty array on error to avoid frontend "Could not load materials"
                return ['materials' => [], 'recordings' => []];
            }
    }

    public static function get_course_materials_returns() {
        return new \external_single_structure([
            'materials'  => new \external_multiple_structure(
                new \external_single_structure([
                    'id'             => new \external_value(PARAM_INT),
                    'filename'       => new \external_value(PARAM_TEXT),
                    'mimetype'       => new \external_value(PARAM_TEXT),
                    'filesize'       => new \external_value(PARAM_INT),
                    'url'            => new \external_value(PARAM_URL),
                    'timemodified'   => new \external_value(PARAM_INT),
                    'time_ago'       => new \external_value(PARAM_TEXT),
                    'page_count'     => new \external_value(PARAM_INT),
                    'status'         => new \external_value(PARAM_ALPHAEXT, 'Indexing status', VALUE_DEFAULT, 'not_indexed'),
                    'material_id'    => new \external_value(PARAM_INT, 'umat_ai_materials record id', VALUE_DEFAULT, 0),
                    'resource_type'  => new \external_value(PARAM_ALPHAEXT, 'document|bbb_recording', VALUE_DEFAULT, 'document'),
                    'studentvisible' => new \external_value(PARAM_INT, '1=published, 0=hidden', VALUE_DEFAULT, 1),
                    'ai_outputs'     => new \external_value(PARAM_RAW, 'Available AI outputs', VALUE_DEFAULT, '[]'),
                    'duration'       => new \external_value(PARAM_TEXT, 'Duration for recordings', VALUE_DEFAULT, ''),
                ]),
                VALUE_DEFAULT, []
            ),
            'recordings' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'             => new \external_value(PARAM_INT),
                    'filename'       => new \external_value(PARAM_TEXT),
                    'mimetype'       => new \external_value(PARAM_TEXT),
                    'filesize'       => new \external_value(PARAM_INT),
                    'url'            => new \external_value(PARAM_URL),
                    'timemodified'   => new \external_value(PARAM_INT),
                    'time_ago'       => new \external_value(PARAM_TEXT),
                    'page_count'     => new \external_value(PARAM_INT),
                    'status'         => new \external_value(PARAM_ALPHAEXT, 'Indexing status', VALUE_DEFAULT, 'not_indexed'),
                    'material_id'    => new \external_value(PARAM_INT, 'umat_ai_materials record id', VALUE_DEFAULT, 0),
                    'resource_type'  => new \external_value(PARAM_ALPHAEXT, 'document|bbb_recording', VALUE_DEFAULT, 'document'),
                    'studentvisible' => new \external_value(PARAM_INT, '1=published, 0=hidden', VALUE_DEFAULT, 1),
                    'ai_outputs'     => new \external_value(PARAM_RAW, 'Available AI outputs', VALUE_DEFAULT, '[]'),
                    'duration'       => new \external_value(PARAM_TEXT, 'Duration for recordings', VALUE_DEFAULT, ''),
                ]),
                VALUE_DEFAULT, []
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // reindex_material                                                    //
    // ------------------------------------------------------------------ //
    public static function reindex_material_parameters() {
        return new \external_function_parameters([
            'courseid'    => new \external_value(PARAM_INT, 'Course ID'),
            'material_id' => new \external_value(PARAM_INT, 'umat_ai_materials record id'),
        ]);
    }

    public static function reindex_material($courseid, $material_id) {
        global $DB;
        try {
            $params = self::validate_parameters(
                self::reindex_material_parameters(),
                ['courseid' => $courseid, 'material_id' => $material_id]);
            $cid = (int)$params['courseid'];
            $mid = (int)$params['material_id'];

            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:approveoutput', $ctx);

            $matRecord = $DB->get_record('umat_ai_materials', ['id' => $mid, 'courseid' => $cid]);
            if (!$matRecord) {
                return ['success' => false, 'message' => 'Material record not found.'];
            }

            $fileId = (int)$matRecord->fileid;
            if ($fileId <= 0) {
                return ['success' => false, 'message' => 'No file associated with this material.'];
            }

            $fs = get_file_storage();
            $context = \context_course::instance($cid);
            $files = $fs->get_area_files($context->id, 'local_umat_ai', 'materials', false, '', false);
            $target = null;
            foreach ($files as $f) {
                if ((int)$f->get_id() === $fileId) {
                    $target = $f;
                    break;
                }
            }
            if (!$target) {
                $allCtxs = [$context->id];
                $modinfo = get_fast_modinfo($cid);
                foreach ($modinfo->get_cms() as $cm) {
                    $allCtxs[] = \context_module::instance($cm->id)->id;
                }
                foreach ($allCtxs as $ctxId) {
                    foreach (['mod_resource' => 'content', 'mod_folder' => 'content', 'course' => 'legacy'] as [$comp, $area]) {
                        $areaFiles = $fs->get_area_files($ctxId, $comp, $area, false, '', false);
                        foreach ($areaFiles as $f) {
                            if ((int)$f->get_id() === $fileId) {
                                $target = $f;
                                break 3;
                            }
                        }
                    }
                }
            }

            if (!$target) {
                return ['success' => false, 'message' => 'Original file not found in Moodle file storage.'];
            }

            $cfg = \local_umat_ai_get_service_config();
            $tempdir  = \make_temp_directory('umat_ai_reindex');
            $filepath = $tempdir . '/' . $target->get_filename();
            $target->copy_content_to($filepath);

            $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
            $client->setHeader([
                'Authorization: Bearer ' . $cfg['token'],
                'X-Request-Id: ' . \local_umat_ai_request_id(),
            ]);
            $client->setopt(['CURLOPT_TIMEOUT' => 120]);

            $response = $client->post($cfg['url'] . '/api/v1/materials/index', [
                'course_id'   => (string)$cid,
                'material_id' => (string)$fileId,
                'filename'    => $target->get_filename(),
                'file'        => new \CURLFile($filepath, $target->get_mimetype(), $target->get_filename()),
            ]);

            if (file_exists($filepath)) unlink($filepath);

            $result = @json_decode($response, true);
            if (!empty($result['success'])) {
                $matRecord->is_indexed  = 1;
                $matRecord->timeindexed = time();
                $DB->update_record('umat_ai_materials', $matRecord);
                return ['success' => true, 'message' => $target->get_filename() . ' is ready for question generation.'];
            }

            $detail = $result['detail'] ?? $result['message'] ?? 'Unknown error';
            return ['success' => false, 'message' => $target->get_filename() . ' could not be indexed: ' . $detail];

        } catch (\Throwable $e) {
            error_log('local_umat_ai reindex_material error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Reindex failed. Please try again or contact the administrator.'];
        }
    }

    public static function reindex_material_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL),
            'message' => new \external_value(PARAM_TEXT),
        ]);
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
        try {
            $params = self::validate_parameters(
                self::get_course_recordings_parameters(), ['courseid' => $courseid]);
            $cid = (int)$params['courseid'];

            $courseIds = [];
            if ($cid > 0) {
                $ctx = \context_course::instance($cid);
                self::validate_context($ctx);
                // Allow both students (chatwithai) and lecturers (viewanalytics).
                $hasChatWithAI = has_capability('local/umat_ai:chatwithai', $ctx);
                $hasViewAnalytics = has_capability('local/umat_ai:viewanalytics', $ctx);
                if (!$hasChatWithAI && !$hasViewAnalytics) {
                    if (!is_enrolled($ctx, $USER->id)) {
                        require_capability('local/umat_ai:chatwithai', $ctx);
                    }
            }
            $courseIds[] = $cid;
        } else {
            $courses = enrol_get_users_courses($USER->id, true, 'id');
            foreach ($courses as $c) $courseIds[] = (int)$c->id;
        }
        if (empty($courseIds)) return ['recordings' => []];

        list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
        $hasAnalytics = has_capability('local/umat_ai:viewanalytics', \context_course::instance($cid));
        $isStudent = !$hasAnalytics;

        $where = "WHERE courseid $inSql AND recording_url IS NOT NULL AND recording_url != ''";
        if ($isStudent) {
            $where .= " AND studentvisible = 1";
        }
        $where .= " ORDER BY timecreated DESC";

        $sessions = $DB->get_records_sql(
            "SELECT * FROM {umat_ai_sessions} $where",
            $inParams, 0, 60);

        $recs = [];
        foreach ($sessions as $sess) {
            // AI-generated title from approved summary output (if available).
            $sumOut = $DB->get_record('umat_ai_outputs', [
                'sessionrecordid' => $sess->id,
                'output_type'     => 'summary',
                'is_approved'     => 1,
            ]);
            $title = ($sess->status === 'completed') ? 'Lecture Session — ' . date('d M Y', $sess->timecreated) : 'Recording — processing...';
            $desc  = '';
            if ($sess->status === 'completed' && $sumOut && $sumOut->content) {
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
                'id'                    => (int)$sess->id,
                'session_key'           => $sess->sessionid,
                'courseid'              => (int)$sess->courseid,
                'title'                 => $title,
                'description'           => $desc,
                'url'                   => $sess->recording_url,
                'status'                => $sess->status ?? 'pending',
                'has_transcript'        => !empty($segments),
                'date'                  => date('d M Y', $sess->timecreated),
                'time_ago'              => self::time_ago($sess->timecreated),
                'duration'              => '',
                'page_count'            => 0,
                'segments'              => $segments,
                'transcription_provider' => $sess->transcription_provider ?? null,
                'transcription_model'    => $sess->transcription_model ?? null,
                'transcription_cost'     => (float)($sess->transcription_cost ?? 0),
                'audio_duration_secs'    => (float)($sess->audio_duration_secs ?? 0),
                'chunk_count'            => (int)($sess->chunk_count ?? 0),
            ];
        }
        return ['recordings' => $recs];
        } catch (\Throwable $e) {
            error_log('local_umat_ai get_course_recordings error: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
            return ['recordings' => []];
        }
    }

    public static function get_course_recordings_returns() {
        return new \external_single_structure(['recordings' => new \external_multiple_structure(
            new \external_single_structure([
                'id'            => new \external_value(PARAM_INT),
                'session_key'   => new \external_value(PARAM_TEXT),
                'courseid'      => new \external_value(PARAM_INT),
                'title'         => new \external_value(PARAM_TEXT),
                'description'   => new \external_value(PARAM_TEXT),
                'url'           => new \external_value(PARAM_URL),
                'status'        => new \external_value(PARAM_TEXT),
                'has_transcript'=> new \external_value(PARAM_BOOL),
                'date'          => new \external_value(PARAM_TEXT),
                'time_ago'      => new \external_value(PARAM_TEXT),
                'duration'      => new \external_value(PARAM_TEXT),
                'page_count'    => new \external_value(PARAM_INT),
                'segments'      => new \external_multiple_structure(
                    new \external_single_structure([
                        'timestamp' => new \external_value(PARAM_TEXT),
                        'start'     => new \external_value(PARAM_FLOAT),
                        'end'       => new \external_value(PARAM_FLOAT),
                        'text'      => new \external_value(PARAM_TEXT),
                    ])
                ),
                'transcription_provider' => new \external_value(PARAM_TEXT, 'Transcription provider (openai|openrouter|local)', VALUE_OPTIONAL),
                'transcription_model'    => new \external_value(PARAM_TEXT, 'Transcription model name', VALUE_OPTIONAL),
                'transcription_cost'     => new \external_value(PARAM_FLOAT, 'Cost in USD', VALUE_OPTIONAL, 0),
                'audio_duration_secs'    => new \external_value(PARAM_FLOAT, 'Audio duration in seconds', VALUE_OPTIONAL, 0),
                'chunk_count'            => new \external_value(PARAM_INT, 'Number of audio chunks transcribed', VALUE_OPTIONAL, 0),
                'has_transcript'         => new \external_value(PARAM_BOOL, 'Whether transcript is available', VALUE_OPTIONAL, false),
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

    // ------------------------------------------------------------------ //
    // get_lecturer_sessions                                                 //
    // ------------------------------------------------------------------ //
    public static function get_lecturer_sessions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)', VALUE_DEFAULT, 0),
            'limit'    => new \external_value(PARAM_INT, 'Max sessions', VALUE_DEFAULT, 20),
        ]);
    }

    public static function get_lecturer_sessions($courseid = 0, $limit = 20) {
        global $DB, $USER;
        $params = self::validate_parameters(self::get_lecturer_sessions_parameters(),
            ['courseid' => $courseid, 'limit' => $limit]);
        $cid = (int)$params['courseid'];
        $limit = min((int)$params['limit'], 50);

        // Sessions WITH session_key (grouped).
        $where = "userid=:uid AND session_key IS NOT NULL AND session_key!=''";
        $wargs = ['uid' => $USER->id];
        if ($cid > 0) { $where .= ' AND courseid=:cid'; $wargs['cid'] = $cid; }

        $rawSessions = $DB->get_records_sql(
            "SELECT session_key, MAX(courseid) AS courseid, MAX(timecreated) AS lastactive,
                    COUNT(*) AS msg_count, MIN(query) AS first_q
               FROM {umat_ai_lecturer_notes}
              WHERE $where
           GROUP BY session_key ORDER BY lastactive DESC",
            $wargs, 0, $limit);

        // Legacy rows WITHOUT session_key (each treated as its own session).
        $legacyWhere = "userid=:uid AND (session_key IS NULL OR session_key='')";
        $legacyWargs = ['uid' => $USER->id];
        if ($cid > 0) { $legacyWhere .= ' AND courseid=:cid'; $legacyWargs['cid'] = $cid; }

        $legacyRows = $DB->get_records_sql(
            "SELECT id, courseid, timecreated AS lastactive, 1 AS msg_count, query AS first_q
               FROM {umat_ai_lecturer_notes}
              WHERE $legacyWhere
           ORDER BY timecreated DESC",
            $legacyWargs, 0, $limit);

        // Resolve course names.
        $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
        $cMap = [];
        foreach ($courses as $c) $cMap[$c->id] = $c;

        $sessions = [];
        foreach ($rawSessions as $s) {
            $cName = $cShort = '';
            if (isset($cMap[$s->courseid])) {
                $cName = format_string($cMap[$s->courseid]->fullname);
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
                    ? mb_substr($s->first_q, 0, 107) . '...' : $s->first_q,
            ];
        }
        foreach ($legacyRows as $s) {
            $cName = $cShort = '';
            if (isset($cMap[$s->courseid])) {
                $cName = format_string($cMap[$s->courseid]->fullname);
                $cShort = $cMap[$s->courseid]->shortname;
            }
            $sessions[] = [
                'session_key'  => 'lec_legacy_' . $s->id,
                'courseid'     => (int)$s->courseid,
                'course_name'  => $cName,
                'course_short' => $cShort,
                'time_label'   => self::time_ago($s->lastactive),
                'msg_count'    => (int)$s->msg_count,
                'preview'      => mb_strlen($s->first_q) > 110
                    ? mb_substr($s->first_q, 0, 107) . '...' : $s->first_q,
            ];
        }

        return ['sessions' => $sessions];
    }

    public static function get_lecturer_sessions_returns() {
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

    // ------------------------------------------------------------------ //
    // get_lecturer_session_detail                                           //
    // ------------------------------------------------------------------ //
    public static function get_lecturer_session_detail_parameters() {
        return new \external_function_parameters([
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, 'Session key'),
        ]);
    }

    public static function get_lecturer_session_detail($session_key = '') {
        global $DB, $USER;
        $params = self::validate_parameters(self::get_lecturer_session_detail_parameters(),
            ['session_key' => $session_key]);
        $sk = trim($params['session_key']);

        // Handle legacy sessions (prefix 'lec_legacy_').
        if (strpos($sk, 'lec_legacy_') === 0) {
            $id = (int) str_replace('lec_legacy_', '', $sk);
            $record = $DB->get_record('umat_ai_lecturer_notes', ['id' => $id, 'userid' => $USER->id]);
            if (!$record) return ['messages' => []];
            return ['messages' => [[
                'id'          => (int)$record->id,
                'question'    => $record->query,
                'answer'      => $record->response ?? '',
                'sources'     => json_decode($record->sources ?? '[]', true) ?? [],
                'timecreated' => (int)$record->timecreated,
            ]]];
        }

        $records = $DB->get_records('umat_ai_lecturer_notes',
            ['userid' => $USER->id, 'session_key' => $sk],
            'timecreated ASC', '*', 0, 50);

        return ['messages' => array_values(array_map(function($r) {
            return [
                'id'          => (int)$r->id,
                'question'    => $r->query,
                'answer'      => $r->response ?? '',
                'sources'     => json_decode($r->sources ?? '[]', true) ?? [],
                'timecreated' => (int)$r->timecreated,
            ];
        }, (array)$records))];
    }

    public static function get_lecturer_session_detail_returns() {
        return new \external_single_structure(['messages' => new \external_multiple_structure(
            new \external_single_structure([
                'id'          => new \external_value(PARAM_INT),
                'question'    => new \external_value(PARAM_TEXT),
                'answer'      => new \external_value(PARAM_RAW),
                'sources'     => new \external_multiple_structure(new \external_value(PARAM_TEXT)),
                'timecreated' => new \external_value(PARAM_INT),
            ])
        )]);
    }

    // ------------------------------------------------------------------ //
    // delete_lecturer_session                                               //
    // ------------------------------------------------------------------ //
    public static function delete_lecturer_session_parameters() {
        return new \external_function_parameters([
            'session_key' => new \external_value(PARAM_ALPHANUMEXT, 'Session key to delete'),
        ]);
    }

    public static function delete_lecturer_session($session_key = '') {
        global $DB, $USER;
        $params = self::validate_parameters(self::delete_lecturer_session_parameters(),
            ['session_key' => $session_key]);
        $sk = trim($params['session_key']);

        if (empty($sk)) {
            throw new \moodle_exception('invalidparameter', 'local_umat_ai', '', 'session_key cannot be empty');
        }

        // Handle legacy sessions.
        if (strpos($sk, 'lec_legacy_') === 0) {
            $id = (int) str_replace('lec_legacy_', '', $sk);
            $exists = $DB->record_exists('umat_ai_lecturer_notes', ['id' => $id, 'userid' => $USER->id]);
            if (!$exists) return ['success' => true, 'deleted' => 0];
            $DB->delete_records('umat_ai_lecturer_notes', ['id' => $id, 'userid' => $USER->id]);
            return ['success' => true, 'deleted' => 1];
        }

        $exists = $DB->record_exists('umat_ai_lecturer_notes', [
            'session_key' => $sk, 'userid' => $USER->id,
        ]);
        if (!$exists) return ['success' => true, 'deleted' => 0];

        $deleted = $DB->delete_records('umat_ai_lecturer_notes', [
            'session_key' => $sk, 'userid' => $USER->id,
        ]);

        return ['success' => true, 'deleted' => (int)$deleted];
    }

    public static function delete_lecturer_session_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL),
            'deleted' => new \external_value(PARAM_INT),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_pending_outputs                                                    //
    // ------------------------------------------------------------------ //
    public static function get_pending_outputs_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID (0 = all)'),
        ]);
    }

    public static function get_pending_outputs($courseid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_pending_outputs_parameters(),
            ['courseid' => $courseid]);
        $cid = (int)$params['courseid'];

        $wheres = ['o.is_approved = 0'];
        $wargs = [];
        if ($cid > 0) {
            $wheres[] = 's.courseid = :cid';
            $wargs['cid'] = $cid;
        }
        $where = implode(' AND ', $wheres);

        $rows = $DB->get_records_sql(
            "SELECT o.id, o.sessionrecordid, o.courseid, o.output_type, o.content,
                    o.timecreated, s.timecreated AS session_time
               FROM {umat_ai_outputs} o
               JOIN {umat_ai_sessions} s ON s.id = o.sessionrecordid
              WHERE $where
           ORDER BY s.timecreated DESC, o.timecreated DESC",
            $wargs);

        $total = count($rows);
        $sessionMap = [];
        $courseCache = [];

        foreach ($rows as $r) {
            $sid = (int)$r->sessionrecordid;
            if (!isset($sessionMap[$sid])) {
                if (!isset($courseCache[$r->courseid])) {
                    $course = $DB->get_record('course', ['id' => $r->courseid], 'fullname');
                    $courseCache[$r->courseid] = $course ? format_string($course->fullname) : 'Unknown';
                }
                $sessionMap[$sid] = [
                    'session_id'    => $sid,
                    'courseid'      => (int)$r->courseid,
                    'course_name'   => $courseCache[$r->courseid],
                    'timecreated'   => (int)$r->session_time,
                    'pending_count' => 0,
                    'outputs'       => [],
                ];
            }
            $sessionMap[$sid]['pending_count']++;
            $sessionMap[$sid]['outputs'][] = [
                'id'          => (int)$r->id,
                'type'        => $r->output_type,
                'content'     => $r->content,
                'timecreated' => (int)$r->timecreated,
            ];
        }

        $sessions = array_values($sessionMap);
        usort($sessions, fn($a, $b) => $b['timecreated'] - $a['timecreated']);

        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
        } else {
            $ctx = \context_system::instance();
        }
        self::validate_context($ctx);
        require_capability('local/umat_ai:approveoutput', $ctx);

        return [
            'total_pending' => $total,
            'sessions'      => $sessions,
        ];
    }

    public static function get_pending_outputs_returns() {
        return new \external_single_structure([
            'total_pending' => new \external_value(PARAM_INT),
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'session_id'    => new \external_value(PARAM_INT),
                    'courseid'      => new \external_value(PARAM_INT),
                    'course_name'   => new \external_value(PARAM_TEXT),
                    'timecreated'   => new \external_value(PARAM_INT),
                    'pending_count' => new \external_value(PARAM_INT),
                    'outputs' => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'          => new \external_value(PARAM_INT),
                            'type'        => new \external_value(PARAM_TEXT),
                            'content'     => new \external_value(PARAM_RAW),
                            'timecreated' => new \external_value(PARAM_INT),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
