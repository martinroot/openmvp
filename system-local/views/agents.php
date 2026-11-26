<?php
$agents = all("SELECT
    p.agent_code, p.agent_name, p.server_ip, p.model, p.agent_chat_id,
    COUNT(DISTINCT p.id) AS nodes,
    COALESCE(SUM(p.cost_total),0) AS cost,
    COALESCE(SUM(p.tok_in),0) AS t_in,
    COALESCE(SUM(p.tok_cache),0) AS t_cache,
    COALESCE(SUM(p.tok_out),0) AS t_out,
    COALESCE(SUM(p.runs_count),0) AS runs,
    COALESCE(SUM(p.duration_ms),0) AS dur,
    MAX(p.last_run_at) AS last_run
  FROM projects p
  WHERE p.agent_code IS NOT NULL AND p.agent_code <> ''
  GROUP BY p.agent_code, p.agent_name, p.server_ip, p.model, p.agent_chat_id
  ORDER BY cost DESC");

$perRun = all("SELECT agent_code, server, model, ts, tok_in, tok_cache, tok_out, cost_usd, duration_ms, job_id
   FROM agent_runs ORDER BY id DESC LIMIT 40");
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Агенты</h1>
    <div class="page-sub">Привязки к проектам, расход токенов и времени</div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">Сводка по агентам</div>
  <div class="card-body p-0">
    <?php if (!$agents): ?>
      <div class="empty-state"><i class="bi bi-cpu"></i>Агенты не привязаны к узлам</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr>
        <th>Агент</th><th>Сервер</th><th>Модель</th>
        <th class="text-end">Узлов</th><th class="text-end">in / cache / out</th>
        <th class="text-end">Запуски</th><th class="text-end">Время</th><th class="text-end">Расход</th>
      </tr></thead>
      <tbody>
      <?php foreach ($agents as $a): ?>
        <tr>
          <td><b><?= h($a['agent_code']) ?></b><div class="tree-meta"><?= h($a['agent_name'] ?: '') ?></div></td>
          <td class="mono"><?= h($a['server_ip'] ?: '—') ?></td>
          <td class="small"><?= h($a['model'] ?: '—') ?></td>
          <td class="text-end"><?= (int)$a['nodes'] ?></td>
          <td class="text-end mono"><?= format_tokens((int)$a['t_in']) ?>/<?= format_tokens((int)$a['t_cache']) ?>/<?= format_tokens((int)$a['t_out']) ?></td>
          <td class="text-end"><?= (int)$a['runs'] ?></td>
          <td class="text-end"><?= format_dur((int)$a['dur']) ?></td>
          <td class="text-end"><b><?= format_usd((float)$a['cost']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">Последние запуски</div>
  <div class="card-body p-0">
    <?php if (!$perRun): ?>
      <div class="empty-state py-4"><i class="bi bi-lightning"></i>Запусков пока нет — метрики приходят через ingest API</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Время</th><th>Агент</th><th>Модель</th><th class="text-end">in</th><th class="text-end">cache</th><th class="text-end">out</th><th class="text-end">$</th><th class="text-end">мс</th></tr></thead>
      <tbody>
      <?php foreach ($perRun as $r): ?>
        <tr>
          <td class="text-nowrap"><?= h(dt($r['ts'], 'd.m H:i:s')) ?></td>
          <td><b><?= h($r['agent_code']) ?></b><div class="tree-meta"><?= h($r['server'] ?: '') ?></div></td>
          <td class="small"><?= h($r['model'] ?: '—') ?></td>
          <td class="text-end mono"><?= format_tokens((int)$r['tok_in']) ?></td>
          <td class="text-end mono"><?= format_tokens((int)$r['tok_cache']) ?></td>
          <td class="text-end mono"><?= format_tokens((int)$r['tok_out']) ?></td>
          <td class="text-end"><?= format_usd((float)$r['cost_usd']) ?></td>
          <td class="text-end"><?= (int)$r['duration_ms'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
