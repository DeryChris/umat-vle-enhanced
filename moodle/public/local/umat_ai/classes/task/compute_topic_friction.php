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

        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {umat_ai_chat_logs}
              WHERE role = 'student'"
        );

        if (empty($courses)) {
            return;
        }

        // Fallback keyword dictionary for questions that don't reference
        // any course material.
        $fallbackKeywords = [
            'referenc'      => 'Referencing & Citations',
            'bibliograph'   => 'Referencing & Citations',
            'hypothesis'    => 'Hypothesis & Methodology',
            'methodology'   => 'Methodology',
            'experiment'    => 'Experiment Design',
            'data analys'   => 'Data Analysis',
            'statistic'     => 'Statistics',
            'regression'    => 'Regression Analysis',
            'correlation'   => 'Correlation',
            'definition'    => 'Definitions',
            'formula'       => 'Formulas & Equations',
            'equation'      => 'Equations',
            'calculate'     => 'Calculations',
            'algorithm'     => 'Algorithms',
            'theory'        => 'Theoretical Concepts',
            'concept'       => 'Key Concepts',
            'code'          => 'Coding',
            'program'       => 'Programming',
            'database'      => 'Databases',
            'sql'           => 'SQL',
            'network'       => 'Networking',
            'security'      => 'Security',
        ];

        $MAX_VOLUME = 50;

        foreach ($courses as $cid) {
            // Get course fullname for context.
            $courseFullname = $DB->get_field('course', 'fullname', ['id' => $cid]);
            $courseShortname = $DB->get_field('course', 'shortname', ['id' => $cid]);

            $logs = $DB->get_records_sql(
                "SELECT id, userid, question, sources, timecreated
                   FROM {umat_ai_chat_logs}
                  WHERE courseid = :cid AND role = 'student'
               ORDER BY timecreated DESC",
                ['cid' => $cid]
            );

            if (empty($logs)) {
                continue;
            }

            $topicdata = [];

            // Pre-load unique material filenames for this course (for matching).
            $materialNames = $DB->get_fieldset_sql(
                "SELECT DISTINCT filename FROM {umat_ai_materials}
                  WHERE courseid = :cid AND filename IS NOT NULL AND filename != ''
               ORDER BY filename",
                ['cid' => $cid]
            );

            foreach ($logs as $log) {
                $qtext   = strtolower($log->question);
                $uid     = (int)$log->userid;
                $qid     = (int)$log->id;
                $sources = !empty($log->sources) ? json_decode($log->sources, true) : [];

                $assigned = false;

                // --- Strategy 1: Extract material references from question text ---
                // Many questions include "[Referencing: EC 1.pdf]" at the start.
                if (preg_match('/\[referencing:\s*([^\]]+\.\w+)\]/i', $qtext, $m)) {
                    $refName = self::clean_material_label(trim($m[1]));
                    if ($refName) {
                        self::add_to_topic($topicdata, $refName, $qid, $uid);
                        $assigned = true;
                    }
                }

                // --- Strategy 2: Use material filenames from chat sources ---
                if (!$assigned && !empty($sources) && is_array($sources)) {
                    foreach ($sources as $src) {
                        $name = is_string($src) ? $src : ($src['filename'] ?? $src['name'] ?? '');
                        if ($name) {
                            $topicLabel = self::clean_material_label($name);
                            if ($topicLabel) {
                                self::add_to_topic($topicdata, $topicLabel, $qid, $uid);
                                $assigned = true;
                                break;
                            }
                        }
                    }
                }

                // --- Strategy 3: Match question text against material filenames ---
                // Looks for significant words from filenames appearing in the question.
                if (!$assigned && !empty($materialNames)) {
                    $materialTopic = self::match_material_topic($qtext, $materialNames);
                    if ($materialTopic) {
                        self::add_to_topic($topicdata, $materialTopic, $qid, $uid);
                        $assigned = true;
                    }
                }

                // --- Strategy 4: Course section keyword matching ---
                if (!$assigned) {
                    $sectionTopic = self::match_section_topic($qtext, $cid, $DB);
                    if ($sectionTopic) {
                        self::add_to_topic($topicdata, $sectionTopic, $qid, $uid);
                        $assigned = true;
                    }
                }

                // --- Strategy 5: Generic keyword matching ---
                if (!$assigned) {
                    foreach ($fallbackKeywords as $keyword => $topic) {
                        if (strpos($qtext, $keyword) !== false) {
                            self::add_to_topic($topicdata, $topic, $qid, $uid);
                            $assigned = true;
                            break;
                        }
                    }
                }

                // --- Last resort: use course prefix + "Course Material" ---
                if (!$assigned) {
                    // Try to derive a meaningful category from the course name.
                    $courseTopic = self::derive_course_topic($courseFullname, $courseShortname, $qtext);
                    if ($courseTopic) {
                        self::add_to_topic($topicdata, $courseTopic, $qid, $uid);
                    } else {
                        self::add_to_topic($topicdata, 'General', $qid, $uid);
                    }
                }
            }

            $topicRows = [];
            foreach ($topicdata as $topic => $data) {
                // Expand short course prefix to full name for readability.
                $displayTopic = self::expand_topic_label($topic, $courseFullname, $courseShortname);
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
                    'topic_label'     => $displayTopic,
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
    //  Helpers: topic classification
    // ------------------------------------------------------------------

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
     * "EC 1.pdf"          -> "EC 1"
     * "Lecture Notes Ch3" -> "Lecture Notes Ch3"
     * "1. E-Commerce.pdf" -> "E-Commerce"
     */
    private static function clean_material_label(string $filename): string {
        // Remove extension.
        $name = preg_replace('/\.[^.]+$/', '', $filename);
        // Remove leading numbering like "1.", "02 -".
        $name = preg_replace('/^\s*\d+[\.\-\s)]+\s*/', '', $name);
        // Replace underscores and hyphens with spaces.
        $name = str_replace(['_', '-'], ' ', $name);
        // Collapse whitespace.
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (empty($name)) {
            return '';
        }
        // Normalise case: upper-case first letter of each word, rest lower.
        $words = explode(' ', $name);
        $result = [];
        foreach ($words as $w) {
            if (strlen($w) <= 2) {
                // Keep short words (like "EC", "B2B", "C2C") as-is.
                $result[] = strtoupper($w);
            } else {
                $result[] = ucfirst(strtolower($w));
            }
        }
        return implode(' ', $result);
    }

    /**
     * Match question text against a pre-loaded list of material filenames.
     *
     * Returns the best-matching material label if at least 2 significant
     * words from the filename appear in the question text.
     *
     * @param string $qtext         Lowercased question text.
     * @param array  $materialNames List of filenames from umat_ai_materials.
     * @return string|null
     */
    private static function match_material_topic(string $qtext, array $materialNames): ?string {
        $best     = null;
        $bestHits = 0;
        $stopWords = ['the','and','for','are','not','this','that','with','from',
                       'lecture','chapter','topic','section','module','unit'];

        foreach ($materialNames as $filename) {
            $name  = preg_replace('/\.[^.]+$/', '', $filename);
            $name  = preg_replace('/^\d+[\.\-\s]+/', '', $name);
            $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

            $hits = 0;
            foreach ($words as $word) {
                if (strlen($word) >= 4
                    && !in_array($word, $stopWords, true)
                    && strpos($qtext, $word) !== false) {
                    $hits++;
                }
            }

            if ($hits >= 2 && $hits > $bestHits) {
                $best     = self::clean_material_label($filename);
                $bestHits = $hits;
            }
        }

        return $best;
    }

    /**
     * Match question text against course section names.
     *
     * Returns the section name if 2+ significant section words appear
     * in the question text.
     */
    private static function match_section_topic(string $qtext, int $courseid, \moodle_database $DB): ?string {
        $sections = $DB->get_records_sql(
            "SELECT name FROM {course_sections}
              WHERE course = :cid
                AND name IS NOT NULL
                AND name != '' AND name != 'General'
           ORDER BY section ASC",
            ['cid' => $courseid]
        );
        if (empty($sections)) {
            return null;
        }

        $stopWords = ['the','and','for','are','not','this','that','with',
                       'from','lecture','chapter','topic','section','module',
                       'unit','week','part','intro','one','two','three',
                       'four','five','six','seven','eight','nine','ten'];

        foreach ($sections as $sec) {
            $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($sec->name), -1, PREG_SPLIT_NO_EMPTY);
            $hits  = 0;
            foreach ($words as $word) {
                if (strlen($word) >= 4
                    && !in_array($word, $stopWords, true)
                    && strpos($qtext, $word) !== false) {
                    $hits++;
                }
            }
            if ($hits >= 2) {
                return ucwords(trim($sec->name));
            }
        }
        return null;
    }

    /**
     * Derive a topic from the course name for questions that don't
     * reference any specific material.
     *
     * Uses the course's shortname prefix (e.g. "EC" from "EC 101")
     * to create a category like "EC Course Material".
     */
    private static function derive_course_topic(?string $fullname, ?string $shortname, string $qtext): ?string {
        // Try to extract a meaningful prefix from the shortname.
        if (!empty($shortname)) {
            $parts = preg_split('/[^a-zA-Z]+/', $shortname, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $p) {
                if (strlen($p) >= 2 && ctype_upper($p)) {
                    // Check if the question mentions course-related terms.
                    if (strpos($qtext, 'course') !== false
                        || strpos($qtext, 'material') !== false
                        || strpos($qtext, 'lecture') !== false
                        || strpos($qtext, 'week') !== false
                        || strpos($qtext, 'lesson') !== false) {
                        return $p . ' Course Material';
                    }
                }
            }
        }

        // Check for common question types.
        if (strpos($qtext, 'practice') !== false
            || strpos($qtext, 'quiz') !== false
            || strpos($qtext, 'question') !== false) {
            return 'Practice Questions';
        }

        if (strpos($qtext, 'summar') !== false) {
            return 'Summaries';
        }

        if (strpos($qtext, 'explain') !== false
            || strpos($qtext, 'what is') !== false
            || strpos($qtext, 'key concept') !== false) {
            return 'Key Concepts';
        }

        return null;
    }

    /**
     * Expand a raw topic label with course name context for readability.
     *
     * "EC 1" + shortname="E-Commerce" → "E-Commerce 1"
     * "EC" + fullname="Electronic Commerce" → "E-Commerce"
     *
     * Builds an acronym from the shortname words (E + Commerce → "EC")
     * and replaces matches with the actual course name.
     */
    private static function expand_topic_label(string $topic, ?string $fullname, ?string $shortname): string {
        if (empty($shortname)) {
            return $topic;
        }

        // Build acronym from shortname: "E-Commerce" → "EC".
        $nameParts = preg_split('/[^a-zA-Z0-9]+/', $shortname, -1, PREG_SPLIT_NO_EMPTY);
        if (count($nameParts) < 2) {
            return $topic;
        }
        $acronym = '';
        foreach ($nameParts as $p) {
            $acronym .= strtoupper($p[0]);
        }

        // Get a clean display base from shortname or fullname.
        $displayBase = $shortname;
        if (!empty($fullname) && preg_match('/\(([^)]+)\)/', $fullname, $fn)) {
            $displayBase = trim($fn[1]);
        }

        // Check if topic starts with the acronym (e.g., "EC").
        if (preg_match('/^' . preg_quote($acronym, '/') . '\s*(\d.*)?$/i', $topic, $m)) {
            $suffix = isset($m[1]) ? ' ' . $m[1] : '';
            return $displayBase . $suffix;
        }

        return $topic;
    }
}
