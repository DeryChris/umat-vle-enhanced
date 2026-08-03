<?php

namespace local_umat_ai\analytics;

defined('MOODLE_INTERNAL') || die();

class evidence_formatter {

    /**
     * One-line summary of a risk result.
     *
     * The keys read here must match what student_risk_calculator::compute()
     * actually returns — risk_level / risk_score, and factors keyed by name
     * with a 'detail' string. The previous version looked for level / score /
     * factor['name'] / factor['display_value'], none of which exist, so it
     * returned "UNKNOWN (0.0%) — no notable risk factors" for every student.
     *
     * @param array $risk_result Output of student_risk_calculator::compute().
     * @return string
     */
    public static function format_summary(array $risk_result): string {
        // Prefer the calculator's own plain-language interpretation.
        if (!empty($risk_result['summary'])) {
            return $risk_result['summary'];
        }

        $level   = $risk_result['risk_level'] ?? 'unknown';
        $score   = $risk_result['risk_score'] ?? 0;
        $factors = $risk_result['factors'] ?? [];

        $parts = [];
        foreach (['inactivity', 'missed_assessments', 'quiz_performance'] as $name) {
            if (isset($factors[$name]['detail'])) {
                $parts[] = $factors[$name]['detail'];
            }
        }

        $detail = !empty($parts) ? implode('; ', $parts) : 'no notable risk factors';

        return strtoupper($level) . ' (' . number_format((float) $score, 1) . ') — ' . $detail;
    }

    public static function format_evidence_list(array $factors): array {
        $lines = [];
        foreach ($factors as $factor) {
            $label = $factor['name'] ?? 'Unknown';
            $display = $factor['display_value'] ?? ($factor['value'] ?? '');
            $weight = $factor['weight'] ?? null;

            $label = str_replace('_', ' ', $label);
            $label = ucwords($label);

            $line = $label . ': ' . $display;
            if ($weight !== null) {
                $line .= ' (' . number_format($weight) . ' pts max)';
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public static function format_trends(array $trends): string {
        if (empty($trends)) {
            return 'No trend data available.';
        }

        $parts = [];
        foreach ($trends as $trend) {
            $name = str_replace('_', ' ', $trend['name'] ?? 'Unknown');
            $name = ucwords($name);

            $direction = $trend['direction'] ?? 'stable';
            $change = $trend['change'] ?? 0;

            $sign = '';
            if ($direction === 'declining') {
                $sign = '−';
            } else if ($direction === 'improving') {
                $sign = '+';
            }

            $parts[] = $name . ' ' . $direction . ' (' . $sign . abs($change) . ')';
        }

        return 'Trends: ' . implode(', ', $parts);
    }

    public static function format_recommendation(array $recommendation): string {
        $priority = $recommendation['priority'] ?? 'info';
        $count = $recommendation['student_count'] ?? $recommendation['count'] ?? null;
        $action = $recommendation['action'] ?? $recommendation['message'] ?? '';
        $reason = $recommendation['reason'] ?? '';

        $tag = strtoupper($priority);

        $line = '[' . $tag . ']';
        if ($count !== null) {
            $line .= ' Reach out to ' . $count . ' student' . ($count != 1 ? 's' : '') . ' — ';
        } else {
            $line .= ' ';
        }

        $line .= $action;
        if (!empty($reason)) {
            $line .= ' and ' . $reason;
        }

        return $line;
    }

    public static function format_briefing(array $recommendations): string {
        $lines = [];
        foreach ($recommendations as $rec) {
            $lines[] = self::format_recommendation($rec);
        }
        return implode("\n", $lines);
    }

    public static function risk_level_icon(string $level): string {
        $icons = [
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
        ];
        return $icons[strtolower($level)] ?? '⚪';
    }
}
