<?php
/**
 * External API: Lecturer asks the AI about course performance and analytics.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class lecturer_ai_query extends \external_api {

    public static function ask_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT,  'Course ID'),
            'query'    => new \external_value(PARAM_TEXT, 'Lecturer query text'),
        ]);
    }

    public static function ask($courseid, $query) {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::ask_parameters(),
            ['courseid' => $courseid, 'query' => $query]
        );

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        // Gather analytics context for the AI prompt.
        $since = time() - (30 * 86400);

        $totalInteractions = $DB->count_records_select(
            'umat_ai_chat_logs',
            'courseid = :courseid AND timecreated > :since',
            ['courseid' => $params['courseid'], 'since' => $since]
        );

        $topQs = $DB->get_records_sql(
            "SELECT question, COUNT(*) AS cnt
               FROM {umat_ai_chat_logs}
              WHERE courseid = :courseid AND timecreated > :since AND role = 'student'
           GROUP BY question ORDER BY cnt DESC",
            ['courseid' => $params['courseid'], 'since' => $since],
            0, 5
        );
        $topQsList = implode('; ', array_column((array) $topQs, 'question'));

        // Build an analytics-enriched prompt.
        $analyticsCtx = "Course analytics context: "
            . "Total AI interactions in last 30 days: {$totalInteractions}. "
            . "Top student questions: {$topQsList}. ";

        $cfg    = local_umat_ai_get_service_config();
        $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($cfg['url'])]);
        $client->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token'],
            'X-Request-Id: ' . local_umat_ai_request_id(),
        ]);
        $client->setopt(['CURLOPT_TIMEOUT' => 30]);

        $payload = json_encode([
            'question'  => $analyticsCtx . ' Lecturer query: ' . $params['query'],
            'course_id' => $params['courseid'],
            'user_id'   => $USER->id,
            'role'      => 'lecturer',
        ]);

        $rawResponse = $client->post($cfg['url'] . '/api/v1/query', $payload);
        $result      = json_decode($rawResponse, true);

        $answer = $result['answer'] ?? get_string('ai_unavailable', 'local_umat_ai');

        // Log lecturer interaction.
        $DB->insert_record('umat_ai_lecturer_notes', (object)[
            'userid'      => $USER->id,
            'courseid'    => $params['courseid'],
            'query'       => $params['query'],
            'response'    => $answer,
            'timecreated' => time(),
        ]);

        return [
            'success'  => !empty($result['answer']),
            'response' => $answer,
        ];
    }

    public static function ask_returns() {
        return new \external_single_structure([
            'success'  => new \external_value(PARAM_BOOL),
            'response' => new \external_value(PARAM_RAW),
        ]);
    }
}
