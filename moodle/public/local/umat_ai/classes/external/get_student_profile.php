<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use local_umat_ai\analytics\student_risk_calculator;
use local_umat_ai\analytics\evidence_formatter;

/**
 * External API: deep per-student profile with chronological activity timeline.
 *
 * Called by analytics_dashboard.js::loadStudentTimeline() when a lecturer
 * expands a student risk card. Uses lazy-loading so only one student's
 * full timeline is fetched at a time.
 *
 * @package    local_umat_ai
 */
class get_student_profile extends \external_api {

    public static function get_student_profile_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'userid'   => new \external_value(PARAM_INT, 'Student user ID'),
        ]);
    }

    public static function get_student_profile($courseid, $userid) {
        global $DB;

        $params = self::validate_parameters(self::get_student_profile_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);
        $cid = (int) $params['courseid'];
        $uid = (int) $params['userid'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // ── Aggregated metrics (from cron) ────────────────────────────────────
        $metric = $DB->get_record('umat_ai_student_metrics', [
            'courseid' => $cid,
            'userid'   => $uid,
        ]);

        $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname, email');

        // ── Past interventions sent by lecturers ──────────────────────────────
        $interventionRows = $DB->get_records(
            'umat_ai_interventions',
            ['courseid' => $cid, 'userid' => $uid],
            'timecreated DESC',
            '*',
            0,
            10
        );
        $interventionList = [];
        foreach ($interventionRows as $inv) {
            $interventionList[] = [
                'action'      => (string) $inv->action_type,
                'status'      => 'sent', // All stored records were successfully sent.
                'timecreated' => (int) $inv->timecreated,
            ];
        }

        // ── Chronological Activity Timeline ───────────────────────────────────
        // Collects the 15 most recent events across all tracked activity sources
        // and returns them in descending time order for the timeline view.
        $twoweeksago = time() - (14 * DAYSECS);
        $rawEvents   = [];

        // 1. Login events (page views in the last 14 days).
        $loginRows = $DB->get_records_sql(
            "SELECT id, timecreated, component, target
               FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid
                AND timecreated > :since AND action = 'viewed'
           ORDER BY timecreated DESC",
            ['uid' => $uid, 'cid' => $cid, 'since' => $twoweeksago],
            0, 5
        );
        foreach ($loginRows as $row) {
            $rawEvents[] = [
                'type'   => 'login',
                'label'  => 'Visited course',
                'detail' => ucfirst(str_replace('_', ' ', $row->component ?: 'course')) . ' — ' .
                            ucfirst(str_replace('_', ' ', $row->target ?: 'page')),
                'icon'   => 'login',
                'time'   => (int) $row->timecreated,
            ];
        }

        // 2. AI questions asked (last 14 days).
        $aiRows = $DB->get_records_sql(
            "SELECT id, question, timecreated
               FROM {umat_ai_chat_logs}
              WHERE userid = :uid AND courseid = :cid
                AND timecreated > :since AND role = 'student'
           ORDER BY timecreated DESC",
            ['uid' => $uid, 'cid' => $cid, 'since' => $twoweeksago],
            0, 5
        );
        foreach ($aiRows as $row) {
            $q = mb_strlen($row->question) > 80
                ? mb_substr($row->question, 0, 77) . '…'
                : $row->question;
            // Strip leading [Referencing: ...] prefix if present.
            $q = preg_replace('/^\[Referencing:\s*[^\]]+\]\s*/i', '', $q);
            $rawEvents[] = [
                'type'   => 'ai_question',
                'label'  => 'Asked AI tutor',
                'detail' => '"' . $q . '"',
                'icon'   => 'smart_toy',
                'time'   => (int) $row->timecreated,
            ];
        }

        // 3. Quiz attempts (last 14 days).
        $quizRows = $DB->get_records_sql(
            "SELECT qg.id, qg.grade, qg.timemodified, q.name AS quiz_name, q.grade AS max_grade
               FROM {quiz_grades} qg
               JOIN {quiz} q ON q.id = qg.quiz
              WHERE qg.userid = :uid AND q.course = :cid
                AND qg.timemodified > :since
           ORDER BY qg.timemodified DESC",
            ['uid' => $uid, 'cid' => $cid, 'since' => $twoweeksago],
            0, 5
        );
        foreach ($quizRows as $row) {
            $pct    = $row->max_grade > 0 ? round(($row->grade / $row->max_grade) * 100) : 0;
            $result = $pct >= 50 ? 'Passed' : 'Failed';
            $rawEvents[] = [
                'type'   => 'quiz',
                'label'  => 'Quiz attempt — ' . $row->quiz_name,
                'detail' => $result . ' — Score: ' . $pct . '% (' .
                            round($row->grade, 1) . ' / ' . round($row->max_grade, 1) . ')',
                'icon'   => 'quiz',
                'time'   => (int) $row->timemodified,
            ];
        }

        // 4. Course material activity events (resource views, AI queries).
        $activityRows = $DB->get_records_sql(
            "SELECT id, event_type, event_data, timecreated
               FROM {umat_ai_activity_log}
              WHERE userid = :uid AND courseid = :cid
                AND timecreated > :since
           ORDER BY timecreated DESC",
            ['uid' => $uid, 'cid' => $cid, 'since' => $twoweeksago],
            0, 5
        );
        foreach ($activityRows as $row) {
            $eventData = $row->event_data ? json_decode($row->event_data, true) : [];
            $icon = match ($row->event_type) {
                'resource_viewed'    => 'menu_book',
                'quiz_submitted'     => 'quiz',
                'submission_graded'  => 'grade',
                default              => 'event',
            };
            $label = match ($row->event_type) {
                'resource_viewed'   => 'Viewed material',
                'quiz_submitted'    => 'Submitted quiz',
                'submission_graded' => 'Assignment graded',
                default             => ucfirst(str_replace('_', ' ', $row->event_type)),
            };
            $detail = isset($eventData['name']) ? $eventData['name'] : '';
            $rawEvents[] = [
                'type'   => $row->event_type,
                'label'  => $label,
                'detail' => $detail,
                'icon'   => $icon,
                'time'   => (int) $row->timecreated,
            ];
        }

        // Sort all events newest-first, then take the 15 most recent.
        usort($rawEvents, function ($a, $b) {
            return $b['time'] - $a['time'];
        });
        $timelineEvents = array_slice($rawEvents, 0, 15);

        // Ensure each event has a non-null detail string.
        $timelineEvents = array_map(function ($e) {
            $e['detail'] = $e['detail'] ?: '';
            return $e;
        }, $timelineEvents);

        // Computed live from the one authoritative model. Errors are surfaced
        // through debugging() rather than swallowed — a bare catch here is what
        // hid the broken analytics layer from view.
        $v2Risk = null;
        try {
            $v2Risk = student_risk_calculator::compute($uid, $cid);
        } catch (\Throwable $e) {
            debugging('local_umat_ai: student_risk_calculator::compute failed for user '
                . $uid . ' — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return [
            'userid'          => $metric ? (int) $metric->userid  : $uid,
            'firstname'       => $user   ? $user->firstname       : '',
            'lastname'        => $user   ? $user->lastname        : '',
            // The live model is the source of truth; the metrics row is only a
            // cached copy that cron refreshes hourly.
            'risk_score'      => $v2Risk
                ? (int) round($v2Risk['risk_score'])
                : ($metric ? (int) $metric->risk_score : 0),
            'total_logins'    => $metric ? (int) $metric->logins           : 0,
            'avg_quiz'        => $v2Risk && isset($v2Risk['factors']['quiz_performance']['raw']['avg_pct'])
                ? (float) $v2Risk['factors']['quiz_performance']['raw']['avg_pct']
                : ($metric ? (float) $metric->avg_quiz_grade : 0.0),
            'ai_queries'      => $metric ? (int) $metric->ai_questions_asked : 0,
            'interventions'   => $interventionList,
            'timeline_events' => $timelineEvents,
            'v2_risk'         => $v2Risk ? [
                'risk_score'     => (float) $v2Risk['risk_score'],
                'risk_level'     => $v2Risk['risk_level'],
                'confidence'     => (float) $v2Risk['confidence'],
                'classification' => $v2Risk['classification'],
                'category_label' => $v2Risk['category_label'],
                'primary_reason' => $v2Risk['primary_reason'],
                'evidence'       => array_map(function ($row) {
                    return [
                        'factor'        => $row['factor'],
                        'label'         => $row['label'],
                        'detail'        => $row['detail'],
                        'points_earned' => (float) $row['points_earned'],
                        'points_max'    => (int) $row['points_max'],
                    ];
                }, $v2Risk['evidence']),
                'trends'         => array_map(function ($t) {
                    return [
                        'direction'  => $t['direction'] ?? 'unknown',
                        'comparable' => !empty($t['comparable']),
                    ];
                }, $v2Risk['trends']),
                'summary'        => evidence_formatter::format_summary($v2Risk),
                'date_range'     => [
                    'from' => (int) $v2Risk['date_range']['from'],
                    'to'   => (int) $v2Risk['date_range']['to'],
                    'days' => (int) $v2Risk['date_range']['days'],
                ],
                'calculated_at'  => (int) $v2Risk['calculated_at'],
            ] : null,
        ];
    }

    public static function get_student_profile_returns() {
        return new \external_single_structure([
            'userid'       => new \external_value(PARAM_INT,   'User ID'),
            'firstname'    => new \external_value(PARAM_TEXT,  'First name'),
            'lastname'     => new \external_value(PARAM_TEXT,  'Last name'),
            'risk_score'   => new \external_value(PARAM_INT,   'Risk score 0–100'),
            'total_logins' => new \external_value(PARAM_INT,   '7-day login count'),
            'avg_quiz'     => new \external_value(PARAM_FLOAT, '14-day avg quiz grade (0–100)'),
            'ai_queries'   => new \external_value(PARAM_INT,   '7-day AI query count'),
            'interventions' => new \external_multiple_structure(
                new \external_single_structure([
                    'action'      => new \external_value(PARAM_TEXT, 'Intervention type'),
                    'status'      => new \external_value(PARAM_TEXT, 'Status (always sent)'),
                    'timecreated' => new \external_value(PARAM_INT,  'Unix timestamp'),
                ])
            ),
            'timeline_events' => new \external_multiple_structure(
                new \external_single_structure([
                    'type'   => new \external_value(PARAM_TEXT, 'Event type identifier'),
                    'label'  => new \external_value(PARAM_TEXT, 'Human-readable event label'),
                    'detail' => new \external_value(PARAM_TEXT, 'Additional event detail'),
                    'icon'   => new \external_value(PARAM_TEXT, 'Material Symbol icon name'),
                    'time'   => new \external_value(PARAM_INT,  'Unix timestamp'),
                ])
            ),
            // Previously built but never declared, so clean_returnvalue()
            // discarded it and the expanded student panel had nothing to show.
            'v2_risk' => new \external_single_structure([
                'risk_score'     => new \external_value(PARAM_FLOAT, 'Risk score 0-100'),
                'risk_level'     => new \external_value(PARAM_TEXT,  'critical|high|medium|low'),
                'confidence'     => new \external_value(PARAM_FLOAT, 'Evidence completeness 0-1'),
                'classification' => new \external_value(PARAM_TEXT,  'Risk category id'),
                'category_label' => new \external_value(PARAM_TEXT,  'Human-readable category'),
                'primary_reason' => new \external_value(PARAM_TEXT,  'Dominant factor, in plain language'),
                'evidence'       => new \external_multiple_structure(
                    new \external_single_structure([
                        'factor'        => new \external_value(PARAM_TEXT),
                        'label'         => new \external_value(PARAM_TEXT),
                        'detail'        => new \external_value(PARAM_TEXT),
                        'points_earned' => new \external_value(PARAM_FLOAT),
                        'points_max'    => new \external_value(PARAM_INT),
                    ]), '', VALUE_OPTIONAL
                ),
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
                'summary'        => new \external_value(PARAM_TEXT, 'Plain-language interpretation'),
                'date_range'     => new \external_single_structure([
                    'from' => new \external_value(PARAM_INT),
                    'to'   => new \external_value(PARAM_INT),
                    'days' => new \external_value(PARAM_INT),
                ], '', VALUE_OPTIONAL),
                'calculated_at'  => new \external_value(PARAM_INT, 'Unix timestamp'),
            ], 'Authoritative risk record', VALUE_OPTIONAL),
        ]);
    }
}
