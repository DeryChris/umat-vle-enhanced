<?php
$t0 = microtime(true);
$mstart = $_SERVER['REQUEST_TIME_FLOAT'] ?? $t0;

require_once 'C:/Users/amkch/Documents/Projects/umat-vle-enhanced/moodle/config.php';

$t1 = microtime(true);

// Print timing info in body
echo "<h1>Timing Diagnostics</h1>\n";
echo "<p>REQUEST_TIME_FLOAT to now: " . (microtime(true) - $mstart) . "s</p>\n";
echo "<p>script start to config.php done: " . ($t1 - $t0) . "s</p>\n";
echo "<p>config.php overhead (not in perfdebug): " . ($t1 - $mstart) . "s</p>\n";

// Now output the rest of the page
echo "<p>Current time: " . date('H:i:s') . "</p>\n";
echo "<p>Session: " . (session_id() ?: 'none') . "</p>\n";

$t2 = microtime(true);

// Trigger perfdebug
global $PERF;
echo "<h2>Performance Info</h2>\n";
echo "<pre>\n";
if (function_exists('get_performance_info')) {
    $info = get_performance_info();
    print_r($info);
}
echo "\nPERF object:\n";
print_r($PERF);
echo "</pre>\n";

$t3 = microtime(true);
echo "<p>Output generation: " . ($t2 - $t1) . "s</p>\n";
echo "<p>Perfinfo generation: " . ($t3 - $t2) . "s</p>\n";
echo "<p>Total PHP time: " . (microtime(true) - $t0) . "s</p>\n";
