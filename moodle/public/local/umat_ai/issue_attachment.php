<?php
/**
 * Sesskey-protected File API upload for one attachment on an issue message.
 *
 * @package local_umat_ai
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

try {
    global $DB, $USER;

    $messageid = required_param('messageid', PARAM_INT);
    $message = $DB->get_record('umat_ai_issue_messages', ['id' => $messageid], '*', MUST_EXIST);
    [$conversation, $role, $context] = \local_umat_ai\issue_manager::require_conversation((int)$message->conversationid);
    if ((int)$message->senderid !== (int)$USER->id || $message->senderrole !== $role) {
        throw new moodle_exception('nopermissions', 'error');
    }
    if (!empty($message->deliveredat) || !empty($message->viewedat)) {
        throw new moodle_exception('The attachment can no longer be changed because the message was delivered.');
    }
    if (empty($_FILES['attachment']) || !is_uploaded_file($_FILES['attachment']['tmp_name'])) {
        throw new invalid_parameter_exception('Select a file to attach.');
    }
    if ((int)$_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        throw new moodle_exception('uploadproblem', 'error');
    }
    if ((int)$_FILES['attachment']['size'] > 10 * 1024 * 1024) {
        throw new invalid_parameter_exception('Attachments must be 10 MB or smaller.');
    }

    $filename = clean_param($_FILES['attachment']['name'], PARAM_FILE);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedextensions = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp3', 'mp4', 'wav',
    ];
    if ($filename === '' || !in_array($extension, $allowedextensions, true)) {
        throw new invalid_parameter_exception('This attachment type is not permitted.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedtype = $finfo->file($_FILES['attachment']['tmp_name']) ?: '';
    $allowedtypes = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'video/mp4',
    ];
    if (!in_array($detectedtype, $allowedtypes, true)) {
        throw new invalid_parameter_exception('The uploaded file content is not a permitted attachment type.');
    }

    $fs = get_file_storage();
    if ($fs->get_area_files($context->id, 'local_umat_ai', 'issue_attachments', $messageid, 'id', false)) {
        throw new moodle_exception('This message already has an attachment.');
    }
    $filerecord = [
        'contextid' => $context->id,
        'component' => 'local_umat_ai',
        'filearea' => 'issue_attachments',
        'itemid' => $messageid,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $USER->id,
        'source' => $filename,
        'author' => fullname($USER),
        'license' => $CFG->sitedefaultlicense,
    ];
    $fs->create_file_from_pathname($filerecord, $_FILES['attachment']['tmp_name']);
    $message->attachmentcount = 1;
    $DB->update_record('umat_ai_issue_messages', $message);

    $attachments = \local_umat_ai\issue_manager::get_attachments($messageid, $context);
    echo json_encode(['success' => true, 'attachment' => reset($attachments)]);
} catch (Throwable $e) {
    debugging('Student Issues attachment upload failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http_response_code(400);
    $message = $e instanceof invalid_parameter_exception || $e instanceof moodle_exception ?
        get_string('error') . ': ' . $e->getMessage() : 'The attachment could not be uploaded.';
    echo json_encode(['success' => false, 'message' => $message]);
}
