<?php
/**
 * External API: Spaced-repetition flashcards (M2 / F3).
 *
 * Self-service workflow: any enrolled student generates their OWN private
 * deck via the AI service (cards are active immediately — no approval flow).
 * Each user only ever sees and reviews cards they created themselves
 * (created_by = user id), through the SM-2 loop
 * (get_due_flashcards + submit_review).
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/../../lib.php'); // SM-2 helpers + service config.

class flashcards extends \external_api {

    /**
     * Shared guard: resolve the course context and verify enrolment.
     *
     * @param int $courseid
     * @return \context_course
     */
    protected static function require_course_access(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/umat_ai:chatwithai', $context);
        if (!is_enrolled($context)) {
            throw new \moodle_exception('You must be enrolled in this course to access flashcards.');
        }
        return $context;
    }

    /**
     * Parse a JSON array parameter safely (external params arrive as strings).
     *
     * @param string $raw
     * @return array
     */
    protected static function parse_ids(string $raw): array {
        $ids = json_decode($raw, true);
        if (!is_array($ids)) {
            $ids = [];
        }
        return array_values(array_filter(array_map('intval', $ids)));
    }

    // ------------------------------------------------------------------ //
    // generate_flashcards — enrolled user → AI service → private cards    //
    // ------------------------------------------------------------------ //

    public static function generate_flashcards_parameters() {
        return new \external_function_parameters([
            'courseid'     => new \external_value(PARAM_INT, 'Course ID'),
            'material_ids' => new \external_value(PARAM_RAW, 'JSON array of material IDs', VALUE_DEFAULT, '[]'),
            'count'        => new \external_value(PARAM_INT, 'Number of cards to generate (1-30)', VALUE_DEFAULT, 10),
            'topic_label'  => new \external_value(PARAM_TEXT, 'Optional topic label applied to all cards', VALUE_DEFAULT, ''),
        ]);
    }

    public static function generate_flashcards($courseid, $material_ids = '[]', $count = 10, $topic_label = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::generate_flashcards_parameters(), [
            'courseid'     => $courseid,
            'material_ids' => $material_ids,
            'count'        => $count,
            'topic_label'  => $topic_label,
        ]);

        // Self-service: any enrolled user may generate their own private deck.
        self::require_course_access((int) $params['courseid']);

        $matids = self::parse_ids($params['material_ids']);
        if (empty($matids)) {
            return ['success' => false, 'cards' => [], 'total' => 0, 'message' => 'Select at least one material.'];
        }
        $count = max(1, min(30, (int) $params['count']));

        $config = \local_umat_ai_get_service_config();
        $url = rtrim($config['url'], '/') . '/api/v1/flashcards/generate';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'course_id'    => (int) $params['courseid'],
                'material_ids' => $matids,
                'count'        => $count,
                'topic_label'  => (string) $params['topic_label'],
                'role'         => 'student',
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['token'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 160,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $detail = $response ? ': ' . mb_substr($response, 0, 300) : '';
            if ($httpCode === 0) {
                throw new \moodle_exception('Cannot reach AI service at ' . $config['url'] . '. Please ensure the AI service is running and try again.');
            }
            throw new \moodle_exception('AI service returned HTTP ' . $httpCode . $detail);
        }

        $result = json_decode($response, true);
        if (!$result || empty($result['cards'])) {
            throw new \moodle_exception('Invalid AI response: ' . mb_substr((string) $response, 0, 500));
        }

        $now = time();
        $saved = [];
        foreach ($result['cards'] as $card) {
            $front = trim((string) ($card['front'] ?? ''));
            $back  = trim((string) ($card['back'] ?? ''));
            if ($front === '' || $back === '') {
                continue;
            }
            $rec = (object) [
                'courseid'     => (int) $params['courseid'],
                'materialid'   => (int) ($card['materialid'] ?? ($matids[0] ?? null)),
                'front'        => $front,
                'back'         => $back,
                'topic'        => \core_text::substr(trim((string) ($card['topic'] ?? $params['topic_label'] ?? '')), 0, 255),
                'status'       => 1, // private deck — active immediately, no approval
                'created_by'   => (int) $USER->id,
                'approved_by'  => 0,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
            $rec->id = $DB->insert_record('umat_ai_flashcards', $rec);
            $saved[] = [
                'id'    => (int) $rec->id,
                'front' => $rec->front,
                'back'  => $rec->back,
                'topic' => $rec->topic,
            ];
        }

        return [
            'success' => !empty($saved),
            'cards'   => $saved,
            'total'   => count($saved),
            'message' => count($saved) . ' flashcard(s) added to your private deck.',
        ];
    }

    public static function generate_flashcards_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether generation succeeded'),
            'cards'   => new \external_multiple_structure(
                new \external_single_structure([
                    'id'    => new \external_value(PARAM_INT, 'Flashcard ID'),
                    'front' => new \external_value(PARAM_TEXT, 'Question side'),
                    'back'  => new \external_value(PARAM_TEXT, 'Answer side'),
                    'topic' => new \external_value(PARAM_TEXT, 'Topic label'),
                ])
            ),
            'total'   => new \external_value(PARAM_INT, 'Number of cards saved'),
            'message' => new \external_value(PARAM_TEXT, 'Feedback message'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_flashcards — the user's own private deck listing                 //
    // ------------------------------------------------------------------ //

    public static function get_flashcards_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'status'   => new \external_value(PARAM_INT, 'Legacy status filter (ignored — deck is private)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function get_flashcards($courseid, $status = 1) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_flashcards_parameters(), [
            'courseid' => $courseid,
            'status'   => $status,
        ]);

        self::require_course_access((int) $params['courseid']);

        // Private deck: only cards this user generated for this course.
        $cards = $DB->get_records('umat_ai_flashcards', [
            'courseid'  => (int) $params['courseid'],
            'created_by'=> (int) $USER->id,
        ], 'timecreated ASC');

        $out = [];
        foreach ($cards as $card) {
            $review = $DB->get_record('umat_ai_flashcard_reviews', ['userid' => (int) $USER->id, 'flashcardid' => (int) $card->id]);
            $out[] = [
                'id'           => (int) $card->id,
                'front'        => $card->front,
                'back'         => $card->back,
                'topic'        => (string) $card->topic,
                'materialid'   => (int) $card->materialid,
                'status'       => (int) $card->status,
                'timecreated'  => (int) $card->timecreated,
                'review'       => $review ? [
                    'ease'        => (float) $review->ease,
                    'interval'    => (int) $review->interval,
                    'repetitions' => (int) $review->repetitions,
                    'due_at'      => (int) $review->due_at,
                    'timereviewed'=> (int) $review->timereviewed,
                ] : null,
            ];
        }

        return ['cards' => $out, 'total' => count($out)];
    }

    public static function get_flashcards_returns() {
        return new \external_single_structure([
            'cards' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT, 'Flashcard ID'),
                    'front'       => new \external_value(PARAM_TEXT, 'Question side'),
                    'back'        => new \external_value(PARAM_TEXT, 'Answer side'),
                    'topic'       => new \external_value(PARAM_TEXT, 'Topic label'),
                    'materialid'  => new \external_value(PARAM_INT, 'Source material ID'),
                    'status'      => new \external_value(PARAM_INT, 'Workflow status'),
                    'timecreated' => new \external_value(PARAM_INT, 'Creation timestamp'),
                    'review'      => new \external_single_structure([
                        'ease'        => new \external_value(PARAM_FLOAT, 'SM-2 ease factor'),
                        'interval'    => new \external_value(PARAM_INT, 'Interval in days'),
                        'repetitions' => new \external_value(PARAM_INT, 'Repetition count'),
                        'due_at'      => new \external_value(PARAM_INT, 'Next due timestamp'),
                        'timereviewed'=> new \external_value(PARAM_INT, 'Last review timestamp'),
                    ], 'Student review state', VALUE_OPTIONAL, null, NULL_ALLOWED),
                ])
            ),
            'total' => new \external_value(PARAM_INT, 'Number of cards returned'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // get_due_flashcards — user review queue (SM-2 due cards from own deck)//
    // ------------------------------------------------------------------ //

    public static function get_due_flashcards_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'limit'    => new \external_value(PARAM_INT, 'Maximum cards to return (default 20)', VALUE_DEFAULT, 20),
        ]);
    }

    public static function get_due_flashcards($courseid, $limit = 20) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_due_flashcards_parameters(), [
            'courseid' => $courseid,
            'limit'    => $limit,
        ]);

        self::require_course_access((int) $params['courseid']);

        $limit = max(1, min(100, (int) $params['limit']));
        $now = time();
        $uid = (int) $USER->id;

        // Cards the user created themselves with no review row (new) or with due_at in the past.
        $sql = "SELECT f.id, f.front, f.back, f.topic, f.materialid,
                       COALESCE(r.ease, 2.5)     AS ease,
                       COALESCE(r.interval, 0)   AS interval,
                       COALESCE(r.repetitions, 0) AS repetitions,
                       COALESCE(r.due_at, 0)      AS due_at,
                       COALESCE(r.timereviewed, 0) AS timereviewed
                  FROM {umat_ai_flashcards} f
             LEFT JOIN {umat_ai_flashcard_reviews} r
                    ON r.flashcardid = f.id AND r.userid = :uid
                 WHERE f.courseid = :cid AND f.created_by = :uid AND f.status = 1
                   AND (r.id IS NULL OR r.due_at <= :now)
              ORDER BY COALESCE(r.due_at, 0) ASC, f.id ASC
                 LIMIT " . $limit;

        $rows = $DB->get_records_sql($sql, ['uid' => $uid, 'cid' => (int) $params['courseid'], 'now' => $now]);

        $cards = [];
        foreach ($rows as $row) {
            $cards[] = [
                'id'          => (int) $row->id,
                'front'       => $row->front,
                'back'        => $row->back,
                'topic'       => (string) $row->topic,
                'materialid'  => (int) $row->materialid,
                'ease'        => (float) $row->ease,
                'interval'    => (int) $row->interval,
                'repetitions' => (int) $row->repetitions,
                'due_at'      => (int) $row->due_at,
                'timereviewed'=> (int) $row->timereviewed,
            ];
        }

        return ['cards' => $cards, 'total' => count($cards)];
    }

    public static function get_due_flashcards_returns() {
        return new \external_single_structure([
            'cards' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT, 'Flashcard ID'),
                    'front'       => new \external_value(PARAM_TEXT, 'Question side'),
                    'back'        => new \external_value(PARAM_TEXT, 'Answer side'),
                    'topic'       => new \external_value(PARAM_TEXT, 'Topic label'),
                    'materialid'  => new \external_value(PARAM_INT, 'Source material ID'),
                    'ease'        => new \external_value(PARAM_FLOAT, 'SM-2 ease factor'),
                    'interval'    => new \external_value(PARAM_INT, 'Interval in days'),
                    'repetitions' => new \external_value(PARAM_INT, 'Repetition count'),
                    'due_at'      => new \external_value(PARAM_INT, 'Next due timestamp'),
                    'timereviewed'=> new \external_value(PARAM_INT, 'Last review timestamp'),
                ])
            ),
            'total' => new \external_value(PARAM_INT, 'Number of due cards'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // submit_review — user grades one of their own cards (SM-2 transition) //
    // ------------------------------------------------------------------ //

    public static function submit_review_parameters() {
        return new \external_function_parameters([
            'cardid'  => new \external_value(PARAM_INT, 'Flashcard ID'),
            'courseid'=> new \external_value(PARAM_INT, 'Course ID'),
            'button'  => new \external_value(PARAM_ALPHA, 'again|hard|good|easy'),
        ]);
    }

    public static function submit_review($cardid, $courseid, $button) {
        global $DB, $USER;

        $params = self::validate_parameters(self::submit_review_parameters(), [
            'cardid'   => $cardid,
            'courseid' => $courseid,
            'button'   => $button,
        ]);

        self::require_course_access((int) $params['courseid']);

        $quality = \local_umat_ai_sm2_button_quality($params['button']);
        if ($quality === null) {
            return ['success' => false, 'message' => 'Invalid review button.', 'next_due_at' => 0];
        }

        // Card must exist, belong to this course and be one of the user's own cards.
        $card = $DB->get_record('umat_ai_flashcards', [
            'id'         => (int) $params['cardid'],
            'courseid'   => (int) $params['courseid'],
            'created_by' => (int) $USER->id,
            'status'     => 1,
        ]);
        if (!$card) {
            return ['success' => false, 'message' => 'Flashcard not found.', 'next_due_at' => 0];
        }

        $review = $DB->get_record('umat_ai_flashcard_reviews', [
            'userid'      => (int) $USER->id,
            'flashcardid' => (int) $card->id,
        ]);

        $state = \local_umat_ai_sm2_review(
            $quality,
            (float) ($review->ease ?? 2.5),
            (int) ($review->interval ?? 0),
            (int) ($review->repetitions ?? 0)
        );

        $now = time();
        $rec = (object) [
            'userid'       => (int) $USER->id,
            'flashcardid'  => (int) $card->id,
            'quality'      => $quality,
            'ease'         => $state['ease'],
            'interval'     => $state['interval'],
            'repetitions'  => $state['repetitions'],
            'due_at'       => \local_umat_ai_sm2_next_due($state['interval'], $now),
            'timereviewed' => $now,
        ];

        if ($review) {
            $rec->id = $review->id;
            $DB->update_record('umat_ai_flashcard_reviews', $rec);
        } else {
            $rec->id = $DB->insert_record('umat_ai_flashcard_reviews', $rec);
        }

        return [
            'success'      => true,
            'message'      => 'Review recorded.',
            'next_due_at'  => (int) $rec->due_at,
            'interval'     => (int) $rec->interval,
            'repetitions'  => (int) $rec->repetitions,
            'ease'         => (float) $rec->ease,
        ];
    }

    public static function submit_review_returns() {
        return new \external_single_structure([
            'success'     => new \external_value(PARAM_BOOL, 'Whether the review was recorded'),
            'message'     => new \external_value(PARAM_TEXT, 'Feedback message'),
            'next_due_at' => new \external_value(PARAM_INT, 'Next due timestamp'),
            'interval'    => new \external_value(PARAM_INT, 'New interval in days', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'repetitions' => new \external_value(PARAM_INT, 'New repetition count', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'ease'        => new \external_value(PARAM_FLOAT, 'New ease factor', VALUE_OPTIONAL, null, NULL_ALLOWED),
        ]);
    }
}
