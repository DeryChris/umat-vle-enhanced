<?php
/**
 * Chat voice transcription proxy.
 * Accepts audio from the browser, forwards to AI service, returns transcript.
 * Keeps the AI service token server-side.
 *
 * @package local_umat_ai
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);
ob_start();

$t_start = microtime(true);

try {
    require_login(false, false);

    if (!confirm_sesskey()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid sesskey']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    if (empty($_FILES['audio'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No audio file provided']);
        exit;
    }

    $file = $_FILES['audio'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'webm');
    $allowed_ext = ['webm', 'ogg', 'wav', 'mp3', 'mp4', 'm4a', 'flac', 'aac'];
    if (!in_array($ext, $allowed_ext)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => 'Unsupported audio format: ' . $ext]);
        exit;
    }

    if ($file['size'] < 512) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Audio too short']);
        exit;
    }

    if ($file['size'] > 25 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Audio too large (max 25 MB)']);
        exit;
    }

    $t_validated = microtime(true);

    $config = get_config('local_umat_ai');
    $ai_url = rtrim($config->ai_service_url ?? 'http://localhost:8000', '/');
    $token  = $config->ai_service_token ?? '';

    $filename = 'voice_' . bin2hex(random_bytes(6)) . '.' . $ext;

    $curlFile = curl_file_create($file['tmp_name'], 'audio/' . $ext, $filename);

    $ch = curl_init($ai_url . '/api/v1/transcription/transcribe');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => $curlFile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => local_umat_ai_is_localhost($ai_url) ? false : true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $curl_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    $t_curl = microtime(true);

    ob_end_clean();

    if ($err) {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'AI service error: ' . $err]);
        exit;
    }

    $result = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && !empty($result['success'])) {
        $t_total = microtime(true) - $t_start;
        error_log(sprintf(
            '[local_umat_ai] transcribe_chat: valid=%.0fms curl=%.0fms total=%.0fms',
            ($t_validated - $t_start) * 1000,
            $curl_time * 1000,
            $t_total * 1000
        ));
        echo json_encode([
            'success'    => true,
            'transcript' => $result['transcript'] ?? '',
            'language'   => $result['language'] ?? 'en',
        ]);
    } else {
        $msg = $result['detail'] ?? $result['message'] ?? 'Transcription failed';
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => $msg]);
    }

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('[local_umat_ai] transcribe_chat.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
