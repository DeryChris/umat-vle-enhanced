<?php
/**
 * External API: struggle insights for the lecturer.
 * Identifies topics, materials, and students where difficulty is highest.
 * PHP fallback: topic extraction from sources + AI analysis key_concepts.
 *
 * @package    local_umat_ai
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

use local_umat_ai\analytics\student_risk_calculator;
use local_umat_ai\analytics\topic_insight_builder;
use local_umat_ai\analytics\evidence_formatter;
use local_umat_ai\analytics\academic_query_classifier;
use local_umat_ai\analytics\safe_percentage;
use local_umat_ai\analytics\bbb_attendance_analyser;

class get_struggle_insights extends \external_api {

    public static function get_struggle_insights_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 60),
        ]);
    }

    public static function get_struggle_insights($courseid, $days = 60) {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(
            self::get_struggle_insights_parameters(),
            ['courseid' => $courseid, 'days' => $days]
        );
        $cid   = (int)$params['courseid'];
        $since = time() - ($params['days'] * DAYSECS);

        // ── All Courses mode (cid=0) ──
        if ($cid === 0) {
            self::validate_context(\context_system::instance());
            require_capability('local/umat_ai:viewanalytics', \context_system::instance());

            $cache    = \cache::make('local_umat_ai', 'struggle_insights');
            $cachekey = "struggle_all_{$params['days']}";
            $cached   = $cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }

            $result = self::get_all_courses_insights($params['days']);
            $cache->set($cachekey, $result);
            return $result;
        }

        // ── Single Course mode ──
        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // ── Moodle cache: check for cached response ──
        $cache    = \cache::make('local_umat_ai', 'struggle_insights');
        $cachekey = "struggle_{$cid}_{$params['days']}";
        $cached   = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        // ──────────────────────────────────────────────────────────────
        // 1. Student chat logs (questions, sources, userid, timecreated)
        // ──────────────────────────────────────────────────────────────
        $rawLogs = $DB->get_records_sql(
            "SELECT id, userid, question, sources, session_key, role, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );

        // Only genuine academic learning questions drive the analytics. The
        // dashboard previously counted greetings, "quiz me" commands and filler
        // as questions, which inflated every downstream figure. Keys are
        // preserved because later code indexes $logs by chat log id.
        $intentBreakdown = academic_query_classifier::intent_breakdown($rawLogs);
        $logs = [];
        foreach ($rawLogs as $qid => $log) {
            if (academic_query_classifier::is_academic(
                    (string) $log->question,
                    academic_query_classifier::source_from_log($log))) {
                $logs[$qid] = $log;
            }
        }
        $totalQuestions = count($logs);
        $totalRawMessages = count($rawLogs);

        // ──────────────────────────────────────────────────────────────
        // 2. Materials for this course (with AI analysis)
        // ──────────────────────────────────────────────────────────────
        $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid], '', 'id, cmid, filename, fileid');
        $matIds    = array_keys($materials);
        $matByName = [];
        foreach ($materials as $m) {
            $key = strtolower(trim($m->filename));
            $matByName[$key] = $m;
        }

        // ──────────────────────────────────────────────────────────────
        // 3. AI analysis for materials (key_concepts, difficulty)
        // ──────────────────────────────────────────────────────────────
        $matAnalyses = []; // materialid => { key_concepts, difficulty }
        if (!empty($matIds)) {
            list($inSql, $inParams) = $DB->get_in_or_equal($matIds, SQL_PARAMS_NAMED);
            $inParams['atype'] = 'key_concepts';
            $conceptRows = $DB->get_records_sql(
                "SELECT materialid, analysis_type, summary, token_count
                   FROM {umat_ai_analysis}
                  WHERE materialid $inSql AND status = 'completed'
               ORDER BY timemodified DESC",
                $inParams
            );
            foreach ($conceptRows as $row) {
                $mid = (int)$row->materialid;
                if (!isset($matAnalyses[$mid])) {
                    $matAnalyses[$mid] = [
                        'key_concepts' => [],
                        'difficulty'   => 'intermediate',
                    ];
                }
                if ($row->analysis_type === 'key_concepts' && $row->summary) {
                    $parsed = json_decode($row->summary, true);
                    if (is_array($parsed)) {
                        $concepts = [];
                        if (isset($parsed['concepts']) && is_array($parsed['concepts'])) {
                            foreach ($parsed['concepts'] as $c) {
                                $concepts[] = $c['term'] ?? $c['name'] ?? (is_string($c) ? $c : '');
                            }
                        }
                        $matAnalyses[$mid]['key_concepts'] = array_unique(array_filter($concepts));
                    }
                }
                // Also check full_analysis for difficulty
                if ($row->analysis_type === 'full_analysis' && $row->summary) {
                    $parsed = json_decode($row->summary, true);
                    if (is_array($parsed) && isset($parsed['difficulty'])) {
                        $matAnalyses[$mid]['difficulty'] = $parsed['difficulty'];
                    }
                }
            }
        }

        // ──────────────────────────────────────────────────────────────
        // 4. Build master concept list from all analyses
        // ──────────────────────────────────────────────────────────────
        $allConcepts = [];
        $conceptToMaterials = []; // concept => [materialid => filename]
        foreach ($matAnalyses as $mid => $ana) {
            $fname = $materials[$mid]->filename ?? ('material_' . $mid);
            foreach ($ana['key_concepts'] as $c) {
                $lc = strtolower(trim($c));
                if (!$lc) continue;
                $allConcepts[$lc] = $c;
                $conceptToMaterials[$lc][$mid] = $fname;
            }
        }

        // ──────────────────────────────────────────────────────────────
        // 5. Per-question: extract material references + topic concepts
        // ──────────────────────────────────────────────────────────────
        $questionTopics = [];   // questionid => [topic => count]
        $materialQuestions = []; // materialid => [questionid => true]
        $studentQuestions = [];  // userid => [questionid => timecreated]
        $topicQuestions = [];    // topic => [questionid => true]
        $topicStudents = [];     // topic => [userid => true]
        $topicMaterials = [];    // topic => [materialid => true]

        foreach ($logs as $l) {
            $uid = (int)$l->userid;
            $qid = (int)$l->id;
            $qTopicAssigned = false; // whether THIS question got a topic

            // Track per-student
            if (!isset($studentQuestions[$uid])) $studentQuestions[$uid] = [];
            $studentQuestions[$uid][$qid] = (int)$l->timecreated;

            // Parse sources
            $sourceFiles = json_decode($l->sources ?? '[]', true) ?? [];
            $sourceFiles = array_filter($sourceFiles, 'is_string');

            $matchedMids = [];

            foreach ($sourceFiles as $src) {
                $srcLower = strtolower(trim($src));
                // Try exact filename match
                if (isset($matByName[$srcLower])) {
                    $mid = (int)$matByName[$srcLower]->id;
                    $matchedMids[$mid] = true;
                    continue;
                }
                // Try partial match
                foreach ($matByName as $fname => $m) {
                    if (strpos($srcLower, $fname) !== false || strpos($fname, $srcLower) !== false) {
                        $matchedMids[(int)$m->id] = true;
                    }
                }
            }

            // Default: assign to first matching material by filename keyword
            if (empty($matchedMids)) {
                $qtext = strtolower($l->question);
                foreach ($matByName as $fname => $m) {
                    $fnameBase = strtolower(pathinfo($fname, PATHINFO_FILENAME));
                    $keywords = preg_split('/[_\s\-]+/', $fnameBase);
                    $matchCnt = 0;
                    foreach ($keywords as $kw) {
                        if (strlen($kw) > 3 && strpos($qtext, $kw) !== false) {
                            $matchCnt++;
                        }
                    }
                    if ($matchCnt >= 2 || ($matchCnt >= 1 && count($keywords) <= 2)) {
                        $matchedMids[(int)$m->id] = true;
                    }
                }
            }

            // Track material question counts
            foreach ($matchedMids as $mid => $_) {
                if (!isset($materialQuestions[$mid])) $materialQuestions[$mid] = [];
                $materialQuestions[$mid][$qid] = true;
                // Also track topics from this material's analysis
                foreach ($matAnalyses as $amaId => $ana) {
                    if ((int)$amaId === $mid) {
                        foreach ($ana['key_concepts'] as $c) {
                            $lc = strtolower(trim($c));
                            if (!$lc) continue;
                            if (!isset($topicQuestions[$lc])) $topicQuestions[$lc] = [];
                            $topicQuestions[$lc][$qid] = true;
                            $topicStudents[$lc][$uid] = true;
                            $topicMaterials[$lc][$mid] = true;
                            $qTopicAssigned = true;
                        }
                    }
                }
            }

            // If no material matched but we have concepts, try keyword matching against questions
            if (empty($matchedMids) && !empty($allConcepts)) {
                $qtext = strtolower($l->question);
                foreach ($allConcepts as $lc => $orig) {
                    $words = explode(' ', $lc);
                    $wordMatch = 0;
                    foreach ($words as $w) {
                        if (strlen($w) > 2 && strpos($qtext, $w) !== false) {
                            $wordMatch++;
                        }
                    }
                    if ($wordMatch >= 1) {
                        if (!isset($topicQuestions[$lc])) $topicQuestions[$lc] = [];
                        $topicQuestions[$lc][$qid] = true;
                        $topicStudents[$lc][$uid] = true;
                        $qTopicAssigned = true;
                        if (isset($conceptToMaterials[$lc])) {
                            foreach ($conceptToMaterials[$lc] as $mid => $_) {
                                $matchedMids[$mid] = true;
                                $topicMaterials[$lc][$mid] = true;
                            }
                        }
                    }
                }
            }

            // Fallback for any question that didn't get a topic above:
            // Instead of creating ad-hoc single-word topics (which pollute
            // the topic matrix with junk like "practice", "create", etc.),
            // we collect them for the AI service which can extract real
            // multi-word topics from the full question set.
            if (!$qTopicAssigned) {
                $unmatchedQuestions[] = $l->question;
            }
        }
        // Ensure the collector array exists for later use
        if (!isset($unmatchedQuestions)) $unmatchedQuestions = [];

        // ──────────────────────────────────────────────────────────────
        // 5b. Student context events (quiz failures, repeated views,
        //     assignment failures) and issue reports
        // ──────────────────────────────────────────────────────────────
        $eventRecords = $DB->get_records_select('umat_ai_student_context',
            'courseid = ? AND timemodified > ?', [$cid, $since]);
        $topicEvents = []; // normalized_label_or_topic => [reason => count]
        $studentEvents = []; // userid => [reason => count]
        $eventTopicMap = []; // normalized_label_or_topic => human_label

        foreach ($eventRecords as $er) {
            $label = trim($er->topic_label ?? '');
            $reason = $er->struggle_reason;
            $uid = (int)$er->userid;
            $norm = strtolower(preg_replace('/[^a-z0-9\s]/', '', $label));
            if (empty($norm)) $norm = '_unnamed';

            if (!isset($topicEvents[$norm])) $topicEvents[$norm] = [];
            $topicEvents[$norm][$reason] = ($topicEvents[$norm][$reason] ?? 0) + 1;
            $eventTopicMap[$norm] = $label
                ?: ($reason === 'quiz_failure' ? 'Quiz Failure'
                    : ($reason === 'assignment_failure' ? 'Assignment Failure'
                        : ($reason === 'repeated_views' ? 'Repeated Views'
                            : 'Activity')));
            if (!isset($studentEvents[$uid])) $studentEvents[$uid] = [];
            $studentEvents[$uid][$reason] = ($studentEvents[$uid][$reason] ?? 0) + 1;
        }

        // Issue reports (already fetched in step 11, but we need category counts earlier)
        $issueCategoryCounts = [];
        $issueRecords = $DB->get_records_select('umat_ai_issue_reports',
            'courseid = ? AND timecreated > ?', [$cid, $since]);
        $totalIssues = count($issueRecords);
        $openIssues = 0;
        foreach ($issueRecords as $ir) {
            if ($ir->status === 'open' || $ir->status === 'in_review') $openIssues++;
            $cat = $ir->category;
            if (!isset($issueCategoryCounts[$cat])) $issueCategoryCounts[$cat] = 0;
            $issueCategoryCounts[$cat]++;
            // Issue-report topics are deliberately NOT merged into the academic
            // topic matrix. A login problem or a broken-file report is a
            // support ticket, not a subject the class is struggling to learn,
            // and folding them together is what put "Login Issue Report" on the
            // Topic Struggle Heatmap. Issue counts remain available separately
            // via $issueCategoryCounts and the summary block.
        }
        $catLabels = [
            'concept_confusion' => 'Concept Confusion',
            'material_error'    => 'Material Error',
            'technical_issue'   => 'Technical Issue',
            'suggestion'        => 'Suggestion',
            'other'             => 'Other',
        ];
        $topIssueTopics = [];
        foreach ($issueCategoryCounts as $cat => $cnt) {
            $topIssueTopics[] = ($catLabels[$cat] ?? $cat) . ' (' . $cnt . ')';
        }
        usort($topIssueTopics, function ($a, $b) {
            $aCnt = (int) preg_replace('/.*\((\d+)\)/', '$1', $a);
            $bCnt = (int) preg_replace('/.*\((\d+)\)/', '$1', $b);
            return $bCnt - $aCnt;
        });

        // ──────────────────────────────────────────────────────────────
        // 6. Compute per-topic struggle scores
        // ──────────────────────────────────────────────────────────────
        $enrolledCount = (int) count_enrolled_users($context, '', 0, true);
        $uniqueStudents = count($studentQuestions);
        $topicMatrix = [];
        $maxTopicQ = 1;

        foreach ($topicQuestions as $lc => $qids) {
            $cnt = count($qids);
            if ($cnt > $maxTopicQ) $maxTopicQ = $cnt;
        }

        foreach ($topicQuestions as $lc => $qids) {
            $cnt       = count($qids);
            $stuCnt    = count($topicStudents[$lc] ?? []);
            $difficulty = 'intermediate';
            $materialIds = array_keys($topicMaterials[$lc] ?? []);
            if (!empty($materialIds)) {
                $diffs = array_map(function($mid) use ($matAnalyses) {
                    return $matAnalyses[$mid]['difficulty'] ?? 'intermediate';
                }, $materialIds);
                $diffs = array_count_values($diffs);
                arsort($diffs);
                $difficulty = key($diffs);
            }
            $diffWeight = ['beginner' => 5, 'intermediate' => 10, 'advanced' => 20];

            // Struggle score 0-100
            $qScore    = $maxTopicQ > 0 ? ($cnt / $maxTopicQ) * 40 : 0;
            $sScore    = $enrolledCount > 0 ? ($stuCnt / $enrolledCount) * 30 : 0;
            $dScore    = ($diffWeight[$difficulty] ?? 10) * 1.5;
            $score     = round(min(100, $qScore + $sScore + $dScore));

            // Trend: compare last 14 days vs previous 14 days
            $now       = time();
            $recentCut = $now - (14 * DAYSECS);
            $olderCut  = $now - (28 * DAYSECS);
            $recentCnt = 0;
            $olderCnt  = 0;
            foreach ($qids as $qid => $_) {
                $logTs = $logs[$qid]->timecreated ?? 0;
                if ($logTs >= $recentCut) $recentCnt++;
                elseif ($logTs >= $olderCut) $olderCnt++;
            }
            $trendPct = 0;
            $trend    = 'stable';
            if ($olderCnt > 0) {
                $trendPct = round((($recentCnt - $olderCnt) / $olderCnt) * 100);
                if ($trendPct > 10) $trend = 'up';
                elseif ($trendPct < -10) $trend = 'down';
            } elseif ($recentCnt > 0) {
                $trend = 'up';
                $trendPct = 100;
            }

            // Material names for this topic
            $topicMatList = [];
            foreach ($materialIds as $mid) {
                if (isset($materials[$mid])) {
                    $fname = $materials[$mid]->filename;
                    $mc    = isset($materialQuestions[$mid]) ? count($materialQuestions[$mid]) : 0;
                    $topicMatList[] = [
                        'id'              => (int)$mid,
                        'name'            => $fname,
                        'question_count'  => $mc,
                    ];
                }
            }
            usort($topicMatList, function($a, $b) {
                return $b['question_count'] - $a['question_count'];
            });

            // Merge event-based data for this topic
            $topicName = $allConcepts[$lc] ?? ucwords(str_replace('_', ' ', $lc));
            $topicNorm = strtolower(preg_replace('/[^a-z0-9\s]/', '', $topicName));
            $evSrc = ['chat_questions' => $cnt, 'quiz_failures' => 0, 'repeated_views' => 0, 'assignment_failures' => 0, 'issue_reports' => 0];
            // Try direct match by normalized label
            if (isset($topicEvents[$topicNorm])) {
                foreach ($topicEvents[$topicNorm] as $reason => $ec) {
                    $mapKey = ['quiz_failure' => 'quiz_failures', 'repeated_views' => 'repeated_views',
                               'assignment_failure' => 'assignment_failures', 'issue_reported' => 'issue_reports'];
                    $k = $mapKey[$reason] ?? null;
                    if ($k) $evSrc[$k] = ($evSrc[$k] ?? 0) + $ec;
                }
            }
            // Try partial match: any event whose normalized label overlaps this topic
            foreach ($topicEvents as $evNorm => $evReasons) {
                if ($evNorm === $topicNorm) continue;
                if (strpos($topicNorm, $evNorm) !== false || strpos($evNorm, $topicNorm) !== false) {
                    foreach ($evReasons as $reason => $ec) {
                        $mapKey = ['quiz_failure' => 'quiz_failures', 'repeated_views' => 'repeated_views',
                                   'assignment_failure' => 'assignment_failures', 'issue_reported' => 'issue_reports'];
                        $k = $mapKey[$reason] ?? null;
                        if ($k) $evSrc[$k] = ($evSrc[$k] ?? 0) + $ec;
                    }
                }
            }

            // Recalculate struggle score factoring all event sources
            $eventTotal = array_sum($evSrc) - $evSrc['chat_questions']; // non-chat events
            $eventBonus = min(30, $eventTotal * 3);
            $score = round(min(100, $score + $eventBonus));

            $topicMatrix[] = [
                'topic'           => $topicName,
                'question_count'  => $cnt,
                'student_count'   => $stuCnt,
                'struggle_score'  => $score,
                'trend'           => $trend,
                'trend_pct'       => $trendPct,
                'difficulty'      => $difficulty,
                'event_sources'   => $evSrc,
                'materials'       => $topicMatList,
            ];
        }

        // ── Merge event-only topics (topics with quiz/issue/assignment events
        //     that didn't match any chat-based topic) into the matrix.
        foreach ($topicEvents as $evNorm => $evReasons) {
            $alreadyInMatrix = false;
            foreach ($topicMatrix as $existing) {
                $existingNorm = strtolower(preg_replace('/[^a-z0-9\s]/', '', $existing['topic']));
                if ($existingNorm === $evNorm) { $alreadyInMatrix = true; break; }
            }
            if ($alreadyInMatrix) continue;

            $totalEvents = array_sum($evReasons);
            if ($totalEvents < 1) continue;

            $evSrc = ['chat_questions' => 0, 'quiz_failures' => 0, 'repeated_views' => 0,
                      'assignment_failures' => 0, 'issue_reports' => 0];
            $mapKey = ['quiz_failure' => 'quiz_failures', 'repeated_views' => 'repeated_views',
                       'assignment_failure' => 'assignment_failures', 'issue_reported' => 'issue_reports'];
            foreach ($evReasons as $reason => $ec) {
                $k = $mapKey[$reason] ?? null;
                if ($k) $evSrc[$k] = ($evSrc[$k] ?? 0) + $ec;
            }

            $humanLabel = $eventTopicMap[$evNorm] ?? ucwords(str_replace('_', ' ', $evNorm));
            $evScore = min(100, 30 + $totalEvents * 5);
            $evScore = min(100, $evScore + ($evSrc['quiz_failures'] ?? 0) * 8);

            $topicMatrix[] = [
                'topic'           => $humanLabel,
                'question_count'  => 0,
                'student_count'   => 0,
                'struggle_score'  => $evScore,
                'trend'           => 'stable',
                'trend_pct'       => 0,
                'difficulty'      => 'intermediate',
                'event_sources'   => $evSrc,
                'materials'       => [],
            ];
        }

        // Filter out non-academic topics (test issues, login issues, etc.)
        $nonAcademicPattern = '/^(test issue|login issue report|login issue)$/i';
        $topicMatrix = array_values(array_filter($topicMatrix, function($t) use ($nonAcademicPattern) {
            return !preg_match($nonAcademicPattern, $t['topic']);
        }));

        // ── AI service: PRIMARY topic extraction (always called when data exists) ──
        // Uses all data sources (questions, issues, events, materials) to extract
        // real multi-word course-specific topics. Has memory caching for continuity.
        $aiTopics = [];
        $aiSummaryInsight = '';
        $hasAiResult = false;
        $hasAnyData = ($totalQuestions > 0) || !empty($issueRecords) || !empty($eventRecords);

        if ($hasAnyData) {
            $cfg = \local_umat_ai_get_service_config();
            if (!empty($cfg['token']) && !empty($cfg['url'])) {
                try {
                    require_once($CFG->libdir . '/filelib.php');
                    $client = new \curl(['ignoresecurity' => true]);
                    $client->setHeader([
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $cfg['token'],
                    ]);
                    $client->setopt(['CURLOPT_TIMEOUT' => 30]);

                    // Questions (up to 150)
                    $questionTexts = array_map(function($l) { return $l->question; },
                        array_slice($logs, 0, 150));

                    // Materials with AI analysis concepts
                    $materialsList = [];
                    foreach ($materials as $m) {
                        $entry = ['filename' => $m->filename];
                        if (isset($matAnalyses[(int)$m->id]['key_concepts'])) {
                            $entry['key_concepts'] = $matAnalyses[(int)$m->id]['key_concepts'];
                        }
                        $materialsList[] = $entry;
                    }

                    // Issue reports
                    $issueList = [];
                    foreach ($issueRecords as $ir) {
                        $issueList[] = [
                            'topic' => $ir->topic ?? '',
                            'category' => $ir->category ?? '',
                            'description' => $ir->description ?? '',
                        ];
                    }

                    // Student events (quiz failures, repeated views, etc.)
                    $eventList = [];
                    foreach ($eventRecords as $er) {
                        $eventList[] = [
                            'userid' => (int)$er->userid,
                            'reason' => $er->struggle_reason ?? '',
                            'topic_label' => $er->topic_label ?? '',
                        ];
                    }

                    // Course sections
                    $sections = $DB->get_records('course_sections', ['course' => $cid], 'section ASC',
                        'id, section, name, summary');
                    $sectionListSimple = [];
                    foreach ($sections as $sec) {
                        $sectionListSimple[] = [
                            'name' => $sec->name ?: ('Week ' . ($sec->section + 1)),
                            'section' => (int)$sec->section,
                            'summary' => $sec->summary ?? '',
                        ];
                    }

                    $courseName = $DB->get_field('course', 'fullname', ['id' => $cid]) ?: '';

                    // Previous topics for continuity (from cache or current material-based)
                    $previousTopics = [];
                    foreach ($topicMatrix as $existing) {
                        $previousTopics[] = [
                            'topic_name' => $existing['topic'],
                            'struggle_score' => $existing['struggle_score'],
                            'question_count' => $existing['question_count'],
                        ];
                    }

                    $payload = json_encode([
                        'course_id'        => $cid,
                        'course_name'      => $courseName,
                        'questions'        => $questionTexts,
                        'course_materials' => $materialsList,
                        'course_sections'  => $sectionListSimple,
                        'issue_reports'    => $issueList,
                        'student_events'   => $eventList,
                        'previous_topics'  => $previousTopics,
                    ]);

                    $raw = $client->post($cfg['url'] . '/api/v1/analytics/extract-topics', $payload, [
                        'CURLOPT_TIMEOUT' => 30,
                    ]);
                    $aiResult = json_decode($raw, true);

                    if ($aiResult && isset($aiResult['topics']) && !empty($aiResult['topics'])) {
                        $hasAiResult = true;
                        $aiSummaryInsight = $aiResult['summary_insight'] ?? '';

                        foreach ($aiResult['topics'] as $aiTopic) {
                            $tName = $aiTopic['topic_name'] ?? '';
                            if (!$tName) continue;

                            $evSrc = $aiTopic['evidence_sources'] ?? [];
                            $affectedIds = [];
                            if (!empty($aiTopic['affected_student_ids'])) {
                                $affectedIds = array_map('intval',
                                    array_filter($aiTopic['affected_student_ids'], function($id) {
                                        return is_numeric($id);
                                    }));
                            }

                            // Map evidence_sources to match our schema
                            $eventSources = [
                                'chat_questions'      => (int)($evSrc['chat_questions'] ?? 0),
                                'quiz_failures'       => (int)($evSrc['quiz_failures'] ?? 0),
                                'repeated_views'      => (int)($evSrc['repeated_views'] ?? 0),
                                'assignment_failures' => (int)($evSrc['assignment_failures'] ?? 0),
                                'issue_reports'       => (int)($evSrc['issue_reports'] ?? 0),
                            ];

                            $relatedMats = [];
                            foreach ($aiTopic['related_materials'] ?? [] as $rm) {
                                $relatedMats[] = ['name' => $rm, 'question_count' => 0];
                            }

                            $aiTopics[] = [
                                'topic'             => $tName,
                                'question_count'    => (int)($aiTopic['question_count'] ?? 0),
                                'student_count'     => (int)($aiTopic['student_count'] ?? count($affectedIds)),
                                'struggle_score'    => min(100, max(1, (float)($aiTopic['struggle_score'] ?? 50))),
                                'trend'             => $aiTopic['trend'] ?? 'stable',
                                'trend_pct'         => 0,
                                'difficulty'        => 'intermediate',
                                'severity'          => $aiTopic['severity'] ?? 'watch',
                                'event_sources'     => $eventSources,
                                'materials'         => $relatedMats,
                                'sample_questions'  => array_slice($aiTopic['sample_questions'] ?? [], 0, 3),
                                'suggestion'        => $aiTopic['suggestion'] ?? '',
                                'suggestion_type'   => $aiTopic['suggestion_type'] ?? 'recap',
                                'ai_classified'     => true,
                                'affected_student_ids' => $affectedIds,
                            ];
                        }

                        // Sort by struggle score descending
                        usort($aiTopics, function($a, $b) {
                            return ($b['struggle_score'] ?? 0) - ($a['struggle_score'] ?? 0);
                        });
                    }
                } catch (\Throwable $e) {
                    // AI failed; proceed with material-based topics below
                    $hasAiResult = false;
                }
            }
        }

        // ── MERGE STRATEGY ──
        // AI topics take precedence. Material-based topics (from concept matching)
        // supplement any gaps. Ad-hoc word topics and bigram/trigram are ELIMINATED.
        if ($hasAiResult && !empty($aiTopics)) {
            // Use AI topics as primary, but merge material-based topics that don't overlap
            $aiTopicNames = array_map(function($t) {
                return strtolower(trim(preg_replace('/[^a-z0-9\s]/', '', $t['topic'])));
            }, $aiTopics);

            foreach ($topicMatrix as $existingTopic) {
                $existingNorm = strtolower(trim(preg_replace('/[^a-z0-9\s]/', '', $existingTopic['topic'])));
                // Skip if AI already covers this topic
                $isDuplicate = false;
                foreach ($aiTopicNames as $aiName) {
                    if ($existingNorm === $aiName || strpos($existingNorm, $aiName) !== false || strpos($aiName, $existingNorm) !== false) {
                        $isDuplicate = true;
                        break;
                    }
                }
                // Also skip if this is a junk single-word topic (no material match, no concept match)
                $wordCount = str_word_count($existingTopic['topic']);
                $hasMaterials = !empty($existingTopic['materials']);
                if (!$isDuplicate && ($wordCount >= 2 || $hasMaterials)) {
                    $aiTopics[] = $existingTopic;
                }
            }

            $topicMatrix = $aiTopics;

            // Re-sort
            usort($topicMatrix, function($a, $b) {
                return ($b['struggle_score'] ?? 0) - ($a['struggle_score'] ?? 0);
            });
        }
        // If AI failed or returned nothing, fall through with the existing material-based
        // topicMatrix (no ad-hoc word or bigram/trigram fallback applied).

        // Sort by struggle score descending (final pass)
        usort($topicMatrix, function($a, $b) {
            return $b['struggle_score'] - $a['struggle_score'];
        });

        // Worst topic
        $worstTopic = !empty($topicMatrix) ? $topicMatrix[0]['topic'] : '—';

        // ──────────────────────────────────────────────────────────────
        // 7. Material breakdown (grouped by course sections)
        // ──────────────────────────────────────────────────────────────
        // Get course sections with their modules
        $sections = $DB->get_records('course_sections', ['course' => $cid], 'section ASC', 'id, section, name, summary, sequence');
        $sectionList = [];

        // Build module-id -> section mapping
        $modToSection = [];
        foreach ($sections as $sec) {
            $modIds = array_filter(explode(',', $sec->sequence ?? ''));
            foreach ($modIds as $modId) {
                $modToSection[(int)$modId] = $sec->id;
            }
        }

        // Get course_modules for section grouping
        $allModIds = array_keys($modToSection);
        $cmModules = [];
        if (!empty($allModIds)) {
            list($inSql, $inParams) = $DB->get_in_or_equal($allModIds, SQL_PARAMS_NAMED);
            $cmRows = $DB->get_records_sql(
                "SELECT id, module, instance FROM {course_modules} WHERE id $inSql",
                $inParams
            );
            foreach ($cmRows as $cm) {
                $cmModules[(int)$cm->id] = $cm;
            }
        }

        // Group materials by section
        $uncategorized = [];
        $sectionedMats = []; // sectionid => [materials]

        foreach ($materials as $mid => $mat) {
            $secId = null;
            if ($mat->cmid && isset($modToSection[(int)$mat->cmid])) {
                $secId = $modToSection[(int)$mat->cmid];
            }
            $qCnt = isset($materialQuestions[$mid]) ? count($materialQuestions[$mid]) : 0;

            // Get key_concepts for this material with counts
            $concepts = [];
            if (isset($matAnalyses[$mid])) {
                foreach ($matAnalyses[$mid]['key_concepts'] as $c) {
                    $lc = strtolower(trim($c));
                    $cCnt = 0;
                    if ($lc && isset($topicQuestions[$lc])) {
                        // Count questions that came through this material
                        $cCnt = count($topicQuestions[$lc]);
                    }
                    if ($cCnt > 0) {
                        $concepts[] = [
                            'concept'        => $c,
                            'question_count'  => $cCnt,
                        ];
                    }
                }
            }

            $matEntry = [
                'id'              => (int)$mid,
                'filename'        => $mat->filename,
                'fileid'          => (int)$mat->fileid,
                'question_count'  => $qCnt,
                'student_count'   => 0, // computed below
                'difficulty'      => $matAnalyses[$mid]['difficulty'] ?? 'intermediate',
                'key_concepts'    => $concepts,
            ];

            if ($secId && isset($sections[$secId])) {
                if (!isset($sectionedMats[$secId])) $sectionedMats[$secId] = [];
                $sectionedMats[$secId][] = $matEntry;
            } else {
                $uncategorized[] = $matEntry;
            }
        }

        // Sort materials within each section by question_count
        foreach ($sectionedMats as $secId => &$mats) {
            usort($mats, function($a, $b) {
                return $b['question_count'] - $a['question_count'];
            });
        }
        unset($mats);
        usort($uncategorized, function($a, $b) {
            return $b['question_count'] - $a['question_count'];
        });

        // Build section list
        foreach ($sections as $sec) {
            $secId = (int)$sec->id;
            if (!isset($sectionedMats[$secId]) || empty($sectionedMats[$secId])) continue;
            $sectionName = $sec->name ?: 'Week ' . ($sec->section + 1);
            $sectionList[] = [
                'section_name' => $sectionName,
                'section_num'  => (int)$sec->section + 1,
                'materials'    => $sectionedMats[$secId],
            ];
        }
        // Add uncategorized at the end
        if (!empty($uncategorized)) {
            $sectionList[] = [
                'section_name' => 'Other Materials',
                'section_num'  => 0,
                'materials'    => $uncategorized,
            ];
        }

        // ──────────────────────────────────────────────────────────────
        // 8. At-risk students
        // ──────────────────────────────────────────────────────────────
        $atRiskStudents = [];
        $since30 = time() - (30 * DAYSECS);

        // Score every enrolled student, not only the ones who used the AI
        // assistant. The previous implementation iterated $studentQuestions, so
        // a student who never opened the chat could not appear in the at-risk
        // list at all — precisely the disengaged student a lecturer most needs
        // to see.
        $enrolledUsers = get_enrolled_users($context, 'local/umat_ai:chatwithai', 0,
            'u.id, u.firstname, u.lastname, u.email', null, 0, 0, true);
        if (empty($enrolledUsers)) {
            $enrolledUsers = get_enrolled_users($context, '', 0,
                'u.id, u.firstname, u.lastname, u.email', null, 0, 0, true);
        }

        // One shared course context for the whole batch — this is what keeps
        // scoring N students from issuing N copies of the same course queries.
        $riskCourseContext = student_risk_calculator::build_course_context($cid);
        $riskResults = student_risk_calculator::compute_batch(
            array_keys($enrolledUsers), $cid, $riskCourseContext);

        foreach ($enrolledUsers as $uid => $enrolledUser) {
            $uid   = (int) $uid;
            $qMap  = $studentQuestions[$uid] ?? [];
            $qCnt  = count($qMap);
            $times = array_values($qMap);
            $lastTs  = $times ? max($times) : 0;
            $firstTs = $times ? min($times) : 0;
            $span    = max(1, $lastTs - $firstTs);

            // Recent vs older
            $recentQ = 0;
            $olderQ  = 0;
            foreach ($qMap as $qid => $ts) {
                if ($ts >= $recentCut) $recentQ++;
                elseif ($ts >= $olderCut) $olderQ++;
            }

            // Trend
            $trendPct = 0;
            $trend    = 'stable';
            if ($olderQ > 0) {
                $trendPct = round((($recentQ - $olderQ) / $olderQ) * 100);
                if ($trendPct > 10) $trend = 'up';
                elseif ($trendPct < -10) $trend = 'down';
            } elseif ($recentQ > 0) {
                $trend = 'up';
                $trendPct = 100;
            }

            // Topics this student struggles with
            $stuTopics = [];
            foreach ($topicQuestions as $lc => $tqids) {
                foreach ($tqids as $tqid => $_) {
                    foreach ($qMap as $sqid => $_) {
                        if ((int)$tqid === (int)$sqid) {
                            $stuTopics[$lc] = ($stuTopics[$lc] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($stuTopics);
            $stuTopicNames = array_map(function($lc) use ($allConcepts) {
                return $allConcepts[$lc] ?? ucwords(str_replace('_', ' ', $lc));
            }, array_keys(array_slice($stuTopics, 0, 3)));

            // Topic diversity
            $topicDiv = count($stuTopics);

            // Event context, kept for display only — it no longer feeds risk.
            $stuEv = $studentEvents[$uid] ?? [];
            $evQuizFailures = $stuEv['quiz_failure'] ?? 0;
            $evAssignmentFails = $stuEv['assignment_failure'] ?? 0;
            $evRepeatedViews = $stuEv['repeated_views'] ?? 0;
            $evIssueReports = $stuEv['issue_reported'] ?? 0;

            // ── The one authoritative risk score ─────────────────────────
            // Chat volume, topic diversity and question recency are no longer
            // risk inputs. A student who asks a lot of good questions and
            // performs well is engaged, not at risk.
            $risk = $riskResults[$uid] ?? null;
            if ($risk === null) {
                continue;
            }
            $riskScore = (int) round($risk['risk_score']);
            $riskLevel = $risk['risk_level'];

            // Days since ANY course activity, from the risk model's own
            // evidence — not from a display string parsed back into a date.
            $daysSince = $risk['factors']['inactivity']['raw']['days_inactive'] ?? null;

            $user = $enrolledUser;

            $issueCnt = $evIssueReports + $DB->count_records_select('umat_ai_issue_reports',
                'userid = ? AND courseid = ? AND timecreated > ?', [$uid, $cid, $since]);

            $atRiskStudents[] = [
                'userid'          => $uid,
                'fullname'        => fullname($user),
                'profileimageurl' => (new \moodle_url('/user/pix.php/' . $uid . '/f1.jpg'))->out(false),
                'question_count'  => $qCnt,
                'issue_count'     => (int)$issueCnt,
                'event_sources'   => [
                    'chat_questions'      => $qCnt,
                    'quiz_failures'       => $evQuizFailures,
                    'assignment_failures' => $evAssignmentFails,
                    'repeated_views'      => $evRepeatedViews,
                    'issue_reports'       => $evIssueReports,
                ],
                'struggle_topics' => $stuTopicNames,
                'risk_score'      => $riskScore,
                'risk_level'      => $riskLevel,
                'trend'           => $trend,
                'days_inactive'   => $daysSince,
                'last_active'     => $daysSince === null
                    ? 'No recorded activity'
                    : ($daysSince < 1 ? 'Active today'
                        : $daysSince . ' day' . ($daysSince === 1 ? '' : 's') . ' ago'),
                '_risk'           => $risk,
            ];
        }

        // Sort by risk score descending
        usort($atRiskStudents, function($a, $b) {
            return $b['risk_score'] - $a['risk_score'];
        });

        // The risk enrichment that used to live here has moved into the loop
        // above: risk is now computed once, up front, by the authoritative
        // calculator rather than layered on top of a second formula.

        // ──────────────────────────────────────────────────────────────
        // 9. Recording struggle (if transcripts exist)
        // ──────────────────────────────────────────────────────────────
        $recordingStruggle = [];
        $sessions = $DB->get_records_sql(
            "SELECT id, sessionid, recording_url, transcript_json, timecreated
               FROM {umat_ai_sessions}
              WHERE courseid = :cid AND status = 'completed'
                AND transcript_json IS NOT NULL AND transcript_json != ''
           ORDER BY timecreated DESC",
            ['cid' => $cid],
            0, 20
        );
        foreach ($sessions as $sess) {
            $transcript = json_decode($sess->transcript_json, true);
            if (!is_array($transcript)) continue;

            $segments = [];
            $totalSegQ = 0;
            foreach ($transcript as $seg) {
                $segText = $seg['text'] ?? '';
                $start   = (float)($seg['start'] ?? 0);
                $end     = (float)($seg['end'] ?? $start + 30);
                if (!$segText) continue;

                // Count questions that mention text from this segment
                $segQCount = 0;
                $segKeywords = array_filter(explode(' ', strtolower($segText)));
                $segKeywords = array_filter($segKeywords, function($w) { return strlen($w) > 3; });
                foreach ($logs as $l) {
                    $qtext = strtolower($l->question);
                    $match = 0;
                    foreach ($segKeywords as $kw) {
                        if (strpos($qtext, $kw) !== false) $match++;
                    }
                    if ($match >= 2) $segQCount++;
                }
                $totalSegQ += $segQCount;

                $m = floor($start / 60);
                $s = floor($start % 60);
                $segments[] = [
                    'start_sec'      => $start,
                    'end_sec'        => $end,
                    'timestamp'      => $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT),
                    'text_snippet'   => mb_substr($segText, 0, 80) . (mb_strlen($segText) > 80 ? '…' : ''),
                    'question_count' => $segQCount,
                    'struggle_level' => $segQCount > 5 ? 'high' : ($segQCount > 2 ? 'medium' : 'low'),
                ];
            }

            if (!empty($segments)) {
                $recordingStruggle[] = [
                    'id'          => (int)$sess->id,
                    'title'       => 'Lecture — ' . date('d M Y', $sess->timecreated),
                    'url'         => $sess->recording_url,
                    'segments'    => $segments,
                    'total_questions' => $totalSegQ,
                ];
            }
        }

    // ──────────────────────────────────────────────────────────────
    // 10. Optional: AI service enhancement
    // ──────────────────────────────────────────────────────────────
    $aiServiceUsed = false;
    $aiOverallSummary = '';
    $aiCourseHealth = null;
    $cfg = \local_umat_ai_get_service_config();
    $totalEvents = 0;
    $eventBreakdown = ['quiz_failures' => 0, 'repeated_views' => 0, 'assignment_failures' => 0, 'issue_reports' => 0];
    if (!empty($cfg['token'])) {
        try {
            require_once($CFG->libdir . '/filelib.php');
            $client = new \curl(['ignoresecurity' => \local_umat_ai_is_localhost($cfg['url'])]);
            $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . \local_umat_ai_request_id()]);
            $client->setopt(['CURLOPT_TIMEOUT' => 15]);

            // Collect questions for AI classification
            $questionItems = [];
            $knownTopics = array_values(array_map(function($t) { return $t['topic']; }, $topicMatrix));
            foreach ($logs as $l) {
                $questionItems[] = ['id' => (int)$l->id, 'text' => preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $l->question)];
                if (count($questionItems) >= 100) break; // batch limit
            }

            if (!empty($questionItems)) {
                $payload = json_encode([
                    'course_id' => $cid,
                    'questions' => $questionItems,
                    'known_topics' => $knownTopics,
                ]);
                $raw = $client->post($cfg['url'] . '/api/v1/analytics/classify-questions', $payload);
                $aiResult = json_decode($raw, true);

                if ($aiResult && isset($aiResult['classifications'])) {
                    // Merge AI classifications into topic matrix
                    $aiTopics = [];
                    foreach ($aiResult['classifications'] as $c) {
                        $topicName = $c['topic'] ?? 'Other';
                        $qid = $c['id'] ?? 0;
                        if (!isset($aiTopics[$topicName])) {
                            $aiTopics[$topicName] = ['question_ids' => [], 'struggle_types' => []];
                        }
                        $aiTopics[$topicName]['question_ids'][] = $qid;
                        if (isset($c['struggle_type'])) {
                            $aiTopics[$topicName]['struggle_types'][] = $c['struggle_type'];
                        }
                    }

                    // If AI returned meaningful classifications, use them to enrich
                    if (count($aiTopics) >= count($topicMatrix) * 0.5) {
                        // Merge AI topics into existing matrix or add new ones
                        foreach ($aiTopics as $tName => $tData) {
                            $found = false;
                            foreach ($topicMatrix as &$existing) {
                                if (strcasecmp($existing['topic'], $tName) === 0) {
                                    $found = true;
                                    // AI may have reclassified some questions - update count
                                    $existing['ai_classified'] = true;
                                    break;
                                }
                            }
                            unset($existing);
                            if (!$found && count($tData['question_ids']) >= 2) {
                                // New topic discovered by AI
                                $topicMatrix[] = [
                                    'topic' => $tName,
                                    'question_count' => count($tData['question_ids']),
                                    'student_count' => 0,
                                    'struggle_score' => 50,
                                    'trend' => 'stable',
                                    'trend_pct' => 0,
                                    'difficulty' => 'intermediate',
                                    'event_sources' => ['chat_questions' => count($tData['question_ids']), 'quiz_failures' => 0, 'repeated_views' => 0, 'assignment_failures' => 0, 'issue_reports' => 0],
                                    'materials' => [],
                                    'ai_classified' => true,
                                ];
                            }
                        }
                        usort($topicMatrix, function($a, $b) {
                            return ($b['struggle_score'] ?? 0) - ($a['struggle_score'] ?? 0);
                        });
                        $aiServiceUsed = true;
                        if (!empty($topicMatrix)) {
                            $worstTopic = $topicMatrix[0]['topic'];
                        }
                    }
                }
            }

            // ── AI struggle-topics enrichment ──
            if (!empty($topicMatrix)) {
                $aiStruggleTopics = [];
                foreach ($topicMatrix as $tm) {
                    $aiStruggleTopics[] = [
                        'topic'          => $tm['topic'],
                        'question_count' => $tm['question_count'],
                        'student_count'  => $tm['student_count'],
                        'struggle_score' => $tm['struggle_score'],
                        'event_sources'  => $tm['event_sources'] ?? [],
                    ];
                }
                $payload = json_encode(['topics' => $aiStruggleTopics]);
                $raw2 = $client->post($cfg['url'] . '/api/v1/analytics/struggle-topics', $payload);
                $stResult = json_decode($raw2, true);
                if ($stResult && isset($stResult['topics'])) {
                    $aiRecs = [];
                    foreach ($stResult['topics'] as $aiT) {
                        $aiRecs[$aiT['topic']] = $aiT['recommendation'] ?? '';
                    }
                    foreach ($topicMatrix as &$tm) {
                        if (isset($aiRecs[$tm['topic']])) {
                            $tm['ai_recommendation'] = $aiRecs[$tm['topic']];
                        }
                    }
                    unset($tm);
                }
                if ($stResult && isset($stResult['summary'])) {
                    $aiOverallSummary = $stResult['summary'];
                }
            }

            // ── AI student-risk enrichment ──
            if (!empty($atRiskStudents)) {
                $aiStudentData = [];
                foreach ($atRiskStudents as $s) {
                    $aiStudentData[] = [
                        'user_id'        => $s['userid'],
                        'fullname'       => $s['fullname'],
                        'question_count' => $s['question_count'],
                        'struggle_topics' => $s['struggle_topics'],
                        'risk_score'     => $s['risk_score'],
                        'trend'          => $s['trend'],
                        'event_sources'  => $s['event_sources'] ?? [],
                    ];
                }
                $payload = json_encode(['students' => $aiStudentData]);
                $raw3 = $client->post($cfg['url'] . '/api/v1/analytics/student-risk', $payload);
                $srResult = json_decode($raw3, true);
                if ($srResult && isset($srResult['students'])) {
                    $aiRiskMap = [];
                    foreach ($srResult['students'] as $aiS) {
                        $aiRiskMap[$aiS['user_id']] = [
                            'risk_factors'    => $aiS['risk_factors'] ?? [],
                            'recommendation'  => $aiS['recommendation'] ?? '',
                        ];
                    }
                    foreach ($atRiskStudents as &$s) {
                        if (isset($aiRiskMap[$s['userid']])) {
                            $s['ai_risk_factors']   = $aiRiskMap[$s['userid']]['risk_factors'];
                            $s['ai_recommendation'] = $aiRiskMap[$s['userid']]['recommendation'];
                        }
                    }
                    unset($s);
                }
            }

            // ── AI course-health report ──
            $totalEvents = 0;
            $eventBreakdown = ['quiz_failures' => 0, 'repeated_views' => 0, 'assignment_failures' => 0, 'issue_reports' => 0];
            foreach ($topicEvents as $evNorm => $evReasons) {
                foreach ($evReasons as $reason => $ec) {
                    $mapKey = ['quiz_failure' => 'quiz_failures', 'repeated_views' => 'repeated_views',
                               'assignment_failure' => 'assignment_failures', 'issue_reported' => 'issue_reports'];
                    $k = $mapKey[$reason] ?? null;
                    if ($k) $eventBreakdown[$k] += $ec;
                }
            }
            $totalEvents = array_sum($eventBreakdown);

            if (!empty($topicMatrix)) {
                $healthPayload = [
                    'course_id'        => $cid,
                    'total_questions'  => $totalQuestions,
                    'total_students'   => $uniqueStudents,
                    'worst_topic'      => $worstTopic,
                    'topic_matrix'     => array_map(function($t) {
                        return [
                            'topic'          => $t['topic'],
                            'question_count' => $t['question_count'],
                            'struggle_score' => $t['struggle_score'],
                            'trend'          => $t['trend'],
                            'event_sources'  => $t['event_sources'] ?? [],
                        ];
                    }, array_slice($topicMatrix, 0, 10)),
                    'at_risk_students' => array_map(function($s) {
                        return [
                            'fullname'       => $s['fullname'],
                            'question_count' => $s['question_count'],
                            'risk_score'     => $s['risk_score'],
                            'struggle_topics'=> $s['struggle_topics'],
                        ];
                    }, array_slice($atRiskStudents, 0, 5)),
                    'event_breakdown'  => $eventBreakdown,
                    'total_events'     => $totalEvents,
                    'total_issues'     => $totalIssues,
                    'open_issues'      => $openIssues,
                ];
                $payload = json_encode($healthPayload);
                $raw4 = $client->post($cfg['url'] . '/api/v1/analytics/course-health', $payload);
                $chResult = json_decode($raw4, true);
                if ($chResult) {
                    $aiCourseHealth = $chResult;
                }
            }
        } catch (\Throwable $e) {
            $aiServiceUsed = false;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 12. Generate actionable insights (narrative cards)
    // ──────────────────────────────────────────────────────────────

    // -- 12a. Struggle area narratives (topic cards with stories) --
    $struggleAreas = [];
    $nowTs = time();
    $recent14 = $nowTs - (14 * DAYSECS);
    $older14 = $nowTs - (28 * DAYSECS);
    $recent7 = $nowTs - (7 * DAYSECS);
    $older7 = $nowTs - (14 * DAYSECS);

    foreach ($topicMatrix as $tm) {
        $stuPct = $enrolledCount > 0 ? round(($tm['student_count'] / $enrolledCount) * 100) : 0;
        $score  = $tm['struggle_score'] ?? 0;

        // Use AI severity if available, otherwise compute from data
        if (!empty($tm['severity'])) {
            $severity = $tm['severity'];
        } else {
            if ($stuPct >= 50 || $score >= 70) {
                $severity = 'critical';
            } elseif ($stuPct >= 25 || $score >= 40) {
                $severity = 'attention';
            } else {
                $severity = 'watch';
            }
        }

        // Generate description from data
        $descParts = [];
        if ($tm['student_count'] > 0 && $enrolledCount > 0) {
            $descParts[] = $tm['student_count'] . ' of ' . $enrolledCount . ' students (' . $stuPct . '%)';
        }
        // Find what they struggle with from sample questions
        $sampleQ = $tm['sample_questions'] ?? [];
        if (!empty($sampleQ)) {
            $descParts[] = 'asking about: ' . $sampleQ[0];
        }
        $description = implode(' are struggling with this concept. They\'ve asked ', [
            $tm['student_count'] . ' of ' . $enrolledCount . ' students (' . $stuPct . '%) are struggling',
            $tm['question_count'] . ' questions'
        ]);
        if ($tm['trend'] === 'up' && $tm['trend_pct'] > 0) {
            $description .= ' — up ' . $tm['trend_pct'] . '% from last week';
        }
        $description .= '.';

        // Use AI suggestion if available, otherwise generate from heuristics
        if (!empty($tm['suggestion'])) {
            $suggestionText = $tm['suggestion'];
            $suggestionType = $tm['suggestion_type'] ?? 'recap';
        } else {
            $suggestion = self::generate_topic_suggestion($tm, $stuPct, $enrolledCount);
            $suggestionText = $suggestion['text'];
            $suggestionType = $suggestion['type'];
        }

        // Get sample questions from topic_matrix (may have been added by AI or query matching)
        $topicSampleQ = $tm['sample_questions'] ?? [];
        // Also try to pull actual questions from logs for this topic
        if (empty($topicSampleQ)) {
            $topicSampleQ = self::get_sample_questions_for_topic($tm['topic'], $logs, 3);
        }

        // Compute avg quiz score for this topic (from student events)
        $topicQuizScores = [];
        foreach ($topicEvents as $evNorm => $evReasons) {
            $topicNorm2 = strtolower(preg_replace('/[^a-z0-9\s]/', '', $tm['topic']));
            if ($evNorm === $topicNorm2 || strpos($topicNorm2, $evNorm) !== false || strpos($evNorm, $topicNorm2) !== false) {
                // We don't have per-topic quiz scores directly, but we have quiz_failures count
            }
        }

        $struggleAreas[] = [
            'topic'              => $tm['topic'],
            'severity'           => $severity,
            'student_count'      => $tm['student_count'],
            'total_students'     => $enrolledCount,
            'student_pct'        => $stuPct,
            'question_count'     => $tm['question_count'],
            'prev_question_count' => 0,
            'trend'              => $tm['trend'],
            'trend_pct'          => $tm['trend_pct'],
            'struggle_score'     => $score,
            'description'        => $description,
            'sample_questions'   => array_slice($topicSampleQ, 0, 3),
            'suggestion'         => $suggestionText,
            'suggestion_type'    => $suggestionType,
            'materials'          => array_map(function($m) {
                return ['name' => $m['name'], 'question_count' => $m['question_count']];
            }, array_slice($tm['materials'] ?? [], 0, 3)),
            'affected_student_ids' => $tm['affected_student_ids'] ?? array_keys($topicStudents[strtolower($tm['topic'])] ?? []),
            'ai_explanation'     => $description,
            'confidence'         => min(99, max(50, $score + 5)),
            'evidence_sources'   => $tm['event_sources'] ?? [],
            'recommendation'     => $suggestionText,
        ];
    }

    // -- 12b. Section struggle breakdown --
    $sectionStruggle = [];
    foreach ($sectionList as $sec) {
        $secQCnt = 0;
        $secStuCnt = 0;
        $secTopics = [];
        foreach ($sec['materials'] as $mat) {
            $secQCnt += $mat['question_count'];
            foreach ($mat['key_concepts'] as $kc) {
                $secTopics[$kc['concept']] = ($secTopics[$kc['concept']] ?? 0) + $kc['question_count'];
            }
        }
        // Count unique students in this section's materials
        foreach ($topicStudents as $lc => $stuMap) {
            foreach ($sec['materials'] as $mat) {
                $matId = $mat['id'];
                if (isset($topicMaterials[$lc][$matId])) {
                    $secStuCnt = max($secStuCnt, count($stuMap));
                }
            }
        }
        arsort($secTopics);
        $topTopics = array_slice(array_keys($secTopics), 0, 3);

        $secPct = $enrolledCount > 0 ? round(($secStuCnt / $enrolledCount) * 100) : 0;
        $secSeverity = $secPct >= 50 ? 'critical' : ($secPct >= 25 ? 'attention' : 'watch');

        $hint = '';
        if ($secSeverity === 'critical') {
            $hint = '⚠️ Needs recap — ' . implode(', ', $topTopics);
        } elseif ($secSeverity === 'attention') {
            $hint = '📎 ' . implode(', ', $topTopics);
        } else {
            $hint = '✅ Healthy' . ($secPct > 0 ? ' — minor issues' : ' — no issues');
        }

        $sectionStruggle[] = [
            'section_name' => $sec['section_name'],
            'section_num'  => $sec['section_num'],
            'struggle_pct' => $secPct,
            'student_count' => $secStuCnt,
            'question_count' => $secQCnt,
            'severity'     => $secSeverity,
            'top_topics'   => $topTopics,
            'hint'         => $hint,
        ];
    }

    // -- 12c. Material struggle list --
    $materialStruggle = [];
    foreach ($sectionList as $sec) {
        foreach ($sec['materials'] as $mat) {
            if ($mat['question_count'] < 1) continue;
            $matTopics = array_map(function($kc) { return $kc['concept']; }, $mat['key_concepts']);
            $suggestion = '';
            if (!empty($matTopics)) {
                $suggestion = 'Review this material — students struggle with: ' . implode(', ', array_slice($matTopics, 0, 2));
            } else {
                $suggestion = 'Students have asked ' . $mat['question_count'] . ' question(s) about this material';
            }
            $materialStruggle[] = [
                'material_name'   => $mat['filename'],
                'question_count'  => $mat['question_count'],
                'struggle_topics' => $matTopics,
                'suggestion'      => $suggestion,
            ];
        }
    }
    usort($materialStruggle, function($a, $b) { return $b['question_count'] - $a['question_count']; });
    $materialStruggle = array_slice($materialStruggle, 0, 10);

    // -- 12d. Student narratives --
    $studentNarratives = [];
    foreach ($atRiskStudents as $s) {
        $summary = self::generate_student_summary($s, $enrolledCount);
        $suggestion = self::generate_student_suggestion($s);

        // Evidence comes from the risk model's own factor breakdown, so what a
        // lecturer reads is exactly what was scored — nothing more, nothing
        // invented. Only factors that actually had data appear.
        $risk = $s['_risk'] ?? null;
        $reasons = [];
        $evidence = [];
        $ev = $s['event_sources'] ?? [];

        if ($risk !== null) {
            foreach ($risk['evidence'] as $row) {
                $evidence[] = $row['label'] . ': ' . $row['detail'];
                // A factor is only worth naming as a reason when it is actually
                // contributing — a third or more of its available points.
                if ($row['points_max'] > 0 && ($row['points_earned'] / $row['points_max']) >= 0.34) {
                    $reasons[] = $row['detail'];
                }
            }
        }

        // Context that is reported but never scored.
        if (($ev['issue_reports'] ?? 0) > 0) {
            $evidence[] = sprintf('Support issues filed: %d (not counted as academic risk)', $ev['issue_reports']);
        }
        if (!empty($s['struggle_topics'])) {
            $evidence[] = 'Recurring questions: ' . implode(', ', array_slice($s['struggle_topics'], 0, 3));
        }

        if (empty($reasons)) {
            $reasons[] = $risk['primary_reason'] ?? 'No dominant risk factor.';
        }

        // Plain-language interpretation that distinguishes academic struggle
        // from disengagement, rather than restating the score.
        $explanation = $risk['summary'] ?? 'Not enough evidence to interpret this student\'s position.';

        // Recommendations follow the classification, so they match the reason.
        $recommendations = [];
        $classification = $risk['classification'] ?? 'low_risk';
        switch ($classification) {
            case 'academically_struggling':
                $recommendations[] = 'Review the specific concepts below with this student — performance, not attendance, is the gap.';
                $recommendations[] = 'Offer targeted practice on their weakest quiz topics.';
                break;
            case 'assessment_risk':
                $recommendations[] = 'Check whether the missed submissions are a deadline problem or a comprehension problem.';
                $recommendations[] = 'Confirm the student can still submit, and agree a catch-up date.';
                break;
            case 'attendance_risk':
                $recommendations[] = 'Ask why live sessions are being missed before assuming disengagement.';
                $recommendations[] = 'Point the student to the recordings for the sessions they missed.';
                break;
            case 'disengaged':
                $recommendations[] = 'Send a check-in message — there is no recent activity of any kind to work from.';
                break;
            case 'resource_engagement_risk':
                $recommendations[] = 'Confirm the student knows which materials are published and where to find them.';
                break;
            case 'monitoring':
                $recommendations[] = 'No action needed today; revisit if the trend continues.';
                break;
            default:
                $recommendations[] = 'No intervention indicated by the current evidence.';
        }

        // Quick actions
        $quickActions = [
            ['action' => 'view_activity', 'label' => 'View Activity', 'icon' => 'timeline'],
            ['action' => 'send_message', 'label' => 'Send Message', 'icon' => 'mail'],
            ['action' => 'recommend_resource', 'label' => 'Recommend Resource', 'icon' => 'school'],
            ['action' => 'view_quiz_history', 'label' => 'View Quiz History', 'icon' => 'quiz'],
        ];

        $daysSince = $s['days_inactive'] ?? null;
        $quizAvg = $risk['factors']['quiz_performance']['raw']['avg_pct'] ?? null;

        $studentNarratives[] = [
            'userid'             => $s['userid'],
            'fullname'           => $s['fullname'],
            'profileimageurl'    => $s['profileimageurl'],
            'risk_score'         => $s['risk_score'],
            'risk_level'         => $s['risk_level'],
            'summary'            => $summary,
            'struggle_topics'    => $s['struggle_topics'],
            'last_active'        => $s['last_active'],
            'days_since_last_login' => $daysSince === null ? 0 : (int) $daysSince,
            'question_count'     => $s['question_count'],
            'avg_quiz'           => $quizAvg === null ? 0 : (float) $quizAvg,
            'ai_queries'         => $s['question_count'],
            'quiz_failures'      => $ev['quiz_failures'] ?? 0,
            'issue_reports'      => $ev['issue_reports'] ?? 0,
            'suggestion'         => $suggestion['text'],
            'suggestion_type'    => $suggestion['type'],
            'reasons'            => $reasons,
            'evidence'           => $evidence,
            'explanation'        => $explanation,
            // Confidence is evidence completeness, not a restatement of the
            // score. The old formula was min(99, max(50, risk_score + 5)).
            'confidence'         => (int) ($risk['confidence_pct'] ?? 30),
            'recommendation'     => $recommendations,
            'quick_actions'      => $quickActions,
            'trend'              => $s['trend'],

            // The full, auditable risk record the redesigned UI renders.
            'v2_risk'            => $risk === null ? null : [
                'risk_score'     => (float) $risk['risk_score'],
                'risk_level'     => $risk['risk_level'],
                'confidence'     => (float) $risk['confidence'],
                'classification' => $risk['classification'],
                'category_label' => $risk['category_label'],
                'primary_reason' => $risk['primary_reason'],
                'summary'        => $risk['summary'],
                'evidence'       => array_map(function ($row) {
                    return [
                        'factor'        => $row['factor'],
                        'label'         => $row['label'],
                        'detail'        => $row['detail'],
                        'points_earned' => (float) $row['points_earned'],
                        'points_max'    => (int) $row['points_max'],
                    ];
                }, $risk['evidence']),
                'trends'         => array_map(function ($t) {
                    return [
                        'direction'  => $t['direction'] ?? 'unknown',
                        'comparable' => !empty($t['comparable']),
                    ];
                }, $risk['trends']),
                'date_range'     => [
                    'from' => (int) $risk['date_range']['from'],
                    'to'   => (int) $risk['date_range']['to'],
                    'days' => (int) $risk['date_range']['days'],
                ],
                'calculated_at'  => (int) $risk['calculated_at'],
            ],
        ];
    }

    // -- 12e. Common questions (question radar) --
    // $logs is already academic-only, and near-duplicate phrasings are
    // collapsed into a single intent by the classifier's dedup key, so
    // "What is a payment gateway" and "how does the payment gateway work?"
    // no longer occupy two rows.
    $questionCounts = [];
    foreach (academic_query_classifier::build_question_map($logs) as $entry) {
        $questionCounts[$entry['dedup_key']] = [
            'text'         => $entry['question'],
            'count'        => (int) $entry['count'],
            'students'     => array_fill_keys($entry['studentids'], true),
            'topic'        => '',
            'variant_count' => (int) ($entry['variant_count'] ?? 1),
            'last_asked'   => (int) ($entry['last_asked'] ?? 0),
        ];
    }

    // Assign topics to questions
    foreach ($questionCounts as &$qc) {
        $qLower = strtolower($qc['text']);
        foreach ($topicMatrix as $tm) {
            $topicLower = strtolower($tm['topic']);
            if (strpos($qLower, $topicLower) !== false || strpos($topicLower, $qLower) !== false) {
                $qc['topic'] = $tm['topic'];
                break;
            }
            // Check sample questions
            foreach (($tm['sample_questions'] ?? []) as $sq) {
                if (similar_text(strtolower($sq), $qLower) > strlen($qLower) * 0.4) {
                    $qc['topic'] = $tm['topic'];
                    break 2;
                }
            }
        }
        if (empty($qc['topic'])) {
            $qc['topic'] = 'Unmatched to a material';
        }
    }
    unset($qc);
    // Breadth first — five students asking once matters more than one student
    // asking five times.
    $questionCounts = array_values($questionCounts);
    usort($questionCounts, function($a, $b) {
        $sa = count($a['students']);
        $sb = count($b['students']);
        if ($sa !== $sb) {
            return $sb - $sa;
        }
        return $b['count'] - $a['count'];
    });

    $commonQuestions = [];
    foreach (array_slice($questionCounts, 0, 15) as $qc) {
        $stuCnt = count($qc['students']);
        // Show anything more than one student asked, or that one student
        // returned to. A single one-off question is not a class-wide signal.
        if ($stuCnt < 2 && $qc['count'] < 2) {
            continue;
        }
        $topicName = $qc['topic'];
        $suggestion = '';
        // Find matching topic suggestion
        foreach ($struggleAreas as $sa) {
            if (strtolower($sa['topic']) === strtolower($topicName)) {
                $suggestion = $sa['suggestion'];
                break;
            }
        }
        if (empty($suggestion)) {
            $suggestion = $stuCnt >= 2
                ? sprintf('Asked by %d students — worth a short clarification in class.', $stuCnt)
                : sprintf('One student returned to this %d times — a targeted follow-up may resolve it.', $qc['count']);
        }

        $commonQuestions[] = [
            'text'          => $qc['text'],
            'student_count' => $stuCnt,
            'ask_count'     => $qc['count'],
            'topic'         => $topicName,
            'suggestion'    => $suggestion,
            // How it reads: breadth versus persistence.
            'interpretation' => $stuCnt >= 2
                ? sprintf('%d students asked this %d times', $stuCnt, $qc['count'])
                : sprintf('1 student asked this %d times', $qc['count']),
        ];
    }

    // -- 12f. Course pulse --
    // Compare this week vs last week
    $thisWeekStart = $nowTs - (7 * DAYSECS);
    $lastWeekStart = $nowTs - (14 * DAYSECS);
    $thisWeekQ = 0;
    $lastWeekQ = 0;
    $thisWeekStudents = [];
    $lastWeekStudents = [];
    foreach ($logs as $l) {
        if ($l->timecreated >= $thisWeekStart) {
            $thisWeekQ++;
            $thisWeekStudents[$l->userid] = true;
        } elseif ($l->timecreated >= $lastWeekStart) {
            $lastWeekQ++;
            $lastWeekStudents[$l->userid] = true;
        }
    }

    // Question-volume change, with a guarded percentage. Dividing this week's
    // count by a previous week of 1 is what produced values like "+957%", and
    // a previous week of 0 used to be hard-coded to "+100%". When the baseline
    // is too small, pct_change is null and the UI shows the raw counts only.
    $qChange = safe_percentage::change($thisWeekQ, $lastWeekQ);
    $qTrend = $qChange['direction'];
    $qTrendPct = $qChange['pct_change'];
    $qTrendComparable = $qChange['comparable'];

    // Top struggle topic for pulse
    $topStruggle = !empty($struggleAreas) ? $struggleAreas[0]['topic'] : '—';
    $topStruggleTrend = !empty($struggleAreas) ? ($struggleAreas[0]['trend_pct'] > 0 ? '+' . $struggleAreas[0]['trend_pct'] . '%' : 'stable') : '—';

    // Disengaged students (no recorded activity in 7+ days).
    // The previous version ran strtotime('-3 days ago') on a display string,
    // which returns false, so this list was populated with nonsense.
    $disengagedStudents = [];
    foreach ($atRiskStudents as $s) {
        $days = $s['days_inactive'] ?? null;
        if ($days !== null && $days >= 7) {
            $disengagedStudents[] = ['name' => $s['fullname'], 'days' => (int) $days];
        }
    }

    // Improving topics
    $improvingTopics = [];
    foreach ($topicMatrix as $tm) {
        if ($tm['trend'] === 'down' && $tm['question_count'] > 0) {
            $improvingTopics[] = $tm['topic'];
        }
    }

    // ── Real quiz average ────────────────────────────────────────────────
    // This used to be 100 minus the average risk score, which meant the "Avg
    // Quiz" tile showed an inversion of a number that was itself mostly AI chat
    // volume. It is now the actual mean of graded attempts, normalised against
    // each quiz's maximum grade, and null when the course has no graded
    // attempts at all.
    $quizStats = $DB->get_record_sql(
        "SELECT AVG(qg.grade / q.grade) AS avg_ratio, COUNT(qg.id) AS attempts,
                COUNT(DISTINCT qg.userid) AS students
           FROM {quiz_grades} qg
           JOIN {quiz} q ON q.id = qg.quiz
          WHERE q.course = :cid AND q.grade > 0",
        ['cid' => $cid]
    );
    $avgQuiz = null;
    $quizAttempts = 0;
    if ($quizStats && $quizStats->avg_ratio !== null && (int) $quizStats->attempts > 0) {
        $avgQuiz = (int) round(max(0, min(100, ((float) $quizStats->avg_ratio) * 100)));
        $quizAttempts = (int) $quizStats->attempts;
    }

    // At-risk = medium or above on the one authoritative model.
    $atRiskCountPulse = 0;
    foreach ($atRiskStudents as $s) {
        if (in_array($s['risk_level'], ['critical', 'high', 'medium'], true)) {
            $atRiskCountPulse++;
        }
    }

    $coursePulse = [
        'avg_quiz'              => $avgQuiz === null ? 0 : $avgQuiz,
        'avg_quiz_available'    => $avgQuiz !== null,
        'quiz_attempts'         => $quizAttempts,
        'quiz_trend'            => 'unknown',
        'quiz_trend_pct'        => 0,
        'at_risk_count'         => $atRiskCountPulse,
        'at_risk_trend'         => 'unknown',
        'at_risk_trend_delta'   => 0,
        'top_struggle_topic'    => $topStruggle,
        'top_struggle_trend'    => $topStruggleTrend,
        'active_this_week'      => count($thisWeekStudents),
        'total_students'        => $enrolledCount,
        'questions_this_week'   => $thisWeekQ,
        'questions_last_week'   => $lastWeekQ,
        'questions_trend'       => $qTrend,
        // Null when the baseline is too small to divide by. The JS renders the
        // absolute counts and the comparison period instead.
        'questions_trend_pct'   => $qTrendPct === null ? 0 : (int) $qTrendPct,
        'questions_trend_comparable' => $qTrendComparable,
        'questions_period_label'     => 'this week vs last week',
        // Message counts by intent, so a rise in "questions" can be read as
        // engagement, confusion or simply more greetings.
        'messages_total'        => $totalRawMessages,
        'messages_academic'     => $totalQuestions,
        'messages_greeting'     => $intentBreakdown['greeting'] ?? 0,
        'messages_command'      => $intentBreakdown['command'] ?? 0,
        'messages_filler'       => $intentBreakdown['filler'] ?? 0,
        // BBB attendance summary (tile data; full detail via get_session_attendance)
        'bbb_available'           => bbb_attendance_analyser::is_available($cid),
    ];

    // Compute attendance summary for the course-pulse tile.
    $bbbAvailable = $coursePulse['bbb_available'];
    if ($bbbAvailable) {
        $bbbSummary = bbb_attendance_analyser::get_course_attendance_summary($cid);
        $coursePulse['bbb_total_sessions']       = $bbbSummary['total_sessions'];
        $coursePulse['bbb_avg_attendance_rate']  = $bbbSummary['avg_attendance_rate'];
        $coursePulse['bbb_attended_count']       = count($bbbSummary['students_who_attended']);
        $coursePulse['bbb_never_attended_count'] = count($bbbSummary['students_who_never_attended']);
    } else {
        $coursePulse['bbb_total_sessions']       = 0;
        $coursePulse['bbb_avg_attendance_rate']  = 0.0;
        $coursePulse['bbb_attended_count']       = 0;
        $coursePulse['bbb_never_attended_count'] = 0;
    }

    // -- 12g. Priority actions --
    $priorityActions = [];

    // Recap needed (topics with high struggle + trend up)
    $recapTopics = [];
    foreach ($struggleAreas as $sa) {
        if ($sa['severity'] === 'critical' || ($sa['severity'] === 'attention' && $sa['trend'] === 'up')) {
            $recapTopics[] = $sa;
        }
    }
    if (!empty($recapTopics)) {
        $items = array_map(function($sa) {
            return [
                'name'     => $sa['topic'],
                'students' => $sa['student_count'],
                'pct'      => $sa['student_pct'],
                'avg_quiz' => 0,
                'trend'    => $sa['trend'] === 'up' ? '+' . $sa['trend_pct'] . '%' : $sa['trend'],
            ];
        }, array_slice($recapTopics, 0, 5));
        $topicNames = array_map(function($t) { return $t['topic']; }, $recapTopics);
        $urgency = $recapTopics[0]['severity'] === 'critical' ? 'high' : 'medium';
        $priorityActions[] = [
            'type'       => 'recap_needed',
            'urgency'    => $urgency,
            'icon'       => 'school',
            'title'      => 'Recap Needed',
            'text'       => count($recapTopics) . ' topic' . (count($recapTopics) > 1 ? 's need' : ' needs') . ' immediate attention — students are increasingly confused.',
            'items'      => $items,
            'suggestion' => 'Dedicate 20 minutes in your next lecture to recap these topics. Start with ' . $recapTopics[0]['topic'] . ' — it\'s the most urgent.',
            'action_label' => 'View Affected Students',
        ];
    }

    // Students disengaging
    if (!empty($disengagedStudents)) {
        $names = array_map(function($d) { return $d['name'] . ' (' . $d['days'] . ' days)'; }, $disengagedStudents);
        $urgency = count($disengagedStudents) >= 3 ? 'high' : 'medium';
        $priorityActions[] = [
            'type'       => 'disengagement',
            'urgency'    => $urgency,
            'icon'       => 'person_off',
            'title'      => 'Students Disengaging',
            'text'       => count($disengagedStudents) . ' student' . (count($disengagedStudents) > 1 ? 's haven' . "'" . 't' : ' hasn' . "'" . 't') . ' logged in for 7+ days.',
            'items'      => array_map(function($d) { return ['name' => $d['name'], 'days' => $d['days']]; }, $disengagedStudents),
            'suggestion' => 'Send a quick encouragement message. A simple check-in can make a difference.',
            'action_label' => 'Send Encouragement',
        ];
    }

    // Improving topics (positive reinforcement)
    if (!empty($improvingTopics)) {
        $priorityActions[] = [
            'type'       => 'improving',
            'urgency'    => 'low',
            'icon'       => 'trending_down',
            'title'      => 'Improving',
            'text'       => implode(', ', array_slice($improvingTopics, 0, 3)) . ' — student understanding improved this week.',
            'items'      => [],
            'suggestion' => 'Your recent approach is working! Consider applying the same format to other struggling topics.',
            'action_label' => null,
        ];
    }

    // Academic question activity falling away. Stated in absolute counts; the
    // percentage is only added when the baseline is large enough to support it.
    if ($qTrend === 'down' && $qTrendComparable) {
        $drop = $qTrendPct !== null ? ' (' . abs((int) $qTrendPct) . '% down)' : '';
        $priorityActions[] = [
            'type'       => 'quiz_drop',
            'urgency'    => 'medium',
            'icon'       => 'quiz',
            'title'      => 'Academic questions falling',
            'text'       => 'Academic questions fell from ' . $lastWeekQ . ' last week to '
                . $thisWeekQ . ' this week' . $drop
                . '. Greetings and quiz commands are excluded from these counts.',
            'items'      => [],
            'suggestion' => 'Check whether students still have access to materials, or whether the topic simply moved on.',
            'action_label' => null,
        ];
    }

    // Issue reports unresolved
    if ($openIssues > 0) {
        $priorityActions[] = [
            'type'       => 'issues',
            'urgency'    => 'medium',
            'icon'       => 'report',
            'title'      => 'Unresolved Issues',
            'text'       => $openIssues . ' student issue' . ($openIssues > 1 ? 's' : '') . ' awaiting resolution.',
            'items'      => [],
            'suggestion' => 'Review and address open issue reports to prevent student frustration from building up.',
            'action_label' => 'View Issues',
        ];
    }

    // Sort by urgency
    $urgencyOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($priorityActions, function($a, $b) use ($urgencyOrder) {
        return ($urgencyOrder[$a['urgency']] ?? 2) - ($urgencyOrder[$b['urgency']] ?? 2);
    });

    // ──────────────────────────────────────────────────────────────
    // 13. Summary & return
    // ──────────────────────────────────────────────────────────────
    $aiSummary = $aiOverallSummary ?: '';
    $aiCourseHealthJson = $aiCourseHealth ? json_encode($aiCourseHealth) : null;

    // The full risk record travels nested inside each narrative; drop the
    // working copy so it is not serialised twice.
    foreach ($atRiskStudents as &$_stu) {
        unset($_stu['_risk']);
    }
    unset($_stu);

    $result = [
        // NEW: Actionable insights
        'priority_actions'    => $priorityActions,
        'struggle_areas'      => $struggleAreas,
        'section_struggle'    => $sectionStruggle,
        'material_struggle'   => $materialStruggle,
        'student_narratives'  => $studentNarratives,
        'common_questions'    => $commonQuestions,
        'course_pulse'        => $coursePulse,

        // EXISTING: Legacy fields (kept for compatibility)
        'topic_matrix'       => $topicMatrix,
        'material_breakdown' => $sectionList,
        'recording_struggle' => $recordingStruggle,
        'at_risk_students'   => $atRiskStudents,

        // NEW: Analytics services enrichment.
        // Failures are reported through debugging() rather than swallowed —
        // a silent catch here is what hid the broken table names for so long.
        'v2_topic_insights'  => (function() use ($cid) {
            try {
                return topic_insight_builder::build($cid);
            } catch (\Throwable $e) {
                debugging('local_umat_ai: topic_insight_builder::build failed — '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                return [];
            }
        })(),

        // Provenance for everything above: which window was analysed and when.
        'meta' => [
            'date_from'     => (int) $since,
            'date_to'       => time(),
            'window_days'   => (int) $params['days'],
            'generated_at'  => time(),
            'cache_ttl'     => 120,
            'risk_model'    => 'student_risk_calculator/1',
        ],

        'summary' => [
            'total_questions'    => $totalQuestions,
            'total_students'     => $uniqueStudents,
            'worst_topic'        => $worstTopic,
            'ai_service_used'    => $aiServiceUsed,
            'ai_overall_summary' => $aiSummary,
            'ai_course_health'   => $aiCourseHealthJson,
            'summary_insight'    => $aiSummaryInsight ?? '',
            'total_issues'       => $totalIssues,
            'open_issues'        => $openIssues,
            'top_issue_topics'   => $topIssueTopics,
            'event_breakdown'    => $eventBreakdown,
            'total_events'       => $totalEvents,
        ],
    ];
    $cache->set($cachekey, $result);
    return $result;

    }

    /**
     * Aggregate struggle insights across ALL courses the lecturer teaches.
     * Returns a lightweight summary plus AI-powered cross-course analysis.
     */
    private static function get_all_courses_insights(int $days): array {
        global $DB, $CFG, $USER;
        $since = time() - ($days * DAYSECS);

        // Get courses where user has viewanalytics capability
        $courses = enrol_get_my_courses('id, fullname, shortname', 'visible DESC, sortorder ASC', 0,
            ['local/umat_ai:viewanalytics']);
        if (empty($courses)) {
            $courses = get_user_capability_course('local/umat_ai:viewanalytics', $USER->id, true, 'id, fullname, shortname');
        }
        if (empty($courses)) {
            // Fallback: try to find courses the user can access
            $courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.fullname, c.shortname
                   FROM {course} c
                   JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
                   JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = :uid
                  WHERE c.id > 1",
                ['uid' => $USER->id]
            );
        }

        $courseIds = array_keys($courses);
        if (empty($courseIds)) {
            return [
                'mode'              => 'all_courses',
                'all_courses_summary' => [
                    'total_courses'    => 0,
                    'total_students'   => 0,
                    'total_questions'  => 0,
                    'total_at_risk'    => 0,
                ],
                'courses_summary' => [],
                'struggle_areas'  => [],
                'priority_actions' => [['type' => 'info', 'urgency' => 'low', 'icon' => 'info',
                    'title' => 'No Courses Found', 'text' => 'No courses with analytics access found.',
                    'items' => [], 'suggestion' => 'Contact your administrator.', 'action_label' => '']],
                'course_pulse' => ['total_students' => 0, 'at_risk_count' => 0,
                    'questions_this_week' => 0, 'questions_last_week' => 0, 'active_this_week' => 0],
                'common_questions' => [],
                'student_narratives' => [],
            ];
        }

        // ── Aggregate data across all courses ──
        list($insql, $inparams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
        $inparams['since'] = $since;

        // Total questions
        $allLogs = $DB->get_records_sql(
            "SELECT id, courseid, userid, question, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid $insql AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            $inparams
        );
        $totalQuestions = count($allLogs);
        $uniqueStudents = count(array_unique(array_map(function($l) { return $l->userid; }, $allLogs)));

        // Per-course stats
        $courseData = [];
        $allCourseQuestions = [];
        $allCourseIssues = [];
        $allCourseEvents = [];
        $allCourseMaterials = [];

        foreach ($courses as $c) {
            $cid = (int)$c->id;
            $ctx = \context_course::instance($cid);

            // Questions for this course
            $cLogs = array_filter($allLogs, function($l) use ($cid) {
                return (int)$l->courseid === $cid;
            });
            $cQCount = count($cLogs);
            $cStudents = count(array_unique(array_map(function($l) { return $l->userid; }, $cLogs)));
            $cEnrolled = (int) count_enrolled_users($ctx, '', 0, true);

            // At-risk count (from student_context)
            $cAtRisk = $DB->count_records_select('umat_ai_student_context',
                'courseid = ? AND timemodified > ? AND struggle_level = ?',
                [$cid, $since, 'high']);

            // Top questions for this course
            $cTopQs = [];
            if (!empty($cLogs)) {
                $qTexts = array_map(function($l) { return $l->question; },
                    array_slice(array_values($cLogs), 0, 20));
                $cTopQs = $qTexts;
                $allCourseQuestions = array_merge($allCourseQuestions, $qTexts);
            }

            // Issues for this course
            $cIssues = $DB->get_records_sql(
                "SELECT id, category, topic, description FROM {umat_ai_issue_reports}
                  WHERE courseid = ? AND timecreated > ?",
                [$cid, $since]
            );
            foreach ($cIssues as $ir) {
                $allCourseIssues[] = [
                    'topic' => $ir->topic ?? '',
                    'category' => $ir->category ?? '',
                    'description' => $ir->description ?? '',
                ];
            }

            // Events for this course
            $cEvents = $DB->get_records_sql(
                "SELECT id, userid, struggle_reason, topic_label FROM {umat_ai_student_context}
                  WHERE courseid = ? AND timemodified > ?",
                [$cid, $since]
            );
            foreach ($cEvents as $er) {
                $allCourseEvents[] = [
                    'userid' => (int)$er->userid,
                    'reason' => $er->struggle_reason ?? '',
                    'topic_label' => $er->topic_label ?? '',
                ];
            }

            // Materials for this course
            $cMats = $DB->get_records('umat_ai_materials', ['courseid' => $cid], '', 'id, filename');
            foreach ($cMats as $mat) {
                $allCourseMaterials[] = ['filename' => $mat->filename];
                // Get AI concepts for this material
                $cAna = $DB->get_records_sql(
                    "SELECT materialid, summary FROM {umat_ai_analysis}
                      WHERE materialid = ? AND analysis_type = 'key_concepts' AND status = 'completed'
                     LIMIT 1",
                    [(int)$mat->id]
                );
                foreach ($cAna as $row) {
                    $parsed = json_decode($row->summary, true);
                    if (is_array($parsed) && isset($parsed['concepts'])) {
                        $concepts = [];
                        foreach ($parsed['concepts'] as $cpt) {
                            $concepts[] = $cpt['term'] ?? $cpt['name'] ?? (is_string($cpt) ? $cpt : '');
                        }
                        // Add concepts to the material entry
                        end($allCourseMaterials);
                        $idx = key($allCourseMaterials);
                        $allCourseMaterials[$idx]['key_concepts'] = array_unique(array_filter($concepts));
                    }
                }
            }

            // Most-asked question as proxy topic
            $topTopic = '—';
            $topTopicScore = 0;
            if (!empty($cLogs)) {
                $wordCounts = [];
                foreach ($cLogs as $l) {
                    $words = str_word_count(strtolower($l->question), 1);
                    foreach ($words as $w) {
                        if (strlen($w) > 3 && !in_array($w, ['this','that','with','from','have','been','what','how','why','when','where','which','they','their','about','would','could','should','there'])) {
                            $wordCounts[$w] = ($wordCounts[$w] ?? 0) + 1;
                        }
                    }
                }
                if (!empty($wordCounts)) {
                    arsort($wordCounts);
                    $topWord = key($wordCounts);
                    $topTopicScore = reset($wordCounts);
                    // Try to find a material-based topic instead
                    $cConcepts = [];
                    foreach ($allCourseMaterials as $cm) {
                        if (!empty($cm['key_concepts'])) {
                            $cConcepts = array_merge($cConcepts, $cm['key_concepts']);
                        }
                    }
                    if (!empty($cConcepts)) {
                        $topTopic = $cConcepts[array_rand($cConcepts)];
                    } else {
                        $topTopic = ucfirst($topWord);
                    }
                }
            }

            // Compute trend and health
            $cTrend = 'stable';
            $cHealthPct = $cEnrolled > 0 ? max(5, min(100, round((1 - $cAtRisk / max($cEnrolled, 1)) * 100))) : 50;

            $courseData[] = [
                'id'             => $cid,
                'fullname'       => $c->fullname,
                'shortname'      => $c->shortname,
                'students'       => $cEnrolled,
                'questions'      => $cQCount,
                'unique_students'=> $cStudents,
                'at_risk'        => $cAtRisk,
                'trend'          => $cTrend,
                'health_pct'     => $cHealthPct,
                'top_topic'      => $topTopic,
                'top_topic_score'=> $topTopicScore,
            ];
        }

        // Sort courses by question count (most struggling first)
        usort($courseData, function($a, $b) {
            return $b['questions'] - $a['questions'];
        });

        // ── Try AI service for cross-course analysis ──
        $aiSummaryInsight = '';
        $hasAiInsight = false;
        $cfg = \local_umat_ai_get_service_config();
        if (!empty($cfg['token']) && !empty($cfg['url']) && $totalQuestions > 0) {
            try {
                require_once($CFG->libdir . '/filelib.php');
                $client = new \curl(['ignoresecurity' => true]);
                $client->setHeader([
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg['token'],
                ]);
                $client->setopt(['CURLOPT_TIMEOUT' => 30]);

                $questionTexts = array_map(function($l) { return $l->question; },
                    array_slice($allLogs, 0, 150));

                $payload = json_encode([
                    'course_id'        => 0,
                    'course_name'      => 'All Courses (Cross-Course Analysis)',
                    'questions'        => $questionTexts,
                    'course_materials' => $allCourseMaterials,
                    'issue_reports'    => $allCourseIssues,
                    'student_events'   => $allCourseEvents,
                    'previous_topics'  => [],
                ]);

                $raw = $client->post($cfg['url'] . '/api/v1/analytics/extract-topics', $payload, [
                    'CURLOPT_TIMEOUT' => 30,
                ]);
                $aiResult = json_decode($raw, true);
                if ($aiResult && isset($aiResult['topics'])) {
                    $hasAiInsight = true;
                    $aiSummaryInsight = $aiResult['summary_insight'] ?? '';
                }
            } catch (\Throwable $e) {
                // AI failed, proceed without
            }
        }

        // ── Build all_courses_summary ──
        $totalAtRisk = array_sum(array_column($courseData, 'at_risk'));
        $totalStudents = array_sum(array_column($courseData, 'students'));

        // Count this week vs last week questions
        $now = time();
        $weekAgo = $now - (7 * DAYSECS);
        $twoWeeksAgo = $now - (14 * DAYSECS);
        $thisWeek = 0;
        $lastWeek = 0;
        foreach ($allLogs as $l) {
            if ($l->timecreated >= $weekAgo) $thisWeek++;
            elseif ($l->timecreated >= $twoWeeksAgo) $lastWeek++;
        }

        // Active this week: unique students who asked questions
        $activeThisWeek = count(array_unique(array_map(function($l) { return $l->userid; },
            array_filter($allLogs, function($l) use ($weekAgo) { return $l->timecreated >= $weekAgo; }))));

        // ── Build course_pulses (mini sparkline data per course) ──
        $coursePulses = [];
        foreach ($courseData as $cd) {
            $cid = $cd['id'];
            $pLogs = array_filter($allLogs, function($l) use ($cid) { return (int)$l->courseid === $cid; });
            // Weekly counts for last 6 weeks
            $weeklyCounts = [];
            for ($w = 5; $w >= 0; $w--) {
                $weekStart = $now - (($w + 1) * 7 * DAYSECS);
                $weekEnd = $now - ($w * 7 * DAYSECS);
                $count = 0;
                foreach ($pLogs as $l) {
                    if ($l->timecreated >= $weekStart && $l->timecreated < $weekEnd) $count++;
                }
                $weeklyCounts[] = $count;
            }
            $coursePulses[] = [
                'name'          => $cd['shortname'],
                'trend_values'  => implode(',', $weeklyCounts),
            ];
        }

        $result = [
            'mode'               => 'all_courses',
            'all_courses_summary' => [
                'total_courses'    => count($courseData),
                'total_students'   => $totalStudents,
                'total_questions'  => $totalQuestions,
                'total_at_risk'    => $totalAtRisk,
                'unique_students'  => $uniqueStudents,
            ],
            'courses_summary' => $courseData,
            'cross_cutting_insight' => $aiSummaryInsight,
            'has_ai_insight'        => $hasAiInsight,

            // Legacy fields needed for rendering compatibility
            'priority_actions' => [],
            'struggle_areas'   => [],
            'section_struggle' => [],
            'material_struggle'=> [],
            'student_narratives'=> [],
            'common_questions' => [],

            // Course pulse (aggregated)
            'course_pulse' => [
                'total_students'      => $totalStudents,
                'at_risk_count'       => $totalAtRisk,
                'questions_this_week' => $thisWeek,
                'questions_last_week' => $lastWeek,
                'active_this_week'    => $activeThisWeek,
            ],
            'course_pulses'    => $coursePulses,
            'topic_matrix'       => [],
            'material_breakdown' => [],
            'recording_struggle' => [],
            'at_risk_students'   => [],
            'summary' => [
                'total_questions'    => $totalQuestions,
                'total_students'     => $uniqueStudents,
                'worst_topic'        => '—',
                'ai_service_used'    => $hasAiInsight,
                'ai_overall_summary' => '',
                'ai_course_health'   => null,
                'summary_insight'    => $aiSummaryInsight,
                'total_issues'       => count($allCourseIssues),
                'open_issues'        => 0,
                'top_issue_topics'   => [],
                'event_breakdown'    => [
                    'quiz_failures'       => 0,
                    'repeated_views'      => 0,
                    'assignment_failures' => 0,
                    'issue_reports'       => count($allCourseIssues),
                ],
                'total_events' => count($allCourseEvents),
            ],
        ];

        return $result;
    }

    public static function get_struggle_insights_returns() {
        $structure = [
            // NEW: Actionable insights
            'priority_actions' => new \external_multiple_structure(
                new \external_single_structure([
                    'type'         => new \external_value(PARAM_TEXT),
                    'urgency'      => new \external_value(PARAM_TEXT),
                    'icon'         => new \external_value(PARAM_TEXT),
                    'title'        => new \external_value(PARAM_TEXT),
                    'text'         => new \external_value(PARAM_TEXT),
                    'items'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'name'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'students' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'pct'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'avg_quiz' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                            'trend'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'days'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'suggestion'   => new \external_value(PARAM_TEXT),
                    'action_label' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),
            'struggle_areas' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic'              => new \external_value(PARAM_TEXT),
                    'severity'           => new \external_value(PARAM_TEXT),
                    'student_count'      => new \external_value(PARAM_INT),
                    'total_students'     => new \external_value(PARAM_INT),
                    'student_pct'        => new \external_value(PARAM_INT),
                    'question_count'     => new \external_value(PARAM_INT),
                    'prev_question_count'=> new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'trend'              => new \external_value(PARAM_TEXT),
                    'trend_pct'          => new \external_value(PARAM_INT),
                    'struggle_score'     => new \external_value(PARAM_INT),
                    'description'        => new \external_value(PARAM_TEXT),
                    'sample_questions'   => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'suggestion'         => new \external_value(PARAM_TEXT),
                    'suggestion_type'    => new \external_value(PARAM_TEXT),
                    'materials'          => new \external_multiple_structure(
                        new \external_single_structure([
                            'name'           => new \external_value(PARAM_TEXT),
                            'question_count' => new \external_value(PARAM_INT),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'affected_student_ids' => new \external_multiple_structure(
                        new \external_value(PARAM_INT), '', VALUE_OPTIONAL
                    ),
                    'ai_explanation'     => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'confidence'         => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'evidence_sources'   => new \external_single_structure([], '', VALUE_OPTIONAL),
                    'recommendation'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),
            'section_struggle' => new \external_multiple_structure(
                new \external_single_structure([
                    'section_name'  => new \external_value(PARAM_TEXT),
                    'section_num'   => new \external_value(PARAM_INT),
                    'struggle_pct'  => new \external_value(PARAM_INT),
                    'student_count' => new \external_value(PARAM_INT),
                    'question_count'=> new \external_value(PARAM_INT),
                    'severity'      => new \external_value(PARAM_TEXT),
                    'top_topics'    => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'hint'          => new \external_value(PARAM_TEXT),
                ]), '', VALUE_OPTIONAL
            ),
            'material_struggle' => new \external_multiple_structure(
                new \external_single_structure([
                    'material_name'   => new \external_value(PARAM_TEXT),
                    'question_count'  => new \external_value(PARAM_INT),
                    'struggle_topics' => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'suggestion'      => new \external_value(PARAM_TEXT),
                ]), '', VALUE_OPTIONAL
            ),
            'student_narratives' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'             => new \external_value(PARAM_INT),
                    'fullname'           => new \external_value(PARAM_TEXT),
                    'profileimageurl'    => new \external_value(PARAM_URL),
                    'risk_score'         => new \external_value(PARAM_INT),
                    'risk_level'         => new \external_value(PARAM_TEXT),
                    'summary'            => new \external_value(PARAM_TEXT),
                    'struggle_topics'    => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'last_active'        => new \external_value(PARAM_TEXT),
                    'days_since_last_login' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'question_count'     => new \external_value(PARAM_INT),
                    'avg_quiz'           => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'ai_queries'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'quiz_failures'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'issue_reports'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'suggestion'         => new \external_value(PARAM_TEXT),
                    'suggestion_type'    => new \external_value(PARAM_TEXT),
                    'reasons'            => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'evidence'           => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'explanation'        => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                    'confidence'         => new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                    'recommendation'     => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'quick_actions'      => new \external_multiple_structure(
                        new \external_single_structure([
                            'action' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'label'  => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                            'icon'   => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                        ]), '', VALUE_OPTIONAL
                    ),
                    'trend'              => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),

                    // The auditable risk record. Declared here because Moodle's
                    // clean_returnvalue() silently discards any key that is not
                    // in the structure — which is why the previous v2 payload
                    // never reached the browser.
                    'v2_risk' => new \external_single_structure([
                        'risk_score'     => new \external_value(PARAM_FLOAT),
                        'risk_level'     => new \external_value(PARAM_TEXT),
                        'confidence'     => new \external_value(PARAM_FLOAT),
                        'classification' => new \external_value(PARAM_TEXT),
                        'category_label' => new \external_value(PARAM_TEXT),
                        'primary_reason' => new \external_value(PARAM_TEXT),
                        'summary'        => new \external_value(PARAM_TEXT),
                        'evidence'       => new \external_multiple_structure(
                            new \external_single_structure([
                                'factor'        => new \external_value(PARAM_TEXT),
                                'label'         => new \external_value(PARAM_TEXT),
                                'detail'        => new \external_value(PARAM_TEXT),
                                'points_earned' => new \external_value(PARAM_FLOAT),
                                'points_max'    => new \external_value(PARAM_INT),
                            ]), '', VALUE_OPTIONAL
                        ),
                        // Named keys, not a list: a multiple_structure would
                        // discard the array keys and the UI would lose the
                        // labels that say which metric each trend belongs to.
                        'trends'         => new \external_single_structure([
                            'quiz'       => new \external_single_structure([
                                'direction'  => new \external_value(PARAM_TEXT),
                                'comparable' => new \external_value(PARAM_BOOL),
                            ], '', VALUE_OPTIONAL),
                            'activity'   => new \external_single_structure([
                                'direction'  => new \external_value(PARAM_TEXT),
                                'comparable' => new \external_value(PARAM_BOOL),
                            ], '', VALUE_OPTIONAL),
                            'attendance' => new \external_single_structure([
                                'direction'  => new \external_value(PARAM_TEXT),
                                'comparable' => new \external_value(PARAM_BOOL),
                            ], '', VALUE_OPTIONAL),
                        ], '', VALUE_OPTIONAL),
                        'date_range'     => new \external_single_structure([
                            'from' => new \external_value(PARAM_INT),
                            'to'   => new \external_value(PARAM_INT),
                            'days' => new \external_value(PARAM_INT),
                        ], '', VALUE_OPTIONAL),
                        'calculated_at'  => new \external_value(PARAM_INT),
                    ], '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),
            'common_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'text'           => new \external_value(PARAM_TEXT),
                    'student_count'  => new \external_value(PARAM_INT),
                    'ask_count'      => new \external_value(PARAM_INT),
                    'topic'          => new \external_value(PARAM_TEXT),
                    'suggestion'     => new \external_value(PARAM_TEXT),
                    'interpretation' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),
            'course_pulse' => new \external_single_structure([
                'avg_quiz'              => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'avg_quiz_available'    => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
                'quiz_attempts'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'questions_trend_comparable' => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
                'questions_period_label'     => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'messages_total'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'messages_academic'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'messages_greeting'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'messages_command'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'messages_filler'       => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'quiz_trend'            => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'quiz_trend_pct'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'at_risk_count'         => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'at_risk_trend'         => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'at_risk_trend_delta'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'top_struggle_topic'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'top_struggle_trend'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'active_this_week'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'total_students'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'questions_this_week'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'questions_last_week'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'questions_trend'        => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'questions_trend_pct'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'bbb_available'          => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
                'bbb_total_sessions'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'bbb_avg_attendance_rate'=> new \external_value(PARAM_FLOAT, '', VALUE_OPTIONAL),
                'bbb_attended_count'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'bbb_never_attended_count' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),

            // EXISTING: Legacy fields (kept for compatibility)
            'topic_matrix' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic'          => new \external_value(PARAM_TEXT),
                    'question_count' => new \external_value(PARAM_INT),
                    'student_count'  => new \external_value(PARAM_INT),
                    'struggle_score' => new \external_value(PARAM_INT),
                    'trend'          => new \external_value(PARAM_TEXT),
                    'trend_pct'      => new \external_value(PARAM_INT),
                    'difficulty'     => new \external_value(PARAM_TEXT),
                    'ai_classified'     => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
                    'ai_recommendation' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'event_sources'     => new \external_single_structure([
                        'chat_questions'      => new \external_value(PARAM_INT),
                        'quiz_failures'       => new \external_value(PARAM_INT),
                        'repeated_views'      => new \external_value(PARAM_INT),
                        'assignment_failures' => new \external_value(PARAM_INT),
                        'issue_reports'       => new \external_value(PARAM_INT),
                    ], '', VALUE_OPTIONAL),
                    'materials'      => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'             => new \external_value(PARAM_INT),
                            'name'           => new \external_value(PARAM_TEXT),
                            'question_count' => new \external_value(PARAM_INT),
                        ])
                    ),
                    'sample_questions' => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                ])
            ),
            'material_breakdown' => new \external_multiple_structure(
                new \external_single_structure([
                    'section_name' => new \external_value(PARAM_TEXT),
                    'section_num'  => new \external_value(PARAM_INT),
                    'materials'    => new \external_multiple_structure(
                        new \external_single_structure([
                            'id'             => new \external_value(PARAM_INT),
                            'filename'       => new \external_value(PARAM_TEXT),
                            'fileid'         => new \external_value(PARAM_INT),
                            'question_count' => new \external_value(PARAM_INT),
                            'student_count'  => new \external_value(PARAM_INT),
                            'difficulty'     => new \external_value(PARAM_TEXT),
                            'key_concepts'   => new \external_multiple_structure(
                                new \external_single_structure([
                                    'concept'        => new \external_value(PARAM_TEXT),
                                    'question_count' => new \external_value(PARAM_INT),
                                ])
                            ),
                        ])
                    ),
                ])
            ),
            'recording_struggle' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'              => new \external_value(PARAM_INT),
                    'title'           => new \external_value(PARAM_TEXT),
                    'url'             => new \external_value(PARAM_URL),
                    'segments'        => new \external_multiple_structure(
                        new \external_single_structure([
                            'start_sec'      => new \external_value(PARAM_FLOAT),
                            'end_sec'        => new \external_value(PARAM_FLOAT),
                            'timestamp'      => new \external_value(PARAM_TEXT),
                            'text_snippet'   => new \external_value(PARAM_TEXT),
                            'question_count' => new \external_value(PARAM_INT),
                            'struggle_level' => new \external_value(PARAM_TEXT),
                        ])
                    ),
                    'total_questions' => new \external_value(PARAM_INT),
                ])
            ),
            'at_risk_students' => new \external_multiple_structure(
                new \external_single_structure([
                    'userid'          => new \external_value(PARAM_INT),
                    'fullname'        => new \external_value(PARAM_TEXT),
                    'profileimageurl' => new \external_value(PARAM_URL),
                    'question_count'  => new \external_value(PARAM_INT),
                    'issue_count'     => new \external_value(PARAM_INT),
                    'event_sources'   => new \external_single_structure([
                        'chat_questions'      => new \external_value(PARAM_INT),
                        'quiz_failures'       => new \external_value(PARAM_INT),
                        'repeated_views'      => new \external_value(PARAM_INT),
                        'assignment_failures' => new \external_value(PARAM_INT),
                        'issue_reports'       => new \external_value(PARAM_INT),
                    ], '', VALUE_OPTIONAL),
                    'struggle_topics' => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT)
                    ),
                    'risk_score'        => new \external_value(PARAM_INT),
                    'risk_level'        => new \external_value(PARAM_TEXT),
                    'trend'             => new \external_value(PARAM_TEXT),
                    'last_active'       => new \external_value(PARAM_TEXT),
                    'ai_risk_factors'   => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'ai_recommendation' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                ])
            ),
            // Declared so the payload actually survives clean_returnvalue().
            // It was being built and then silently discarded.
            'v2_topic_insights' => new \external_multiple_structure(
                new \external_single_structure([
                    'topic_name'     => new \external_value(PARAM_TEXT),
                    'question_count' => new \external_value(PARAM_INT),
                    'student_count'  => new \external_value(PARAM_INT),
                    'total_askers'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'struggle_score' => new \external_value(PARAM_FLOAT),
                    'top_students'   => new \external_multiple_structure(
                        new \external_value(PARAM_INT), '', VALUE_OPTIONAL
                    ),
                    'student_names'  => new \external_multiple_structure(
                        new \external_value(PARAM_TEXT), '', VALUE_OPTIONAL
                    ),
                    'first_asked'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'last_asked'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),

            // Provenance: the window analysed and when it was computed, so the
            // dashboard can show a date range and a last-updated time.
            'meta' => new \external_single_structure([
                'date_from'    => new \external_value(PARAM_INT),
                'date_to'      => new \external_value(PARAM_INT),
                'window_days'  => new \external_value(PARAM_INT),
                'generated_at' => new \external_value(PARAM_INT),
                'cache_ttl'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'risk_model'   => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),

            'summary' => new \external_single_structure([
                'total_questions'    => new \external_value(PARAM_INT),
                'total_students'     => new \external_value(PARAM_INT),
                'worst_topic'        => new \external_value(PARAM_TEXT),
                'ai_service_used'    => new \external_value(PARAM_BOOL),
                'ai_overall_summary' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'ai_course_health'   => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
                'summary_insight'    => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'total_issues'       => new \external_value(PARAM_INT),
                'open_issues'        => new \external_value(PARAM_INT),
                'top_issue_topics'   => new \external_multiple_structure(
                    new \external_value(PARAM_TEXT)
                ),
                'event_breakdown'    => new \external_single_structure([
                    'quiz_failures'       => new \external_value(PARAM_INT),
                    'repeated_views'      => new \external_value(PARAM_INT),
                    'assignment_failures' => new \external_value(PARAM_INT),
                    'issue_reports'       => new \external_value(PARAM_INT),
                ], '', VALUE_OPTIONAL),
                'total_events'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            ]),
            // All-courses mode fields
            'mode'                 => new \external_value(PARAM_TEXT, 'all_courses when aggregating', VALUE_OPTIONAL),
            'all_courses_summary'  => new \external_single_structure([
                'total_courses'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'total_students'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'total_questions'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'total_at_risk'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                'unique_students'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            ], '', VALUE_OPTIONAL),
            'courses_summary'      => new \external_multiple_structure(
                new \external_single_structure([
                    'id'             => new \external_value(PARAM_INT),
                    'fullname'       => new \external_value(PARAM_TEXT),
                    'shortname'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'students'       => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'questions'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'unique_students'=> new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'at_risk'        => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'trend'          => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'health_pct'     => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'top_topic'      => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'top_topic_score'=> new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                ]), '', VALUE_OPTIONAL
            ),
            'course_pulses'        => new \external_multiple_structure(
                new \external_single_structure([
                    'name'          => new \external_value(PARAM_TEXT),
                    'trend_values'  => new \external_value(PARAM_TEXT, 'comma-separated weekly counts'),
                ]), '', VALUE_OPTIONAL
            ),
            'cross_cutting_insight' => new \external_value(PARAM_TEXT, 'AI-generated cross-course insight', VALUE_OPTIONAL),
            'has_ai_insight'        => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
        ];

        return new \external_single_structure($structure);
    }

    /**
     * Generate a plain-English suggestion for a topic based on its data.
     */
    private static function generate_topic_suggestion($topic, $stuPct, $enrolledCount) {
        $score = $topic['struggle_score'] ?? 0;
        $trend = $topic['trend'] ?? 'stable';
        $trendPct = $topic['trend_pct'] ?? 0;
        $qCnt = $topic['question_count'] ?? 0;

        // High struggle + trending up → urgent recap
        if ($stuPct >= 30 && $trend === 'up') {
            return [
                'text' => 'Consider a dedicated recap session on ' . $topic['topic'] . '. ' .
                          $stuPct . '% of students are confused and the number of questions is increasing.',
                'type' => 'recap',
            ];
        }

        // High struggle but stable → review needed
        if ($stuPct >= 30) {
            return [
                'text' => 'Students are struggling with ' . $topic['topic'] . '. ' .
                          'Consider reviewing this topic in your next lecture with practical examples.',
                'type' => 'review',
            ];
        }

        // Moderate struggle + many questions → clarify concept
        if ($qCnt >= 10 && $stuPct >= 15) {
            return [
                'text' => 'Students are asking many questions about ' . $topic['topic'] . '. ' .
                          'Consider a quick concept clarification or worked example.',
                'type' => 'clarify',
            ];
        }

        // Improving → positive reinforcement
        if ($trend === 'down') {
            return [
                'text' => 'Students are understanding ' . $topic['topic'] . ' better — no immediate action needed.',
                'type' => 'positive',
            ];
        }

        // Low struggle → watch
        if ($stuPct < 15 && $score < 40) {
            return [
                'text' => 'Minor issues only — monitor but no immediate action needed.',
                'type' => 'watch',
            ];
        }

        // Default
        return [
            'text' => 'Monitor this topic — ' . $stuPct . '% of students have asked questions about it.',
            'type' => 'monitor',
        ];
    }

    /**
     * Generate a plain-English summary for a student based on their data.
     */
    private static function generate_student_summary($student, $enrolledCount) {
        $parts = [];

        // Topic struggles
        $topics = $student['struggle_topics'] ?? [];
        if (!empty($topics)) {
            if (count($topics) === 1) {
                $parts[] = 'Struggles with ' . $topics[0];
            } elseif (count($topics) === 2) {
                $parts[] = 'Struggles with ' . $topics[0] . ' and ' . $topics[1];
            } else {
                $parts[] = 'Struggles with ' . $topics[0] . ', ' . $topics[1] . ', and ' . $topics[2];
            }
        }

        // Question volume
        $qCnt = $student['question_count'] ?? 0;
        if ($qCnt > 0) {
            $parts[] = 'asked ' . $qCnt . ' question' . ($qCnt !== 1 ? 's' : '');
        }

        // Quiz failures
        $quizFails = $student['event_sources']['quiz_failures'] ?? 0;
        if ($quizFails > 0) {
            $parts[] = 'failed ' . $quizFails . ' quiz attempt' . ($quizFails !== 1 ? 's' : '');
        }

        // Last active — read from the numeric field rather than parsed back out
        // of the display string, which broke whenever the wording changed.
        $days = $student['days_inactive'] ?? null;
        if ($days !== null && $days > 7) {
            $parts[] = 'has had no course activity for ' . (int) $days . ' days';
        } else if ($days !== null && $days > 3) {
            $parts[] = 'last active ' . (int) $days . ' days ago';
        }

        // Issue reports
        $issues = $student['event_sources']['issue_reports'] ?? 0;
        if ($issues > 0) {
            $parts[] = 'reported ' . $issues . ' issue' . ($issues !== 1 ? 's' : '');
        }

        if (empty($parts)) {
            return 'No significant activity recorded for this student.';
        }

        // Capitalize first letter and join
        $summary = implode('. ', $parts) . '.';
        $summary[0] = strtoupper($summary[0]);
        return $summary;
    }

    /**
     * Generate a plain-English suggestion for a student.
     */
    /**
     * Suggested next step for one student.
     *
     * Driven by the risk model's classification rather than by question volume,
     * so the advice matches the stated reason. In particular, a student who is
     * present and failing is never told they have "disengaged", and asking many
     * questions is no longer treated as a warning sign.
     *
     * @param array $student
     * @return array ['text' => string, 'type' => string]
     */
    private static function generate_student_suggestion($student) {
        $risk = $student['_risk'] ?? null;
        $classification = $risk['classification'] ?? 'low_risk';
        $topics = $student['struggle_topics'] ?? [];
        $topicStr = !empty($topics) ? $topics[0] : 'the course material';
        $days = $student['days_inactive'] ?? null;
        $quizAvg = $risk['factors']['quiz_performance']['raw']['avg_pct'] ?? null;
        $missed = $risk['factors']['missed_assessments']['raw']['missed_count'] ?? 0;

        switch ($classification) {
            case 'academically_struggling':
                return [
                    'text' => $quizAvg !== null
                        ? sprintf('Present and engaged, but averaging %d%% on quizzes. Work through %s with them — this is a comprehension gap, not an attendance one.',
                            round($quizAvg), $topicStr)
                        : sprintf('Present and engaged, but performing below expectation on %s. A worked example may resolve it.', $topicStr),
                    'type' => 'meeting',
                ];

            case 'assessment_risk':
                return [
                    'text' => sprintf('%d past-due assessment%s unsubmitted. Confirm whether this is a deadline problem or a comprehension problem before escalating.',
                        $missed, $missed === 1 ? ' is' : 's are'),
                    'type' => 'assessment',
                ];

            case 'attendance_risk':
                return [
                    'text' => 'Live session attendance is low while coursework continues. Ask what is preventing attendance and point them to the recordings.',
                    'type' => 'attendance',
                ];

            case 'disengaged':
                return [
                    'text' => $days !== null
                        ? sprintf('No course activity of any kind for %d days. A short check-in message is the right first step.', (int) $days)
                        : 'No recorded course activity. A short check-in message is the right first step.',
                    'type' => 'encourage',
                ];

            case 'resource_engagement_risk':
                return [
                    'text' => 'Most published materials remain unopened. Confirm the student knows what is available and where.',
                    'type' => 'resource',
                ];

            case 'monitoring':
                return [
                    'text' => 'Signals are mixed and none is decisive. Worth watching rather than acting on today.',
                    'type' => 'monitor',
                ];

            default:
                return [
                    'text' => 'No intervention indicated by the current evidence.',
                    'type' => 'none',
                ];
        }
    }

    /**
     * Get sample questions for a topic from chat logs.
     */
    private static function get_sample_questions_for_topic($topicName, $logs, $limit = 3) {
        $topicLower = strtolower($topicName);
        $matching = [];
        foreach ($logs as $l) {
            $clean = preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $l->question);
            $qLower = strtolower($clean);
            if (strpos($qLower, $topicLower) !== false || strpos($topicLower, $qLower) !== false) {
                $matching[] = $clean;
            }
            if (count($matching) >= $limit) break;
        }
        return $matching;
    }
}
