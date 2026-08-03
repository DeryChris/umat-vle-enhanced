<?php

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\student_risk_calculator;

final class student_risk_calculator_test extends \advanced_testcase {

    private $course;
    private $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    public function test_compute_returns_structured_result(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);

        $this->assertArrayHasKey('userid', $result);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('evidence', $result);
        $this->assertArrayHasKey('trends', $result);
        $this->assertArrayHasKey('classification', $result);
        $this->assertSame($this->student->id, $result['userid']);
    }

    public function test_risk_score_range(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);
        $this->assertGreaterThanOrEqual(0.0, $result['risk_score']);
        $this->assertLessThanOrEqual(100.0, $result['risk_score']);
    }

    public function test_risk_level_valid(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);
        $this->assertContains($result['risk_level'], ['critical', 'high', 'moderate', 'low']);
    }

    public function test_confidence_range(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);
        $this->assertGreaterThanOrEqual(0.3, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    public function test_compute_batch(): void {
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student2->id, $this->course->id, 'student');

        $results = student_risk_calculator::compute_batch(
            [$this->student->id, $student2->id],
            $this->course->id
        );

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($this->student->id, $results);
        $this->assertArrayHasKey($student2->id, $results);
    }

    public function test_inactive_student_has_higher_risk(): void {
        $oldstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($oldstudent->id, $this->course->id, 'student');

        global $DB;
        $DB->insert_record('local_umat_ai_chat_logs', (object) [
            'userid' => $oldstudent->id,
            'courseid' => $this->course->id,
            'question' => 'What is marketing?',
            'response' => 'Marketing is...',
            'timecreated' => time() - (30 * DAYSECS),
        ]);

        $old_result = student_risk_calculator::compute($oldstudent->id, $this->course->id);
        $new_result = student_risk_calculator::compute($this->student->id, $this->course->id);

        $this->assertGreaterThanOrEqual($new_result['risk_score'], $old_result['risk_score']);
    }

    public function test_evidence_array_structure(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);

        foreach ($result['evidence'] as $evidence) {
            $this->assertArrayHasKey('factor', $evidence);
            $this->assertArrayHasKey('detail', $evidence);
            $this->assertArrayHasKey('points_earned', $evidence);
            $this->assertArrayHasKey('points_max', $evidence);
        }
    }
}
