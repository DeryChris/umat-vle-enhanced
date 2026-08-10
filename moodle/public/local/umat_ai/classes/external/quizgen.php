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
    // generate_quiz_draft — create job + call AI service synchronously    //
    // ------------------------------------------------------------------ //
    public static function generate_quiz_draft_parameters() {
        return new \external_function_parameters([
            'courseid'              => new \external_value(PARAM_INT, 'Target course ID'),
            'source_type'           => new \external_value(PARAM_ALPHAEXT, '"text" or "material_id"'),
            'content'               => new \external_value(PARAM_RAW, 'Raw text content', VALUE_DEFAULT, null),
            'material_ids'          => new \external_value(PARAM_RAW, 'JSON array of material IDs', VALUE_DEFAULT, '[]'),
            'bloom_level'           => new \external_value(PARAM_RAW, 'Bloom level string or JSON distribution', VALUE_DEFAULT, 'understand'),
            'question_types'        => new \external_value(PARAM_RAW, 'JSON object mapping type to count', VALUE_DEFAULT, '{"multichoice":5}'),
            'difficulty'            => new \external_value(PARAM_RAW, 'Difficulty string or JSON distribution', VALUE_DEFAULT, 'medium'),
            'marks_per_question'    => new \external_value(PARAM_FLOAT, 'Marks per question', VALUE_DEFAULT, 1.0),
            'category_name'         => new \external_value(PARAM_TEXT, 'Quiz/category name', VALUE_DEFAULT, ''),
            'ai_instructions'       => new \external_value(PARAM_RAW, 'Custom instructions for the AI', VALUE_DEFAULT, ''),
            'grounding_mode'        => new \external_value(PARAM_ALPHA, 'Grounding mode: strict, applied, enriched', VALUE_DEFAULT, 'applied'),
            'instruction_presets'   => new \external_value(PARAM_RAW, 'JSON array of selected preset keys', VALUE_DEFAULT, '[]'),
            'quiz_description'      => new \external_value(PARAM_RAW, 'Quiz introduction text', VALUE_DEFAULT, ''),
            'shuffle_questions'     => new \external_value(PARAM_INT, 'Shuffle question order (0/1)', VALUE_DEFAULT, 0),
            'shuffle_answers'       => new \external_value(PARAM_INT, 'Shuffle answer order (0/1)', VALUE_DEFAULT, 1),
            'show_feedback'         => new \external_value(PARAM_INT, 'Show feedback during review (0/1)', VALUE_DEFAULT, 1),
            'time_limit'            => new \external_value(PARAM_INT, 'Time limit in minutes (0=unlimited)', VALUE_DEFAULT, 0),
            'max_attempts'          => new \external_value(PARAM_INT, 'Max attempts (-1=unlimited)', VALUE_DEFAULT, -1),
            // Schedule
            'time_open'             => new \external_value(PARAM_RAW, 'Quiz open datetime (ISO)', VALUE_DEFAULT, ''),
            'time_close'            => new \external_value(PARAM_RAW, 'Quiz close datetime (ISO)', VALUE_DEFAULT, ''),
            // Access & Security
            'password'              => new \external_value(PARAM_RAW, 'Quiz access password', VALUE_DEFAULT, ''),
            'browser_security'      => new \external_value(PARAM_INT, 'Browser security level (0-2)', VALUE_DEFAULT, 0),
            'groupmode'             => new \external_value(PARAM_INT, 'Group mode (0=none,1=separate,2=visible)', VALUE_DEFAULT, 0),
            'groupingid'            => new \external_value(PARAM_INT, 'Grouping ID for access restriction', VALUE_DEFAULT, 0),
            'group_ids'             => new \external_value(PARAM_RAW, 'JSON array of allowed group IDs', VALUE_DEFAULT, '[]'),
            // Placement
            'section_num'           => new \external_value(PARAM_INT, 'Course section number', VALUE_DEFAULT, 0),
            'grade_category'        => new \external_value(PARAM_INT, 'Gradebook category ID', VALUE_DEFAULT, 0),
            // Advanced
            'preferred_behaviour'   => new \external_value(PARAM_ALPHA, 'Question behaviour', VALUE_DEFAULT, 'deferredfeedback'),
            'grade_method'          => new \external_value(PARAM_INT, 'Grading method (1=mean,2=highest,4=first,6=last)', VALUE_DEFAULT, 1),
            'nav_method'            => new \external_value(PARAM_ALPHA, 'Navigation method: free, sequential', VALUE_DEFAULT, 'free'),
            'questions_per_page'    => new \external_value(PARAM_INT, 'Questions per page (0=all)', VALUE_DEFAULT, 0),
            'review_attempt'        => new \external_value(PARAM_INT, 'Review: show attempt (0/1)', VALUE_DEFAULT, 1),
            'review_correctness'    => new \external_value(PARAM_INT, 'Review: show correctness (0/1)', VALUE_DEFAULT, 1),
            'review_marks'          => new \external_value(PARAM_INT, 'Review: show marks (0/1)', VALUE_DEFAULT, 1),
            'review_responses'      => new \external_value(PARAM_INT, 'Review: show responses after (0/1)', VALUE_DEFAULT, 1),
            'review_feedback'       => new \external_value(PARAM_INT, 'Review: show feedback after (0/1)', VALUE_DEFAULT, 1),
            'review_overall'        => new \external_value(PARAM_INT, 'Review: show overall feedback (0/1)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function generate_quiz_draft(
        $courseid,
        $source_type,
        $content = null,
        $material_ids = '[]',
        $bloom_level = 'understand',
        $question_types = '{"multichoice":5}',
        $difficulty = 'medium',
        $marks_per_question = 1.0,
        $category_name = '',
        $ai_instructions = '',
        $grounding_mode = 'applied',
        $instruction_presets = '[]',
        $quiz_description = '',
        $shuffle_questions = 0,
        $shuffle_answers = 1,
        $show_feedback = 1,
        $time_limit = 0,
        $max_attempts = -1,
        $time_open = '',
        $time_close = '',
        $password = '',
        $browser_security = 0,
        $groupmode = 0,
        $groupingid = 0,
        $group_ids = '[]',
        $section_num = 0,
        $grade_category = 0,
        $preferred_behaviour = 'deferredfeedback',
        $grade_method = 1,
        $nav_method = 'free',
        $questions_per_page = 0,
        $review_attempt = 1,
        $review_correctness = 1,
        $review_marks = 1,
        $review_responses = 1,
        $review_feedback = 1,
        $review_overall = 1
    ) {
        global $DB, $USER;

        $params = self::validate_parameters(self::generate_quiz_draft_parameters(), [
            'courseid'             => $courseid,
            'source_type'          => $source_type,
            'content'              => $content,
            'material_ids'         => $material_ids,
            'bloom_level'          => $bloom_level,
            'question_types'       => $question_types,
            'difficulty'           => $difficulty,
            'marks_per_question'   => $marks_per_question,
            'category_name'        => $category_name,
            'ai_instructions'      => $ai_instructions,
            'grounding_mode'       => $grounding_mode,
            'instruction_presets'  => $instruction_presets,
            'quiz_description'     => $quiz_description,
            'shuffle_questions'    => $shuffle_questions,
            'shuffle_answers'      => $shuffle_answers,
            'show_feedback'        => $show_feedback,
            'time_limit'           => $time_limit,
            'max_attempts'         => $max_attempts,
            'time_open'            => $time_open,
            'time_close'           => $time_close,
            'password'             => $password,
            'browser_security'     => $browser_security,
            'groupmode'            => $groupmode,
            'groupingid'           => $groupingid,
            'group_ids'            => $group_ids,
            'section_num'          => $section_num,
            'grade_category'       => $grade_category,
            'preferred_behaviour'  => $preferred_behaviour,
            'grade_method'         => $grade_method,
            'nav_method'           => $nav_method,
            'questions_per_page'   => $questions_per_page,
            'review_attempt'       => $review_attempt,
            'review_correctness'   => $review_correctness,
            'review_marks'         => $review_marks,
            'review_responses'     => $review_responses,
            'review_feedback'      => $review_feedback,
            'review_overall'       => $review_overall,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        @set_time_limit(180);

        // Parse question_types as dict.
        $qtypes = json_decode($params['question_types'], true);
        if (!is_array($qtypes) || empty($qtypes)) {
            $qtypes = ['multichice' => 5];
        }

        // Parse bloom_level — can be a string or a dict.
        $bloom = json_decode($params['bloom_level'], true);
        if (!is_array($bloom)) {
            $bloom = $params['bloom_level'];
        }

        // Parse difficulty — can be a string or a dict.
        $diff = json_decode($params['difficulty'], true);
        if (!is_array($diff)) {
            $diff = $params['difficulty'];
        }

        // Parse material_ids.
        $matids = json_decode($params['material_ids'], true);
        if (!is_array($matids)) {
            $matids = [];
        }

        // Parse instruction_presets.
        $instrPresets = json_decode($params['instruction_presets'], true);
        if (!is_array($instrPresets)) {
            $instrPresets = [];
        }

        // Validate grounding_mode.
        $validGrounding = ['strict', 'applied', 'enriched'];
        $groundingMode = in_array($params['grounding_mode'], $validGrounding) ? $params['grounding_mode'] : 'applied';

        // Calculate total questions.
        $total = array_sum($qtypes);
        if ($total <= 0) {
            $total = 5;
            $qtypes = ['multichoice' => 5];
        }

        // Quiz name.
        $catname = $params['category_name'];
        if (empty(trim($catname))) {
            $course = $DB->get_record('course', ['id' => $params['courseid']]);
            $catname = 'AI Quiz — ' . ($course->shortname ?? 'Course ' . $params['courseid']) . ' (' . date('Y-m-d') . ')';
        }

        // Parse group_ids.
        $groupids = json_decode($params['group_ids'], true);
        if (!is_array($groupids)) {
            $groupids = [];
        }

        // Validate behaviour.
        $validBehaviours = ['deferredfeedback', 'adaptive', 'adaptive_no_penalty', 'interactive', 'interactive_no_certificate'];
        $behaviour = in_array($params['preferred_behaviour'], $validBehaviours) ? $params['preferred_behaviour'] : 'deferredfeedback';

        // Validate grade method.
        $validGradeMethods = [1, 2, 4, 6];
        $gmethod = in_array((int)$params['grade_method'], $validGradeMethods) ? (int)$params['grade_method'] : 1;

        // Validate nav method.
        $navmethod = ($params['nav_method'] === 'sequential') ? 'sequential' : 'free';

        // Convert ISO datetime to Unix timestamp for Moodle.
        $timeOpen = 0;
        if (!empty($params['time_open'])) {
            $timeOpen = (int)strtotime($params['time_open']);
            if ($timeOpen <= 0) $timeOpen = 0;
        }
        $timeClose = 0;
        if (!empty($params['time_close'])) {
            $timeClose = (int)strtotime($params['time_close']);
            if ($timeClose <= 0) $timeClose = 0;
        }

        // Build config_json with all settings.
        $config = json_encode([
            'bloom_level'         => $bloom,
            'question_types'      => $qtypes,
            'total_questions'     => $total,
            'difficulty'          => $diff,
            'marks_per_question'  => $params['marks_per_question'],
            'ai_instructions'     => $params['ai_instructions'],
            'grounding_mode'      => $groundingMode,
            'instruction_presets' => $instrPresets,
            'quiz_description'    => $params['quiz_description'],
            'shuffle_questions'   => (int)$params['shuffle_questions'],
            'shuffle_answers'     => (int)$params['shuffle_answers'],
            'show_feedback'       => (int)$params['show_feedback'],
            'time_limit'          => (int)$params['time_limit'],
            'max_attempts'        => (int)$params['max_attempts'],
            'material_ids'        => $matids,
            // Schedule
            'time_open'           => $timeOpen,
            'time_close'          => $timeClose,
            // Access & Security
            'password'            => $params['password'] ?: '',
            'browser_security'    => (int)$params['browser_security'],
            'groupmode'           => (int)$params['groupmode'],
            'groupingid'          => (int)$params['groupingid'],
            'group_ids'           => $groupids,
            // Placement
            'section_num'         => (int)$params['section_num'],
            'grade_category'      => (int)$params['grade_category'],
            // Advanced
            'preferred_behaviour' => $behaviour,
            'grade_method'        => $gmethod,
            'nav_method'          => $navmethod,
            'questions_per_page'  => (int)$params['questions_per_page'],
            'review_attempt'      => (int)$params['review_attempt'],
            'review_correctness'  => (int)$params['review_correctness'],
            'review_marks'        => (int)$params['review_marks'],
            'review_responses'    => (int)$params['review_responses'],
            'review_feedback'     => (int)$params['review_feedback'],
            'review_overall'      => (int)$params['review_overall'],
        ]);

        $job = (object)[
            'courseid'      => (int)$params['courseid'],
            'userid'        => (int)$USER->id,
            'material_id'   => !empty($matids) ? (int)$matids[0] : null,
            'source_text'   => $params['source_type'] === 'text' ? $params['content'] : null,
            'config_json'   => $config,
            'category_name' => $catname,
            'status'        => 'generating',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ];
        $job->id = $DB->insert_record('umat_ai_quizgen_jobs', $job);

        // Call AI service synchronously.
        try {
            $cfg = get_config('local_umat_ai');
            $payload = [
                'source_type'          => $params['source_type'] === 'material' ? 'material_id' : 'text',
                'content'              => $params['content'],
                'material_ids'         => !empty($matids) ? $matids : null,
                'course_id'            => (int)$params['courseid'],
                'bloom_level'          => $bloom,
                'question_types'       => $qtypes,
                'difficulty'           => $diff,
                'marks_per_question'   => (float)$params['marks_per_question'],
                'ai_instructions'      => $params['ai_instructions'],
                'grounding_mode'       => $groundingMode,
                'instruction_presets'  => $instrPresets,
            ];

            $url = rtrim($cfg->ai_service_url, '/') . '/api/v1/quizgen/generate';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg->ai_service_token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 160,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $detail = $response ? ': ' . mb_substr($response, 0, 300) : '';
                if ($httpCode === 0) {
                    throw new \moodle_exception('Cannot reach AI service at ' . $cfg->ai_service_url . '. Please ensure the AI service is running and try again.');
                }
                throw new \moodle_exception('AI service returned HTTP ' . $httpCode . $detail);
            }

            $result = json_decode($response, true);
            if (!$result || !isset($result['questions'])) {
                throw new \moodle_exception('Invalid AI response: ' . mb_substr($response, 0, 500));
            }

            $questions = $result['questions'];

            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'             => $job->id,
                'status'         => 'processing_xml',
                'questions_json' => json_encode($questions),
                'timemodified'   => time(),
            ]);

            require_once(__DIR__ . '/../quiz/xml_builder.php');
            $xml = \local_umat_ai\quiz\xml_builder::build_moodle_xml($questions, $catname, (float)$params['marks_per_question']);

            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'          => $job->id,
                'status'      => 'completed',
                'xml_content' => $xml,
                'timemodified'=> time(),
            ]);

            return [
                'job_id'        => (int)$job->id,
                'status'        => 'completed',
                'category_name' => $catname,
                'questions'     => json_encode($questions),
            ];
        } catch (\Throwable $e) {
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'             => $job->id,
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
                'timemodified'   => time(),
            ]);

            return [
                'job_id'         => (int)$job->id,
                'status'         => 'failed',
                'category_name'  => $catname,
                'failure_reason' => $e->getMessage(),
                'questions'      => null,
            ];
        }
    }

    public static function generate_quiz_draft_returns() {
        return new \external_single_structure([
            'job_id'         => new \external_value(PARAM_INT, 'Job ID'),
            'status'         => new \external_value(PARAM_ALPHAEXT, 'completed|failed|pending'),
            'category_name'  => new \external_value(PARAM_TEXT, 'Question category / quiz name'),
            'failure_reason' => new \external_value(PARAM_TEXT, 'Error message if failed', VALUE_OPTIONAL),
            'questions'      => new \external_value(PARAM_RAW, 'JSON array of generated questions', VALUE_OPTIONAL),
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

        $canImport = ($job->status === 'completed' && !empty($job->xml_content))
                  || ($job->status === 'importing' && !empty($job->xml_content));

        if (!$canImport) {
            $hint = '';
            if ($job->status === 'failed') {
                $hint = ' The generation failed: ' . ($job->failure_reason ?: 'unknown error') . '. Please go back and generate a new quiz.';
            } elseif (in_array($job->status, ['generating', 'processing_xml'])) {
                $hint = ' The AI is still working. Please wait a moment and try again.';
            }
            throw new \moodle_exception('quizgen_not_ready', 'local_umat_ai', '', null, "Job status: {$job->status}.$hint");
        }
        if (!$job->xml_content) {
            throw new \moodle_exception('quizgen_no_xml', 'local_umat_ai');
        }

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // Parse quiz settings from config_json.
        $config = json_decode($job->config_json, true) ?: [];

        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'           => $job->id,
            'status'       => 'importing',
            'timemodified' => time(),
        ]);

        require_once(__DIR__ . '/../quiz/importer.php');

        try {
            $quizSettings = [
                'intro'              => $config['quiz_description'] ?? '',
                'shufflequestions'   => $config['shuffle_questions'] ?? 0,
                'shuffleanswers'     => $config['shuffle_answers'] ?? 1,
                'showfeedback'       => $config['show_feedback'] ?? 1,
                'timelimit'          => (int)($config['time_limit'] ?? 0) * 60, // minutes -> Moodle seconds (0 = unlimited).
                'attempts'           => $config['max_attempts'] ?? -1,
                // Schedule
                'timeopen'           => $config['time_open'] ?? 0,
                'timeclose'          => $config['time_close'] ?? 0,
                // Access & Security
                'password'           => $config['password'] ?? '',
                'browsersecurity'    => $config['browser_security'] ?? 0,
                'groupmode'          => $config['groupmode'] ?? 0,
                'groupingid'         => $config['groupingid'] ?? 0,
                'groupids'           => $config['group_ids'] ?? [],
                // Placement
                'sectionnum'         => $config['section_num'] ?? 0,
                'grade_category'     => $config['grade_category'] ?? 0,
                // Advanced
                'preferredbehaviour' => $config['preferred_behaviour'] ?? 'deferredfeedback',
                'grademethod'        => $config['grade_method'] ?? 1,
                'navmethod'          => $config['nav_method'] ?? 'free',
                'questionsperpage'   => $config['questions_per_page'] ?? 0,
                'reviewattempt'      => $config['review_attempt'] ?? 1,
                'reviewcorrectness'  => $config['review_correctness'] ?? 1,
                'reviewmarks'        => $config['review_marks'] ?? 1,
                'reviewresponses'    => $config['review_responses'] ?? 1,
                'reviewfeedback'     => $config['review_feedback'] ?? 1,
                'reviewoverall'      => $config['review_overall'] ?? 1,
            ];

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
                    (int)$job->userid,
                    $quizSettings
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

        $limit = 50;
        $sql = "SELECT id, status, category_name, quiz_id, failure_reason, timecreated, timemodified, config_json,
                       LENGTH(questions_json) AS qjson_len
                  FROM {umat_ai_quizgen_jobs}
                 WHERE courseid = :courseid
              ORDER BY timecreated DESC
                 LIMIT :lim";

        $rows = $DB->get_records_sql($sql, ['courseid' => $params['courseid'], 'lim' => $limit]);

        // Resolve course module info (id + visibility) for every created quiz
        // in a single query so the UI can offer Publish/Hide/Delete actions.
        $quizids = array_values(array_filter(array_map(function ($r) {
            return (int)$r->quiz_id;
        }, $rows)));
        $cminfo = [];
        if ($quizids) {
            list($insql, $inparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED);
            $quizmod = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
            $cms = $DB->get_records_sql(
                "SELECT cm.id AS cmid, cm.instance, cm.visible
                   FROM {course_modules} cm
                  WHERE cm.module = :quizmod AND cm.instance $insql",
                array_merge(['quizmod' => $quizmod], $inparams)
            );
            foreach ($cms as $c) {
                $cminfo[(int)$c->instance] = (object)[
                    'cmid'    => (int)$c->cmid,
                    'visible' => (int)$c->visible,
                ];
            }
        }

        $history = [];
        foreach ($rows as $row) {
            $config = json_decode($row->config_json, true) ?: [];
            $quizId = (int)($row->quiz_id ?: 0);
            $cm = $cminfo[$quizId] ?? null;

            $history[] = [
                'job_id'         => (int)$row->id,
                'status'         => $row->status,
                'question_count' => $row->qjson_len > 2 ? max(1, intval($row->qjson_len / 200)) : 0,
                'category_name'  => $row->category_name,
                'quiz_id'        => $quizId,
                'quiz_cmid'      => $cm ? $cm->cmid : 0,
                'visible'        => $cm ? $cm->visible : 0,
                'failure_reason' => $row->failure_reason ?? '',
                'timecreated'    => (int)$row->timecreated,
                'timemodified'   => (int)$row->timemodified,
                'config_summary' => json_encode([
                    'bloom_level'     => $config['bloom_level'] ?? 'understand',
                    'difficulty'      => $config['difficulty'] ?? 'medium',
                    'question_types'  => $config['question_types'] ?? ['multichoice'],
                    'total_questions' => $config['total_questions'] ?? 5,
                ]),
                // Full settings so the UI can prefill the reconfigure dialog
                // without an extra round trip. Must be JSON-encoded — external
                // values reject arrays/objects (strict mode throws
                // "Invalid response value detected").
                'config'         => json_encode($config),
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
                    'quiz_cmid'      => new \external_value(PARAM_INT, 'Course module ID of the quiz (0 if not created)'),
                    'visible'        => new \external_value(PARAM_INT, '1 = quiz visible on course page, 0 = hidden draft'),
                    'failure_reason' => new \external_value(PARAM_TEXT, 'Error message if failed'),
                    'timecreated'    => new \external_value(PARAM_INT, 'Unix timestamp'),
                    'timemodified'   => new \external_value(PARAM_INT, 'Last update timestamp'),
                    'config_summary' => new \external_value(PARAM_RAW, 'JSON summary of generation config'),
                    'config'         => new \external_value(PARAM_RAW, 'JSON object of the full quiz settings for reconfigure prefill'),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // export_quiz_word — generate .docx via AI service and return base64  //
    // ------------------------------------------------------------------ //
    public static function export_quiz_word_parameters() {
        return new \external_function_parameters([
            'questions_json' => new \external_value(PARAM_RAW, 'JSON array of question objects'),
            'export_type'    => new \external_value(PARAM_ALPHAEXT, 'question_paper | answer_key | examiner_copy', VALUE_DEFAULT, 'question_paper'),
            'version'        => new \external_value(PARAM_ALPHA, 'A | B | C', VALUE_DEFAULT, 'A'),
            'doc_settings'   => new \external_value(PARAM_RAW, 'JSON object of document settings', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function export_quiz_word(
        $questions_json,
        $export_type = 'question_paper',
        $version = 'A',
        $doc_settings = '{}'
    ) {
        global $CFG;

        $params = self::validate_parameters(self::export_quiz_word_parameters(), [
            'questions_json' => $questions_json,
            'export_type'    => $export_type,
            'version'        => $version,
            'doc_settings'   => $doc_settings,
        ]);

        $questions = json_decode($params['questions_json'], true);
        if (!is_array($questions) || empty($questions)) {
            throw new \moodle_exception('No questions provided for Word export.');
        }

        $settings = json_decode($params['doc_settings'], true) ?: [];

        $cfg = get_config('local_umat_ai');
        $payload = [
            'questions'    => $questions,
            'export_type'  => $params['export_type'],
            'version'      => $params['version'],
            'doc_settings' => $settings,
        ];

        error_log('[UMaT AI] export_quiz_word called: export_type=' . $params['export_type'] .
            ' version=' . $params['version'] . ' questions=' . count($questions));

        $url = rtrim($cfg->ai_service_url, '/') . '/api/v1/quizgen/export-word';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg->ai_service_token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            $detail = $response ? ': ' . mb_substr($response, 0, 300) : '';
            error_log('[UMaT AI] export_quiz_word failed: HTTP ' . $httpCode . ' curl_error=' . $curlError . ' response=' . $detail);
            if ($httpCode === 0) {
                throw new \moodle_exception('Cannot reach AI service. Please ensure it is running.');
            }
            throw new \moodle_exception('AI service returned HTTP ' . $httpCode . $detail);
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['docx_base64'])) {
            error_log('[UMaT AI] export_quiz_word: invalid response: ' . mb_substr($response, 0, 200));
            throw new \moodle_exception('Invalid response from AI service.');
        }

        error_log('[UMaT AI] export_quiz_word OK: filename=' . ($result['filename'] ?? '?'));
        return [
            'docx_base64'    => $result['docx_base64'],
            'filename'       => $result['filename'] ?? 'assessment.docx',
            'total_marks'    => (float)($result['total_marks'] ?? 0),
            'question_count' => (int)($result['question_count'] ?? 0),
        ];
    }

    public static function export_quiz_word_returns() {
        return new \external_single_structure([
            'docx_base64'    => new \external_value(PARAM_RAW, 'Base64-encoded .docx file content'),
            'filename'       => new \external_value(PARAM_TEXT, 'Suggested filename'),
            'total_marks'    => new \external_value(PARAM_FLOAT, 'Total marks'),
            'question_count' => new \external_value(PARAM_INT, 'Number of questions in document'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // save_quizgen_questions — persist edited questions + rebuild XML     //
    // ------------------------------------------------------------------ //
    public static function save_quizgen_questions_parameters() {
        return new \external_function_parameters([
            'jobid'          => new \external_value(PARAM_INT, 'Job ID'),
            'questions_json' => new \external_value(PARAM_RAW, 'JSON array of edited question objects'),
        ]);
    }

    public static function save_quizgen_questions($jobid, $questions_json) {
        global $DB;

        $params = self::validate_parameters(self::save_quizgen_questions_parameters(), [
            'jobid'          => $jobid,
            'questions_json' => $questions_json,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        if (!in_array($job->status, ['completed', 'importing'])) {
            throw new \moodle_exception('Cannot edit questions in current status: ' . $job->status);
        }

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $questions = json_decode($params['questions_json'], true);
        if (!is_array($questions) || empty($questions)) {
            throw new \moodle_exception('No questions provided.');
        }

        // Rebuild XML from the edited questions.
        $config = json_decode($job->config_json, true) ?: [];
        $marksPerQ = $config['marks_per_question'] ?? 1.0;

        require_once(__DIR__ . '/../quiz/xml_builder.php');
        $xml = \local_umat_ai\quiz\xml_builder::build_moodle_xml($questions, $job->category_name, (float)$marksPerQ);

        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'             => $job->id,
            'questions_json' => json_encode($questions),
            'xml_content'    => $xml,
            'timemodified'   => time(),
        ]);

        return [
            'status'         => 'saved',
            'question_count' => count($questions),
        ];
    }

    public static function save_quizgen_questions_returns() {
        return new \external_single_structure([
            'status'         => new \external_value(PARAM_ALPHAEXT, 'saved on success'),
            'question_count' => new \external_value(PARAM_INT, 'Number of questions saved'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // regenerate_quizgen_question — regenerate a single question via AI   //
    // ------------------------------------------------------------------ //
    public static function regenerate_quizgen_question_parameters() {
        return new \external_function_parameters([
            'jobid'                    => new \external_value(PARAM_INT, 'Job ID'),
            'question_index'           => new \external_value(PARAM_INT, 'Index of the question to regenerate (0-based)'),
            'question_json'            => new \external_value(PARAM_RAW, 'Current question object as JSON'),
            'regeneration_instruction' => new \external_value(PARAM_RAW, 'Additional instruction for regeneration', VALUE_DEFAULT, ''),
        ]);
    }

    public static function regenerate_quizgen_question($jobid, $question_index, $question_json, $regeneration_instruction = '') {
        global $DB;

        $params = self::validate_parameters(self::regenerate_quizgen_question_parameters(), [
            'jobid'                    => $jobid,
            'question_index'           => $question_index,
            'question_json'            => $question_json,
            'regeneration_instruction' => $regeneration_instruction,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        if (!in_array($job->status, ['completed', 'importing'])) {
            throw new \moodle_exception('Cannot regenerate questions in current status: ' . $job->status);
        }

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $currentQuestion = json_decode($params['question_json'], true);
        if (!$currentQuestion) {
            throw new \moodle_exception('Invalid question data.');
        }

        $config = json_decode($job->config_json, true) ?: [];

        // Call AI service to regenerate this single question.
        try {
            $cfg = get_config('local_umat_ai');

            // Build context: use original source text if available, otherwise use the question itself.
            $sourceContext = $job->source_text ?: 'Generate a similar question based on the following question and its properties.';

            // Build instruction-aware regeneration prompt.
            $groundingMode = $config['grounding_mode'] ?? 'applied';
            $instrPresets = $config['instruction_presets'] ?? [];
            $customInstr = $config['ai_instructions'] ?? '';
            $additionalInstr = $params['regeneration_instruction'];

            $prompt = "You are an expert assessment writer at the University of Mines and Technology (UMaT), Ghana.\n";
            $prompt .= "Regenerate the following question to be different but of the same type, difficulty, and Bloom's taxonomy level.\n\n";

            $prompt .= "ORIGINAL QUESTION:\n" . json_encode($currentQuestion, JSON_PRETTY_PRINT) . "\n\n";

            // Grounding mode instructions.
            $prompt .= "GROUNDING MODE: " . strtoupper($groundingMode) . "\n";
            if ($groundingMode === 'strict') {
                $prompt .= "Use ONLY information explicitly stated in the source material. Do not construct new scenarios.\n";
            } elseif ($groundingMode === 'applied') {
                $prompt .= "The tested concept must come from the source material. You MAY construct new realistic scenarios, case studies, and examples that are not in the source. Students must be able to answer correctly by applying concepts taught in the material.\n";
            } else {
                $prompt .= "The tested concept must come from the source material. You MAY use limited, widely accepted external context to make scenarios realistic. Do not use unverifiable facts or make the answer depend on external information.\n";
            }

            // Structured preset instructions.
            if (!empty($instrPresets)) {
                $presetLabels = [
                    'critical_thinking'        => 'Ask critical-thinking questions requiring analysis and evaluation',
                    'application_based'        => 'Use application-based questions testing concept application',
                    'scenario_based'           => 'Create scenario-based questions with realistic situations',
                    'case_study'               => 'Include a short meaningful case study scenario',
                    'real_world_examples'      => 'Use real-world examples and practical applications',
                    'ghanaian_examples'        => 'Use Ghanaian examples and contexts',
                    'industry_examples'        => 'Use industry-specific examples',
                    'problem_solving'          => 'Test problem-solving ability',
                    'comparison_justification' => 'Require comparison and justification',
                    'avoid_direct_recall'      => 'Avoid direct recall or definition questions',
                    'avoid_trick_ambiguous'    => 'Avoid trick or ambiguous questions',
                    'include_calculations'     => 'Include calculations where supported by material',
                    'provide_explanations'     => 'Provide answer explanations',
                ];
                $prompt .= "\nLECTURER INSTRUCTIONS (follow these precisely):\n";
                foreach ($instrPresets as $preset) {
                    if (isset($presetLabels[$preset])) {
                        $prompt .= "- " . $presetLabels[$preset] . "\n";
                    }
                }
            }

            // Custom instructions.
            if (!empty($customInstr)) {
                $prompt .= "\nADDITIONAL LECTURER INSTRUCTIONS:\n" . $customInstr . "\n";
            }

            // Regeneration-specific instruction.
            if (!empty($additionalInstr)) {
                $prompt .= "\nREGENERATION REQUEST:\n" . $additionalInstr . "\n";
            }

            $prompt .= "\nREQUIREMENTS:\n";
            $prompt .= "- Keep the same question type: " . ($currentQuestion['type'] ?? 'multichoice') . "\n";
            $prompt .= "- Keep approximately the same difficulty and Bloom's taxonomy level.\n";
            $prompt .= "- Generate a DIFFERENT question — do not copy the original.\n";
            $prompt .= "- The scenario or context must be fresh and different from the original.\n";
            $prompt .= "- Output ONLY valid JSON matching the original question schema.\n";
            $prompt .= "- No markdown, no code fences, no extra text.\n\n";
            $prompt .= "SOURCE CONTEXT:\n" . mb_substr($sourceContext, 0, 5000);

            $payload = [
                'prompt'       => $prompt,
                'temperature'  => 0.7,
                'max_chars'    => 2000,
            ];

            $url = rtrim($cfg->ai_service_url, '/') . '/api/v1/quizgen/regenerate-single';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg->ai_service_token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                // Fallback: return the original with a note.
                return [
                    'status'          => 'completed',
                    'question'        => $currentQuestion,
                    'failure_reason'  => 'AI service returned HTTP ' . $httpCode . '. Returning original.',
                ];
            }

            $result = json_decode($response, true);
            if (!$result || !isset($result['question'])) {
                return [
                    'status'          => 'completed',
                    'question'        => $currentQuestion,
                    'failure_reason'  => 'Invalid AI response. Returning original.',
                ];
            }

            $newQuestion = $result['question'];
            // Preserve the original marks.
            $newQuestion['marks'] = $currentQuestion['marks'] ?? 1.0;

            return [
                'status'   => 'completed',
                'question' => json_encode($newQuestion),
            ];
        } catch (\Throwable $e) {
            return [
                'status'          => 'completed',
                'question'        => $currentQuestion,
                'failure_reason'  => 'Error: ' . $e->getMessage() . '. Returning original.',
            ];
        }
    }

    public static function regenerate_quizgen_question_returns() {
        return new \external_single_structure([
            'status'         => new \external_value(PARAM_ALPHAEXT, 'completed'),
            'question'       => new \external_value(PARAM_RAW, 'Regenerated question as JSON', VALUE_OPTIONAL),
            'failure_reason' => new \external_value(PARAM_TEXT, 'Error message if regeneration failed', VALUE_OPTIONAL),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_course_quiz_config_data — sections, grade cats, groups, etc.    //
    // ------------------------------------------------------------------ //
    public static function get_course_quiz_config_data_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function get_course_quiz_config_data($courseid) {
        global $DB;

        $params = self::validate_parameters(self::get_course_quiz_config_data_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // 1. Course sections
        $sections = $DB->get_records_sql(
            "SELECT id, section, name, visible
             FROM {course_sections}
             WHERE course = :courseid
             ORDER BY section ASC",
            ['courseid' => $courseid]
        );
        $sectionList = [];
        foreach ($sections as $s) {
            $sectionList[] = [
                'id' => (int)$s->id,
                'section' => (int)$s->section,
                'name' => $s->name ?: ($s->section == 0 ? 'General' : 'Topic ' . $s->section),
                'visible' => (bool)$s->visible,
            ];
        }

        // 2. Grade categories (from grade_items hierarchy)
        // Use grade_categories table
        $gradeCats = $DB->get_records_sql(
            "SELECT gc.id, gc.fullname
             FROM {grade_categories} gc
             WHERE gc.courseid = :courseid
             ORDER BY gc.fullname ASC",
            ['courseid' => $courseid]
        );
        $gradeCatList = [['id' => 0, 'name' => 'No category (default)']];
        foreach ($gradeCats as $gc) {
            $gradeCatList[] = ['id' => (int)$gc->id, 'name' => $gc->fullname];
        }

        // 3. Groups
        $groups = groups_get_all_groups($courseid, 0, '', 'g.id, g.name, g.groupingid');
        $groupList = [];
        foreach ($groups as $g) {
            $groupList[] = [
                'id' => (int)$g->id,
                'name' => $g->name,
                'groupingid' => (int)$g->groupingid,
            ];
        }

        // 4. Groupings
        $groupings = groups_get_all_groupings($courseid);
        $groupingList = [['id' => 0, 'name' => 'None']];
        foreach ($groupings as $g) {
            $groupingList[] = ['id' => (int)$g->id, 'name' => $g->name];
        }

        return [
            'sections' => $sectionList,
            'grade_categories' => $gradeCatList,
            'groups' => $groupList,
            'groupings' => $groupingList,
        ];
    }

    public static function get_course_quiz_config_data_returns() {
        return new \external_single_structure([
            'sections' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Section ID'),
                'section' => new \external_value(PARAM_INT, 'Section number'),
                'name' => new \external_value(PARAM_TEXT, 'Section name'),
                'visible' => new \external_value(PARAM_BOOL, 'Whether visible'),
            ])),
            'grade_categories' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Category ID'),
                'name' => new \external_value(PARAM_TEXT, 'Category name'),
            ])),
            'groups' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Group ID'),
                'name' => new \external_value(PARAM_TEXT, 'Group name'),
                'groupingid' => new \external_value(PARAM_INT, 'Parent grouping ID'),
            ])),
            'groupings' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Grouping ID'),
                'name' => new \external_value(PARAM_TEXT, 'Grouping name'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------ //
    // set_quiz_visible — publish (show) or unpublish (hide) a quiz on     //
    // the course page. No external config needed: the plugin controls     //
    // visibility directly via the standard Moodle API.                    //
    // ------------------------------------------------------------------ //
    public static function set_quiz_visible_parameters() {
        return new \external_function_parameters([
            'jobid'   => new \external_value(PARAM_INT, 'Quiz generation job ID'),
            'visible' => new \external_value(PARAM_INT, '1 = publish to course page, 0 = hide (draft)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function set_quiz_visible($jobid, $visible = 1) {
        global $DB;

        $params = self::validate_parameters(self::set_quiz_visible_parameters(), [
            'jobid'   => $jobid,
            'visible' => $visible,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        if (!$job->quiz_id) {
            throw new \moodle_exception('quizgen_not_created', 'local_umat_ai', '', null,
                "Job {$job->id} has no quiz activity yet.");
        }
        if ($job->status === 'deleted') {
            throw new \moodle_exception('quizgen_already_deleted', 'local_umat_ai');
        }

        $quizmod = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $cm = $DB->get_record('course_modules', [
            'module'   => $quizmod,
            'instance' => $job->quiz_id,
        ], '*', MUST_EXIST);

        // Standard Moodle visibility toggle — fires course_module_updated events,
        // updates visible/visibleold and refreshes caches.
        set_coursemodule_visible($cm->id, (int)$params['visible']);

        // Track state on the job so history can show Draft/Published status.
        $newstatus = (int)$params['visible'] === 1 ? 'published' : 'imported';
        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'           => $job->id,
            'status'       => $newstatus,
            'timemodified' => time(),
        ]);

        return [
            'status'    => $newstatus,
            'quiz_id'   => (int)$job->quiz_id,
            'quiz_cmid' => (int)$cm->id,
            'visible'   => (int)$params['visible'],
        ];
    }

    public static function set_quiz_visible_returns() {
        return new \external_single_structure([
            'status'    => new \external_value(PARAM_ALPHAEXT, 'published or imported (draft)'),
            'quiz_id'   => new \external_value(PARAM_INT, 'Quiz activity ID'),
            'quiz_cmid' => new \external_value(PARAM_INT, 'Course module ID of the quiz'),
            'visible'   => new \external_value(PARAM_INT, '1 = visible, 0 = hidden'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // delete_quiz — remove a draft or published quiz created by the       //
    // plugin. Uses the standard mod_quiz deletion API so all quiz data    //
    // (slots, attempts, grade items, course module) is cleaned up.        //
    // Questions stay in the course question bank, matching Moodle's       //
    // standard behaviour when deleting any quiz.                          //
    // ------------------------------------------------------------------ //
    public static function delete_quiz_parameters() {
        return new \external_function_parameters([
            'jobid' => new \external_value(PARAM_INT, 'Quiz generation job ID'),
        ]);
    }

    public static function delete_quiz($jobid) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::delete_quiz_parameters(), [
            'jobid' => $jobid,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        if (!$job->quiz_id) {
            throw new \moodle_exception('quizgen_not_created', 'local_umat_ai', '', null,
                "Job {$job->id} has no quiz activity to delete.");
        }
        if ($job->status === 'deleted') {
            throw new \moodle_exception('quizgen_already_deleted', 'local_umat_ai');
        }

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        quiz_delete_instance($job->quiz_id);

        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'           => $job->id,
            'status'       => 'deleted',
            'quiz_id'      => null,
            'timemodified' => time(),
        ]);

        return [
            'status'  => 'deleted',
            'job_id'  => (int)$job->id,
            'message' => get_string('quizgen_deleted', 'local_umat_ai'),
        ];
    }

    public static function delete_quiz_returns() {
        return new \external_single_structure([
            'status'  => new \external_value(PARAM_ALPHAEXT, 'deleted on success'),
            'job_id'  => new \external_value(PARAM_INT, 'Quiz generation job ID'),
            'message' => new \external_value(PARAM_TEXT, 'Human-readable confirmation'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // update_quiz_settings — reconfigure a generated quiz after creation. //
    // Accepts a JSON object of settings (same key names as config_json).  //
    // The merged config is saved on the job, and when a quiz activity     //
    // exists the values are pushed into the mdl_quiz record — the same    //
    // fields the importer sets at creation time, so the live quiz and the //
    // stored job config never drift apart.                                //
    // ------------------------------------------------------------------ //
    public static function update_quiz_settings_parameters() {
        return new \external_function_parameters([
            'jobid'    => new \external_value(PARAM_INT, 'Quiz generation job ID'),
            'settings' => new \external_value(PARAM_RAW, 'JSON object of settings to change'),
        ]);
    }

    public static function update_quiz_settings($jobid, $settings = '{}') {
        global $DB;

        $params = self::validate_parameters(self::update_quiz_settings_parameters(), [
            'jobid'    => $jobid,
            'settings' => $settings,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        if ($job->status === 'deleted') {
            throw new \moodle_exception('quizgen_already_deleted', 'local_umat_ai');
        }

        $incoming = json_decode($params['settings'], true);
        if (!is_array($incoming) || empty($incoming)) {
            throw new \moodle_exception('quizgen_invalid_settings', 'local_umat_ai');
        }

        // Only these settings can be reconfigured after creation (same names
        // as stored in config_json). Everything else is generation-time only.
        $whitelist = [
            'time_limit', 'max_attempts', 'time_open', 'time_close', 'password',
            'browser_security', 'shuffle_questions', 'shuffle_answers', 'show_feedback',
            'questions_per_page', 'preferred_behaviour', 'grade_method', 'nav_method',
            'review_attempt', 'review_correctness', 'review_marks', 'review_responses',
            'review_feedback', 'review_overall',
        ];

        $config = json_decode($job->config_json, true) ?: [];
        $changed = [];
        foreach ($whitelist as $key) {
            if (array_key_exists($key, $incoming)) {
                $config[$key] = $incoming[$key];
                $changed[] = $key;
            }
        }
        if (empty($changed)) {
            throw new \moodle_exception('quizgen_invalid_settings', 'local_umat_ai');
        }

        // Persist the merged config on the job (drives later finalize/import).
        $DB->update_record('umat_ai_quizgen_jobs', (object)[
            'id'           => $job->id,
            'config_json'  => json_encode($config),
            'timemodified' => time(),
        ]);

        $quizupdated = false;
        if (!empty($job->quiz_id)) {
            $quiz = $DB->get_record('quiz', ['id' => $job->quiz_id], '*', MUST_EXIST);
            $upd = (object)['id' => $quiz->id, 'timemodified' => time()];

            if (array_key_exists('time_limit', $incoming)) {
                $upd->timelimit = (int)$config['time_limit'] * 60; // minutes -> seconds (0 = unlimited).
            }
            if (array_key_exists('max_attempts', $incoming)) {
                $upd->attempts = (int)$config['max_attempts'];
            }
            if (array_key_exists('time_open', $incoming)) {
                $upd->timeopen = (int)$config['time_open'];
            }
            if (array_key_exists('time_close', $incoming)) {
                $upd->timeclose = (int)$config['time_close'];
            }
            if (array_key_exists('password', $incoming)) {
                $upd->password = (string)$config['password'];
            }
            if (array_key_exists('browser_security', $incoming)) {
                $browserMap = [0 => '', 1 => 'securewindow', 2 => 'securewindowandcmid'];
                $upd->browsersecurity = $browserMap[(int)$config['browser_security']] ?? '';
            }
            if (array_key_exists('shuffle_questions', $incoming)) {
                // Moodle 5: shufflequestions lives on quiz_sections, not mdl_quiz.
                $DB->set_field_select('quiz_sections', 'shufflequestions', (int)$config['shuffle_questions'], 'quizid = ?', [$quiz->id]);
            }
            if (array_key_exists('shuffle_answers', $incoming)) {
                $upd->shuffleanswers = (int)$config['shuffle_answers'];
            }
            if (array_key_exists('questions_per_page', $incoming)) {
                // Pagination is stored on mdl_quiz.questionsperpage (quiz_sections
                // has no such column in Moodle 5).
                $upd->questionsperpage = (int)$config['questions_per_page'];
            }
            if (array_key_exists('preferred_behaviour', $incoming)) {
                $upd->preferredbehaviour = $config['preferred_behaviour'];
            }
            if (array_key_exists('grade_method', $incoming)) {
                $upd->grademethod = (int)$config['grade_method'];
            }
            if (array_key_exists('nav_method', $incoming)) {
                $upd->navmethod = $config['nav_method'] === 'sequential' ? 'sequential' : 'free';
            }

            // Review options are stored as bitfields on mdl_quiz. When any
            // review_* flag changes, recompute them all (same mapping the
            // importer uses at creation time) to keep them consistent.
            // NOTE: 'review responses' (reviewresponses) column was removed
            // in Moodle 5 — the setting is kept in config_json but cannot be
            // written to the quiz record anymore.
            $reviewKeys = [
                'review_attempt'     => 'reviewattempt',
                'review_correctness' => 'reviewcorrectness',
                'review_marks'       => 'reviewmarks',
                'review_feedback'    => 'reviewfeedback',
                'review_overall'     => 'reviewoverall',
            ];
            if (array_intersect(array_keys($reviewKeys), $changed)) {
                $during = \mod_quiz\question\display_options::DURING;
                $immediately = \mod_quiz\question\display_options::IMMEDIATELY_AFTER;
                $open = \mod_quiz\question\display_options::LATER_WHILE_OPEN;
                $closed = \mod_quiz\question\display_options::AFTER_CLOSE;
                $allphases = $during | $immediately | $open | $closed;
                $allbutduring = $immediately | $open | $closed;

                $rAttempt   = (int)($config['review_attempt'] ?? 1) ? $allphases : 0;
                $rCorrect   = (int)($config['review_correctness'] ?? 1) ? $allphases : 0;
                $rMarks     = (int)($config['review_marks'] ?? 1) ? $allphases : 0;
                $rFeedback  = (int)($config['review_feedback'] ?? 1) ? $allphases : 0;
                $rOverall   = (int)($config['review_overall'] ?? 1) ? $allbutduring : 0;

                $upd->reviewattempt          = $rAttempt;
                $upd->reviewcorrectness      = $rCorrect;
                $upd->reviewmarks            = $rMarks;
                $upd->reviewspecificfeedback = $rFeedback;
                $upd->reviewgeneralfeedback  = $rFeedback;
                $upd->reviewrightanswer      = $rCorrect;
                $upd->reviewoverallfeedback  = $rOverall;
            }

            $DB->update_record('quiz', $upd);
            $quizupdated = true;
        }

        return [
            'status'       => 'updated',
            'job_id'       => (int)$job->id,
            'quiz_id'      => (int)($job->quiz_id ?: 0),
            'quiz_updated' => $quizupdated ? 1 : 0,
            'updated_keys' => implode(',', $changed),
            'config'       => json_encode($config),
        ];
    }

    public static function update_quiz_settings_returns() {
        return new \external_single_structure([
            'status'       => new \external_value(PARAM_ALPHAEXT, 'updated on success'),
            'job_id'       => new \external_value(PARAM_INT, 'Quiz generation job ID'),
            'quiz_id'      => new \external_value(PARAM_INT, 'Quiz activity ID (0 if not created yet)'),
            'quiz_updated' => new \external_value(PARAM_INT, '1 = live quiz updated, 0 = settings saved for later import only'),
            'updated_keys' => new \external_value(PARAM_TEXT, 'Comma-separated list of changed settings'),
            'config'       => new \external_value(PARAM_RAW, 'Updated full settings object (JSON)'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // reopen_quiz — make a generated quiz available to students again.    //
    // Clears an expired close date and/or a future open date so the quiz  //
    // is available immediately, and re-shows it on the course page if it  //
    // was hidden. Existing finished attempts are kept (lecturers can      //
    // remove individual attempts from the standard quiz reports if        //
    // needed).                                                            //
    // ------------------------------------------------------------------ //
    public static function reopen_quiz_parameters() {
        return new \external_function_parameters([
            'jobid' => new \external_value(PARAM_INT, 'Quiz generation job ID'),
        ]);
    }

    public static function reopen_quiz($jobid) {
        global $DB;

        $params = self::validate_parameters(self::reopen_quiz_parameters(), [
            'jobid' => $jobid,
        ]);

        $job = $DB->get_record('umat_ai_quizgen_jobs', ['id' => $params['jobid']], '*', MUST_EXIST);

        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        if (!$job->quiz_id) {
            throw new \moodle_exception('quizgen_not_created', 'local_umat_ai', '', null,
                "Job {$job->id} has no quiz activity yet.");
        }
        if ($job->status === 'deleted') {
            throw new \moodle_exception('quizgen_already_deleted', 'local_umat_ai');
        }

        $quiz = $DB->get_record('quiz', ['id' => $job->quiz_id], '*', MUST_EXIST);
        $now = time();
        $changed = [];

        // 1) Clear a close date that has already passed.
        $upd = (object)['id' => $quiz->id, 'timemodified' => $now];
        if ($quiz->timeclose > 0 && $quiz->timeclose < $now) {
            $upd->timeclose = 0;
            $changed[] = 'timeclose';
        }
        // 2) Clear a future open date so the quiz is available right now.
        if ($quiz->timeopen > 0 && $quiz->timeopen > $now) {
            $upd->timeopen = 0;
            $changed[] = 'timeopen';
        }
        if ($changed) {
            $DB->update_record('quiz', $upd);
        }

        // 3) Make sure the quiz is visible on the course page.
        $quizmod = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $cm = $DB->get_record('course_modules', [
            'module'   => $quizmod,
            'instance' => $quiz->id,
        ], '*', MUST_EXIST);
        if (!$cm->visible) {
            set_coursemodule_visible($cm->id, 1);
            $cm->visible = 1;
            $changed[] = 'visible';
        }

        // Mirror schedule changes into config_json so the stored job config
        // stays in sync with the live quiz (used by later re-finalize).
        if ($changed) {
            $config = json_decode($job->config_json, true) ?: [];
            if (in_array('timeclose', $changed, true)) {
                $config['time_close'] = 0;
            }
            if (in_array('timeopen', $changed, true)) {
                $config['time_open'] = 0;
            }
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'           => $job->id,
                'status'       => 'published',
                'config_json'  => json_encode($config),
                'timemodified' => $now,
            ]);
        } else {
            $DB->update_record('umat_ai_quizgen_jobs', (object)[
                'id'           => $job->id,
                'status'       => 'published',
                'timemodified' => $now,
            ]);
        }

        return [
            'status'  => 'reopened',
            'job_id'  => (int)$job->id,
            'quiz_id' => (int)$quiz->id,
            'visible' => (int)$cm->visible,
            'changes' => implode(',', $changed ?: ['none']),
        ];
    }

    public static function reopen_quiz_returns() {
        return new \external_single_structure([
            'status'  => new \external_value(PARAM_ALPHAEXT, 'reopened on success'),
            'job_id'  => new \external_value(PARAM_INT, 'Quiz generation job ID'),
            'quiz_id' => new \external_value(PARAM_INT, 'Quiz activity ID'),
            'visible' => new \external_value(PARAM_INT, '1 = visible on course page'),
            'changes' => new \external_value(PARAM_TEXT, 'Comma-separated list of what was changed (none = already open)'),
        ]);
    }
}
