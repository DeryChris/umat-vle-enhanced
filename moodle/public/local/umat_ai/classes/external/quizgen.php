<?php
/**
 * External API: Quiz generation workflow — start job, poll status, finalize,
 * history tracking, append to existing quiz.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class quizgen extends \external_api {

    // ------------------------------------------------------------------ //
    // generate_quiz_draft — create job + queue adhoc task                 //
    // ------------------------------------------------------------------ //
    public static function generate_quiz_draft_parameters() {
        return new \external_function_parameters([
            'courseid'        => new \external_value(PARAM_INT, 'Target course ID'),
            'source_type'     => new \external_value(PARAM_ALPHA, '"text" or "material_id"'),
            'content'         => new \external_value(PARAM_RAW, 'Raw text content', VALUE_DEFAULT, null),
            'material_id'     => new \external_value(PARAM_INT, 'ID of an indexed material', VALUE_DEFAULT, null),
            'bloom_level'     => new \external_value(PARAM_ALPHA, 'Bloom\'s taxonomy level', VALUE_DEFAULT, 'understand'),
            'question_types'  => new \external_value(PARAM_RAW, 'JSON array of question type strings', VALUE_DEFAULT, '["multichoice"]'),
            'total_questions' => new \external_value(PARAM_INT, 'Number of questions to generate', VALUE_DEFAULT, 5),
            'difficulty'      => new \external_value(PARAM_ALPHA, '"easy", "medium", or "hard"', VALUE_DEFAULT, 'medium'),
            'category_name'   => new \external_value(PARAM_TEXT, 'Name for the question category (and quiz name)', VALUE_DEFAULT, ''),
            'ai_instructions' => new \external_value(PARAM_RAW, 'Custom instructions for the AI', VALUE_DEFAULT, ''),
        ]);
    }

    public static function generate_quiz_draft(
        $courseid,
        $source_type,
        $content = null,
        $material_id = null,
        $bloom_level = 'understand',
        $question_types = '["multichoice"]',
        $total_questions = 5,
        $difficulty = 'medium',
        $category_name = '',
        $ai_instructions = ''
    ) {
        global $DB, $USER;

        $params = self::validate_parameters(self::generate_quiz_draft_parameters(), [
            'courseid'        => $courseid,
            'source_type'     => $source_type,
            'content'         => $content,
            'material_id'     => $material_id,
            'bloom_level'     => $bloom_level,
            'question_types'  => $question_types,
            'total_questions' => $total_questions,
            'difficulty'      => $difficulty,
            'category_name'   => $category_name,
            'ai_instructions' => $ai_instructions,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $qtypes = json_decode($params['question_types'], true);
        if (!is_array($qtypes) || empty($qtypes)) {
            $qtypes = ['multichoice'];
        }

        $catname = $params['category_name'];
        if (empty(trim($catname))) {
            $course = $DB->get_record('course', ['id' => $params['courseid']]);
            $catname = 'AI Quiz — ' . ($course->shortname ?? 'Course ' . $params['courseid']) . ' (' . date('Y-m-d') . ')';
        }

        $config = json_encode([
            'bloom_level'     => $params['bloom_level'],
            'question_types'  => $qtypes,
            'total_questions' => (int)$params['total_questions'],
            'difficulty'      => $params['difficulty'],
            'ai_instructions' => $params['ai_instructions'],
        ]);

        $job = (object)[
            'courseid'      => (int)$params['courseid'],
            'userid'        => (int)$USER->id,
            'material_id'   => (int)$params['material_id'] ?: null,
            'source_text'   => $params['source_type'] === 'text' ? $params['content'] : null,
            'config_json'   => $config,
            'category_name' => $catname,
            'status'        => 'pending',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ];
        $job->id = $DB->insert_record('umat_ai_quizgen_jobs', $job);

        $task = new \local_umat_ai\task\generate_quiz_adhoc();
        $task->set_component('local_umat_ai');
        $task->set_custom_data(['jobid' => $job->id]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'job_id'        => (int)$job->id,
            'status'        => 'pending',
            'category_name' => $catname,
        ];
    }

    public static function generate_quiz_draft_returns() {
        return new \external_single_structure([
            'job_id'        => new \external_value(PARAM_INT, 'Job ID for polling'),
            'status'        => new \external_value(PARAM_ALPHAEXT, 'Initial status (pending)'),
            'category_name' => new \external_value(PARAM_TEXT, 'Question category / quiz name'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_quiz_job_status — poll job progress                             //
    // ------------------------------------------------------------------ //
    public static function get_quiz_job_status_parameters() {
        return new \external_function_parameters([
            'jobid' => new \external_value(PARAM_INT, 'Job ID from generate_quiz_draft'),
        ]);
    }

    public static function get_quiz_job_status($jobid) {
        global $DB;

        $params = self::validate_parameters(self::get_quiz_job_status_parameters(), [
            'jobid' => $jobid,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $questions = null;
        if ($job->status === 'completed' && $job->questions_json) {
            $questions = json_decode($job->questions_json, true);
        }

        return [
            'job_id'         => (int)$job->id,
            'status'         => $job->status,
            'questions'      => $questions,
            'failure_reason' => $job->failure_reason,
            'timecreated'    => (int)$job->timecreated,
            'timemodified'   => (int)$job->timemodified,
        ];
    }

    public static function get_quiz_job_status_returns() {
        return new \external_single_structure([
            'job_id'         => new \external_value(PARAM_INT, 'Job ID'),
            'status'         => new \external_value(PARAM_ALPHAEXT, 'pending|generating|processing_xml|completed|failed|importing|imported'),
            'questions'      => new \external_value(PARAM_RAW, 'JSON array of generated questions', VALUE_OPTIONAL),
            'failure_reason' => new \external_value(PARAM_TEXT, 'Error message if failed', VALUE_OPTIONAL),
            'timecreated'    => new \external_value(PARAM_INT, 'Unix timestamp when job was created'),
            'timemodified'   => new \external_value(PARAM_INT, 'Unix timestamp of last update'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // finalize_quiz — import XML into question bank + create/append quiz  //
    // ------------------------------------------------------------------ //
    public static function finalize_quiz_parameters() {
        return new \external_function_parameters([
            'jobid'            => new \external_value(PARAM_INT, 'Job ID from generate_quiz_draft'),
            'category_choice'  => new \external_value(PARAM_ALPHA, '"new" or "existing"', VALUE_DEFAULT, 'new'),
            'existing_job_id'  => new \external_value(PARAM_INT, 'Existing job ID whose category/quiz to reuse', VALUE_DEFAULT, 0),
        ]);
    }

    public static function finalize_quiz($jobid, $category_choice = 'new', $existing_job_id = 0) {
        global $DB;

        $params = self::validate_parameters(self::finalize_quiz_parameters(), [
            'jobid'           => $jobid,
            'category_choice' => $category_choice,
            'existing_job_id' => $existing_job_id,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        if ($job->status !== 'completed') {
            throw new \moodle_exception('quizgen_not_ready', 'local_umat_ai', '', null, "Job status: $job->status");
        }
        if (!$job->xml_content) {
            throw new \moodle_exception('quizgen_no_xml', 'local_umat_ai');
        }

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'           => $job->id,
            'status'       => 'importing',
            'timemodified' => time(),
        ]);

        require_once(__DIR__ . '/../quiz/importer.php');

        try {
            if ($params['category_choice'] === 'existing' && $params['existing_job_id'] > 0) {
                $existingJob = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['existing_job_id']], '*', MUST_EXIST);
                $importResult = \local_umat_ai\quiz\importer::append_to_existing_quiz(
                    $job->id,
                    $job->xml_content,
                    (int)$existingJob->quiz_id,
                    $existingJob->category_name
                );
            } else {
                $importResult = \local_umat_ai\quiz\importer::import_questions_and_create_quiz(
                    $job->id,
                    $job->xml_content,
                    (int)$job->courseid,
                    $job->category_name,
                    (int)$job->userid
                );
            }

            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'           => $job->id,
                'status'       => 'imported',
                'quiz_id'      => $importResult->quiz_id,
                'timemodified' => time(),
            ]);

            return [
                'status'         => 'imported',
                'quiz_id'        => $importResult->quiz_id,
                'quiz_cmid'      => $importResult->quiz_cmid,
                'category_id'    => $importResult->category_id,
                'question_count' => count($importResult->question_ids),
            ];
        } catch (\Throwable $e) {
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'             => $job->id,
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
                'timemodified'   => time(),
            ]);
            throw $e;
        }
    }

    public static function finalize_quiz_returns() {
        return new \external_single_structure([
            'status'         => new \external_value(PARAM_ALPHAEXT, 'imported on success'),
            'quiz_id'        => new \external_value(PARAM_INT, 'Quiz activity ID'),
            'quiz_cmid'      => new \external_value(PARAM_INT, 'Course module ID for the quiz'),
            'category_id'    => new \external_value(PARAM_INT, 'Question category ID'),
            'question_count' => new \external_value(PARAM_INT, 'Number of questions imported'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_quiz_job_history — list all generation jobs for a course         //
    // ------------------------------------------------------------------ //
    public static function get_quiz_job_history_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_quiz_job_history($courseid) {
        global $DB;

        $params = self::validate_parameters(self::get_quiz_job_history_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $jobs = $DB->get_records('umat_ai_quizgen_jobs', ['courseid' => $params['courseid']], 'timecreated DESC');

        $history = [];
        foreach ($jobs as $job) {
            $config = json_decode($job->config_json, true) ?: [];
            $qcount = 0;
            if ($job->questions_json) {
                $qdata = json_decode($job->questions_json, true);
                $qcount = is_array($qdata) ? count($qdata) : 0;
            }

            $history[] = [
                'job_id'         => (int)$job->id,
                'status'         => $job->status,
                'question_count' => $qcount,
                'category_name'  => $job->category_name,
                'quiz_id'        => (int)$job->quiz_id ?: 0,
                'failure_reason' => $job->failure_reason ?? '',
                'timecreated'    => (int)$job->timecreated,
                'timemodified'   => (int)$job->timemodified,
                'config_summary' => json_encode([
                    'bloom_level'     => $config['bloom_level'] ?? 'understand',
                    'difficulty'      => $config['difficulty'] ?? 'medium',
                    'question_types'  => $config['question_types'] ?? ['multichoice'],
                    'total_questions' => $config['total_questions'] ?? 5,
                ]),
            ];
        }

        return ['jobs' => $history];
    }

    public static function get_quiz_job_history_returns() {
        return new \external_single_structure([
            'jobs' => new \external_multiple_structure(
                new \external_single_structure([
                    'job_id'         => new \external_value(PARAM_INT, 'Job ID'),
                    'status'         => new \external_value(PARAM_ALPHAEXT, 'Job status'),
                    'question_count' => new \external_value(PARAM_INT, 'Number of questions generated'),
                    'category_name'  => new \external_value(PARAM_TEXT, 'Quiz/category name'),
                    'quiz_id'        => new \external_value(PARAM_INT, 'Quiz activity ID (0 if not yet imported)'),
                    'failure_reason' => new \external_value(PARAM_TEXT, 'Error message if failed'),
                    'timecreated'    => new \external_value(PARAM_INT, 'Unix timestamp'),
                    'timemodified'   => new \external_value(PARAM_INT, 'Last update timestamp'),
                    'config_summary' => new \external_value(PARAM_RAW, 'JSON summary of generation config'),
                ])
            ),
        ]);
    }
}
