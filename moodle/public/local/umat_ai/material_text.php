<?php
/**
 * Returns extracted text content for a material from the AI service.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$materialid = required_param('material_id', PARAM_INT);
$courseid   = required_param('course_id', PARAM_INT);

$course = get_course($courseid);
require_login($course, true);

header('Content-Type: application/json');

$config = local_umat_ai_get_service_config();

$url = rtrim($config['url'], '/') . '/api/v1/materials/' . $materialid . '/text?course_id=' . $courseid;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $config['token'],
        'X-Request-Id: ' . local_umat_ai_request_id(),
    ],
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Curl error: ' . $error]);
    exit;
}

if ($httpcode >= 400) {
    http_response_code($httpcode);
    echo $response ?: json_encode(['success' => false, 'error' => 'AI service returned HTTP ' . $httpcode]);
    exit;
}

echo $response;
