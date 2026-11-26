<?php
declare(strict_types=1);
/**
 * POST /api/ingest.php
 *   X-Ingest-Key: <key>
 *
 *   type=run   (по умолчанию) {agent_code, server, model, project_ref|chat_id, tok_in,
 *                              tok_cache, tok_out, cost_usd, duration_ms, job_id, error}
 *   type=turn  {agent_code, chat_id|project_ref, task_id?, model, server, turn_id,
 *               calls, tok_in, tok_cache, tok_out, tok_think, cost_usd, duration_ms, ts}
 *   type=call  {agent_code, chat_id|project_ref, task_id?, model, turn_id, call_no,
 *               tok_in, tok_cache, tok_out, tok_think, cost_usd, duration_ms, ts}
 *   type=event {project_ref, kind, level, text, chat_msg_id, meta}
 *
 *   Направление определяется по ID чата (agent_chat_id) или по project_ref.
 *   Задача — по task_id, иначе активная задача направления.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$b = ingest_body();
$type = strtolower(trim((string)($b['type'] ?? 'run')));

switch ($type) {
    case 'call':
        ingest_json(ingest_call($b));
        // no break
    case 'turn':
        ingest_json(ingest_turn($b));
        // no break
    case 'event':
        ingest_json(ingest_event($b));
        // no break
    case 'chat':
        ingest_json(ingest_chat_message($b));
        // no break
    case 'run':
    default:
        ingest_json(ingest_run($b));
}
