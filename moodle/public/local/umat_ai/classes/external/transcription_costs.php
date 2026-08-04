<?php
/**
 * External API: transcription_costs — aggregated cost breakdown per course/month
 * for the Transcription Cost Dashboard.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_umat_ai\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class transcription_costs extends \external_api {

    // ------------------------------------------------------------------ //
    // get_transcription_costs                                               //
    // ------------------------------------------------------------------ //
    public static function get_transcription_costs_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT,
                'Course ID (0 = all courses user can view analytics for)',
                VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_transcription_costs($courseid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::get_transcription_costs_parameters(),
            ['courseid' => $courseid]
        );
        $cid = (int)$params['courseid'];

        // Determine which course IDs the user can view analytics for.
        $courseIds = [];
        if ($cid > 0) {
            $ctx = \context_course::instance($cid);
            self::validate_context($ctx);
            require_capability('local/umat_ai:viewanalytics', $ctx);
            $courseIds[] = $cid;
        } else {
            // Get all courses where user has viewanalytics capability.
            $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
            foreach ($courses as $c) {
                $ctx = \context_course::instance($c->id);
                if (has_capability('local/umat_ai:viewanalytics', $ctx)) {
                    $courseIds[] = (int)$c->id;
                }
            }
        }
        if (empty($courseIds)) {
            return self::_empty_response();
        }

        list($inSql, $inParams) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);

        // Aggregate per course.
        $perCourse = $DB->get_records_sql(
            "SELECT courseid,
                    COUNT(id) AS recording_count,
                    COALESCE(SUM(transcription_cost), 0) AS total_cost,
                    COALESCE(SUM(audio_duration_secs), 0) AS total_duration_secs,
                    COALESCE(SUM(chunk_count), 0) AS total_chunks,
                    COUNT(CASE WHEN transcription_provider IS NOT NULL AND transcription_provider != '' THEN 1 END) AS transcribed_count,
                    COUNT(CASE WHEN has_transcript = 1 OR (transcript_json IS NOT NULL AND transcript_json != '') OR status = 'completed' THEN 1 END) AS has_transcript_count
               FROM {umat_ai_sessions}
              WHERE courseid $inSql
                AND recording_url IS NOT NULL AND recording_url != ''
           GROUP BY courseid
           ORDER BY total_cost DESC",
            $inParams
        );

        // Monthly trend across all queried courses.
        $monthlyRows = $DB->get_records_sql(
            "SELECT TO_CHAR(TO_TIMESTAMP(timecreated), 'YYYY-MM') AS month,
                    COUNT(id) AS recording_count,
                    COALESCE(SUM(transcription_cost), 0) AS total_cost,
                    COALESCE(SUM(audio_duration_secs), 0) AS total_duration_secs
               FROM {umat_ai_sessions}
              WHERE courseid $inSql
                AND recording_url IS NOT NULL AND recording_url != ''
                AND transcription_cost IS NOT NULL AND transcription_cost > 0
           GROUP BY month
           ORDER BY month",
            $inParams
        );

        // Provider breakdown across all queried courses.
        $providerRows = $DB->get_records_sql(
            "SELECT COALESCE(NULLIF(transcription_provider, ''), 'unknown') AS provider,
                    COUNT(id) AS recording_count,
                    COALESCE(SUM(transcription_cost), 0) AS total_cost,
                    COALESCE(SUM(audio_duration_secs), 0) AS total_duration_secs
               FROM {umat_ai_sessions}
              WHERE courseid $inSql
                AND recording_url IS NOT NULL AND recording_url != ''
                AND transcription_provider IS NOT NULL AND transcription_provider != ''
           GROUP BY provider
           ORDER BY total_cost DESC",
            $inParams
        );

        // Grand totals.
        $grandTotal = $DB->get_record_sql(
            "SELECT COUNT(id) AS total_recordings,
                    COALESCE(SUM(transcription_cost), 0) AS total_cost,
                    COALESCE(SUM(audio_duration_secs), 0) AS total_duration_secs,
                    COUNT(CASE WHEN transcription_provider IS NOT NULL AND transcription_provider != '' THEN 1 END) AS transcribed_count
               FROM {umat_ai_sessions}
              WHERE courseid $inSql
                AND recording_url IS NOT NULL AND recording_url != ''",
            $inParams
        );

        // Resolve course names.
        $courseNames = [];
        if (!empty($courseIds)) {
            $courses = $DB->get_records_list('course', 'id', $courseIds, '', 'id,fullname,shortname');
            foreach ($courses as $c) {
                $courseNames[$c->id] = format_string($c->fullname);
            }
        }

        // Build per-course array.
        $courseList = [];
        foreach ($perCourse as $pc) {
            $cid = (int)$pc->courseid;
            $courseList[] = [
                'courseid'            => $cid,
                'course_name'         => $courseNames[$cid] ?? 'Unknown Course',
                'recording_count'     => (int)$pc->recording_count,
                'total_cost'          => (float)$pc->total_cost,
                'total_duration_secs' => (float)$pc->total_duration_secs,
                'total_chunks'        => (int)$pc->total_chunks,
                'transcribed_count'   => (int)$pc->transcribed_count,
                'has_transcript_count' => (int)$pc->has_transcript_count,
                'avg_cost_per_recording' => $pc->recording_count > 0
                    ? round((float)$pc->total_cost / (int)$pc->recording_count, 6)
                    : 0,
            ];
        }

        // Build monthly trend array.
        $monthlyTrend = [];
        foreach ($monthlyRows as $mr) {
            $monthlyTrend[] = [
                'month'            => $mr->month,
                'recording_count'  => (int)$mr->recording_count,
                'total_cost'       => (float)$mr->total_cost,
                'total_duration_secs' => (float)$mr->total_duration_secs,
            ];
        }

        // Build provider breakdown array.
        $providerBreakdown = [];
        foreach ($providerRows as $pr) {
            $providerBreakdown[] = [
                'provider'          => $pr->provider,
                'recording_count'   => (int)$pr->recording_count,
                'total_cost'        => (float)$pr->total_cost,
                'total_duration_secs' => (float)$pr->total_duration_secs,
            ];
        }

        // If no provider breakdown rows but we have data, provide defaults.
        if (empty($providerBreakdown) && $grandTotal && $grandTotal->transcribed_count > 0) {
            $providerBreakdown[] = [
                'provider'          => 'local',
                'recording_count'   => (int)$grandTotal->transcribed_count,
                'total_cost'        => 0,
                'total_duration_secs' => (float)$grandTotal->total_duration_secs,
            ];
        }

        return [
            'total_cost'          => (float)($grandTotal->total_cost ?? 0),
            'total_duration_secs' => (float)($grandTotal->total_duration_secs ?? 0),
            'total_recordings'    => (int)($grandTotal->total_recordings ?? 0),
            'transcribed_count'   => (int)($grandTotal->transcribed_count ?? 0),
            'per_course'          => $courseList,
            'monthly_trend'       => $monthlyTrend,
            'provider_breakdown'  => $providerBreakdown,
        ];
    }

    public static function get_transcription_costs_returns() {
        return new \external_single_structure([
            'total_cost'          => new \external_value(PARAM_FLOAT, 'Grand total transcription cost in USD'),
            'total_duration_secs' => new \external_value(PARAM_FLOAT, 'Total audio duration in seconds'),
            'total_recordings'    => new \external_value(PARAM_INT, 'Total recording count'),
            'transcribed_count'   => new \external_value(PARAM_INT, 'Number of recordings with a known provider'),
            'per_course'          => new \external_multiple_structure(
                new \external_single_structure([
                    'courseid'             => new \external_value(PARAM_INT),
                    'course_name'          => new \external_value(PARAM_TEXT),
                    'recording_count'      => new \external_value(PARAM_INT),
                    'total_cost'           => new \external_value(PARAM_FLOAT),
                    'total_duration_secs'  => new \external_value(PARAM_FLOAT),
                    'total_chunks'         => new \external_value(PARAM_INT),
                    'transcribed_count'    => new \external_value(PARAM_INT),
                    'has_transcript_count' => new \external_value(PARAM_INT),
                    'avg_cost_per_recording' => new \external_value(PARAM_FLOAT),
                ])
            ),
            'monthly_trend' => new \external_multiple_structure(
                new \external_single_structure([
                    'month'            => new \external_value(PARAM_TEXT, 'YYYY-MM'),
                    'recording_count'  => new \external_value(PARAM_INT),
                    'total_cost'       => new \external_value(PARAM_FLOAT),
                    'total_duration_secs' => new \external_value(PARAM_FLOAT),
                ])
            ),
            'provider_breakdown' => new \external_multiple_structure(
                new \external_single_structure([
                    'provider'          => new \external_value(PARAM_TEXT),
                    'recording_count'   => new \external_value(PARAM_INT),
                    'total_cost'        => new \external_value(PARAM_FLOAT),
                    'total_duration_secs' => new \external_value(PARAM_FLOAT),
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------ //
    // Helpers                                                               //
    // ------------------------------------------------------------------ //

    /** Return an empty response when no courses are accessible. */
    private static function _empty_response() {
        return [
            'total_cost'          => 0,
            'total_duration_secs' => 0,
            'total_recordings'    => 0,
            'transcribed_count'   => 0,
            'per_course'          => [],
            'monthly_trend'       => [],
            'provider_breakdown'  => [],
        ];
    }
}
