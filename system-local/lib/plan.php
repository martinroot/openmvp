<?php
declare(strict_types=1);
/**
 * Глобальное планирование — «план на сегодня» для направлений.
 *
 * Цепочка: глобальный MLL → план дня → RoadMap (этапы со временем) →
 * задача с целью и сроком → выполнение → разбор результата → след. задача.
 *
 * План дня хранится один на направление: что добиться, метрика, почему,
 * первое действие. Задачи ссылаются на него (projects.plan_id), поэтому
 * видно, что задача выросла из глобального плана, а не случайна.
 */
require_once __DIR__ . '/core.php';

/* ------------------------------------------------------------------ план */

/** План дня направления (или все планы на день). */
function plan_for_day(?int $projectId = null, ?string $day = null): array
{
    $day  = $day ?: date('Y-m-d');
    $args = [$day];
    $types = 's';
    $where = 'day=?';
    if ($projectId !== null) {
        $where .= ' AND project_id=?';
        $args[] = $projectId;
        $types .= 'i';
    }
    return all("SELECT p.*, pr.title proj_title, pr.icon proj_icon, pr.agent_chat_id,
                       (SELECT COUNT(*) FROM projects t WHERE t.parent_id=p.project_id AND t.kind='task' AND t.status<>'done') tasks_open,
                       (SELECT COUNT(*) FROM projects t WHERE t.parent_id=p.project_id AND t.kind='task' AND t.status='done') tasks_done
                FROM global_plans p JOIN projects pr ON pr.id=p.project_id
                WHERE $where ORDER BY p.prio, p.project_id", $types, $args);
}

/** Сохранить/обновить план дня направления. */
function plan_save(int $projectId, array $d): int
{
    $day = trim((string)($d['day'] ?? '')) ?: date('Y-m-d');
    $goal = trim((string)($d['goal'] ?? ''));
    if ($goal === '') {
        return 0;
    }
    q("INSERT INTO global_plans (day, project_id, goal, metric, why, first_move, prio, source, status)
       VALUES (?,?,?,?,?,?,?,'global-mll','active')
       ON DUPLICATE KEY UPDATE goal=VALUES(goal), metric=VALUES(metric), why=VALUES(why),
                               first_move=VALUES(first_move), prio=VALUES(prio), status='active'",
      'sissssi', [$day, $projectId, $goal, (string)($d['metric'] ?? ''), (string)($d['why'] ?? ''),
                   (string)($d['first_move'] ?? ''), max(1, min(3, (int)($d['prio'] ?? 2)))]);
    $row = one("SELECT id FROM global_plans WHERE day=? AND project_id=?", 'si', [$day, $projectId]);
    if ($row) {
        q("INSERT INTO events (project_id, kind, level, title, text)
           VALUES (?, 'system', 'info', ?, ?)",
          'iss', [$projectId, 'план на сегодня обновлён', $goal
                   . (!empty($d['metric']) ? "\nметрика: " . (string)$d['metric'] : '')
                   . (!empty($d['first_move']) ? "\nпервое действие: " . (string)$d['first_move'] : '')]);
    }
    return $row ? (int)$row['id'] : 0;
}

/** Постановка/обновление цели направления (projects.goal). */
function plan_set_direction_goal(int $projectId, string $goal, string $metric = ''): void
{
    q("UPDATE projects SET summary=? WHERE id=?", 'si',
      [$goal !== '' ? $goal : null, $projectId]);
}

/* ------------------------------------------------------- связь с задачами */

/**
 * Привязать задачу к плану дня: копируем цель и срок в поля задачи,
 * чтобы и дерево, и агент видели, чего добиваемся и к какому сроку.
 */
function plan_link_task(int $taskId, int $planId): void
{
    $plan = one("SELECT * FROM global_plans WHERE id=?", 'i', [$planId]);
    if (!$plan) {
        return;
    }
    $deadline = date('d.m.Y', strtotime((string)$plan['day']));
    q("UPDATE projects SET plan_id=?, goal=?, deadline=? WHERE id=?",
      'issi', [$planId, (string)($plan['goal'] ?? ''), $deadline, $taskId]);
}

/** План дня для направления (одна строка) — для контекста и UI. */
function plan_current(int $projectId, ?string $day = null): ?array
{
    $day = $day ?: date('Y-m-d');
    return one("SELECT * FROM global_plans WHERE project_id=? AND day=?", 'is', [$projectId, $day]);
}

/* ------------------------------------------------------ разбор результата */

/**
 * Разбор результата выполненной задачи: что сделано, потрачено, что дальше.
 * Собирается из фактов (лог, токены, стоимость, закрытие) — без LLM,
 * чтобы не жечь деньги на каждой задаче.
 */
function plan_review_task(int $taskId): array
{
    $t = node($taskId);
    if (!$t) {
        return ['ok' => false, 'error' => 'задача не найдена'];
    }
    $pid = (int)($t['parent_id'] ?: $taskId);
    $calls = all("SELECT COUNT(*) n, COALESCE(SUM(tok_in),0) ti, COALESCE(SUM(tok_out),0) tout,
                         COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(duration_ms),0) dur,
                         COALESCE(SUM(tok_think),0) th, COUNT(DISTINCT model) models,
                         SUM(CASE WHEN error<>'' THEN 1 ELSE 0 END) errs
                  FROM llm_calls WHERE task_id=?", 'i', [$taskId]);
    $c = $calls[0] ?? [];
    $errs = (int)(scalar("SELECT COUNT(*) FROM events WHERE task_id=? AND level='error'", 'i', [$taskId]) ?? 0);
    $lastAgent = one("SELECT text, ts FROM events WHERE task_id=? AND kind='agent' ORDER BY id DESC LIMIT 1",
                      'i', [$taskId]);
    $plan = !empty($t['plan_id']) ? one("SELECT * FROM global_plans WHERE id=?", 'i', [(int)$t['plan_id']]) : null;

    $parts = [];
    $parts[] = 'Цель: ' . (!empty($t['goal']) ? (string)$t['goal']
                 : (!empty($plan['goal']) ? (string)$plan['goal'] : 'не задана'));
    $parts[] = sprintf('Вызовов: %d, токены %d in / %d out, размышления %d, $%s, время %.1f с',
        (int)($c['n'] ?? 0), (int)($c['ti'] ?? 0), (int)($c['tout'] ?? 0), (int)($c['th'] ?? 0),
        number_format((float)($c['cost'] ?? 0), 6), (int)($c['dur'] ?? 0) / 1000);
    if ($errs > 0) {
        $parts[] = 'Ошибок в логе задачи: ' . $errs;
    }
    $reply = trim(preg_replace('/\s+/u', ' ', (string)($lastAgent['text'] ?? '')));
    if ($reply !== '') {
        $parts[] = 'Ответ агента: ' . mb_substr($reply, 0, 400);
    }
    $verdict = $errs > 0 ? 'с ошибками' : ((int)($c['n'] ?? 0) > 0 ? 'выполнено' : 'нет вызовов модели');
    // сверка с целью этапа/плана дня: результат без факта достижения цели
    $stageGoal = trim((string)($t['stage_goal'] ?? ''));
    if ($stageGoal !== '') {
        $parts[] = 'Цель этапа: ' . $stageGoal;
    }
    $stageReached = null;
    if ($stageGoal !== '' && $reply !== '') {
        // эвристика: агент проговорил ключевые слова цели — считаем, что цель отражена в результате
        $keys = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($stageGoal)) ?: []);
        $hit  = 0;
        foreach ($keys as $w) {
            if (mb_strlen($w) >= 5 && mb_stripos($reply, $w) !== false) {
                $hit++;
            }
        }
        if ($keys) {
            $stageReached = $hit > 0;
            $parts[] = 'Цель этапа в ответе агента: ' . ($stageReached ? 'отражена' : 'не отражена');
        }
    }
    $parts[] = 'Итог: ' . $verdict;

    $note = implode("\n", $parts);
    q("UPDATE projects SET result_note=? WHERE id=?", 'si', [$note, $taskId]);
    q("INSERT INTO events (project_id, task_id, kind, level, title, text)
       VALUES (?, ?, 'system', ?, ?, ?)",
      'iisss', [$pid, $taskId, $errs > 0 ? 'warn' : 'ok', 'разбор результата', $note]);
    return ['ok' => true, 'note' => $note, 'verdict' => $verdict, 'errors' => $errs,
            'calls' => (int)($c['n'] ?? 0), 'cost' => (float)($c['cost'] ?? 0),
            'stage_reached' => $stageReached];
}
