<?php
declare(strict_types=1);
/**
 * GET /api/diag_script.php?token=<ключ> — отдаёт диагностический скрипт агента.
 *
 * Скрипт собирает факты по конвейеру (память, Chrome/CDP, порты, парсеры/keeper,
 * лента, диск), ничего не меняет. Агент скачивает его и запускает.
 */
require __DIR__ . '/../lib/core.php';

$token = (string)($_GET['token'] ?? '');
$expected = (string)(getenv('SYSTEM_DIAG_TOKEN') ?: '');
if ($expected === '') {
    $row = one("SELECT v FROM settings WHERE k='diag_token'");
    $expected = (string)($row['v'] ?? '');
}
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "no access";
    exit;
}

$path = __DIR__ . '/../dist/diag_collect.py';
if (!is_file($path)) {
    http_response_code(404);
    echo "not found";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="diag_collect.py"');
readfile($path);
