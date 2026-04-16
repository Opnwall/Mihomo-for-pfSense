<?php
require_once("guiconfig.inc");

$log_file = '/var/log/sub.log';
$max_lines = 200;

header('Content-Type: text/plain; charset=UTF-8');

if (!file_exists($log_file)) {
    echo "[提示] 日志文件不存在：{$log_file}\n";
    exit;
}

$lines = @file($log_file, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    echo "[错误] 无法读取日志文件。\n";
    exit;
}

$tail = array_slice($lines, -$max_lines);
echo implode("\n", $tail);
if (!empty($tail)) {
    echo "\n";
}