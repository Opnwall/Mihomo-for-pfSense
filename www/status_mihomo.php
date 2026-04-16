<?php
header('Content-Type: application/json');

$output = [];
$return_var = 0;
exec('/usr/sbin/service mihomo status 2>&1', $output, $return_var);

$status_text = implode("\n", $output);
$is_running = false;

if ($return_var === 0 && preg_match('/running\s+as\s+pid/i', $status_text)) {
    $is_running = true;
}

echo json_encode([
    'status' => $is_running ? 'running' : 'stopped'
]);