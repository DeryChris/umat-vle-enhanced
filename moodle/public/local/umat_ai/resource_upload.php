<?php
/**
 * Handles multipart file upload for the Resource Bank.
 * Accepts files via FormData, stores in Moodle file API, creates DB record.
 *
 * @package local_umat_ai
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);
ob_start();

try {
    global $DB, $USER;
    require_login(false, false);
    require_sesskey();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }

    // Only users with resource bank access can upload.
    $sysctx = \context_system::instance();
    if (!has_capability('local/umat_ai:manageresources', $sysctx)) {
        // Fallback: check if user has lecturer role anywhere.
        $isLecturer = $DB->record_exists_sql(
            "SELECT 1 FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :uid AND r.shortname IN ('editingteacher','teacher','manager')",
            ['uid' => $USER->id]
        );
        if (!$isLecturer) {
            throw new \moodle_exception('nopermission', 'error', '', 'Upload resource bank files');
        }
    }

    $parentid = optional_param('parentid', 0, PARAM_INT);

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file provided']);
        exit;
    }

    $upload = $_FILES['file'];

    // Validate file (max 500 MB).
    $maxbytes = 500 * 1024 * 1024;
    if ($upload['size'] > $maxbytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'File too large (max 500 MB)']);
        exit;
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Upload error code: ' . $upload['error']]);
        exit;
    }

    $filename = clean_param($upload['name'], PARAM_FILE);
    if (!$filename) {
        $filename = 'uploaded_file';
    }

    $fs = get_file_storage();
    $userctx = \context_user::instance($USER->id);
    $now = time();

    // Detect MIME type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);

    // Create DB record.
    $record = new \stdClass();
    $record->userid      = $USER->id;
    $record->parentid    = $parentid ? $parentid : null;
    $record->name        = $filename;
    $record->filename    = $filename;
    $record->filesize    = $upload['size'];
    $record->mimetype    = $mime;
    $record->isfolder    = 0;
    $record->timecreated = $now;
    $record->timemodified = $now;
    $itemid = $DB->insert_record('umat_resource_items', $record);

    // Store file in Moodle file API.
    $filerecord = [
        'contextid' => $userctx->id,
        'component' => 'local_umat_ai',
        'filearea'  => 'resourcebank',
        'itemid'    => $itemid,
        'filepath'  => '/',
        'filename'  => $filename,
    ];
    $file = $fs->create_file_from_pathname($filerecord, $upload['tmp_name']);

    // Update DB with fileid.
    $record->id = $itemid;
    $record->fileid = $file->get_id();
    $record->filesize = $file->get_filesize();
    $DB->update_record('umat_resource_items', $record);

    $url = \moodle_url::make_pluginfile_url(
        $userctx->id,
        'local_umat_ai',
        'resourcebank',
        $itemid,
        '/',
        $filename
    );

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'id'       => (int)$itemid,
        'filename' => $filename,
        'filesize' => (int)$file->get_filesize(),
        'mimetype' => $file->get_mimetype() ?? '',
        'fileurl'  => $url->out(false),
    ]);

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('[umat_resource_upload] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
