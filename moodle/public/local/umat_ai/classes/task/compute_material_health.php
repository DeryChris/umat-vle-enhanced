<?php
/**
 * Scheduled task: compute material health metrics.
 *
 * Calculates pct_complete, pct_questions, and pct_correct per material
 * and logs results via mtrace(). Data will later be served live by the
 * struggle dashboard.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class compute_material_health extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_compute_material_health', 'local_umat_ai');
    }

    public function execute() {
        global $DB;

        $courses = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {umat_ai_materials}");

        if (empty($courses)) {
            mtrace("compute_material_health: no courses with materials found.");
            return;
        }

        foreach ($courses as $cid) {
            $materials = $DB->get_records('umat_ai_materials', ['courseid' => $cid]);
            if (empty($materials)) {
                continue;
            }

            mtrace("compute_material_health: processing course {$cid} (" . count($materials) . " materials)");

            foreach ($materials as $mat) {
                $mid    = (int)$mat->id;
                $fname  = $mat->filename;
                $escfn  = $DB->sql_like_escape($fname);
                $likefn = '%"' . $escfn . '"%';

                $pctComplete = $DB->get_field_sql(
                    "SELECT AVG(progress_pct) FROM {umat_ai_material_progress} WHERE materialid = :mid",
                    ['mid' => $mid]
                );
                $pctComplete = $pctComplete !== false ? round((float)$pctComplete, 1) : 0.0;

                $chatCount = $DB->count_records_select(
                    'umat_ai_chat_logs',
                    $DB->sql_like('sources', ':pattern') . " AND courseid = :cid AND role = 'student'",
                    ['pattern' => $likefn, 'cid' => $cid]
                );
                $distinctStudents = $DB->get_field_sql(
                    "SELECT COUNT(DISTINCT userid) FROM {umat_ai_chat_logs}
                      WHERE courseid = :cid AND role = 'student'",
                    ['cid' => $cid]
                );
                $pctQuestions = $distinctStudents > 0 ? round(($chatCount / $distinctStudents) * 100, 1) : 0.0;

                $avgRating = $DB->get_field_sql(
                    "SELECT AVG(h.rating)
                       FROM {umat_ai_chat_log_helpfulness} h
                       JOIN {umat_ai_chat_logs} l ON l.id = h.chatlogid
                      WHERE " . $DB->sql_like('l.sources', ':pattern') . "
                        AND l.courseid = :cid AND l.role = 'student'",
                    ['pattern' => $likefn, 'cid' => $cid]
                );
                $pctCorrect = $avgRating !== false ? round((float)$avgRating * 25, 1) : 0.0;

                mtrace("  [{$cid}] {$fname}: pct_complete={$pctComplete}%, pct_questions={$pctQuestions}%, pct_correct={$pctCorrect}%");
            }
        }
    }
}
