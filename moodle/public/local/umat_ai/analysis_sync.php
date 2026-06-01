<?php
/**
 * Standalone endpoint: called by the AI service to mirror analysis metadata
 * into the Moodle-side umat_ai_analysis table.
 *
 * POST /local/umat_ai/analysis_sync.php
 * Auth: Bearer token matching AI_SERVICE_TOKEN
 *
 * @package    local_umat_ai
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

// ── Auth: validate Bearer token ────────────────────────

$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    http_response_code(401);
    die(json_encode(['error' => 'Missing or invalid Authorization header']));
}

$expectedToken = get_config('local_umat_ai', 'ai_service_token')
              ?: ($CFG->forced_plugin_settings['local_umat_ai']['ai_service_token'] ?? '');

if (empty($expectedToken) || !hash_equals($expectedToken, $m[1])) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid token']));
}

// ── Read JSON body ─────────────────────────────────────

$body = @json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON body']));
}

$materialId   = (int)($body['material_id'] ?? 0);
$courseid     = (int)($body['courseid'] ?? 0);
$fileid       = (int)($body['fileid'] ?? 0);
$aiAnalysisId = (int)($body['ai_analysis_id'] ?? 0);
$analysisType = $body['analysis_type'] ?? 'full_analysis';
$scope        = $body['scope'] ?? 'full';
$status       = $body['status'] ?? 'completed';
$modelVersion = $body['model_version'] ?? '';
$tokenCount   = (int)($body['token_count'] ?? 0);
$summary      = $body['summary'] ?? '';

if (!$materialId || !$aiAnalysisId) {
    http_response_code(400);
    die(json_encode(['error' => 'material_id and ai_analysis_id required']));
}

// ── Upsert umat_ai_analysis record ─────────────────────

global $DB;

$now = time();

$existing = $DB->get_record('umat_ai_analysis', [
    'materialid'    => $materialId,
    'analysis_type' => $analysisType,
    'scope'         => $scope,
]);

if ($existing) {
    $existing->ai_analysis_id = $aiAnalysisId;
    $existing->status         = $status;
    $existing->model_version  = $modelVersion;
    $existing->token_count    = $tokenCount;
    $existing->summary        = $summary;
    $existing->timemodified   = $now;
    $DB->update_record('umat_ai_analysis', $existing);
} else {
    $DB->insert_record('umat_ai_analysis', (object)[
        'courseid'      => $courseid,
        'materialid'    => $materialId,
        'fileid'        => $fileid,
        'analysis_type' => $analysisType,
        'scope'         => $scope,
        'status'        => $status,
        'ai_analysis_id' => $aiAnalysisId,
        'model_version'  => $modelVersion,
        'token_count'    => $tokenCount,
        'summary'        => $summary,
        'timecreated'    => $now,
        'timemodified'   => $now,
    ]);
}

// Also update umat_ai_materials.is_analyzed flag
$DB->set_field('umat_ai_materials', 'is_analyzed', 1, ['id' => $materialId]);
$DB->set_field('umat_ai_materials', 'timeanalyzed', $now, ['id' => $materialId]);

echo json_encode(['success' => true, 'material_id' => $materialId]);
