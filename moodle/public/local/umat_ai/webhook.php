<?php
// ============================================================
// Webhook receiver for AI Service callbacks.
//
// Called by the AI service when a recording transcription job
// completes. Updates the session status immediately instead of
// waiting for the next polling cycle (every 15 min).
//
// Endpoint: POST /local/umat_ai/webhook.php
// Auth:     Bearer token (same as AI_SERVICE_TOKEN)
// ============================================================

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// Authenticate via Bearer token.
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    http_response_code(401);
    die('Missing or invalid Authorization header');
}
$token = trim($m[1]);
$expected = get_config('local_umat_ai', 'ai_service_token');
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    die('Invalid token');
}

// Read JSON body.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['event']) || empty($data['session_id'])) {
    http_response_code(400);
    die('Invalid payload');
}

$event     = $data['event'];
$sessionId = $data['session_id'];
$courseId  = $data['course_id'] ?? 0;
$status    = $data['status'] ?? 'completed';

global $DB;

$session = $DB->get_record('umat_ai_sessions', ['sessionid' => $sessionId]);
if (!$session) {
    // Session doesn't exist yet — create placeholder.
    $newStatus = ($event === 'recording.completed') ? 'processing' : 'pending';
    $session = (object)[
        'sessionid'       => $sessionId,
        'courseid'        => $courseId,
        'userid'          => 0,
        'cmid'            => 0,
        'source_type'     => 'bbb',
        'resource_type'   => 'bbb_recording',
        'recording_url'   => '',
        'recording_path'  => '',
        'transcript_json' => null,
        'status'          => $newStatus,
        'studentvisible'  => 0,
        'timecreated'     => time(),
        'timemodified'    => time(),
    ];
    $session->id = $DB->insert_record('umat_ai_sessions', $session);
    mtrace("  [webhook] Created new session record: {$sessionId}");
} else {
    $session->timemodified = time();
}

if ($event === 'recording.completed') {
    $session->status = 'completed';

    // Poll the AI service for transcript + outputs.
    $cfg = local_umat_ai_get_service_config();
    $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
    $client->setHeader([
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['token'],
    ]);

    $statusUrl = $cfg['url'] . '/api/v1/recording/status/' . urlencode($sessionId);
    $rawResp = $client->get($statusUrl);
    $result = json_decode($rawResp, true);

    if (!empty($result['status']) && $result['status'] === 'completed') {
        $tjson = $result['transcript'] ?? null;
        if ($tjson) {
            $session->transcript_json = json_encode(
                local_umat_ai_parse_segments($tjson)
            );
        }

        $DB->update_record('umat_ai_sessions', $session);

        // Create output records.
        $newTypes = [];
        foreach (['summary', 'notes', 'quiz'] as $type) {
            $content = $result['outputs'][$type] ?? null;
            if (!$content) continue;

            $exists = $DB->record_exists('umat_ai_outputs', [
                'sessionrecordid' => $session->id,
                'output_type'     => $type,
            ]);
            if ($exists) continue;

            $DB->insert_record('umat_ai_outputs', (object)[
                'sessionrecordid' => $session->id,
                'courseid'        => $session->courseid,
                'output_type'     => $type,
                'content'         => $content,
                'is_approved'     => 0,
                'approved_by'     => null,
                'timecreated'     => time(),
                'timepublished'   => null,
            ]);
            $newTypes[] = $type;
        }

        mtrace("  [webhook] Recording completed via webhook: {$sessionId}");
    } else {
        $session->status = 'processing';
        $DB->update_record('umat_ai_sessions', $session);
        mtrace("  [webhook] Recording not yet ready, set to processing: {$sessionId}");
    }
} else {
    $session->status = $status;
    $DB->update_record('umat_ai_sessions', $session);
    mtrace("  [webhook] Session {$sessionId} → {$status}");
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);