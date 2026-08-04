<?php
/**
 * Handles multipart file upload for course materials (Library tab).
 * Accepts files via FormData, stores in course context, creates material record.
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

    $courseid = required_param('courseid', PARAM_INT);
    if ($courseid <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid course must be selected.']);
        exit;
    }

    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    $context = \context_course::instance($courseid);
    if (!has_capability('local/umat_ai:viewanalytics', $context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to upload materials.']);
        exit;
    }

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file provided']);
        exit;
    }

    $upload = $_FILES['file'];

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
    $now = time();

    // Remove existing file with same name in the same area.
    $existing = $fs->get_file($context->id, 'local_umat_ai', 'materials', 0, '/', $filename);
    if ($existing) {
        $existing->delete();
    }

    // Store file in course context.
    $filerecord = [
        'contextid' => $context->id,
        'component' => 'local_umat_ai',
        'filearea'  => 'materials',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => $filename,
    ];
    $stored = $fs->create_file_from_pathname($filerecord, $upload['tmp_name']);

    // Record in umat_ai_materials with real Moodle file ID.
    $existingMat = $DB->get_record('umat_ai_materials', [
        'courseid' => $courseid,
        'filename' => $filename,
    ]);
    if ($existingMat) {
        $existingMat->fileid      = $stored->get_id();
        $existingMat->is_indexed  = 0;
        $existingMat->timecreated = $now;
        $DB->update_record('umat_ai_materials', $existingMat);
        $materialId = $existingMat->id;
    } else {
        $mat = new stdClass();
        $mat->courseid    = $courseid;
        $mat->fileid      = $stored->get_id();
        $mat->filename    = $filename;
        $mat->is_indexed  = 0;
        $mat->timecreated = $now;
        $materialId = $DB->insert_record('umat_ai_materials', $mat);
    }

    $url = \moodle_url::make_pluginfile_url(
        $context->id,
        'local_umat_ai',
        'materials',
        0,
        '/',
        $filename
    );

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'id'       => (int)$materialId,
        'filename' => $filename,
        'filesize' => (int)$stored->get_filesize(),
        'mimetype' => $stored->get_mimetype() ?? '',
        'fileurl'  => $url->out(false),
    ]);

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('[umat_course_upload] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
