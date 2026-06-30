<?php
define('AJAX_SCRIPT', true);
require_once __DIR__ . '/config.php';

$PAGE->set_url('/umat_test_issues.php');
require_login();

$method = 'local_umat_ai_get_course_issues';
$args = ['courseid' => 2];

try {
    $response = \core_external\external_api::call_external_function($method, $args, true);
    echo json_encode($response, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}
