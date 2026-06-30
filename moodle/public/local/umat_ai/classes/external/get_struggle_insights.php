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
            $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
            $client->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['token'], 'X-Request-Id: ' . local_umat_ai_request_id()]);
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
    // 12. Summary & return
    // ──────────────────────────────────────────────────────────────
    $aiSummary = $aiOverallSummary ?: '';
    $aiCourseHealthJson = $aiCourseHealth ? json_encode($aiCourseHealth) : null;
    $result = [
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
        return new \external_single_structure([
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
        ]);
    }
}
