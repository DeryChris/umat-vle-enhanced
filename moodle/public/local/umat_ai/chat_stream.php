<?php
/**
 * Streaming chat proxy — forwards SSE from the AI service to the browser.
 * Keeps the service token server-side; logs completed answers to Moodle.
 *
 * @package local_umat_ai
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$question = required_param('question', PARAM_TEXT);
$sessionkey = optional_param('session_key', '', PARAM_ALPHANUMEXT);
$materialidsraw = optional_param('material_ids', '[]', PARAM_RAW);

$context = context_course::instance($courseid);
$is_lecturer = local_umat_ai_is_lecturer($courseid);
$role = 'student';
$question_to_send = $question;

if ($is_lecturer) {
    require_capability('local/umat_ai:viewanalytics', $context);
    $role = 'lecturer';

    // Gather analytics context for the AI prompt.
    $since = time() - (30 * 86400);

    $totalInteractions = $DB->count_records_select(
        'umat_ai_chat_logs',
        'courseid = :courseid AND timecreated > :since',
        ['courseid' => $courseid, 'since' => $since]
    );

    $topQs = $DB->get_records_sql(
        "SELECT question, COUNT(*) AS cnt
           FROM {umat_ai_chat_logs}
          WHERE courseid = :courseid AND timecreated > :since AND role = 'student'
       GROUP BY question ORDER BY cnt DESC",
        ['courseid' => $courseid, 'since' => $since],
        0, 5
    );
    $topQsList = implode('; ', array_column((array) $topQs, 'question'));

    // Build an analytics-enriched prompt.
    $analyticsCtx = "Course analytics context: "
        . "Total AI interactions in last 30 days: {$totalInteractions}. "
        . "Top student questions: {$topQsList}. ";

    $question_to_send = $analyticsCtx . ' Lecturer query: ' . $question;
} else {
    require_capability('local/umat_ai:chatwithai', $context);

    $rateLimit = (int) get_config('local_umat_ai', 'rate_limit') ?: 10;
    $remaining = local_umat_ai_questions_remaining($USER->id, $rateLimit);
    if ($remaining <= 0) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\n";
        echo 'data: ' . json_encode([
            'message' => get_string('rate_limit_hit', 'local_umat_ai'),
            'error' => 'rate_limit',
            'remaining' => 0,
        ]) . "\n\n";
        exit;
    }
}

$materialids = json_decode($materialidsraw, true);
if (!is_array($materialids)) {
    $materialids = [];
}

// Filter materials by access restrictions (security boundary).
// For students, only accessible materials are sent to the AI service.
$hadSelection = !empty($materialids);
$accessible = local_umat_ai_filter_accessible_materials(
    $courseid, $USER->id,
    $hadSelection ? $materialids : null
);
// When the student explicitly selected materials, always pass the
// accessible subset (even if empty) so the AI never searches
// unrestricted materials.
$finalMaterialIds = !empty($accessible) ? $accessible
    : ($hadSelection ? [] : $materialids);

$cfg = local_umat_ai_get_service_config();
if ($cfg['url'] === '' || $cfg['token'] === '') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: error\n";
    echo 'data: ' . json_encode([
        'message' => get_string('ai_unavailable', 'local_umat_ai'),
        'error' => 'service_error',
    ]) . "\n\n";
    exit;
}

$payload = json_encode([
    'question'     => $question_to_send,
    'course_id'    => $courseid,
    'user_id'      => (int) $USER->id,
    'session_key'  => $sessionkey,
    'material_ids' => $finalMaterialIds,
    'role'         => $role,
]);

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

global $DB;

$buffer = '';
$fullanswer = '';
$sources = [];
$logged = false;

$ch = curl_init($cfg['url'] . '/api/v1/query/stream');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['token'],
        'Accept: text/event-stream',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_WRITEFUNCTION  => function($curl, $data) use (&$buffer, &$fullanswer, &$sources, &$logged, $DB, $USER, $courseid, $question, $sessionkey, $role) {
        echo $data;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();

        $buffer .= $data;
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $event = 'message';
            $eventdata = '';
            foreach (explode("\n", $block) as $line) {
                if (strpos($line, 'event:') === 0) {
                    $event = trim(substr($line, 6));
                } else if (strpos($line, 'data:') === 0) {
                    $eventdata = trim(substr($line, 5));
                }
            }

            if ($eventdata === '') {
                continue;
            }

            $parsed = json_decode($eventdata, true);
            if (!is_array($parsed)) {
                continue;
            }

            if ($event === 'token' && !empty($parsed['text'])) {
                $fullanswer .= $parsed['text'];
            } else if ($event === 'done') {
                $fullanswer = $parsed['answer'] ?? $fullanswer;
                $sources = $parsed['sources'] ?? $sources;
                if (!$logged && $fullanswer !== '') {
                    if ($role === 'lecturer') {
                        $DB->insert_record('umat_ai_lecturer_notes', (object)[
                            'userid'      => $USER->id,
                            'courseid'    => $courseid,
                            'query'       => $question,
                            'response'    => $fullanswer,
                            'timecreated' => time(),
                        ]);
                    } else {
                        $DB->insert_record('umat_ai_chat_logs', (object) [
                            'userid'      => $USER->id,
                            'courseid'    => $courseid,
                            'session_key' => $sessionkey,
                            'role'        => 'student',
                            'question'    => $question,
                            'answer'      => $fullanswer,
                            'sources'     => json_encode($sources),
                            'timecreated' => time(),
                        ]);
                    }
                    $logged = true;
                }
            }
        }

        return strlen($data);
    },
]);

curl_exec($ch);
$httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode < 200 || $httpcode >= 300) {
    echo "event: error\n";
    echo 'data: ' . json_encode([
        'message' => get_string('ai_unavailable', 'local_umat_ai'),
        'error' => 'service_error',
    ]) . "\n\n";
    flush();
}
