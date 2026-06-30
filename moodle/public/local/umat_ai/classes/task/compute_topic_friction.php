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

        $topickeywords = [
            'referenc'      => 'Referencing & Citations',
            'citation'      => 'Referencing & Citations',
            'bibliograph'   => 'Referencing & Citations',
            'hypothesis'    => 'Hypothesis & Methodology',
            'methodology'   => 'Hypothesis & Methodology',
            'method'        => 'Hypothesis & Methodology',
            'experiment'    => 'Experiment Design',
            'data analys'   => 'Data Analysis',
            'statistic'     => 'Data Analysis',
            'regression'    => 'Data Analysis',
            'correlation'   => 'Data Analysis',
            'theory'        => 'Theoretical Concepts',
            'concept'       => 'Theoretical Concepts',
            'definition'    => 'Definitions',
            'formula'       => 'Formulas & Equations',
            'equation'      => 'Formulas & Equations',
            'calculate'     => 'Calculations',
            'algorithm'     => 'Algorithms',
            'implement'     => 'Implementation',
            'code'          => 'Coding',
            'program'       => 'Coding',
            'debug'         => 'Debugging',
            'error'         => 'Error Handling',
            'exception'     => 'Error Handling',
            'syntax'        => 'Syntax',
            'framework'     => 'Frameworks',
            'library'       => 'Libraries',
            'function'      => 'Functions',
            'variable'      => 'Variables',
            'loop'          => 'Loops & Iteration',
            'condition'     => 'Conditionals',
            'array'         => 'Data Structures',
            'object'        => 'OOP Concepts',
            'class'         => 'OOP Concepts',
            'inheritance'   => 'OOP Concepts',
            'polymorphism'  => 'OOP Concepts',
            'database'      => 'Databases',
            'sql'           => 'SQL',
            'query'         => 'Queries',
            'normaliz'      => 'Normalization',
            'prototype'     => 'Design & Prototyping',
            'simulation'    => 'Simulation',
            'model'         => 'Modelling',
            'network'       => 'Networking',
            'protocol'      => 'Networking',
            'security'      => 'Security',
            'encryption'    => 'Security',
            'authentication' => 'Security',
        ];

        $MAX_VOLUME = 50;

        foreach ($courses as $cid) {
            $logs = $DB->get_records_sql(
                "SELECT id, userid, question, timecreated
                   FROM {umat_ai_chat_logs}
                  WHERE courseid = :cid AND timecreated > :since AND role = 'student'
               ORDER BY timecreated DESC",
                ['cid' => $cid, 'since' => $since]
            );

            if (empty($logs)) {
                continue;
            }

            $topicdata = [];

            foreach ($logs as $log) {
                $qtext = strtolower($log->question);
                $uid   = (int)$log->userid;
                $qid   = (int)$log->id;

                $assigned = false;
                foreach ($topickeywords as $keyword => $topic) {
                    if (strpos($qtext, $keyword) !== false) {
                        if (!isset($topicdata[$topic])) {
                            $topicdata[$topic] = ['questions' => [], 'users' => []];
                        }
                        $topicdata[$topic]['questions'][$qid] = true;
                        $topicdata[$topic]['users'][$uid] = true;
                        $assigned = true;
                    }
                }

                if (!$assigned) {
                    if (!isset($topicdata['General'])) {
                        $topicdata['General'] = ['questions' => [], 'users' => []];
                    }
                    $topicdata['General']['questions'][$qid] = true;
                    $topicdata['General']['users'][$uid] = true;
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
}
