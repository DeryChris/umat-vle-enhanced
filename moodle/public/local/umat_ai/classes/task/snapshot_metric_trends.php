<?php
/**
 * Scheduled task: snapshot engagement and at-risk metric trends.
 *
 * Computes average engagement_score, at_risk_count, and total_students
 * per course from umat_ai_student_metrics, stores in umat_ai_metric_trends
 * for sparkline charts, and prunes rows older than 30 days.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class snapshot_metric_trends extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_snapshot_metric_trends', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {umat_ai_student_metrics}"
        );

        if (empty($courses)) {
            return;
        }

        $now           = time();
        $thirtydaysago = $now - (30 * DAYSECS);

        foreach ($courses as $cid) {
            try {
                $transaction = $DB->start_delegated_transaction();

                $stats = $DB->get_record_sql(
                    "SELECT COUNT(id) AS total_students,
                            AVG(risk_score) AS avg_risk
                       FROM {umat_ai_student_metrics}
                      WHERE courseid = :cid",
                    ['cid' => $cid]
                );

                if (!$stats || (int)$stats->total_students === 0) {
                    $transaction->allow_commit();
                    continue;
                }

                $totalStudents   = (int)$stats->total_students;
                $avgRisk         = (float)$stats->avg_risk;
                $engagementScore = round(100 - $avgRisk, 1);

                $atRiskCount = $DB->count_records_select(
                    'umat_ai_student_metrics',
                    'courseid = :cid AND risk_score >= :minrisk',
                    ['cid' => $cid, 'minrisk' => 60]
                );

                $DB->insert_record('umat_ai_metric_trends', [
                    'courseid'         => $cid,
                    'engagement_score' => $engagementScore,
                    'at_risk_count'    => (int)$atRiskCount,
                    'total_students'   => $totalStudents,
                    'snapshot_date'    => $now,
                ]);

                $DB->delete_records_select(
                    'umat_ai_metric_trends',
                    'courseid = :cid AND snapshot_date < :cutoff',
                    ['cid' => $cid, 'cutoff' => $thirtydaysago]
                );

                $transaction->allow_commit();
            } catch (\Exception $e) {
                if (isset($transaction)) {
                    $transaction->rollback($e);
                }
                mtrace("snapshot_metric_trends: error processing course {$cid}: " . $e->getMessage());
            }
        }
    }
}
