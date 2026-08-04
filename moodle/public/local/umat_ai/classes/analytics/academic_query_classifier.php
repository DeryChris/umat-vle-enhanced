<?php
/**
 * Classifies student chat messages so that only genuine academic learning
 * questions reach the analytics layer.
 *
 * The dashboard previously counted every row in umat_ai_chat_logs as a
 * "question", so greetings, "quiz me" commands and one-word filler inflated the
 * Question Activity chart, the Common Questions list and — because question
 * volume was the dominant risk input — the at-risk student list itself.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class academic_query_classifier {

    /** Session-key prefixes that identify non-student-learning conversations. */
    const NON_ACADEMIC_SESSION_PREFIXES = ['ins_nlq_', 'lec_nlq_', 'lec_mini_', 'sd_ai_'];

    /**
     * Load the shared risk configuration.
     *
     * Note: this must be `require`, not `require_once`. risk_config.php is a
     * plain "return [...]" file that several analytics classes include. With
     * require_once, whichever class loaded second received boolean true instead
     * of the config array, and every subsequent array access failed.
     *
     * @return array
     */
    private static function config(): array {
        global $CFG;
        static $cache = null;
        if ($cache === null) {
            $cache = require($CFG->dirroot . '/local/umat_ai/classes/analytics/risk_config.php');
        }
        return $cache;
    }

    /**
     * Lower-case, collapse whitespace, strip client-added citation prefixes and
     * trim surrounding punctuation.
     *
     * @param string $question
     * @return string
     */
    public static function normalize(string $question): string {
        $text = trim($question);

        // "[Referencing: lecture3.pdf] What is a payment gateway?" → the question.
        $config = self::config();
        foreach ($config['query_filter']['strip_prefixes'] as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/^[^\w]+|[^\w]+$/u', '', $text);

        return $text === null ? '' : $text;
    }

    /**
     * Infer a source type from a chat log record when the row does not carry
     * one explicitly. umat_ai_chat_logs has no source column, so the session
     * key prefix is the only available signal.
     *
     * @param object $log
     * @return string
     */
    public static function source_from_log($log): string {
        $role = isset($log->role) ? (string) $log->role : 'student';
        if ($role !== 'student') {
            // Lecturer NLQ and system rows are not student learning questions.
            return 'system';
        }

        $sessionkey = isset($log->session_key) ? (string) $log->session_key : '';
        foreach (self::NON_ACADEMIC_SESSION_PREFIXES as $prefix) {
            if ($sessionkey !== '' && strpos($sessionkey, $prefix) === 0) {
                return 'system';
            }
        }

        return 'chat';
    }

    /**
     * Classify a single message.
     *
     * @param string $question
     * @param string $source_type One of: chat, quiz_generation, issue_report, system.
     * @return string One of: academic, greeting, command, filler, quiz_request, non_academic.
     */
    public static function classify_intent(string $question, string $source_type = 'chat'): string {
        $config = self::config();
        $filter = $config['query_filter'];

        // Source alone can disqualify a message regardless of its wording.
        if (in_array($source_type, $filter['non_academic_sources'], true)) {
            return $source_type === 'quiz_generation' ? 'quiz_request' : 'non_academic';
        }

        $normalized = self::normalize($question);
        if ($normalized === '') {
            return 'filler';
        }

        if (preg_match($filter['greeting_patterns'], $normalized)) {
            return 'greeting';
        }

        if (preg_match($filter['command_patterns'], $normalized)) {
            return 'command';
        }

        if (preg_match($filter['filler_patterns'], $normalized)) {
            return 'filler';
        }

        // Too short to carry academic content. A trailing "?" is not enough on
        // its own — "ok?" is still filler.
        $wordcount = count(preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY));
        if (mb_strlen($normalized) < $filter['min_question_length']
            || $wordcount < $filter['min_question_words']) {
            return 'filler';
        }

        return 'academic';
    }

    /**
     * @param string $question
     * @param string $source_type
     * @return bool
     */
    public static function is_academic(string $question, string $source_type = 'chat'): bool {
        return self::classify_intent($question, $source_type) === 'academic';
    }

    /**
     * Keep only the academic learning questions from a set of chat log records.
     * Each surviving record is annotated with _intent and _normalized.
     *
     * @param array $logs Records from umat_ai_chat_logs.
     * @return array Re-indexed list of academic records.
     */
    public static function filter_academic(array $logs): array {
        $result = [];
        foreach ($logs as $log) {
            $question = isset($log->question) ? (string) $log->question : '';
            $source   = self::source_from_log($log);
            $intent   = self::classify_intent($question, $source);
            if ($intent !== 'academic') {
                continue;
            }
            $log->_intent     = $intent;
            $log->_normalized = self::normalize($question);
            $result[] = $log;
        }
        return $result;
    }

    /**
     * Count messages by intent. Used to explain a question-volume change as
     * engagement rather than letting raw totals speak for themselves.
     *
     * @param array $logs
     * @return array intent => count
     */
    public static function intent_breakdown(array $logs): array {
        $counts = [
            'academic' => 0, 'greeting' => 0, 'command' => 0,
            'filler' => 0, 'quiz_request' => 0, 'non_academic' => 0,
        ];
        foreach ($logs as $log) {
            $question = isset($log->question) ? (string) $log->question : '';
            $intent = self::classify_intent($question, self::source_from_log($log));
            $counts[$intent] = ($counts[$intent] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Reduce a question to a comparable key so that "What is a payment gateway"
     * and "how does the payment gateway work?" collapse to one intent.
     *
     * @param string $question
     * @return string
     */
    public static function normalize_for_dedup(string $question): string {
        $stopwords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'what', 'how', 'why', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'can', 'could', 'would', 'should',
            'will', 'shall', 'may', 'might', 'must',
            'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'and', 'or',
            'from', 'as', 'into', 'about', 'between', 'through', 'work', 'works',
            'during', 'before', 'after', 'above', 'below', 'under', 'over',
            'me', 'my', 'you', 'your', 'we', 'our', 'it', 'its', 'this', 'that',
            'please', 'explain', 'tell', 'give', 'mean', 'means',
        ];

        $normalized = self::normalize($question);
        $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $filtered = array_values(array_diff($words, $stopwords));

        // Crude singularisation so "gateways" and "gateway" share a key.
        $filtered = array_map(function ($w) {
            if (mb_strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
                return substr($w, 0, -1);
            }
            return $w;
        }, $filtered);

        $filtered = array_unique($filtered);
        sort($filtered);

        return implode(' ', $filtered);
    }

    /**
     * Group academic questions by normalised intent.
     *
     * The representative text is the LONGEST original variant, which reads far
     * better to a lecturer than the alphabetised keyword soup used as the key.
     *
     * @param array $logs Academic chat log records.
     * @return array Ranked list of grouped questions.
     */
    public static function build_question_map(array $logs): array {
        $map = [];

        foreach ($logs as $log) {
            $question = isset($log->question) ? (string) $log->question : '';
            $userid   = isset($log->userid) ? (int) $log->userid : 0;
            $ts       = isset($log->timecreated) ? (int) $log->timecreated : 0;
            $display  = self::strip_prefixes_only($question);
            $dedup    = self::normalize_for_dedup($question);

            if ($dedup === '') {
                continue;
            }

            if (!isset($map[$dedup])) {
                $map[$dedup] = [
                    'question'      => $display,
                    'dedup_key'     => $dedup,
                    'count'         => 0,
                    'student_count' => 0,
                    'studentids'    => [],
                    'variants'      => [],
                    'first_asked'   => $ts,
                    'last_asked'    => $ts,
                ];
            }

            $map[$dedup]['count']++;
            $map[$dedup]['variants'][$display] = true;

            // Prefer the fullest phrasing as the label shown to the lecturer.
            if (mb_strlen($display) > mb_strlen($map[$dedup]['question'])) {
                $map[$dedup]['question'] = $display;
            }

            if ($ts > 0) {
                $map[$dedup]['first_asked'] = min($map[$dedup]['first_asked'] ?: $ts, $ts);
                $map[$dedup]['last_asked']  = max($map[$dedup]['last_asked'], $ts);
            }

            if ($userid && !isset($map[$dedup]['studentids'][$userid])) {
                $map[$dedup]['studentids'][$userid] = true;
                $map[$dedup]['student_count']++;
            }
        }

        $result = array_values($map);
        foreach ($result as &$entry) {
            $entry['studentids']   = array_keys($entry['studentids']);
            $entry['variant_count'] = count($entry['variants']);
            unset($entry['variants']);
        }
        unset($entry);

        usort($result, function ($a, $b) {
            // Breadth first: something five students asked once matters more
            // than something one student asked five times.
            if ($b['student_count'] !== $a['student_count']) {
                return $b['student_count'] <=> $a['student_count'];
            }
            return $b['count'] <=> $a['count'];
        });

        return $result;
    }

    /**
     * Remove citation prefixes but keep the author's original casing.
     *
     * @param string $question
     * @return string
     */
    public static function strip_prefixes_only(string $question): string {
        $text = trim($question);
        $config = self::config();
        foreach ($config['query_filter']['strip_prefixes'] as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        return trim($text);
    }
}
