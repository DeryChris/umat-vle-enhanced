<?php

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\teaching_brief_builder;
use local_umat_ai\analytics\course_health_calculator;
use local_umat_ai\analytics\recommendation_engine;
use local_umat_ai\analytics\evidence_formatter;
use local_umat_ai\analytics\trend_analyser;
use local_umat_ai\analytics\student_risk_calculator;

final class integration_test extends \advanced_testcase {

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

    public function test_student_risk_produces_valid_result(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertContains($result['risk_level'], ['critical', 'high', 'moderate', 'low']);
    }

    public function test_course_health_computes_without_error(): void {
        $result = course_health_calculator::compute($this->course->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_students', $result);
        $this->assertArrayHasKey('risk_distribution', $result);
        $this->assertArrayHasKey('students', $result);
        $this->assertGreaterThanOrEqual(1, $result['total_students']);
    }

    public function test_course_health_summary(): void {
        $result = course_health_calculator::get_summary($this->course->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_students', $result);
        $this->assertArrayHasKey('avg_risk_score', $result);
        $this->assertArrayHasKey('trend_direction', $result);
    }

    public function test_recommendation_engine_generates_array(): void {
        $health = course_health_calculator::compute($this->course->id);
        $recs = recommendation_engine::generate($health);

        $this->assertIsArray($recs);
        foreach ($recs as $rec) {
            $this->assertArrayHasKey('priority', $rec);
            $this->assertArrayHasKey('type', $rec);
            $this->assertArrayHasKey('title', $rec);
            $this->assertArrayHasKey('description', $rec);
            $this->assertArrayHasKey('students_affected', $rec);
        }
    }

    public function test_trend_analyser_compute(): void {
        $result = trend_analyser::compute_trend(10.0, 5.0, 3.0);
        $this->assertSame('improving', $result['direction']);

        $result = trend_analyser::compute_trend(5.0, 10.0, 3.0);
        $this->assertSame('declining', $result['direction']);

        $result = trend_analyser::compute_trend(10.0, 10.0, 3.0);
        $this->assertSame('stable', $result['direction']);
    }

    public function test_evidence_formatter_summary(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);
        $summary = evidence_formatter::format_summary($result);

        $this->assertIsString($summary);
        $this->assertStringContainsString('Risk:', $summary);
    }

    public function test_evidence_formatter_trends(): void {
        $result = student_risk_calculator::compute($this->student->id, $this->course->id);
        $trends = evidence_formatter::format_trends($result['trends']);

        $this->assertIsString($trends);
        $this->assertStringContainsString('Trends:', $trends);
    }

    public function test_teaching_brief_builds_without_error(): void {
        $brief = teaching_brief_builder::build($this->course->id);

        $this->assertIsArray($brief);
        $this->assertArrayHasKey('courseid', $brief);
        $this->assertArrayHasKey('health', $brief);
        $this->assertArrayHasKey('recommendations', $brief);
        $this->assertArrayHasKey('briefing_text', $brief);
    }

    public function test_teaching_brief_for_student(): void {
        $brief = teaching_brief_builder::build_for_student($this->course->id, $this->student->id);

        $this->assertIsArray($brief);
        $this->assertArrayHasKey('risk', $brief);
        $this->assertArrayHasKey('summary', $brief);
        $this->assertArrayHasKey('evidence', $brief);
        $this->assertArrayHasKey('trends_text', $brief);
    }
}
