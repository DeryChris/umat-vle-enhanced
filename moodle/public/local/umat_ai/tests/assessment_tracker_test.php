<?php

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\assessment_tracker;

final class assessment_tracker_test extends \advanced_testcase {

    private $course;
    private $student;
    private $assignment1;
    private $assignment2;
    private $quiz1;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');

        $this->assignment1 = $generator->create_module('assign', [
            'course' => $this->course->id,
            'name' => 'Assignment 1',
            'duedate' => time() - (7 * DAYSECS),
            'grade' => 100,
        ]);

        $this->assignment2 = $generator->create_module('assign', [
            'course' => $this->course->id,
            'name' => 'Assignment 2',
            'duedate' => time() + (7 * DAYSECS),
            'grade' => 100,
        ]);

        $this->quiz1 = $generator->create_module('quiz', [
            'course' => $this->course->id,
            'name' => 'Quiz 1',
            'timeclose' => time() - (3 * DAYSECS),
            'grade' => 50,
        ]);
    }

    public function test_get_course_assessments_past_due(): void {
        $assessments = assessment_tracker::get_course_assessments($this->course->id, false);
        $this->assertCount(2, $assessments);
        $names = array_column($assessments, 'name');
        $this->assertContains('Assignment 1', $names);
        $this->assertContains('Quiz 1', $names);
        $this->assertNotContains('Assignment 2', $names);
    }

    public function test_get_course_assessments_all(): void {
        $assessments = assessment_tracker::get_course_assessments($this->course->id, true);
        $this->assertCount(3, $assessments);
    }

    public function test_find_missed_no_submission(): void {
        $missed = assessment_tracker::find_missed($this->course->id, $this->student->id);
        $this->assertGreaterThanOrEqual(1, count($missed));
    }

    public function test_count_total_past_due(): void {
        $count = assessment_tracker::count_total_past_due($this->course->id);
        $this->assertSame(2, $count);
    }
}
