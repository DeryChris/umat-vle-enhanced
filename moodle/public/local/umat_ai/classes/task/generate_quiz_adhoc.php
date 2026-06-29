<?php
/**
 * Adhoc task: generates quiz questions via AI service, converts to Moodle XML,
 * and stores the result in the quizgen_jobs table for the lecturer to review.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class generate_quiz_adhoc extends \core\task\adhoc_task {

    public function execute(): void {
        global $DB;

        $data   = $this->get_custom_data();
        $jobid  = (int)($data->jobid ?? 0);
        $job    = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace("[umat_ai] Quiz gen adhoc: job $jobid not found, aborting.");
            return;
        }

        try {
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'     => $job->id,
                'status' => 'generating',
                'timemodified' => time(),
            ]);

            $cfg = get_config('local_umat_ai');

            // ── Build request to AI service ──
            $config = json_decode($job->config_json, true);
            $payload = [
                'source_type'     => $job->material_id ? 'material_id' : 'text',
                'content'         => $job->source_text,
                'material_id'     => (int)$job->material_id,
                'course_id'       => (int)$job->courseid,
                'bloom_level'     => $config['bloom_level'] ?? 'understand',
                'question_types'  => $config['question_types'] ?? ['multichoice'],
                'total_questions' => (int)($config['total_questions'] ?? 5),
                'difficulty'      => $config['difficulty'] ?? 'medium',
                'ai_instructions' => $config['ai_instructions'] ?? '',
            ];

            $client = new \curl();
            $client->setHeader([
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg->ai_service_token,
            ]);

            $payloadJson = json_encode($payload);
            mtrace("[umat_ai] Calling AI service for job $jobid...");
            $response = $client->post(
                rtrim($cfg->ai_service_url, '/') . '/api/v1/quizgen/generate',
                $payloadJson
            );

            $httpCode = $client->get_info()['http_code'] ?? 0;
            mtrace("[umat_ai] AI service responded HTTP $httpCode");

            $result = json_decode($response, true);
            if (!$result || !isset($result['questions'])) {
                $truncated = mb_substr($response, 0, 2000);
                mtrace("[umat_ai] Invalid AI response (HTTP $httpCode): $truncated");
                throw new \moodle_exception('quizgen_ai_invalid', 'local_umat_ai', '', null, "HTTP $httpCode: " . mb_substr($response, 0, 500));
            }

            $questions = $result['questions'];

            // ── Convert to Moodle XML ──
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'     => $job->id,
                'status' => 'processing_xml',
                'questions_json' => json_encode($questions),
                'timemodified'   => time(),
            ]);

            require_once(__DIR__ . '/../quiz/xml_builder.php');
            $xml = \local_umat_ai\quiz\xml_builder::build_moodle_xml($questions, $job->category_name);

            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'          => $job->id,
                'status'      => 'completed',
                'xml_content' => $xml,
                'timemodified'=> time(),
            ]);

            mtrace("[umat_ai] Quiz gen job $jobid completed: " . count($questions) . " questions generated.");
        } catch (\Throwable $e) {
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'             => $job->id,
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
                'timemodified'   => time(),
            ]);
            mtrace("[umat_ai] Quiz gen job $jobid FAILED: " . $e->getMessage());
        }
    }
}
