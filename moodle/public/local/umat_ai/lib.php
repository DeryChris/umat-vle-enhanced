<?php
/**
 * Local library functions for local_umat_ai.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the AI service URL and token from plugin settings.
 * Falls back to sensible defaults for local development.
 *
 * @return array ['url' => string, 'token' => string]
 */
function local_umat_ai_get_service_config(): array {
    $url   = get_config('local_umat_ai', 'ai_service_url')   ?: 'http://localhost:8000';
    $token = get_config('local_umat_ai', 'ai_service_token') ?: '';

    return [
        'url'   => rtrim($url, '/'),
        'token' => $token,
    ];
}

/**
 * Returns remaining questions for the current user in the current rate-limit window.
 *
 * @param int $userid
 * @param int $limitPerMinute
 * @return int
 */
function local_umat_ai_questions_remaining(int $userid, int $limitPerMinute = 10): int {
    global $DB;

    $used = $DB->count_records_select(
        'umat_ai_chat_logs',
        'userid = :uid AND timecreated > :since AND role = :role',
        ['uid' => $userid, 'since' => time() - 60, 'role' => 'student']
    );

    return max(0, $limitPerMinute - (int) $used);
}

/**
 * Returns true if the current user is a lecturer (has viewanalytics) in the given course.
 *
 * @param int $courseid
 * @return bool
 */
function local_umat_ai_is_lecturer(int $courseid): bool {
    if (!$courseid) return false;
    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) return false;
    return has_capability('local/umat_ai:viewanalytics', $ctx);
}

/**
 * Returns true if the current user is enrolled as a student in the given course.
 *
 * @param int $courseid
 * @return bool
 */
function local_umat_ai_is_student(int $courseid): bool {
    global $USER;
    if (!$courseid) return false;
    $ctx = context_course::instance($courseid, IGNORE_MISSING);
    if (!$ctx) return false;
    if (has_capability('local/umat_ai:viewanalytics', $ctx)) return false; // lecturer, not student
    return is_enrolled($ctx, $USER, '', false);
}

/**
 * Reduce a list of enrolled users to those holding a role with the
 * 'student' archetype in the given course context.
 *
 * get_enrolled_users()/enrol_get_course_users() also return admins and
 * lecturers when they hold a capability (e.g. local/umat_ai:chatwithai) or
 * are simply enrolled — without this filter the lecturer and site admin
 * show up in the at-risk students list and the NLQ student dataset.
 *
 * Roles assigned in parent contexts (category/system) count as well.
 * Falls back to the full list when no student-archetype assignment is
 * found, so unusual role setups do not silently blank the student views.
 *
 * @param array $users User list keyed by user id (get_enrolled_users shape).
 * @param \context $context Course context.
 * @return array The same list, reduced to student-role users.
 */
function local_umat_ai_student_only(array $users, \context $context): array {
    if (empty($users)) {
        return $users;
    }
    $students = [];
    foreach (get_archetype_roles('student') as $role) {
        // parentcontexts = true: also pick up roles assigned at category or
        // system level; only active enrolments are considered by default.
        $students += get_role_users($role->id, $context, true, 'u.id', 'u.id');
    }
    if (empty($students)) {
        return $users;
    }
    return array_intersect_key($users, $students);
}

/**
 * Apply one SM-2 review step (canonical SuperMemo-2).
 *
 * Mirrors ai_service/core/spaced_repetition.py::sm2_update — keep both in sync.
 *
 * @param int   $quality     Self-assessment 0-5 (4-button UI maps again=1, hard=3, good=4, easy=5)
 * @param float $ease        Current ease factor (default 2.5, floored at 1.3)
 * @param int   $interval    Current interval in days (0 = never reviewed)
 * @param int   $repetitions Current repetition count
 * @return array ['ease' => float, 'interval' => int, 'repetitions' => int]
 */
function local_umat_ai_sm2_review(int $quality, float $ease = 2.5, int $interval = 0, int $repetitions = 0): array {
    $quality = max(0, min(5, $quality));

    // Ease factor update: EF' = EF + (0.1 - (5-q) * (0.08 + (5-q) * 0.02)), floored at 1.3.
    $ease = max(1.3, $ease + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02)));

    if ($quality < 3) {
        // Lapse: repeat tomorrow, repetitions reset.
        return ['ease' => $ease, 'interval' => 1, 'repetitions' => 0];
    }

    $reps = $repetitions + 1;
    if ($reps === 1) {
        $interval = 1;
    } else if ($reps === 2) {
        $interval = 6;
    } else {
        $interval = max(1, (int) round($interval * $ease));
    }

    return ['ease' => $ease, 'interval' => $interval, 'repetitions' => $reps];
}

/**
 * Map a UI review button to the SM-2 quality score.
 *
 * @param string $button 'again'|'hard'|'good'|'easy'
 * @return int|null Quality 1/3/4/5, or null when unknown.
 */
function local_umat_ai_sm2_button_quality(string $button): ?int {
    $map = ['again' => 1, 'hard' => 3, 'good' => 4, 'easy' => 5];
    return $map[strtolower($button)] ?? null;
}

/**
 * Next review timestamp in epoch seconds for an interval (days).
 *
 * @param int $interval Interval in days
 * @param int $now      Epoch reference time (defaults to time())
 * @return int Epoch timestamp of the next due review
 */
function local_umat_ai_sm2_next_due(int $interval, int $now = 0): int {
    return ($now ?: time()) + $interval * 86400;
}

/**
 * Base64url encode (JWT helper).
 *
 * @param string $data
 * @return string
 */
function local_umat_ai_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Create a short-lived HS256 JWT for webhook authentication.
 *
 * @param array $claims Custom claims to embed in the payload
 * @param int $ttl Seconds until expiry (default 5 minutes)
 * @return string Signed JWT
 */
function local_umat_ai_create_jwt(array $claims, int $ttl = 300): string {
    $config = local_umat_ai_get_service_config();
    $secret = $config['token'];
    if ($secret === '') {
        return '';
    }

    $header  = local_umat_ai_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = local_umat_ai_base64url_encode(json_encode(array_merge($claims, [
        'iat' => time(),
        'exp' => time() + $ttl,
        'iss' => 'umat_vle_moodle',
    ])));

    $signature = local_umat_ai_base64url_encode(
        hash_hmac('sha256', $header . '.' . $payload, $secret, true)
    );

    return $header . '.' . $payload . '.' . $signature;
}

/**
 * Filter material file IDs based on the user's access to associated course modules.
 *
 * Uses Moodle's availability/restriction system (dates, group, grade, completion)
 * to determine which materials the user can access. Materials linked to course
 * modules with restrictions the user doesn't meet are excluded.
 *
 * @param int      $courseid    Course ID
 * @param int      $userid      Moodle user ID
 * @param int[]|null $fileIds   Optional subset of file IDs to check; null = all course materials
 * @return int[]                Filtered file IDs the user can access
 */
function local_umat_ai_filter_accessible_materials(int $courseid, int $userid, ?array $fileIds = null): array {
    global $DB;

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course, $userid);

    $params = ['courseid' => $courseid];
    if ($fileIds !== null && !empty($fileIds)) {
        [$insql, $inparams] = $DB->get_in_or_equal($fileIds, SQL_PARAMS_NAMED, 'fid');
        $params = array_merge($params, $inparams);
        $records = $DB->get_records_select('umat_ai_materials',
            "courseid = :courseid AND fileid $insql", $params, '', 'fileid,cmid');
    } else {
        $records = $DB->get_records('umat_ai_materials', $params, '', 'fileid,cmid');
    }

    if (empty($records)) {
        return $fileIds ?? [];
    }

    $allowed = [];
    foreach ($records as $rec) {
        $cmid = (int)$rec->cmid;
        if ($cmid === 0) {
            // Course-level or manual upload — no CM restriction.
            $allowed[] = (int)$rec->fileid;
            continue;
        }
        try {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->uservisible) {
                $allowed[] = (int)$rec->fileid;
            }
        } catch (\moodle_exception $e) {
            // CM not found in modinfo — treat as restricted.
            continue;
        }
    }

    return $allowed;
}

/**
 * Push student analytics event to the Python AI engine.
 * Uses JWT when a service token is configured, otherwise Bearer token.
 *
 * @param array $body Request body (user_id, course_id, event_type, payload, profile)
 * @return bool True if the webhook was accepted (HTTP 2xx)
 */
function local_umat_ai_push_analytics(array $body): bool {
    $config = local_umat_ai_get_service_config();
    if ($config['url'] === '' || $config['token'] === '') {
        return false;
    }

    $url  = $config['url'] . '/api/v1/analytics/update';
    $jwt  = local_umat_ai_create_jwt(['sub' => 'moodle_webhook']);
    $auth = $jwt !== '' ? 'Bearer ' . $jwt : 'Bearer ' . $config['token'];

    $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($config['url'])]);
    $client->setHeader([
        'Content-Type: application/json',
        'Authorization: ' . $auth,
        'X-Request-Id: ' . local_umat_ai_request_id(),
    ]);

    $response = $client->post($url, json_encode($body));
    $info     = $client->get_info();
    $httpcode = (int) ($info['http_code'] ?? 0);

    return $httpcode >= 200 && $httpcode < 300;
}

/**
 * Check if a URL points to localhost — safe to skip SSL verification.
 */
function local_umat_ai_is_localhost(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * Generate a unique request ID for tracing Moodle→AI service calls.
 */
function local_umat_ai_request_id(): string {
    return sprintf(
        'umt-%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

/**
 * Parse formatted transcript text (e.g. "[00:00] Hello world") into
 * structured segments [{start, end, text}] for the transcript viewer.
 *
 * Also handles JSON string input (raw segments from AI service).
 *
 * @param string $formatted Transcript text or JSON string
 * @return array Array of segment objects
 */
function local_umat_ai_parse_segments(string $formatted): array {
    // Try JSON first (raw segments from AI service).
    $decoded = json_decode($formatted, true);
    if (is_array($decoded) && !empty($decoded) && isset($decoded[0]['start'])) {
        return $decoded;
    }

    // Fall back to parsing formatted text with [MM:SS] timestamps.
    $segments = [];
    $lines = explode("\n", $formatted);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^\[(\d{1,2}):(\d{2})\]\s*(.*)$/', $line, $m)) {
            $start = (float)(intval($m[1]) * 60 + intval($m[2]));
            $text  = trim($m[3]);
            if (!empty($segments)) {
                $segments[count($segments) - 1]['end'] = $start;
            }
            $segments[] = ['start' => $start, 'end' => $start + 30.0, 'text' => $text];
        } elseif (!empty($segments)) {
            $segments[count($segments) - 1]['text'] .= ' ' . $line;
        }
    }
    return $segments;
}

/**
 * Serve recording files stored in Moodle's file system.
 *
 * URL: /pluginfile.php/{contextid}/local_umat_ai/recordings/{sessionid}/{filename}
 */
function local_umat_ai_pluginfile($course, $cm, \context $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($filearea === 'issue_attachments') {
        require_login($course);
        $messageid = (int)array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $message = $DB->get_record('umat_ai_issue_messages', ['id' => $messageid], '*', IGNORE_MISSING);
        if (!$message) {
            return false;
        }
        [$conversation, $role, $coursecontext] = \local_umat_ai\issue_manager::require_conversation((int)$message->conversationid);
        if ((int)$context->id !== (int)$coursecontext->id) {
            return false;
        }
        $file = get_file_storage()->get_file(
            $coursecontext->id,
            'local_umat_ai',
            'issue_attachments',
            $messageid,
            $filepath,
            $filename
        );
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, null, 0, true, $options);
        return;
    }

    if ($filearea === 'materials') {
        // URL format: /pluginfile.php/{contextid}/local_umat_ai/materials/{itemid}/{path}{filename}
        $itemid = (int)array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

        $coursecontext = \context_course::instance($context->instanceid);
        require_capability('local/umat_ai:chatwithai', $coursecontext);

        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_umat_ai', 'materials', $itemid, $filepath, $filename);
        if (!$file) {
            // Files may be stored with itemid = 0 (course_upload.php / materials.php)
            // or itemid = courseid (RB push_to_course) — try both variants.
            $file = $fs->get_file($context->id, 'local_umat_ai', 'materials', 0, $filepath, $filename);
        }
        if (!$file) {
            // Final fallback: match by filename across every itemid in this course context.
            $candidates = $fs->get_area_files($context->id, 'local_umat_ai', 'materials', false, 'id', false);
            foreach ($candidates as $cand) {
                if (!$cand->is_directory() && $cand->get_filename() === $filename) {
                    $file = $cand;
                    break;
                }
            }
        }
        if (!$file) {
            return false;
        }

        send_stored_file($file, null, 0, true);
        return;
    }

    if ($filearea === 'resourcebank') {
        global $USER, $DB;
        $itemid = (int)array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

        // Only the owner can access their resource bank files.
        $item = $DB->get_record('umat_resource_items', ['id' => $itemid]);
        if (!$item || $item->userid != $USER->id) {
            return false;
        }

        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_umat_ai', 'resourcebank', $itemid, $filepath, $filename);
        if (!$file) {
            return false;
        }

        send_stored_file($file, null, 0, true);
        return;
    }

    if ($filearea !== 'recordings') {
        return false;
    }

    $sessionid = (int)array_shift($args);
    $filename  = array_pop($args);
    $filepath  = $args ? '/' . implode('/', $args) . '/' : '/';

    $session = $DB->get_record('umat_ai_sessions', ['id' => $sessionid]);
    if (!$session) {
        return false;
    }

    $coursecontext = \context_course::instance($session->courseid);
    require_capability('local/umat_ai:chatwithai', $coursecontext);

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_umat_ai', 'recordings', $sessionid, $filepath, $filename);
    if (!$file) {
        return false;
    }

    send_stored_file($file, null, 0, true);
}
