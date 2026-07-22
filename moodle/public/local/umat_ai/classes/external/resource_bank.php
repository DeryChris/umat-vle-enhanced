<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');

class resource_bank extends \external_api {

    /**
     * List items in a folder (or root).
     */
    public static function list_items_parameters() {
        return new \external_function_parameters([
            'parentid' => new \external_value(PARAM_INT, 'Parent folder ID (0 for root)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function list_items($parentid = 0) {
        global $DB, $USER;
        self::validate_parameters(self::list_items_parameters(), ['parentid' => $parentid]);
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        $pid = $parentid ? $parentid : null;
        $items = $DB->get_records('umat_resource_items', [
            'userid' => $USER->id,
            'parentid' => $pid,
        ], 'isfolder DESC, name ASC');

        $result = [];
        foreach ($items as $item) {
            $row = [
                'id'          => (int)$item->id,
                'name'        => $item->name,
                'isfolder'    => (bool)$item->isfolder,
                'filesize'    => (int)$item->filesize,
                'mimetype'    => $item->mimetype ?? '',
                'filename'    => $item->filename ?? '',
                'timecreated' => (int)$item->timecreated,
                'timemodified'=> (int)$item->timemodified,
                'fileurl'     => '',
                'courseid'    => $item->courseid ? (int)$item->courseid : 0,
            ];
            if (!$item->isfolder && $item->fileid) {
                $fs = get_file_storage();
                $file = $fs->get_file_by_id($item->fileid);
                if ($file) {
                    $url = \moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    $row['fileurl'] = $url->out(false);
                    $row['filesize'] = (int)$file->get_filesize();
                    $row['mimetype'] = $file->get_mimetype() ?? '';
                }
            }
            $result[] = $row;
        }
        return ['items' => $result];
    }

    public static function list_items_returns() {
        return new \external_single_structure([
            'items' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'name'        => new \external_value(PARAM_TEXT),
                    'isfolder'    => new \external_value(PARAM_BOOL),
                    'filesize'    => new \external_value(PARAM_INT),
                    'mimetype'    => new \external_value(PARAM_TEXT),
                    'filename'    => new \external_value(PARAM_TEXT),
                    'timecreated' => new \external_value(PARAM_INT),
                    'timemodified'=> new \external_value(PARAM_INT),
                    'fileurl'     => new \external_value(PARAM_URL),
                    'courseid'    => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    /**
     * Create a folder.
     */
    public static function create_folder_parameters() {
        return new \external_function_parameters([
            'parentid' => new \external_value(PARAM_INT, 'Parent folder ID (0 for root)', VALUE_DEFAULT, 0),
            'name'     => new \external_value(PARAM_TEXT, 'Folder name'),
        ]);
    }

    public static function create_folder($parentid, $name) {
        global $DB, $USER;
        self::validate_parameters(self::create_folder_parameters(), [
            'parentid' => $parentid,
            'name'     => $name,
        ]);
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        $name = trim($name);
        if (!$name) {
            throw new \moodle_exception('Folder name is required');
        }

        $now = time();
        $record = (object)[
            'userid'      => $USER->id,
            'parentid'    => $parentid ? $parentid : null,
            'name'        => $name,
            'isfolder'    => 1,
            'timecreated' => $now,
            'timemodified'=> $now,
        ];
        $id = $DB->insert_record('umat_resource_items', $record);

        return ['id' => (int)$id, 'name' => $name];
    }

    public static function create_folder_returns() {
        return new \external_single_structure([
            'id'   => new \external_value(PARAM_INT),
            'name' => new \external_value(PARAM_TEXT),
        ]);
    }

    /**
     * Rename an item (folder or file).
     */
    public static function rename_item_parameters() {
        return new \external_function_parameters([
            'itemid' => new \external_value(PARAM_INT, 'Item ID to rename'),
            'name'   => new \external_value(PARAM_TEXT, 'New name'),
        ]);
    }

    public static function rename_item($itemid, $name) {
        global $DB, $USER;
        self::validate_parameters(self::rename_item_parameters(), [
            'itemid' => $itemid,
            'name'   => $name,
        ]);
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        $name = trim($name);
        if (!$name) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'Name is required');
        }

        $item = $DB->get_record('umat_resource_items', ['id' => $itemid, 'userid' => $USER->id]);
        if (!$item) {
            throw new \moodle_exception('invalidparameter', 'core', '', 'Item not found');
        }

        // If it's a file, rename the file in the file API too.
        if (!$item->isfolder && $item->fileid) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($item->fileid);
            if ($file) {
                $userctx = \context_user::instance($USER->id);
                // Create a new file with the new name.
                $newfilerecord = [
                    'contextid' => $userctx->id,
                    'component' => 'local_umat_ai',
                    'filearea'  => 'resourcebank',
                    'itemid'    => $itemid,
                    'filepath'  => '/',
                    'filename'  => $name,
                ];
                $newfile = $fs->create_file_from_storedfile($newfilerecord, $file);
                // Delete the old file.
                $file->delete();
                $item->fileid = $newfile->get_id();
                $item->filename = $name;
                $item->mimetype = $newfile->get_mimetype();
            }
        }

        $item->name = $name;
        $item->timemodified = time();
        $DB->update_record('umat_resource_items', $item);

        // Build file URL if it's a file.
        $fileurl = '';
        if (!$item->isfolder && $item->fileid) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($item->fileid);
            if ($file) {
                $url = \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                );
                $fileurl = $url->out(false);
            }
        }

        return ['id' => (int)$item->id, 'name' => $item->name, 'fileurl' => $fileurl];
    }

    public static function rename_item_returns() {
        return new \external_single_structure([
            'id'      => new \external_value(PARAM_INT),
            'name'    => new \external_value(PARAM_TEXT),
            'fileurl' => new \external_value(PARAM_URL),
        ]);
    }

    /**
     * Upload a file to the resource bank.
     */
    public static function upload_file_parameters() {
        return new \external_function_parameters([
            'parentid' => new \external_value(PARAM_INT, 'Parent folder ID (0 for root)', VALUE_DEFAULT, 0),
            'filename' => new \external_value(PARAM_FILE, 'Original file name'),
        ]);
    }

    public static function upload_file($parentid, $filename) {
        global $DB, $USER;
        self::validate_parameters(self::upload_file_parameters(), [
            'parentid' => $parentid,
            'filename' => $filename,
        ]);
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        $filename = trim($filename);
        if (!$filename) {
            throw new \moodle_exception('filenotfound', 'local_umat_ai');
        }

        // Grab the uploaded file from the request.
        $filecontent = file_get_contents('php://input');
        if (!$filecontent || strlen($filecontent) === 0) {
            throw new \moodle_exception('filenotfound', 'local_umat_ai');
        }

        $now = time();
        $fs = get_file_storage();
        $userctx = \context_user::instance($USER->id);

        // Create DB record first to get itemid.
        $record = (object)[
            'userid'      => $USER->id,
            'parentid'    => $parentid ? $parentid : null,
            'name'        => $filename,
            'filename'    => $filename,
            'filesize'    => strlen($filecontent),
            'isfolder'    => 0,
            'timecreated' => $now,
            'timemodified'=> $now,
        ];
        $itemid = $DB->insert_record('umat_resource_items', $record);

        // Store file in Moodle file API.
        $fileinfo = [
            'contextid' => $userctx->id,
            'component' => 'local_umat_ai',
            'filearea'  => 'resourcebank',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        $file = $fs->create_file_from_string($fileinfo, $filecontent);

        // Update DB record with fileid.
        $record->id = $itemid;
        $record->fileid = $file->get_id();
        $record->filesize = $file->get_filesize();
        $record->mimetype = $file->get_mimetype();
        $DB->update_record('umat_resource_items', $record);

        $url = \moodle_url::make_pluginfile_url(
            $userctx->id,
            'local_umat_ai',
            'resourcebank',
            $itemid,
            '/',
            $filename
        );

        return [
            'id'       => (int)$itemid,
            'filename' => $filename,
            'filesize' => (int)$file->get_filesize(),
            'mimetype' => $file->get_mimetype() ?? '',
            'fileurl'  => $url->out(false),
        ];
    }

    public static function upload_file_returns() {
        return new \external_single_structure([
            'id'       => new \external_value(PARAM_INT),
            'filename' => new \external_value(PARAM_TEXT),
            'filesize' => new \external_value(PARAM_INT),
            'mimetype' => new \external_value(PARAM_TEXT),
            'fileurl'  => new \external_value(PARAM_URL),
        ]);
    }

    /**
     * Delete items (folders deleted recursively).
     */
    public static function delete_items_parameters() {
        return new \external_function_parameters([
            'itemids' => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Item ID')
            ),
        ]);
    }

    public static function delete_items($itemids) {
        global $DB, $USER;
        self::validate_parameters(self::delete_items_parameters(), ['itemids' => $itemids]);
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        if (empty($itemids)) {
            return ['deleted' => 0];
        }

        $fs = get_file_storage();
        $userctx = \context_user::instance($USER->id);
        $count = 0;

        foreach ($itemids as $itemid) {
            $item = $DB->get_record('umat_resource_items', ['id' => $itemid, 'userid' => $USER->id]);
            if (!$item) continue;

            if ($item->isfolder) {
                // Recursively delete children.
                self::delete_recursive($item->id, $USER->id, $DB, $fs, $userctx, $count);
            }

            // Delete file from Moodle file API.
            if ($item->fileid) {
                $file = $fs->get_file_by_id($item->fileid);
                if ($file) $file->delete();
            }

            $DB->delete_records('umat_resource_items', ['id' => $item->id, 'userid' => $USER->id]);
            $count++;
        }

        return ['deleted' => $count];
    }

    private static function delete_recursive($parentid, $userid, $DB, $fs, $userctx, &$count) {
        $children = $DB->get_records('umat_resource_items', ['parentid' => $parentid, 'userid' => $userid]);
        foreach ($children as $child) {
            if ($child->isfolder) {
                self::delete_recursive($child->id, $userid, $DB, $fs, $userctx, $count);
            }
            if ($child->fileid) {
                $file = $fs->get_file_by_id($child->fileid);
                if ($file) $file->delete();
            }
            $DB->delete_records('umat_resource_items', ['id' => $child->id]);
            $count++;
        }
    }

    public static function delete_items_returns() {
        return new \external_single_structure([
            'deleted' => new \external_value(PARAM_INT, 'Number of items deleted'),
        ]);
    }

    /**
     * Push items to a course — publishes them as course materials.
     */
    public static function push_to_course_parameters() {
        return new \external_function_parameters([
            'itemids'  => new \external_multiple_structure(
                new \external_value(PARAM_INT, 'Item ID')
            ),
            'courseid' => new \external_value(PARAM_INT, 'Target course ID'),
        ]);
    }

    public static function push_to_course($itemids, $courseid) {
        global $DB, $USER;
        self::validate_parameters(self::push_to_course_parameters(), [
            'itemids'  => $itemids,
            'courseid' => $courseid,
        ]);
        $coursectx = \context_course::instance($courseid);
        self::validate_context($coursectx);
        require_capability('local/umat_ai:adminpanel', \context_system::instance());

        if (empty($itemids)) {
            return ['pushed' => 0];
        }

        $fs = get_file_storage();
        $userctx = \context_user::instance($USER->id);
        $now = time();
        $pushed = 0;

        foreach ($itemids as $itemid) {
            $item = $DB->get_record('umat_resource_items', ['id' => $itemid, 'userid' => $USER->id]);
            if (!$item || $item->isfolder) {
                // For folders, push all children recursively.
                if ($item && $item->isfolder) {
                    $pushed += self::push_recursive($item->id, $USER->id, $courseid, $coursectx, $fs, $userctx, $DB, $now);
                }
                continue;
            }
            if (!$item->fileid) continue;

            $source = $fs->get_file_by_id($item->fileid);
            if (!$source) continue;

            // Copy file to course materials area.
            $filerecord = [
                'contextid' => $coursectx->id,
                'component' => 'local_umat_ai',
                'filearea'  => 'materials',
                'itemid'    => $courseid,
                'filepath'  => '/',
                'filename'  => $source->get_filename(),
            ];
            $fs->create_file_from_storedfile($filerecord, $source);

            // Also add to umat_ai_materials for indexing.
            $mat = (object)[
                'courseid'    => $courseid,
                'fileid'      => $source->get_id(),
                'filename'    => $source->get_filename(),
                'is_indexed'  => 0,
                'is_analyzed' => 0,
                'timecreated' => $now,
            ];
            $DB->insert_record('umat_ai_materials', $mat);

            // Update item's courseid to mark as published.
            $item->courseid = $courseid;
            $item->timemodified = $now;
            $DB->update_record('umat_resource_items', $item);

            $pushed++;
        }

        // Trigger course material re-index.
        \core\task\manager::queue_adhoc_task(new \local_umat_ai\task\index_course_materials($courseid));

        return ['pushed' => $pushed];
    }

    private static function push_recursive($parentid, $userid, $courseid, $coursectx, $fs, $userctx, $DB, $now) {
        $count = 0;
        $children = $DB->get_records('umat_resource_items', ['parentid' => $parentid, 'userid' => $userid]);
        foreach ($children as $child) {
            if ($child->isfolder) {
                $count += self::push_recursive($child->id, $userid, $courseid, $coursectx, $fs, $userctx, $DB, $now);
                continue;
            }
            if (!$child->fileid) continue;
            $source = $fs->get_file_by_id($child->fileid);
            if (!$source) continue;

            $filerecord = [
                'contextid' => $coursectx->id,
                'component' => 'local_umat_ai',
                'filearea'  => 'materials',
                'itemid'    => $courseid,
                'filepath'  => '/',
                'filename'  => $source->get_filename(),
            ];
            $fs->create_file_from_storedfile($filerecord, $source);

            $mat = (object)[
                'courseid'    => $courseid,
                'fileid'      => $source->get_id(),
                'filename'    => $source->get_filename(),
                'is_indexed'  => 0,
                'is_analyzed' => 0,
                'timecreated' => $now,
            ];
            $DB->insert_record('umat_ai_materials', $mat);

            $child->courseid = $courseid;
            $child->timemodified = $now;
            $DB->update_record('umat_resource_items', $child);
            $count++;
        }
        return $count;
    }

    public static function push_to_course_returns() {
        return new \external_single_structure([
            'pushed' => new \external_value(PARAM_INT, 'Number of items pushed'),
        ]);
    }

    /**
     * List courses the user teaches (for push target picker).
     */
    public static function list_teaching_courses_parameters() {
        return new \external_function_parameters([]);
    }

    public static function list_teaching_courses() {
        global $DB, $USER;
        self::validate_parameters(self::list_teaching_courses_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);

        $courses = enrol_get_users_courses($USER->id, true, 'id,shortname,fullname');
        $result = [];
        foreach ($courses as $c) {
            $ctx = \context_course::instance($c->id);
            if (has_capability('local/umat_ai:viewanalytics', $ctx)) {
                $result[] = [
                    'id'        => (int)$c->id,
                    'shortname' => $c->shortname,
                    'fullname'  => $c->fullname,
                ];
            }
        }
        return ['courses' => $result];
    }

    public static function list_teaching_courses_returns() {
        return new \external_single_structure([
            'courses' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'        => new \external_value(PARAM_INT),
                    'shortname' => new \external_value(PARAM_TEXT),
                    'fullname'  => new \external_value(PARAM_TEXT),
                ])
            ),
        ]);
    }
}
