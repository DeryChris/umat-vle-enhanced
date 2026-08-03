<?php
/**
 * Tests for the guarded percentage helper.
 *
 * These lock in the fix for the "+957%" defect: a percentage change against a
 * tiny previous period is not reportable, and a previous period of zero is not
 * "+100%".
 *
 * @package    local_umat_ai
 * @covers     \local_umat_ai\analytics\safe_percentage
 */

namespace local_umat_ai;

defined('MOODLE_INTERNAL') || die();

use local_umat_ai\analytics\safe_percentage;

final class safe_percentage_test extends \advanced_testcase {

    public function test_of_returns_null_below_minimum_denominator(): void {
        // 11 out of 1 would be 1100% — refuse to report it.
        $this->assertNull(safe_percentage::of(11, 1));
        $this->assertNull(safe_percentage::of(5, 4));
        $this->assertNull(safe_percentage::of(1, 0));
    }

    public function test_of_returns_value_at_or_above_minimum_denominator(): void {
        $this->assertSame(50.0, safe_percentage::of(5, 10));
        $this->assertSame(20.0, safe_percentage::of(1, 5));
    }

    public function test_of_population_allows_small_real_populations(): void {
        // "1 of 2 students" is a real statement a lecturer can act on.
        $this->assertSame(50.0, safe_percentage::of_population(1, 2));
        $this->assertSame(100.0, safe_percentage::of_population(3, 3));
        $this->assertNull(safe_percentage::of_population(1, 0));
    }

    public function test_change_withholds_percentage_when_baseline_too_small(): void {
        // The exact shape of the reported defect: 11 questions this week
        // against 1 last week must not render as +1000%.
        $result = safe_percentage::change(11, 1);
        $this->assertNull($result['pct_change']);
        $this->assertFalse($result['comparable']);
        $this->assertSame('up', $result['direction']);
        $this->assertSame(11.0, $result['current']);
        $this->assertSame(1.0, $result['previous']);
    }

    public function test_change_with_zero_baseline_is_not_one_hundred_percent(): void {
        $result = safe_percentage::change(8, 0);
        $this->assertNull($result['pct_change']);
        $this->assertFalse($result['comparable']);
        $this->assertSame('up', $result['direction']);
    }

    public function test_change_reports_percentage_with_adequate_baseline(): void {
        $result = safe_percentage::change(15, 10);
        $this->assertTrue($result['comparable']);
        $this->assertEqualsWithDelta(50.0, $result['pct_change'], 0.01);
        $this->assertSame('up', $result['direction']);
    }

    public function test_change_detects_decline(): void {
        $result = safe_percentage::change(6, 12);
        $this->assertTrue($result['comparable']);
        $this->assertEqualsWithDelta(-50.0, $result['pct_change'], 0.01);
        $this->assertSame('down', $result['direction']);
    }

    public function test_format_renders_dash_for_unreportable_values(): void {
        $this->assertSame('—', safe_percentage::format(null));
        $this->assertSame('42%', safe_percentage::format(42.4));
    }

    public function test_clamp_score_bounds_to_zero_hundred(): void {
        $this->assertSame(0.0, safe_percentage::clamp_score(-25));
        $this->assertSame(100.0, safe_percentage::clamp_score(180));
        $this->assertSame(63.5, safe_percentage::clamp_score(63.45));
    }
}
