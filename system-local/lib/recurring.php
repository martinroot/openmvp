<?php
declare(strict_types=1);
/**
 * Периодические задачи направления: «раз в N минут спросить агента то-то».
 *
 * Задача не входит в план и не закрывается как обычная: сторож сам отдаёт её
 * по расписанию, а ответ агента (с токенами) закрывает текущую задачу плана.
 */
require_once __DIR__ . '/core.php';

/** Периодические задачи узла (проекта или подпроекта). */
function recurring_list(int $projectId): array
{
    return all("SELECT r.id, r.project_id, r.every_min, r.title, r.last_sent, r.created_at,
                       p.title AS project_title
                FROM recurring r LEFT JOIN projects p ON p.id = r.project_id
                WHERE r.project_id = ? ORDER BY r.id", 'i', [$projectId]) ?: [];
}

/** Периодические задачи направления вместе с подпроектами. */
function recurring_for_scope(array $scopeIds): array
{
    if (!$scopeIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($scopeIds), '?'));
    return all("SELECT id, project_id, every_min, title, last_sent,
                       (last_sent IS NULL OR DATE_ADD(last_sent, INTERVAL every_min MINUTE) <= NOW()) AS due
                FROM recurring WHERE project_id IN ($ph) ORDER BY id",
               str_repeat('i', count($scopeIds)), $scopeIds) ?: [];
}

/** Добавить периодическую задачу. */
function recurring_add(int $projectId, int $everyMin, string $title): int
{
    $everyMin = max(1, $everyMin);
    $title    = trim($title);
    if ($title === '') {
        return 0;
    }
    q("INSERT INTO recurring (project_id, every_min, title, created_at) VALUES (?,?,?,?)",
      'iiss', [$projectId, $everyMin, mb_substr($title, 0, 250), date('Y-m-d H:i:s')]);
    return (int)db()->insert_id;
}

/** Удалить периодическую задачу. */
function recurring_delete(int $id): void
{
    q("DELETE FROM recurring WHERE id=?", 'i', [$id]);
}

/** Отметить, что задача отдана агенту. */
function recurring_mark_sent(int $id): void
{
    q("UPDATE recurring SET last_sent = NOW() WHERE id=?", 'i', [$id]);
}
