<?php
/**
 * Library functions for the UMaT AI Academic Support local plugin.
 *
 * This file contains Moodle callback functions and shared helpers.
 *
 * Plugin component: local_umat_ai
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add plugin entry into the course navigation (left navigation drawer),
 * when the user is inside a course context.
 *
 * NOTE:
 * This expects that you will create a page later at:
 *   /local/umat_ai/index.php?courseid=XX
 * If that page does not exist yet, you can comment out the URL lines
 * temporarily to avoid dead links.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course record.
 * @param context_course $context Course context.
 */
function local_umat_ai_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    // Only for logged-in users.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Only allow users with capability to use AI features in this course.
    if (!has_capability('local/umat_ai:chatwithai', $context)) {
        return;
    }

    // If you haven't created /local/umat_ai/index.php yet, comment this out for now.
    $url = new moodle_url('/local/umat_ai/index.php', ['courseid' => $course->id]);

    $navigation->add(
        get_string('pluginname', 'local_umat_ai'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_umat_ai',
        new pix_icon('i/info', '') // Uses core icon. Replace later with your own plugin pix if needed.
    );
}

/**
 * Add plugin item under the course "More" / settings navigation.
 *
 * @param settings_navigation $settingsnav Settings navigation object.
 * @param context $context Current context.
 */
function local_umat_ai_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $COURSE;

    // We only add course-level items.
    if ($context->contextlevel !== CONTEXT_COURSE) {
        return;
    }

    // Only for logged-in users.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Capability check.
    if (!has_capability('local/umat_ai:chatwithai', $context)) {
        return;
    }

    // Locate the course administration node.
    $coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }

    // If you haven't created /local/umat_ai/index.php yet, comment this out for now.
    $url = new moodle_url('/local/umat_ai/index.php', ['courseid' => $COURSE->id]);

    $coursenode->add(
        get_string('pluginname', 'local_umat_ai'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_umat_ai_settingsnav'
    );
}

/**
 * Helper: Get AI service configuration from Moodle config settings.
 *
 * @return array{url:string, token:string} URL and bearer token.
 */
function local_umat_ai_get_service_config(): array {
    $url = (string) get_config('local_umat_ai', 'ai_service_url');
    $token = (string) get_config('local_umat_ai', 'ai_service_token');

    return [
        'url' => rtrim($url, "/"),
        'token' => $token,
    ];
}

/**
 * Helper: Returns true if the AI service is configured (URL and token exist).
 *
 * @return bool
 */
function local_umat_ai_is_service_configured(): bool {
    $cfg = local_umat_ai_get_service_config();
    return !empty($cfg['url']) && !empty($cfg['token']);
}

/**
 * Optional helper: Build standard headers for calling the AI service.
 *
 * @return string[] headers
 */
function local_umat_ai_get_ai_service_headers(): array {
    $cfg = local_umat_ai_get_service_config();
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['token'],
    ];
}