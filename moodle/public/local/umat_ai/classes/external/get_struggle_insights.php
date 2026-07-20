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

class get_struggle_insights extends \external_api {

    public static function get_struggle_insights_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'days'     => new \external_value(PARAM_INT, 'Time window in days', VALUE_DEFAULT, 60),
        ]);
    }

    public static function get_struggle_insights($courseid, $days = 60) {
        global $DB, $CFG;

        $params = self::validate_parameters(
            self::get_struggle_insights_parameters(),
            ['courseid' => $courseid, 'days' => $days]
        );
        $cid   = (int)$params['courseid'];
        $since = time() - ($params['days'] * DAYSECS);

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
        $logs = $DB->get_records_sql(
            "SELECT id, userid, question, sources, timecreated
               FROM {umat_ai_chat_logs}
              WHERE courseid = :cid AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['cid' => $cid, 'since' => $since]
        );
        $totalQuestions = count($logs);

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
            // extract significant words as an ad-hoc topic. (Previously gated
            // on empty($topicQuestions) — the global accumulator — so only the
            // first unmatched question ever produced a topic.)
            if (!$qTopicAssigned) {
                $stopwords = ['the','a','an','is','are','was','were','do','does','did',
                              'how','what','why','when','where','which','who','whom',
                              'this','that','these','those','i','you','he','she','it',
                              'we','they','to','of','in','for','on','with','at','by',
                              'from','as','into','through','during','before','after',
                              'above','below','between','out','off','over','under',
                              'again','further','then','once','here','there','and',
                              'but','or','nor','not','so','yet','if','because','about',
                              'up','down','than','very','just','also','can','will','has',
                              'have','had','been','being','be','get','got','would','could',
                              'should','may','might','shall','need','like','make','made'];
                $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($l->question)));
                $words = array_diff($words, $stopwords);
                $words = array_filter($words, function($w) { return strlen($w) > 3; });
                $words = array_slice(array_unique($words), 0, 3);
                // One topic per word (not one combined string) so the same
                // term aggregates across different students' questions.
                foreach ($words as $w) {
                    if (!isset($topicQuestions[$w])) $topicQuestions[$w] = [];
                    $topicQuestions[$w][$qid] = true;
                    $topicStudents[$w][$uid] = true;
                }
            }
        }

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
            // If issue has a topic, also increment event-based matches
            if (!empty(trim($ir->topic ?? ''))) {
                $irtNorm = strtolower(preg_replace('/[^a-z0-9\s]/', '', $ir->topic));
                if (!empty($irtNorm)) {
                    if (!isset($topicEvents[$irtNorm])) $topicEvents[$irtNorm] = [];
                    $topicEvents[$irtNorm]['issue_reported'] = ($topicEvents[$irtNorm]['issue_reported'] ?? 0) + 1;
                    if (!isset($eventTopicMap[$irtNorm])) $eventTopicMap[$irtNorm] = trim($ir->topic);
                }
            }
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

        // ── AI service: intelligent topic extraction before PHP fallback ──
        if (empty($topicMatrix) && $totalQuestions > 0) {
            $cfg = \local_umat_ai_get_service_config();
            if (!empty($cfg['token']) && !empty($cfg['url'])) {
                try {
                    require_once($CFG->libdir . '/filelib.php');
                    $client = new \curl(['ignoresecurity' => true]);
                    $client->setHeader([
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $cfg['token'],
                    ]);
                    $client->setopt(['CURLOPT_TIMEOUT' => 20]);

                    $questionTexts = [];
                    foreach ($logs as $l) {
                        $questionTexts[] = $l->question;
                        if (count($questionTexts) >= 100) break;
                    }

                    $materialsList = [];
                    foreach ($materials as $m) {
                        $materialsList[] = ['filename' => $m->filename];
                    }

                    $courseName = $DB->get_field('course', 'fullname', ['id' => $cid]) ?: '';

                    $payload = json_encode([
                        'questions'       => $questionTexts,
                        'course_materials' => $materialsList,
                        'course_name'     => $courseName,
                    ]);

                    $raw = $client->post($cfg['url'] . '/api/v1/analytics/extract-topics', $payload);
                    $aiResult = json_decode($raw, true);

                    if ($aiResult && isset($aiResult['topics']) && !empty($aiResult['topics'])) {
                        $topicMatrix = [];
                        $knownTopicNames = [];
                        foreach ($aiResult['topics'] as $aiTopic) {
                            $tName = $aiTopic['topic_name'] ?? '';
                            if (!$tName) continue;
                            $knownTopicNames[] = $tName;
                            $sampleQ = $aiTopic['sample_questions'] ?? [];
                            $topicMatrix[] = [
                                'topic'            => $tName,
                                'question_count'   => (int)($aiTopic['question_count'] ?? 0),
                                'student_count'    => 0,
                                'struggle_score'   => min(100, 40 + ((int)($aiTopic['question_count'] ?? 0) * 3)),
                                'trend'            => 'stable',
                                'trend_pct'        => 0,
                                'difficulty'       => 'intermediate',
                                'event_sources'    => [
                                    'chat_questions'      => (int)($aiTopic['question_count'] ?? 0),
                                    'quiz_failures'       => 0,
                                    'repeated_views'      => 0,
                                    'assignment_failures' => 0,
                                    'issue_reports'       => 0,
                                ],
                                'materials'        => array_map(function($m) {
                                    return ['name' => $m, 'question_count' => 0];
                                }, $aiTopic['related_materials'] ?? []),
                                'sample_questions' => array_slice($sampleQ, 0, 3),
                                'ai_classified'    => true,
                            ];
                        }

                        // Enrich student counts from logs
                        foreach ($topicMatrix as &$tm) {
                            if (!empty($tm['sample_questions'])) {
                                $stuIds = [];
                                foreach ($logs as $l) {
                                    foreach ($tm['sample_questions'] as $sq) {
                                        if (stripos($l->question, $sq) !== false ||
                                            stripos($sq, $l->question) !== false) {
                                            $stuIds[$l->userid] = true;
                                        }
                                    }
                                }
                                $tm['student_count'] = count($stuIds);
                            }
                        }
                        unset($tm);

                        usort($topicMatrix, function($a, $b) {
                            return ($b['struggle_score'] ?? 0) - ($a['struggle_score'] ?? 0);
                        });
                    }
                } catch (\Throwable $e) {
                    // AI failed; proceed to PHP fallback below
                }
            }
        }

        // ── Guaranteed fallback: if PHP topic extraction found nothing
        // (no materials indexed, no keyword matches) but we DO have
        // questions — extract bigrams/trigrams instead of single words. ──
        if (empty($topicMatrix) && $totalQuestions > 0) {
            $stopwords = ['the','a','an','is','are','was','were','do','does','did',
                          'how','what','why','when','where','which','who','can','will',
                          'this','that','these','those','and','but','or','not','so',
                          'to','of','in','for','on','with','at','by','from','as',
                          'into','through','about','up','down','than','very','just',
                          'also','has','have','had','been','being','get','got','would',
                          'could','should','may','might','shall','need','like','make',
                          // Generic academic verbs
                          'explain','define','describe','list','discuss','give','practice',
                          'summarize','outline','identify','state','mention','tell','show',
                          // Greetings and conversational
                          'hello','hi','howdy','thanks','please','thank','you','i','me',
                          'my','our','we','they','them','their','it','its'];

            $bigramCounts = [];
            $bigramStudents = [];
            $bigramQuestions = [];
            $trigramCounts = [];
            $trigramStudents = [];
            $trigramQuestions = [];

            foreach ($logs as $l) {
                $words = array_values(array_filter(
                    preg_split('/[^a-z0-9]+/', strtolower($l->question)),
                    function($w) use ($stopwords) {
                        return strlen($w) > 2 && !in_array($w, $stopwords);
                    }
                ));
                $uniqueWords = array_unique($words);
                if (empty($uniqueWords)) continue;

                // Bigrams
                for ($i = 0; $i < count($words) - 1; $i++) {
                    $bigram = $words[$i] . ' ' . $words[$i + 1];
                    $bigramCounts[$bigram] = ($bigramCounts[$bigram] ?? 0) + 1;
                    $bigramStudents[$bigram][$l->userid] = true;
                    $bigramQuestions[$bigram][] = $l->question;
                }
                // Trigrams
                for ($i = 0; $i < count($words) - 2; $i++) {
                    $trigram = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
                    $trigramCounts[$trigram] = ($trigramCounts[$trigram] ?? 0) + 1;
                    $trigramStudents[$trigram][$l->userid] = true;
                    $trigramQuestions[$trigram][] = $l->question;
                }
            }

            $allPhrases = $bigramCounts;
            foreach ($trigramCounts as $phrase => $cnt) {
                if (!isset($allPhrases[$phrase])) {
                    $allPhrases[$phrase] = $cnt;
                }
            }
            arsort($allPhrases);

            $rank = 0;
            foreach (array_slice($allPhrases, 0, 15, true) as $phrase => $cnt) {
                if ($cnt < 2) continue;
                $stuCnt = count(
                    $bigramStudents[$phrase] ?? $trigramStudents[$phrase] ?? []
                );
                $pct    = $totalQuestions > 0 ? $cnt / $totalQuestions : 0;
                $score  = min(100, (int)round($pct * 50 + $stuCnt * 8 + ($rank === 0 ? 20 : 0)));

                $samples = array_values(array_unique(
                    $bigramQuestions[$phrase] ?? $trigramQuestions[$phrase] ?? []
                ));
                $topicMatrix[] = [
                    'topic'           => ucwords($phrase),
                    'question_count'  => $cnt,
                    'student_count'   => $stuCnt,
                    'struggle_score'  => $score,
                    'trend'           => 'stable',
                    'trend_pct'       => 0,
                    'difficulty'      => 'intermediate',
                    'event_sources'   => ['chat_questions' => $cnt, 'quiz_failures' => 0, 'repeated_views' => 0, 'assignment_failures' => 0, 'issue_reports' => 0],
                    'materials'       => [],
                    'sample_questions'=> array_slice($samples, 0, 3),
                ];
                $rank++;
            }
        }

        // Sort by struggle score descending
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

        foreach ($studentQuestions as $uid => $qMap) {
            $qCnt     = count($qMap);
            $times    = array_values($qMap);
            $lastTs   = max($times);
            $firstTs  = min($times);
            $span     = max(1, $lastTs - $firstTs);

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

            // Recency (days since last question)
            $daysSince = max(0, (time() - $lastTs) / DAYSECS);
            $recencyWeight = $daysSince < 3 ? 20 : ($daysSince < 7 ? 15 : ($daysSince < 14 ? 10 : 5));

            // Event-based risk factors
            $stuEv = $studentEvents[$uid] ?? [];
            $evQuizFailures = $stuEv['quiz_failure'] ?? 0;
            $evAssignmentFails = $stuEv['assignment_failure'] ?? 0;
            $evRepeatedViews = $stuEv['repeated_views'] ?? 0;
            $evIssueReports = $stuEv['issue_reported'] ?? 0;
            $evBonus = min(25, ($evQuizFailures * 8) + ($evAssignmentFails * 6) + ($evRepeatedViews * 3) + ($evIssueReports * 5));

            // Risk score
            $riskScore = round(min(100,
                ($qCnt / 50) * 30 +
                min(20, $topicDiv * 5) +
                ($trend === 'up' ? 25 : ($trend === 'stable' ? 10 : 0)) +
                $recencyWeight +
                $evBonus
            ));

            $riskLevel = $riskScore >= 60 ? 'high' : ($riskScore >= 30 ? 'medium' : 'low');

            // Student info
            $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname');
            if (!$user) continue;

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
                'last_active'     => $daysSince < 1 ? 'Today' : (int)$daysSince . ' days ago',
            ];
        }

        // Sort by risk score descending
        usort($atRiskStudents, function($a, $b) {
            return $b['risk_score'] - $a['risk_score'];
        });

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
                $questionItems[] = ['id' => (int)$l->id, 'text' => $l->question];
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
    // 11. Event breakdown (already fetched in step 5b)
    // ──────────────────────────────────────────────────────────────
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

        // Determine severity
        if ($stuPct >= 50 || $score >= 70) {
            $severity = 'critical';
        } elseif ($stuPct >= 25 || $score >= 40) {
            $severity = 'attention';
        } else {
            $severity = 'watch';
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

        // Generate suggestion
        $suggestion = self::generate_topic_suggestion($tm, $stuPct, $enrolledCount);

        // Get sample questions from topic_matrix (may have been added by AI or bigram fallback)
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
            'prev_question_count' => 0, // computed below
            'trend'              => $tm['trend'],
            'trend_pct'          => $tm['trend_pct'],
            'struggle_score'     => $score,
            'description'        => $description,
            'sample_questions'   => array_slice($topicSampleQ, 0, 3),
            'suggestion'         => $suggestion['text'],
            'suggestion_type'    => $suggestion['type'],
            'materials'          => array_map(function($m) {
                return ['name' => $m['name'], 'question_count' => $m['question_count']];
            }, array_slice($tm['materials'] ?? [], 0, 3)),
            'affected_student_ids' => array_keys($topicStudents[strtolower($tm['topic'])] ?? []),
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

        $studentNarratives[] = [
            'userid'             => $s['userid'],
            'fullname'           => $s['fullname'],
            'profileimageurl'    => $s['profileimageurl'],
            'risk_score'         => $s['risk_score'],
            'risk_level'         => $s['risk_level'],
            'summary'            => $summary,
            'struggle_topics'    => $s['struggle_topics'],
            'last_active'        => $s['last_active'],
            'days_since_last_login' => (int)round((time() - ($s['last_active'] === 'Today' ? time() : strtotime('-' . $s['last_active']))) / DAYSECS),
            'question_count'     => $s['question_count'],
            'avg_quiz'           => 0, // will be filled from student profile if available
            'ai_queries'         => 0,
            'quiz_failures'      => $s['event_sources']['quiz_failures'] ?? 0,
            'issue_reports'      => $s['event_sources']['issue_reports'] ?? 0,
            'suggestion'         => $suggestion['text'],
            'suggestion_type'    => $suggestion['type'],
        ];
    }

    // -- 12e. Common questions (question radar) --
    $questionCounts = []; // question text => ['count' => N, 'students' => [uid => true], 'topic' => '']
    foreach ($logs as $l) {
        $qtext = trim($l->question);
        if (strlen($qtext) < 5) continue;
        $qkey = strtolower($qtext);
        if (!isset($questionCounts[$qkey])) {
            $questionCounts[$qkey] = ['text' => $qtext, 'count' => 0, 'students' => [], 'topic' => ''];
        }
        $questionCounts[$qkey]['count']++;
        $questionCounts[$qkey]['students'][$l->userid] = true;
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
            $qc['topic'] = 'General';
        }
    }
    unset($qc);
    usort($questionCounts, function($a, $b) { return $b['count'] - $a['count']; });

    $commonQuestions = [];
    foreach (array_slice($questionCounts, 0, 15) as $qc) {
        if ($qc['count'] < 2) break;
        $stuCnt = count($qc['students']);
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
            $suggestion = 'Address this in your next lecture — ' . $stuCnt . ' student(s) are confused about this.';
        }

        $commonQuestions[] = [
            'text'          => $qc['text'],
            'student_count' => $stuCnt,
            'ask_count'     => $qc['count'],
            'topic'         => $topicName,
            'suggestion'    => $suggestion,
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

    $qTrend = 'stable';
    $qTrendPct = 0;
    if ($lastWeekQ > 0) {
        $qTrendPct = round((($thisWeekQ - $lastWeekQ) / $lastWeekQ) * 100);
        if ($qTrendPct > 10) $qTrend = 'up';
        elseif ($qTrendPct < -10) $qTrend = 'down';
    } elseif ($thisWeekQ > 0) {
        $qTrend = 'up';
        $qTrendPct = 100;
    }

    // Top struggle topic for pulse
    $topStruggle = !empty($struggleAreas) ? $struggleAreas[0]['topic'] : '—';
    $topStruggleTrend = !empty($struggleAreas) ? ($struggleAreas[0]['trend_pct'] > 0 ? '+' . $struggleAreas[0]['trend_pct'] . '%' : 'stable') : '—';

    // Disengaged students (no login in 7+ days)
    $disengagedStudents = [];
    foreach ($atRiskStudents as $s) {
        $days = (int)round((time() - ($s['last_active'] === 'Today' ? time() : strtotime('-' . $s['last_active']))) / DAYSECS);
        if ($days >= 7) {
            $disengagedStudents[] = ['name' => $s['fullname'], 'days' => $days];
        }
    }

    // Improving topics
    $improvingTopics = [];
    foreach ($topicMatrix as $tm) {
        if ($tm['trend'] === 'down' && $tm['question_count'] > 0) {
            $improvingTopics[] = $tm['topic'];
        }
    }

    $coursePulse = [
        'avg_quiz'              => 0, // computed from student metrics if available
        'quiz_trend'            => 'stable',
        'quiz_trend_pct'        => 0,
        'at_risk_count'         => count($atRiskStudents),
        'at_risk_trend'         => count($atRiskStudents) > 0 ? 'up' : 'stable',
        'at_risk_trend_delta'   => 0,
        'top_struggle_topic'    => $topStruggle,
        'top_struggle_trend'    => $topStruggleTrend,
        'active_this_week'      => count($thisWeekStudents),
        'total_students'        => $enrolledCount,
        'questions_this_week'   => $thisWeekQ,
        'questions_last_week'   => $lastWeekQ,
        'questions_trend'       => $qTrend,
        'questions_trend_pct'   => $qTrendPct,
    ];

    // Try to get avg quiz from student_metrics
    $avgQuizRow = $DB->get_record_sql(
        "SELECT AVG(risk_score) as avg_risk FROM {umat_ai_student_metrics} WHERE courseid = :cid",
        ['cid' => $cid]
    );
    if ($avgQuizRow && $avgQuizRow->avg_risk !== null) {
        // Invert risk to get a rough engagement/quiz proxy
        $coursePulse['avg_quiz'] = max(0, min(100, round(100 - $avgQuizRow->avg_risk)));
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

    // Quiz scores dropping
    if ($qTrend === 'down' && $thisWeekQ > 5) {
        $priorityActions[] = [
            'type'       => 'quiz_drop',
            'urgency'    => 'medium',
            'icon'       => 'quiz',
            'title'      => 'Activity Dropping',
            'text'       => 'Student questions dropped ' . abs($qTrendPct) . '% this week (' . $thisWeekQ . ' vs ' . $lastWeekQ . ' last week). This could indicate disengagement.',
            'items'      => [],
            'suggestion' => 'Consider checking if students are facing technical issues or have lost access to materials.',
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
        'summary' => [
            'total_questions'    => $totalQuestions,
            'total_students'     => $uniqueStudents,
            'worst_topic'        => $worstTopic,
            'ai_service_used'    => $aiServiceUsed,
            'ai_overall_summary' => $aiSummary,
            'ai_course_health'   => $aiCourseHealthJson,
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
                ]), '', VALUE_OPTIONAL
            ),
            'common_questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'text'          => new \external_value(PARAM_TEXT),
                    'student_count' => new \external_value(PARAM_INT),
                    'ask_count'     => new \external_value(PARAM_INT),
                    'topic'         => new \external_value(PARAM_TEXT),
                    'suggestion'    => new \external_value(PARAM_TEXT),
                ]), '', VALUE_OPTIONAL
            ),
            'course_pulse' => new \external_single_structure([
                'avg_quiz'              => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
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
                'questions_trend'       => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'questions_trend_pct'   => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
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
            'summary' => new \external_single_structure([
                'total_questions'    => new \external_value(PARAM_INT),
                'total_students'     => new \external_value(PARAM_INT),
                'worst_topic'        => new \external_value(PARAM_TEXT),
                'ai_service_used'    => new \external_value(PARAM_BOOL),
                'ai_overall_summary' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                'ai_course_health'   => new \external_value(PARAM_RAW, '', VALUE_OPTIONAL),
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

        // Last active
        $lastActive = $student['last_active'] ?? 'unknown';
        if ($lastActive === 'Today') {
            // no need to mention
        } elseif (strpos($lastActive, 'days ago') !== false) {
            $days = (int)$lastActive;
            if ($days > 7) {
                $parts[] = 'hasn\'t logged in for ' . $days . ' days';
            } elseif ($days > 3) {
                $parts[] = 'last active ' . $days . ' days ago';
            }
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
    private static function generate_student_suggestion($student) {
        $risk = $student['risk_score'] ?? 0;
        $qCnt = $student['question_count'] ?? 0;
        $quizFails = $student['event_sources']['quiz_failures'] ?? 0;
        $lastActive = $student['last_active'] ?? '';
        $topics = $student['struggle_topics'] ?? [];
        $topicStr = !empty($topics) ? $topics[0] : 'the course material';

        // Disengaged (no login in 7+ days)
        if (strpos($lastActive, 'days ago') !== false) {
            $days = (int)$lastActive;
            if ($days >= 7) {
                return [
                    'text' => 'This student has disengaged — hasn\'t logged in for ' . $days . ' days. Consider sending an encouragement message to re-engage them.',
                    'type' => 'encourage',
                ];
            }
        }

        // High risk + not using AI tutor
        if ($risk >= 60 && $qCnt > 5) {
            return [
                'text' => 'This student is asking many questions but may not be using the AI tutor effectively. Consider encouraging them to use it for extra support on ' . $topicStr . '.',
                'type' => 'ai_tutor',
            ];
        }

        // High risk + quiz failures
        if ($risk >= 60 && $quizFails > 0) {
            return [
                'text' => 'Schedule a 1:1 meeting to discuss their understanding of ' . $topicStr . '. They may benefit from a worked example or targeted practice.',
                'type' => 'meeting',
            ];
        }

        // Medium risk + many questions
        if ($risk >= 30 && $qCnt >= 8) {
            return [
                'text' => 'This student is actively seeking help but still struggling. Consider assigning a remedial quiz on ' . $topicStr . ' to reinforce understanding.',
                'type' => 'quiz',
            ];
        }

        // Improving (trend down)
        if ($student['trend'] === 'down') {
            return [
                'text' => 'This student is improving — keep up the positive momentum. Consider assigning advanced problems to challenge them.',
                'type' => 'challenge',
            ];
        }

        // Default
        return [
            'text' => 'Monitor this student\'s progress. Check in periodically to ensure they\'re keeping up.',
            'type' => 'monitor',
        ];
    }

    /**
     * Get sample questions for a topic from chat logs.
     */
    private static function get_sample_questions_for_topic($topicName, $logs, $limit = 3) {
        $topicLower = strtolower($topicName);
        $matching = [];
        foreach ($logs as $l) {
            $qLower = strtolower($l->question);
            if (strpos($qLower, $topicLower) !== false || strpos($topicLower, $qLower) !== false) {
                $matching[] = $l->question;
            }
            if (count($matching) >= $limit) break;
        }
        return $matching;
    }
}
