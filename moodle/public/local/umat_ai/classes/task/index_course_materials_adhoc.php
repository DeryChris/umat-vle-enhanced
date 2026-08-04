<?php

namespace local_umat_ai\task;

defined('MOODLE_INTERNAL') || die();

class index_course_materials_adhoc extends \core\task\adhoc_task {

    public function execute(): void {
        $data = $this->get_custom_data();
        $courseid = !empty($data->courseid) ? (int)$data->courseid : 0;
        if (!$courseid) {
            mtrace("  [umat_ai] Adhoc indexing skipped: no courseid provided.");
            return;
        }

        mtrace("  [umat_ai] Adhoc indexing course {$courseid}...");
        $task = new index_course_materials();
        $result = $task->index_course($courseid);
        mtrace("  [umat_ai] Adhoc indexing done for course {$courseid}: {$result['indexed']} indexed, {$result['failed']} failed.");
    }
}
