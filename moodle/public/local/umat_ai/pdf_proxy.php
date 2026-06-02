<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$url = required_param('url', PARAM_URL);

$parts = parse_url($url);
$path = $parts['path'] ?? '';

if (!preg_match('#/pluginfile\.php/(\d+)/([^/]+)/([^/]+)/([^/]+)/(.*)$#', $path, $m)) {
    http_response_code(400);
    die('Invalid URL');
}

$contextid = (int)$m[1];
$component = $m[2];
$filearea  = $m[3];
$itemid    = $m[4];
$rest      = $m[5];

$lastslash = strrpos($rest, '/');
if ($lastslash === false) {
    $filepath = '/';
    $filename = urldecode($rest);
} else {
    $filepath = '/' . urldecode(substr($rest, 0, $lastslash + 1));
    $filename = urldecode(substr($rest, $lastslash + 1));
}

$context = context::instance_by_id($contextid, IGNORE_MISSING);
if (!$context) {
    http_response_code(403);
    die('Access denied');
}

$coursecontext = $context->get_course_context(false);
require_login($coursecontext ? $coursecontext->instanceid : SITEID);

$fs = get_file_storage();
$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

if (!$file) {
    http_response_code(404);
    die('File not found');
}

$mime = $file->get_mimetype() ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . $file->get_filesize());
header('Cache-Control: public, max-age=3600');
$file->readfile();
