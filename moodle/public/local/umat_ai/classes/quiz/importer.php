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
        global $DB, $CFG;
        $tmpfile = self::write_temp_xml($xml, $jobid, $suffix);
        $logfile = $CFG->dataroot . '/quizgen_import.log';

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
                @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "IMPORT FAILED job=$jobid errors=" . implode('; ', $errors ?? []) . "\n", FILE_APPEND);
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

        $qids = [];
        $ref = new \ReflectionProperty($qformat, 'questionids');
        $ref->setAccessible(true);
        $qids = $ref->getValue($qformat) ?? [];

        @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "IMPORT job=$jobid category_id={$cat->id} context_id={$contexts[0]->id} reflection_qids=" . count($qids) . " ids=" . implode(',', array_slice($qids, 0, 10)) . "\n", FILE_APPEND);

        if (!empty($qids)) {
            return $qids;
        }

        sleep(1);

        $records = $DB->get_records('question', ['category' => $cat->id, 'deleted' => 0], 'id ASC', 'id');
        $fallbackIds = array_keys($records);

        @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "FALLBACK job=$jobid category_id={$cat->id} fallback_count=" . count($fallbackIds) . " ids=" . implode(',', array_slice($fallbackIds, 0, 10)) . "\n", FILE_APPEND);

        return $fallbackIds;
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
        global $DB, $CFG;

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

        // Map browser security level to Moodle's browsersecurity field.
        $browserSecurityMap = [0 => '', 1 => 'securewindow', 2 => 'securewindowandcmid'];
        $browserSec = $browserSecurityMap[(int)($quizSettings['browsersecurity'] ?? 0)] ?? '';

        // Build review options array from individual flags.
        $reviewOptions = self::build_review_options($quizSettings);

        $quiz = (object)[
            'course'             => $courseid,
            'name'               => $categoryname,
            'intro'              => $intro,
            'introformat'        => \FORMAT_HTML,
            'timeopen'           => (int)($quizSettings['timeopen'] ?? 0),
            'timeclose'          => (int)($quizSettings['timeclose'] ?? 0),
            'timelimit'          => (int)($quizSettings['timelimit'] ?? 0),
'preferredbehaviour' => $quizSettings['preferredbehaviour'] ?? 'deferredfeedback',
            'canredoquestions'   => 0,
            'attempts'           => (int)($quizSettings['attempts'] ?? -1),
            'attemptonlast'      => 0,
            'grademethod'        => (int)($quizSettings['grademethod'] ?? 1),
            'decimalpoints'      => 2,
            'questiondecimalpoints' => 2,
            'reviewattempt'      => $reviewOptions['reviewattempt'],
            'reviewcorrectness'  => $reviewOptions['reviewcorrectness'],
            'reviewmarks'        => $reviewOptions['reviewmarks'],
            'reviewspecificfeedback' => $reviewOptions['reviewspecificfeedback'],
            'reviewgeneralfeedback'  => $reviewOptions['reviewgeneralfeedback'],
            'reviewrightanswer'      => $reviewOptions['reviewrightanswer'],
            'reviewoverallfeedback'  => $reviewOptions['reviewoverallfeedback'],
            'questionsperpage'   => (int)($quizSettings['questionsperpage'] ?? 0),
            'navmethod'          => $quizSettings['navmethod'] ?? 'free',
            'shuffleanswers'     => (int)($quizSettings['shuffleanswers'] ?? 1),
            'sumgrades'          => 0,
            'grade'              => 100,
            'password'           => $quizSettings['password'] ?? '',
            'subnet'             => '',
            'browsersecurity'    => $browserSec,
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $quiz->id = $DB->insert_record('quiz', $quiz);

        $DB->insert_record('quiz_sections', [
            'quizid'           => $quiz->id,
            'firstslot'        => 1,
            'heading'          => '',
            // Moodle 5: shufflequestions moved from mdl_quiz to quiz_sections.
            'shufflequestions' => (int)($quizSettings['shufflequestions'] ?? 0),
        ]);

        $cm = new \stdClass();
        $cm->course   = $courseid;
        $cm->module   = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        $cm->instance = $quiz->id;
        $cm->section  = (int)($quizSettings['sectionnum'] ?? 0);
        // The quiz is created as a hidden draft by design. The lecturer
        // publishes it (set_coursemodule_visible) from the plugin UI —
        // no external Moodle configuration is required.
        $cm->visible  = 0;
        $cm->visibleold = 0;
        $cm->groupmode    = (int)($quizSettings['groupmode'] ?? 0);
        $cm->groupingid   = (int)($quizSettings['groupingid'] ?? 0);
        $cm->added    = time();

        // Enforce group access via the standard availability system so only
        // members of the selected groups can see/attempt the quiz. The JSON
        // format matches what the standard module form produces; validate it
        // the same way add_moduleinfo() does, falling back to no restriction.
        $groupids = $quizSettings['groupids'] ?? [];
        if (!empty($groupids) && is_array($groupids)) {
            $availabilityJson = json_encode([
                'op'   => '|',
                'show' => false,
                'c'    => array_map(function ($gid) {
                    return ['type' => 'group', 'id' => (int)$gid];
                }, $groupids),
            ]);
            if (!empty($CFG->enableavailability)) {
                try {
                    $tree = new \core_availability\tree(json_decode($availabilityJson));
                    $cm->availability = $availabilityJson;
                } catch (\Throwable $e) {
                    $cm->availability = null;
                }
            }
        }

        $cm->id = $DB->insert_record('course_modules', $cm);

        course_add_cm_to_section($courseid, $cm->id, (int)($quizSettings['sectionnum'] ?? 0));

        // Apply grade category.
        $gradeCategoryId = (int)($quizSettings['grade_category'] ?? 0);
        if ($gradeCategoryId > 0) {
            // Find the grade_item for this quiz and move it to the target category.
            $gradeItem = $DB->get_record('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id,
                'courseid' => $courseid,
            ]);
            if ($gradeItem) {
                $DB->set_field('grade_items', 'categoryid', $gradeCategoryId, ['id' => $gradeItem->id]);
            }
        }

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

        global $CFG;
        $logfile = $CFG->dataroot . '/quizgen_import.log';
        @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "ADD_TO_QUIZ job=$jobid quiz_id={$quiz->id} cmid={$cm->id} question_ids=" . count($result->question_ids) . "\n", FILE_APPEND);

        $quizObj = (object)[
            'id'    => $quiz->id,
            'cmid'  => $cm->id,
            'course' => $courseid,
            'questionsperpage' => (int)($quizSettings['questionsperpage'] ?? 0),
        ];
        $added = 0;
        foreach ($result->question_ids as $qid) {
            try {
                quiz_add_quiz_question($qid, $quizObj, 0, null);
                $added++;
            } catch (\Throwable $e) {
                @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "ADD_FAILED job=$jobid quiz_id={$quiz->id} qid=$qid error=" . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . "ADDED job=$jobid quiz_id={$quiz->id} added=$added/" . count($result->question_ids) . "\n", FILE_APPEND);

        if ($added === 0 && !empty($result->question_ids)) {
            throw new \moodle_exception(
                'quizgen_add_questions_failed', 'local_umat_ai', '',
                null, 'Failed to add any questions to the quiz. Check quizgen_import.log for details.'
            );
        }

        $gradeCalculator = \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator();
        $gradeCalculator->recompute_quiz_sumgrades();

        rebuild_course_cache($courseid, true);

        return $result;
    }

    /**
     * Build Moodle review option bitfields from individual settings.
     *
     * Moodle stores each review option (attempt, correctness, marks, ...) as a
     * bitfield of phase constants:
     *   \mod_quiz\question\display_options::DURING / IMMEDIATELY_AFTER /
     *   LATER_WHILE_OPEN / AFTER_CLOSE
     * The plugin UI offers a simple on/off per option, so an enabled option is
     * applied to every phase (the same result as ticking every phase in the
     * standard quiz settings form). Overall feedback is excluded from the
     * "during attempt" phase, matching the standard form's behaviour.
     */
    private static function build_review_options(array $settings): array {
        $during = \mod_quiz\question\display_options::DURING;
        $immediately = \mod_quiz\question\display_options::IMMEDIATELY_AFTER;
        $open = \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        $closed = \mod_quiz\question\display_options::AFTER_CLOSE;
        $allphases = $during | $immediately | $open | $closed;
        $allbutduring = $immediately | $open | $closed;

        $rAttempt   = (int)($settings['reviewattempt'] ?? 1) ? $allphases : 0;
        $rCorrect   = (int)($settings['reviewcorrectness'] ?? 1) ? $allphases : 0;
        $rMarks     = (int)($settings['reviewmarks'] ?? 1) ? $allphases : 0;
        // NOTE: 'review responses' (reviewresponses) column was removed in
        // Moodle 5 — the option is intentionally not written anymore.
        $rFeedback  = (int)($settings['reviewfeedback'] ?? 1) ? $allphases : 0;
        $rOverall   = (int)($settings['reviewoverall'] ?? 1) ? $allbutduring : 0;

        return [
            'reviewattempt'         => $rAttempt,
            'reviewcorrectness'     => $rCorrect,
            'reviewmarks'           => $rMarks,
            'reviewspecificfeedback'=> $rFeedback,
            'reviewgeneralfeedback' => $rFeedback,
            'reviewrightanswer'     => $rCorrect,
            'reviewoverallfeedback' => $rOverall,
        ];
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
            try {
                quiz_add_quiz_question($qid, $quizObj, 0, null);
            } catch (\Throwable $e) {
                @file_put_contents(
                    $CFG->dataroot . '/quizgen_import.log',
                    date('[Y-m-d H:i:s] ') . "APPEND_FAILED job=$jobid quiz_id=$existingQuizId qid=$qid error=" . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }
        }

        $gradeCalculator = \mod_quiz\quiz_settings::create($existingQuizId)->get_grade_calculator();
        $gradeCalculator->recompute_quiz_sumgrades();

        $result->quiz_cmid = $cmRecord ? (int)$cmRecord->id : 0;

        rebuild_course_cache($courseid, true);

        return $result;
    }
}
