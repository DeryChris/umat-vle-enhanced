<?php
$start = microtime(true);
echo "hello";
$end = microtime(true);
echo "<!-- time: " . ($end - $start) . " -->";
