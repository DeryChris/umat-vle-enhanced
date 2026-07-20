<?php
/**
 * Imports AI-generated Moodle XML into a question category and creates a quiz activity.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\quiz;

defined('MOODLE_INTERNAL') || die();

global $CFG;
if (!is_object($CFG)) {
    $CFG = new \stdClass();
}
if (empty($CFG->dirroot)) {
    $CFG->dirroot = dirname(dirname(dirname(dirname(__DIR__))));
}

require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/lib/questionlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/course/lib.php');

class importer {

    private static function write_temp_xml(string $xml, int $jobid, string $suffix = ''): string {
        $tmpfile = sys_get_temp_dir() . '/quizgen_job_' . $jobid . $suffix . '_' . time() . '.xml';
        file_put_contents($tmpfile, $xml);
        return $tmpfile;
    }

    private static function run_xml_import(
        string $xml,
        \stdClass $cat,
        array $contexts,
        int $courseid,
        int $jobid,
        string $suffix = ''
    ): array {
        global $DB;
        $tmpfile = self::write_temp_xml($xml, $jobid, $suffix);

        ob_start();
        try {
            $qformat = new \qformat_xml();
            $qformat->setCategory($cat);
            $qformat->setContexts($contexts);
            $qformat->setCourse($courseid);
            $qformat->setFilename($tmpfile);
            $qformat->setRealfilename('quizgen_job_' . $jobid . $suffix . '.xml');

            if (!$qformat->importpreprocess()) {
                throw new \moodle_exception('quizgen_import_preprocess_failed', 'local_umat_ai');
            }

            $ok = $qformat->importprocess();
            if (!$ok) {
                $errors = $qformat->get_errors();
                ob_end_clean();
                throw new \moodle_exception(
                    'quizgen_import_process_failed',
                    'local_umat_ai',
                    '',
                    null,
                    implode("\n", $errors ?? [])
                );
            }

            $qformat->importpostprocess();
        } finally {
            ob_end_clean();
            if (file_exists($tmpfile)) {
                @unlink($tmpfile);
            }
        }

        $ref = new \ReflectionProperty($qformat, 'questionids');
        $ref->setAccessible(true);
        $qids = $ref->getValue($qformat) ?? [];
        if (!empty($qids)) {
            return $qids;
        }

        sleep(1);
        $records = $DB->get_records('question', ['category' => $cat->id, 'deleted' => 0], 'id ASC', 'id');
        return array_keys($records);
    }

    /**
     * Import XML content into a question category, then create a quiz activity.
     *
     * @param int    $jobid          The quizgen_jobs row id.
     * @param string $xml            The Moodle XML string to import.
     * @param int    $courseid       Target course.
     * @param string $categoryname   Name for the question category.
     * @param int    $userid         The lecturer who requested it.
     * @param array  $quizSettings   Optional quiz settings (intro, shufflequestions, etc.).
     * @return \stdClass With fields: category_id, question_ids[], quiz_id, quiz_cmid.
     */
    public static function import_questions_and_create_quiz(
        int $jobid,
        string $xml,
        int $courseid,
        string $categoryname,
        int $userid,
        array $quizSettings = []
    ): \stdClass {
        global $DB;

        $result = (object)[
            'category_id'  => 0,
            'question_ids' => [],
            'quiz_id'      => 0,
            'quiz_cmid'    => 0,
        ];

        $intro = $quizSettings['intro'] ?? '';
        if (empty($intro)) {
            $intro = get_string('quizgen_auto_intro', 'local_umat_ai');
        }

        $quiz = (object)[
            'course'             => $courseid,
            'name'               => $categoryname,
            'intro'              => $intro,
            'introformat'        => \FORMAT_HTML,
            'timeopen'           => 0,
            'timeclose'          => 0,
            'timelimit'          => $quizSettings['timelimit'] ?? 0,
            'preferredbehaviour' => 'deferredfeedback',
            'attempts'           => $quizSettings['attempts'] ?? -1,
            'grademethod'        => 1,
            'decimalpoints'      => 2,
            'questionsperpage'   => 0,
            'shufflequestions'   => $quizSettings['shufflequestions'] ?? 0,
            'shuffleanswers'     => $quizSettings['shuffleanswers'] ?? 1,
            'sumgrades'          => 0,
            'grade'              => 100,
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $quiz->id = $DB->insert_record('quiz', $quiz);

        $cm = new \stdClass();
        $cm->course   = $courseid;
        $cm->module   = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        $cm->instance = $quiz->id;
        $cm->section  = 0;
        $cm->visible  = 0;
        $cm->visibleold = 0;
        $cm->added    = time();
        $cm->id = $DB->insert_record('course_modules', $cm);

        course_add_cm_to_section($courseid, $cm->id, 0);

        $result->quiz_cmid = (int)$cm->id;
        $result->quiz_id   = (int)$quiz->id;

        $context = \context_module::instance($cm->id);

        $cat = (object)[
            'name'        => $categoryname,
            'contextid'   => $context->id,
            'info'        => 'Questions generated by AI (job: ' . $jobid . ')',
            'infoformat'  => \FORMAT_HTML,
            'stamp'       => make_unique_id_code(),
            'parent'      => 0,
            'sortorder'   => 999,
        ];
        $cat->id = $DB->insert_record('question_categories', $cat);
        $result->category_id = (int)$cat->id;

        $result->question_ids = self::run_xml_import($xml, $cat, [$context], $courseid, $jobid);

        $quizObj = (object)[
            'id'    => $quiz->id,
            'cmid'  => $cm->id,
            'course' => $courseid,
            'questionsperpage' => 0,
        ];
        foreach ($result->question_ids as $qid) {
            quiz_add_quiz_question($qid, $quizObj, 0, null);
        }

        $gradeCalculator = \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator();
        $gradeCalculator->recompute_quiz_sumgrades();

        rebuild_course_cache($courseid, true);

        return $result;
    }

    /**
     * Append new questions from AI-generated XML into an existing quiz.
     */
    public static function append_to_existing_quiz(
        int $jobid,
        string $xml,
        int $existingQuizId,
        string $categoryname
    ): \stdClass {
        global $DB;

        $result = (object)[
            'category_id'  => 0,
            'question_ids' => [],
            'quiz_id'      => $existingQuizId,
            'quiz_cmid'    => 0,
        ];

        $quiz = $DB->get_record('quiz', ['id' => $existingQuizId], '*', MUST_EXIST);
        $courseid = (int)$quiz->course;

        $cmRecord = $DB->get_record('course_modules', [
            'instance' => $existingQuizId,
            'module'   => $DB->get_field('modules', 'id', ['name' => 'quiz']),
        ]);
        $context = $cmRecord
            ? \context_module::instance($cmRecord->id)
            : \context_course::instance($courseid);

        $existingSlot = $DB->get_record_sql(
            'SELECT qs.id AS slotid, qr.questionbankentryid
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = \'mod_quiz\' AND qr.questionarea = \'slot\'
              WHERE qs.quizid = ? ORDER BY qs.slot ASC LIMIT 1',
            [$existingQuizId]
        );

        $cat = null;
        if ($existingSlot && $existingSlot->questionbankentryid) {
            $ver = $DB->get_record_sql(
                'SELECT qv.questionid FROM {question_versions} qv
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qv.questionbankentryid = ? ORDER BY qv.version DESC LIMIT 1',
                [$existingSlot->questionbankentryid]
            );
            if ($ver) {
                $catId = $DB->get_field('question', 'category', ['id' => $ver->questionid]);
                if ($catId) {
                    $cat = $DB->get_record('question_categories', ['id' => $catId]);
                }
            }
        }

        if (!$cat) {
            $cat = (object)[
                'name'        => $categoryname,
                'contextid'   => $context->id,
                'info'        => 'Questions generated by AI (job: ' . $jobid . ')',
                'infoformat'  => \FORMAT_HTML,
                'stamp'       => make_unique_id_code(),
                'parent'      => 0,
                'sortorder'   => 999,
            ];
            $cat->id = $DB->insert_record('question_categories', $cat);
        }
        $result->category_id = (int)$cat->id;

        $result->question_ids = self::run_xml_import($xml, $cat, [$context], $courseid, $jobid, '_append');

        $quizObj = (object)[
            'id'    => $existingQuizId,
            'cmid'  => $cmRecord ? $cmRecord->id : 0,
            'course' => $courseid,
            'questionsperpage' => 0,
        ];

        foreach ($result->question_ids as $qid) {
            quiz_add_quiz_question($qid, $quizObj, 0, null);
        }

        $gradeCalculator = \mod_quiz\quiz_settings::create($existingQuizId)->get_grade_calculator();
        $gradeCalculator->recompute_quiz_sumgrades();

        $result->quiz_cmid = $cmRecord ? (int)$cmRecord->id : 0;

        rebuild_course_cache($courseid, true);

        return $result;
    }
}
