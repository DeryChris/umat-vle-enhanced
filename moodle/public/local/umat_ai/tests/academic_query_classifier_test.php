<?php
/**
 * Tests for academic question classification.
 *
 * @package    local_umat_ai
 * @covers     \local_umat_ai\analytics\academic_query_classifier
 */

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\academic_query_classifier;

final class academic_query_classifier_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_normalize_basic(): void {
        $this->assertSame('what is b2b', academic_query_classifier::normalize('  What   is  B2B?  '));
        $this->assertSame('hello', academic_query_classifier::normalize('Hello'));
        $this->assertSame('', academic_query_classifier::normalize(''));
    }

    /**
     * The chat client prefixes messages that cite a material. The prefix is not
     * part of the question and must not leak into topic labels.
     */
    public function test_normalize_strips_referencing_prefix(): void {
        $this->assertSame(
            'what is a payment gateway',
            academic_query_classifier::normalize('[Referencing: lecture3.pdf] What is a payment gateway?')
        );
        $this->assertSame(
            'What is a payment gateway?',
            academic_query_classifier::strip_prefixes_only('[Referencing: lecture3.pdf] What is a payment gateway?')
        );
    }

    public function test_classify_greeting(): void {
        $this->assertSame('greeting', academic_query_classifier::classify_intent('hello'));
        $this->assertSame('greeting', academic_query_classifier::classify_intent('Hi'));
        $this->assertSame('greeting', academic_query_classifier::classify_intent('good morning'));
        $this->assertSame('greeting', academic_query_classifier::classify_intent('thank you'));
        $this->assertSame('greeting', academic_query_classifier::classify_intent('how are you'));
        $this->assertSame('greeting', academic_query_classifier::classify_intent('Hiii'));
    }

    public function test_classify_command(): void {
        $this->assertSame('command', academic_query_classifier::classify_intent('quiz me'));
        $this->assertSame('command', academic_query_classifier::classify_intent('give me a quiz'));
        $this->assertSame('command', academic_query_classifier::classify_intent('start a quiz'));
    }

    /**
     * Regression: the old command pattern was anchored only at the start and
     * had no optional-politeness handling, so these phrasings — all named in
     * the brief — were counted as academic questions.
     */
    public function test_classify_command_longer_phrasings(): void {
        $phrasings = [
            'conduct a quiz for me',
            'Conduct a quiz for me please',
            'create a practice quiz',
            'can you generate a mock exam',
            'please make me some revision questions',
            'test me',
        ];
        foreach ($phrasings as $text) {
            $this->assertSame('command', academic_query_classifier::classify_intent($text),
                'Expected "' . $text . '" to be classified as a command');
        }
    }

    /**
     * Regression: with a start-only anchor, "How do I calculate depreciation?"
     * matched the "how are you" greeting alternative and was discarded.
     */
    public function test_greeting_pattern_does_not_swallow_real_questions(): void {
        $this->assertSame('academic',
            academic_query_classifier::classify_intent('How do I calculate depreciation?'));
        $this->assertSame('academic',
            academic_query_classifier::classify_intent('Hello, how does a payment gateway differ from a processor?'));
    }

    public function test_classify_filler(): void {
        $this->assertSame('filler', academic_query_classifier::classify_intent(''));
        $this->assertSame('filler', academic_query_classifier::classify_intent('   '));
        $this->assertSame('filler', academic_query_classifier::classify_intent('lol'));
        $this->assertSame('filler', academic_query_classifier::classify_intent('hmm'));
        $this->assertSame('filler', academic_query_classifier::classify_intent('ok'));
        $this->assertSame('filler', academic_query_classifier::classify_intent('ok?'));
        $this->assertSame('filler', academic_query_classifier::classify_intent('got it'));
    }

    public function test_classify_source_type(): void {
        $this->assertSame('quiz_request',
            academic_query_classifier::classify_intent('anything', 'quiz_generation'));
        // Support tickets are non-academic whatever they say — a login problem
        // must never become a course topic.
        $this->assertSame('non_academic',
            academic_query_classifier::classify_intent('I cannot log in to the portal', 'issue_report'));
        $this->assertSame('non_academic',
            academic_query_classifier::classify_intent('What is a payment gateway?', 'system'));
    }

    public function test_source_from_log_excludes_lecturer_and_nlq_rows(): void {
        $studentrow = (object) ['role' => 'student', 'session_key' => 'chat_42'];
        $lecturerrow = (object) ['role' => 'lecturer', 'session_key' => 'lec_nlq_4'];
        $nlqrow = (object) ['role' => 'student', 'session_key' => 'ins_nlq_4'];

        $this->assertSame('chat', academic_query_classifier::source_from_log($studentrow));
        $this->assertSame('system', academic_query_classifier::source_from_log($lecturerrow));
        $this->assertSame('system', academic_query_classifier::source_from_log($nlqrow));
    }

    public function test_classify_academic(): void {
        $this->assertSame('academic', academic_query_classifier::classify_intent('What is B2B marketing?'));
        $this->assertSame('academic', academic_query_classifier::classify_intent('Explain the concept of supply and demand'));
        $this->assertSame('academic', academic_query_classifier::classify_intent('How does inflation affect GDP?'));
    }

    public function test_is_academic(): void {
        $this->assertTrue(academic_query_classifier::is_academic('What is B2B marketing?'));
        $this->assertFalse(academic_query_classifier::is_academic('hello'));
        $this->assertFalse(academic_query_classifier::is_academic('lol'));
        $this->assertFalse(academic_query_classifier::is_academic('conduct a quiz for me'));
    }

    public function test_filter_academic(): void {
        $logs = [
            (object) ['question' => 'What is B2B marketing?', 'userid' => 1, 'role' => 'student'],
            (object) ['question' => 'hello', 'userid' => 1, 'role' => 'student'],
            (object) ['question' => 'Explain the supply chain process', 'userid' => 2, 'role' => 'student'],
            (object) ['question' => 'lol', 'userid' => 3, 'role' => 'student'],
            (object) ['question' => 'conduct a quiz for me', 'userid' => 2, 'role' => 'student'],
        ];
        $result = academic_query_classifier::filter_academic($logs);
        $this->assertCount(2, $result);
        $this->assertSame('academic', $result[0]->_intent);
    }

    public function test_intent_breakdown_counts_every_category(): void {
        $logs = [
            (object) ['question' => 'What is B2B marketing?', 'userid' => 1, 'role' => 'student'],
            (object) ['question' => 'hello', 'userid' => 1, 'role' => 'student'],
            (object) ['question' => 'hi', 'userid' => 2, 'role' => 'student'],
            (object) ['question' => 'quiz me', 'userid' => 2, 'role' => 'student'],
            (object) ['question' => 'lol', 'userid' => 3, 'role' => 'student'],
        ];
        $counts = academic_query_classifier::intent_breakdown($logs);
        $this->assertSame(1, $counts['academic']);
        $this->assertSame(2, $counts['greeting']);
        $this->assertSame(1, $counts['command']);
        $this->assertSame(1, $counts['filler']);
    }

    public function test_normalize_for_dedup_collapses_phrasings(): void {
        // Stopwords and interrogatives are removed, so different phrasings of
        // the same intent share a key.
        $a = academic_query_classifier::normalize_for_dedup('What is a payment gateway?');
        $b = academic_query_classifier::normalize_for_dedup('How does the payment gateway work?');
        $c = academic_query_classifier::normalize_for_dedup('Explain payment gateways');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_build_question_map_groups_and_ranks_by_breadth(): void {
        $logs = [
            (object) ['question' => 'What is a payment gateway?', 'userid' => 1, 'timecreated' => 100],
            (object) ['question' => 'How does the payment gateway work?', 'userid' => 2, 'timecreated' => 200],
            (object) ['question' => 'Explain payment gateways', 'userid' => 3, 'timecreated' => 300],
            (object) ['question' => 'What is double entry bookkeeping?', 'userid' => 1, 'timecreated' => 400],
            (object) ['question' => 'What is double entry bookkeeping?', 'userid' => 1, 'timecreated' => 500],
            (object) ['question' => 'What is double entry bookkeeping?', 'userid' => 1, 'timecreated' => 600],
            (object) ['question' => 'What is double entry bookkeeping?', 'userid' => 1, 'timecreated' => 700],
        ];
        $map = academic_query_classifier::build_question_map($logs);

        // Three students beat one student asking four times.
        $this->assertSame(3, $map[0]['student_count']);
        $this->assertSame(3, $map[0]['count']);
        $this->assertCount(3, $map[0]['studentids']);

        // The label is the fullest phrasing, not the alphabetised dedup key.
        $this->assertStringContainsString('payment gateway', strtolower($map[0]['question']));

        $this->assertSame(1, $map[1]['student_count']);
        $this->assertSame(4, $map[1]['count']);
    }
}
