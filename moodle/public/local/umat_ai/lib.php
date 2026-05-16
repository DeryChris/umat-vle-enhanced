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
