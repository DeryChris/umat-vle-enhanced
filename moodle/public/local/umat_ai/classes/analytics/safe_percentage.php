<?php
/**
 * Guarded percentage and trend arithmetic.
 *
 * Every percentage shown on the Lecturer Insights dashboard must pass through
 * this class. It exists because the previous implementation produced values
 * such as "+957%" by dividing by a previous-period count of 1, and hard-coded
 * "+100%" whenever the previous period was zero.
 *
 * The rule is simple: when the denominator is too small for the result to mean
 * anything, return null. Callers render null as an em dash, never as a number.
 *
 * @package    local_umat_ai
 * @subpackage analytics
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class safe_percentage {

    /** Default smallest denominator that yields a publishable percentage. */
    const DEFAULT_MIN_DENOMINATOR = 5;

    /**
     * Percentage of $numerator out of $denominator.
     *
     * @param float|int $numerator
     * @param float|int $denominator
     * @param int       $mindenominator Below this, the ratio is not reportable.
     * @param int       $decimals
     * @return float|null Null when the denominator is absent or too small.
     */
    public static function of($numerator, $denominator, int $mindenominator = self::DEFAULT_MIN_DENOMINATOR, int $decimals = 0): ?float {
        $denominator = (float) $denominator;
        if ($denominator <= 0 || $denominator < $mindenominator) {
            return null;
        }
        return round(((float) $numerator / $denominator) * 100, $decimals);
    }

    /**
     * Percentage of a whole where the denominator is a population count
     * (enrolled students, published resources, sessions held). Populations of
     * one or two are still meaningful to a lecturer — "1 of 2 students" is a
     * real statement — so the guard here is only "greater than zero".
     *
     * @param float|int $numerator
     * @param float|int $denominator
     * @param int       $decimals
     * @return float|null
     */
    public static function of_population($numerator, $denominator, int $decimals = 0): ?float {
        $denominator = (float) $denominator;
        if ($denominator <= 0) {
            return null;
        }
        return round(((float) $numerator / $denominator) * 100, $decimals);
    }

    /**
     * Period-over-period change.
     *
     * Returns the absolute counts alongside the percentage so the UI can always
     * show "12 this week vs 9 last week" even when the percentage is withheld.
     *
     * @param float|int $current
     * @param float|int $previous
     * @param int       $mindenominator Minimum previous-period count required
     *                                  before a percentage is reportable.
     * @return array{
     *     current: float, previous: float, delta: float,
     *     pct_change: float|null, direction: string, comparable: bool
     * }
     */
    public static function change($current, $previous, int $mindenominator = self::DEFAULT_MIN_DENOMINATOR): array {
        $current  = (float) $current;
        $previous = (float) $previous;
        $delta    = $current - $previous;

        // A percentage change against a tiny or absent baseline is noise.
        $comparable = $previous >= $mindenominator;
        $pct = $comparable ? round(($delta / $previous) * 100) : null;

        if ($delta > 0) {
            $direction = 'up';
        } else if ($delta < 0) {
            $direction = 'down';
        } else {
            $direction = 'stable';
        }

        // Without a comparable baseline we can still state a direction from the
        // raw counts, but we must not dress it up as a rate of change.
        return [
            'current'    => $current,
            'previous'   => $previous,
            'delta'      => $delta,
            'pct_change' => $pct,
            'direction'  => $direction,
            'comparable' => $comparable,
        ];
    }

    /**
     * Render a percentage for display, or an em dash when it is not reportable.
     *
     * @param float|null $pct
     * @param string     $placeholder
     * @return string
     */
    public static function format(?float $pct, string $placeholder = '—'): string {
        if ($pct === null) {
            return $placeholder;
        }
        return ((int) round($pct)) . '%';
    }

    /**
     * Clamp any computed score into the 0–100 range.
     *
     * @param float|int $value
     * @param int       $decimals
     * @return float
     */
    public static function clamp_score($value, int $decimals = 1): float {
        return round(max(0.0, min(100.0, (float) $value)), $decimals);
    }
}
