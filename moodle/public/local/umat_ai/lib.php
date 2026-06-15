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

    $client = new \curl(['ignoresecurity' => true]);
    $client->setHeader([
        'Content-Type: application/json',
        'Authorization: ' . $auth,
    ]);

    $response = $client->post($url, json_encode($body));
    $info     = $client->get_info();
    $httpcode = (int) ($info['http_code'] ?? 0);

    return $httpcode >= 200 && $httpcode < 300;
}
