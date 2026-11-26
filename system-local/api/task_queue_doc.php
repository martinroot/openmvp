<?php
declare(strict_types=1);
/**
 * Документация постановки задачи агенту — в Markdown, чтобы агент мог
 * действовать сам, без согласования с оператором.
 *
 * GET /api/task_queue_doc.php   → текст в формате Markdown
 * GET /docs/task-queue.md       → тот же файл статикой
 */
$file = __DIR__ . '/../docs/task-queue.md';
$md   = is_readable($file) ? (string)file_get_contents($file) : '';
if (trim($md) === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "документация не найдена\n";
    exit;
}
header('Content-Type: text/markdown; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo $md;
