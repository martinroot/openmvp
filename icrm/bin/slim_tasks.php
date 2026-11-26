<?php
declare(strict_types=1);
/**
 * Переписать задачи учёта в одну строку (текст задачи — без списков критериев).
 */
require __DIR__ . '/../lib/core.php';

$keyRow = one("SELECT v FROM settings WHERE k='ingest_key'");
$key = trim((string)($keyRow['v'] ?? ''));
if ($key === '') {
    echo "нет ingest_key\n";
    exit(1);
}

$base = 'http://5.144.179.170:8080';
$map = [
    32 => ['-1003919000688', 'fab-94'],
    33 => ['-1004434028499', 'wintask'],
    34 => ['-1004469933182', 'adult'],
    35 => ['-1004478264489', 'cpa-145'],
];

foreach ($map as $id => [$chat, $code]) {
    $title = "Отправляй учёт вызовов в трекер: POST {$base}/api/ingest.php, "
           . "X-Ingest-Key: {$key}, type=turn, chat_id={$chat}, agent_code={$code} "
           . "(формат: {$base}/api/ingest_doc.php)";
    q("UPDATE projects SET title=?, criteria=NULL WHERE id=?", 'si', [$title, $id]);
    echo "задача {$id}: одна строка (" . strlen($title) . " символов)\n";
}

foreach (all("SELECT id, parent_id, title FROM projects WHERE kind='task' ORDER BY id") as $r) {
    echo "  [{$r['id']}] {$r['title']}\n";
}
