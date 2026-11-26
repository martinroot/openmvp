<?php
declare(strict_types=1);
/**
 * Раздача плагина учёта вызовов агентам.
 *   GET /api/usage_plugin.php            — код плагина (текст)
 *   GET /api/usage_plugin.php?f=yaml    — манифест плагина
 */
header('Content-Type: text/plain; charset=utf-8');

$root = getenv('SYSTEM_ROOT') ?: '/root/system';
$which = (strtolower((string)($_GET['f'] ?? '')) === 'yaml')
    ? 'tracker_usage_plugin.yaml'
    : 'tracker_usage_plugin.py';
$path = $root . '/dist/' . $which;
if (!is_file($path)) {
    http_response_code(404);
    echo "нет файла: {$which}\n";
    exit;
}
echo file_get_contents($path);
