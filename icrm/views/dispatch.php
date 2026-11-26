<?php
$q = all("SELECT d.*, p.title AS ptitle, p.icon AS picon FROM dispatch_queue d
   LEFT JOIN projects p ON p.id = d.project_id ORDER BY d.id DESC LIMIT 200");
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Диспетчер</h1>
    <div class="page-sub">Очередь задач агентам. Отправка — только вручную, оркестрации нет</div>
  </div>
  <a class="btn btn-primary" href="?page=dispatch_new"><i class="bi bi-plus-lg me-1"></i>Поставить задачу</a>
</div>

<div class="card">
  <div class="card-header">Очередь (<?= count($q) ?>)</div>
  <div class="card-body p-0">
    <?php if (!$q): ?>
      <div class="empty-state"><i class="bi bi-send"></i>Очередь пуста</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Создана</th><th>Агент</th><th>Узел</th><th>Текст задачи</th><th>Статус</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($q as $r): ?>
        <tr>
          <td class="text-nowrap"><?= h(dt($r['created_at'], 'd.m H:i')) ?></td>
          <td><b><?= h($r['agent_code'] ?: '—') ?></b></td>
          <td><?php if ($r['project_id']): ?>
            <a class="text-reset small" href="?page=node&id=<?= (int)$r['project_id'] ?>"><?= h($r['picon'] ?: '') ?> <?= h($r['ptitle'] ?: '') ?></a>
          <?php else: ?>—<?php endif; ?></td>
          <td class="small" style="max-width:420px"><?= h(mb_substr((string)$r['payload'], 0, 220)) ?></td>
          <td>
            <span class="chip chip-<?= $r['status']==='sent'?'chip-ok':($r['status']==='failed'?'chip-danger':'') ?>"><?= h($r['status']) ?></span>
            <?php if ($r['err']): ?><div class="tree-meta"><?= h(mb_substr((string)$r['err'],0,80)) ?></div><?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($r['status'] === 'new'): ?>
              <form method="post" action="?page=dispatch">
                <input type="hidden" name="act" value="send">
                <input type="hidden" name="did" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-primary">Отправить</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
