<?php
/**
 * Handles multipart file upload and forwards to the AI service.
 * Always returns JSON — never HTML.
 *
 * @package local_umat_ai
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

// Ensure we always output JSON, never HTML redirects or debug warnings.
header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);
ob_start();

try {
    require_login(false, false);
    require_sesskey();

    $courseid = required_param('courseid', PARAM_INT);
    $title    = optional_param('title', '', PARAM_TEXT);

    if ($courseid <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid course must be selected before uploading.']);
        exit;
    }

    // Verify course exists.
    if (!$DB->get_record('course', ['id' => $courseid])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Course not found (id: ' . $courseid . ').']);
        exit;
    }

    // Only lecturers/instructors can upload.
    $context = context_course::instance($courseid);
    if (!has_capability('local/umat_ai:viewanalytics', $context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to upload recordings.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    if (empty($_FILES['audio'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No audio/video file provided']);
        exit;
    }

    $file = $_FILES['audio'];
    $allowed = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/aac', 'audio/webm',
        'video/mp4', 'video/webm', 'video/ogg', 'video/x-matroska',
        'application/octet-stream',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => 'Unsupported file type: ' . $mime]);
        exit;
    }

    $maxbytes = 500 * 1024 * 1024;
    if ($file['size'] > $maxbytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'File too large (max 500 MB)']);
        exit;
    }

    $config = get_config('local_umat_ai');
    $ai_url = rtrim($config->ai_service_url ?? 'http://localhost:8000', '/');
    $token  = $config->ai_service_token ?? '';

    $session_id = 'upload_' . bin2hex(random_bytes(6));

    $record = new \stdClass();
    $record->sessionid      = $session_id;
    $record->courseid       = $courseid;
    $record->userid         = $USER->id;
    $record->cmid           = 0;
    $record->source_type    = 'upload';
    $record->recording_url  = null;
    $record->upload_filename = $file['name'];
    $record->status         = 'uploading';
    $record->timecreated    = time();
    $record->timemodified   = time();
    $record->id = $DB->insert_record('umat_ai_sessions', $record);

    // Forward to AI service via multipart.
    $boundary = '----UMaTBoundary' . bin2hex(random_bytes(8));
    $body     = '';

    foreach (['course_id', 'session_id'] as $key) {
        $val = ($key === 'course_id') ? $courseid : $session_id;
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
        $body .= "{$val}\r\n";
    }

    $filename = $file['name'];
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= file_get_contents($file['tmp_name']);
    $body .= "\r\n--{$boundary}--\r\n";

    $ch = curl_init($ai_url . '/api/v1/transcription/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => local_umat_ai_is_localhost($ai_url) ? false : true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    ob_end_clean();

    if ($err) {
        $DB->set_field('umat_ai_sessions', 'status', 'upload_failed', ['id' => $record->id]);
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'AI service error: ' . $err]);
        exit;
    }

    $result = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && !empty($result['job_id'])) {
        $DB->set_field('umat_ai_sessions', 'status', 'processing', ['id' => $record->id]);
        $DB->set_field('umat_ai_sessions', 'sessionid', $result['job_id'], ['id' => $record->id]);
        echo json_encode([
            'success'    => true,
            'job_id'     => $result['job_id'],
            'session_id' => $result['job_id'],
            'status'     => 'processing',
            'message'    => 'Upload successful, processing transcription...',
        ]);
    } else {
        $DB->set_field('umat_ai_sessions', 'status', 'upload_failed', ['id' => $record->id]);
        $msg = $result['detail'] ?? $result['message'] ?? 'Unknown error';
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'AI service rejected: ' . $msg]);
    }

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('[local_umat_ai] upload.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
