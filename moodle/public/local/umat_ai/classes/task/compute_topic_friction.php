<?php
/**
 * Scheduled task: compute per-topic friction scores from chat logs.
 *
 * Groups questions by keyword-matched topic, computes friction_score
 * from volume and estimated student competency, and stores results
 * in umat_ai_topic_friction for the lecturer dashboard.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class compute_topic_friction extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_compute_topic_friction', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        $since = time() - DAYSECS;
        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {umat_ai_chat_logs} WHERE timecreated > :since",
            ['since' => $since]
        );

        if (empty($courses)) {
            return;
        }

        // Fallback keyword dictionary for courses with no section names.
        $fallbackKeywords = [
            'referenc'      => 'Referencing & Citations',
            'citation'      => 'Referencing & Citations',
            'bibliograph'   => 'Referencing & Citations',
            'hypothesis'    => 'Hypothesis & Methodology',
            'methodology'   => 'Hypothesis & Methodology',
            'experiment'    => 'Experiment Design',
            'data analys'   => 'Data Analysis',
            'statistic'     => 'Data Analysis',
            'regression'    => 'Data Analysis',
            'theory'        => 'Theoretical Concepts',
            'definition'    => 'Definitions',
            'formula'       => 'Formulas & Equations',
            'equation'      => 'Formulas & Equations',
            'calculate'     => 'Calculations',
            'algorithm'     => 'Algorithms',
            'code'          => 'Coding',
            'program'       => 'Coding',
            'database'      => 'Databases',
            'sql'           => 'SQL',
            'network'       => 'Networking',
            'security'      => 'Security',
        ];

        $MAX_VOLUME = 50;

        foreach ($courses as $cid) {
            $logs = $DB->get_records_sql(
                "SELECT id, userid, question, sources, timecreated
                   FROM {umat_ai_chat_logs}
                  WHERE courseid = :cid AND timecreated > :since AND role = 'student'
               ORDER BY timecreated DESC",
                ['cid' => $cid, 'since' => $since]
            );

            if (empty($logs)) {
                continue;
            }

            // Build dynamic topic keywords from course section names.
            $topickeywords = self::build_topic_keywords($cid, $DB);

            // If the course has no custom sections, fall back to the generic dictionary.
            if (empty($topickeywords)) {
                $topickeywords = $fallbackKeywords;
            }

            $topicdata = [];

            foreach ($logs as $log) {
                $qtext   = strtolower($log->question);
                $uid     = (int)$log->userid;
                $qid     = (int)$log->id;
                $sources = !empty($log->sources) ? json_decode($log->sources, true) : [];

                $assigned = false;

                // Strategy 1: Match against course section name keywords.
                foreach ($topickeywords as $keyword => $topic) {
                    if (strpos($qtext, $keyword) !== false) {
                        self::add_to_topic($topicdata, $topic, $qid, $uid);
                        $assigned = true;
                        break; // Use first (most specific) match.
                    }
                }

                // Strategy 2: Match using material filenames from chat sources.
                if (!$assigned && !empty($sources) && is_array($sources)) {
                    foreach ($sources as $src) {
                        $name = is_string($src) ? $src : ($src['filename'] ?? $src['name'] ?? '');
                        if ($name) {
                            // Use a cleaned-up version of the filename as the topic.
                            $topicLabel = self::clean_material_label($name);
                            if ($topicLabel) {
                                self::add_to_topic($topicdata, $topicLabel, $qid, $uid);
                                $assigned = true;
                                break;
                            }
                        }
                    }
                }

                // Strategy 3: Try matching against material filenames stored in the DB.
                if (!$assigned) {
                    $materialTopic = self::match_material_topic($qtext, $cid, $DB);
                    if ($materialTopic) {
                        self::add_to_topic($topicdata, $materialTopic, $qid, $uid);
                        $assigned = true;
                    }
                }

                if (!$assigned) {
                    self::add_to_topic($topicdata, 'General', $qid, $uid);
                }
            }

            $topicRows = [];
            foreach ($topicdata as $topic => $data) {
                $questionVolume = count($data['questions']);
                $studentCount   = count($data['users']);
                $uids           = array_keys($data['users']);

                if (!empty($uids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED);
                    $inparams['cid'] = $cid;
                    $avgGrade = $DB->get_field_sql(
                        "SELECT AVG(qg.grade)
                           FROM {quiz_grades} qg
                           JOIN {quiz} q ON q.id = qg.quiz
                          WHERE qg.userid $insql AND q.course = :cid",
                        $inparams
                    );
                } else {
                    $avgGrade = false;
                }

                $avgCompetency = $avgGrade !== false ? max(0.0, min(1.0, (float)$avgGrade / 100.0)) : 0.5;

                $frictionScore = round(min(100,
                    ($questionVolume / $MAX_VOLUME) * 50 + (1 - $avgCompetency) * 50
                ), 1);

                $severity = $frictionScore >= 70 ? 'critical' : ($frictionScore >= 40 ? 'moderate' : 'minor');

                $topicRows[] = [
                    'courseid'        => $cid,
                    'topic_label'     => $topic,
                    'question_volume' => $questionVolume,
                    'friction_score'  => $frictionScore,
                    'student_count'   => $studentCount,
                    'severity'        => $severity,
                    'computed_at'     => time(),
                ];
            }

            if (!empty($topicRows)) {
                $DB->delete_records('umat_ai_topic_friction', ['courseid' => $cid]);
                $DB->insert_records('umat_ai_topic_friction', $topicRows);
            }
        }
    }

    // ------------------------------------------------------------------
    //  Helper: dynamic topic keyword builder from course structure.
    // ------------------------------------------------------------------

    /**
     * Build keyword => topic_label mapping from the course's section names.
     *
     * Each section name is tokenised into meaningful words (>= 4 chars, not
     * stop words).  The section name itself becomes the topic label.
     *
     * @param int   $courseid
     * @param \moodle_database $DB
     * @return array  keyword => topic_label
     */
    private static function build_topic_keywords(int $courseid, \moodle_database $DB): array {
        $sections = $DB->get_records_sql(
            "SELECT id, name, summary
               FROM {course_sections}
              WHERE course = :cid
           ORDER BY section ASC",
            ['cid' => $courseid]
        );

        if (empty($sections)) {
            return [];
        }

        $stopWords = [
            'the','and','for','are','but','not','you','all','can','had',
            'her','was','one','our','out','has','his','how','its','may',
            'new','now','old','see','way','who','why','did','get','let',
            'say','she','too','use','this','that','with','have','from',
            'they','been','said','each','make','like','long','look',
            'many','most','over','such','take','than','them','then',
            'what','when','your','will','would','there','their','about',
            'which','were','being','into','more','also','some','could',
            'other','than','very','just','week','part','intro','week',
            'lecture','chapter','topic','section','module','unit',
        ];

        $keywords = [];

        foreach ($sections as $sec) {
            // Use section name as the topic label; fall back to summary.
            $label = trim($sec->name);
            if (empty($label) || $label === 'General') {
                continue;
            }

            // Tokenise the label into keywords.
            $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($label), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $word) {
                if (strlen($word) >= 4 && !in_array($word, $stopWords, true)) {
                    $keywords[$word] = $label;
                }
            }

            // Also add bigrams for multi-word section names (e.g. "e commerce").
            for ($i = 0; $i < count($words) - 1; $i++) {
                $bigram = $words[$i] . ' ' . $words[$i + 1];
                if (strlen($bigram) >= 8) {
                    $keywords[$bigram] = $label;
                }
            }
        }

        return $keywords;
    }

    /**
     * Add a question/user pair to a topic bucket.
     */
    private static function add_to_topic(array &$topicdata, string $topic, int $qid, int $uid): void {
        if (!isset($topicdata[$topic])) {
            $topicdata[$topic] = ['questions' => [], 'users' => []];
        }
        $topicdata[$topic]['questions'][$qid] = true;
        $topicdata[$topic]['users'][$uid] = true;
    }

    /**
     * Clean a material filename into a readable topic label.
     *
     * "EC 1.pdf" -> "EC 1"
     * "Lecture-Notes-Chapter3.pdf" -> "Lecture Notes Chapter3"
     * "1. E-Commerce Types.pdf" -> "E-Commerce Types"
     */
    private static function clean_material_label(string $filename): string {
        // Remove extension.
        $name = preg_replace('/\.[^.]+$/', '', $filename);
        // Remove leading numbering like "1.", "02 -", etc.
        $name = preg_replace('/^\d+[\.\-\s]+/', '', $name);
        // Replace underscores and hyphens with spaces.
        $name = str_replace(['_', '-'], ' ', $name);
        // Collapse whitespace.
        $name = trim(preg_replace('/\s+/', ' ', $name));
        // Title-case for display.
        return ucwords($name);
    }

    /**
     * Try to match a question against stored material filenames.
     *
     * Looks up the umat_ai_materials table for this course and checks if
     * any material filename words appear in the question text.
     */
    private static function match_material_topic(string $qtext, int $courseid, \moodle_database $DB): ?string {
        $materials = $DB->get_records_sql(
            "SELECT DISTINCT filename
               FROM {umat_ai_materials}
              WHERE courseid = :cid AND filename IS NOT NULL AND filename != ''",
            ['cid' => $courseid],
            0, 50
        );

        if (empty($materials)) {
            return null;
        }

        $stopWords = ['the','and','for','are','not','this','that','with','from',' lecture',' chapter'];

        foreach ($materials as $mat) {
            $name = preg_replace('/\.[^.]+$/', '', $mat->filename);
            $name = preg_replace('/^\d+[\.\-\s]+/', '', $name);
            $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

            $matches = 0;
            foreach ($words as $word) {
                if (strlen($word) >= 4 && !in_array($word, $stopWords, true)
                    && strpos($qtext, $word) !== false) {
                    $matches++;
                }
            }

            // If at least 2 significant words from the filename appear in the question.
            if ($matches >= 2) {
                return self::clean_material_label($mat->filename);
            }
        }

        return null;
    }
}
