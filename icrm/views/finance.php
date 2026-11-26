<?php
$dayKey = date('Y-m-d');
$goals = all("SELECT * FROM goals ORDER BY scope='global' DESC, scope='day' DESC, period_key DESC");
$spendDay = spend_since($dayKey . ' 00:00:00');
$spendWeek = spend_since(date('Y-m-d', strtotime('-6 days')) . ' 00:00:00');
$spendMonth = spend_since(date('Y-m-01') . ' 00:00:00');
$spendAll = (float)scalar("SELECT COALESCE(SUM(cost_usd),0) FROM agent_runs");
$byModel = all("SELECT model, COUNT(*) AS n, COALESCE(SUM(cost_usd),0) AS c,
   COALESCE(SUM(tok_in),0) AS t_in, COALESCE(SUM(tok_cache),0) AS t_cache, COALESCE(SUM(tok_out),0) AS t_out
   FROM agent_runs GROUP BY model ORDER BY c DESC");
$byAgent = all("SELECT agent_code, COALESCE(SUM(cost_usd),0) AS c FROM agent_runs GROUP BY agent_code ORDER BY c DESC LIMIT 10");
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Цели и финансы</h1>
    <div class="page-sub">Глобальная цель, цель дня и фактический расход</div>
  </div>
  <a class="btn btn-primary" href="?page=goal_new"><i class="bi bi-plus-lg me-1"></i>Поставить цель</a>
</div>

<div class="row g-3 mb-3">
  <?php
  $cards = [
    ['Цель дня', $goals ? null : '', '', 'bi-calendar-day'],
    ['Расход сегодня', format_usd($spendDay), '', 'bi-sun'],
    ['Расход за 7 дней', format_usd($spendWeek), '', 'bi-calendar-week'],
    ['Расход за месяц', format_usd($spendMonth), '', 'bi-calendar3'],
    ['Расход всего', format_usd($spendAll), '', 'bi-infinity'],
  ];
  ?>
  <?php foreach ($cards as $i => $c): if ($i === 0) continue; ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card h-100"><div class="card-body">
        <div class="kpi-label"><?= h($c[0]) ?></div>
        <div class="kpi-value mt-1" style="font-size:1.35rem"><?= h($c[1]) ?></div>
        <div class="kpi-sub mt-1"><i class="bi <?= h($c[3]) ?> me-1"></i></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header">Цели</div>
  <div class="card-body p-0">
    <?php if (!$goals): ?>
      <div class="empty-state"><i class="bi bi-bullseye"></i>Цели не заданы</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Скоуп</th><th>Период</th><th>Название</th><th class="text-end">Цель</th><th class="text-end">Факт</th><th style="width:26%">Прогресс</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($goals as $g):
        $fact = $g['scope'] === 'day' ? $spendDay : ($g['scope'] === 'global' ? $spendMonth : $spendMonth);
        $pct = (float)$g['target_usd'] > 0 ? min(100, $fact / (float)$g['target_usd'] * 100) : 0;
        $cls = $pct >= 100 ? 'is-ok' : ($pct >= 60 ? 'is-over' : '');
      ?>
        <tr>
          <td><span class="chip"><?= h($g['scope']) ?></span></td>
          <td class="mono"><?= h($g['period_key']) ?></td>
          <td><b><?= h($g['title']) ?></b><div class="tree-meta"><?= h($g['note'] ?: '') ?></div></td>
          <td class="text-end"><b><?= format_usd((float)$g['target_usd']) ?></b></td>
          <td class="text-end"><?= format_usd($fact) ?></td>
          <td>
            <div class="goal-track"><div class="goal-fill <?= $cls ?>" style="width:<?= round($pct,1) ?>%"></div></div>
            <div class="kpi-sub mt-1"><?= round($pct,1) ?>%</div>
          </td>
          <td class="text-end">
            <form method="post" action="?page=finance" onsubmit="return confirm('Удалить цель?')">
              <input type="hidden" name="act" value="del_goal">
              <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
              <button class="btn btn-sm btn-light border"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">Расход по моделям</div>
      <div class="card-body">
        <div id="chartModel" style="min-height:240px"></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">Расход по агентам</div>
      <div class="card-body">
        <div id="chartAgent" style="min-height:240px"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var m = <?= json_encode(array_map(fn($r)=>['m'=>$r['model'] ?: 'n/a','c'=>round((float)$r['c'],4)], $byModel)) ?>;
  var a = <?= json_encode(array_map(fn($r)=>['m'=>$r['agent_code'],'c'=>round((float)$r['c'],4)], $byAgent)) ?>;
  function bar(id, rows, color){
    var el = document.getElementById(id);
    if(!el || typeof ApexCharts==='undefined' || !rows.length) return;
    new ApexCharts(el, {
      type:'bar', height:240,
      series:[{name:'Расход, $', data:rows.map(function(r){return r.c;})}],
      chart:{toolbar:{show:false}, fontFamily:'inherit', foreColor:'#94a3b8'},
      colors:[color],
      plotOptions:{bar:{horizontal:true, borderRadius:5, barHeight:'62%'}},
      grid:{borderColor:'#eef2f7', strokeDashArray:4},
      dataLabels:{enabled:true, formatter:function(v){return '$'+Number(v).toFixed(2);},
        style:{fontSize:'11px', fontWeight:700, colors:['#334155']}},
      xaxis:{categories:rows.map(function(r){return r.m;}), labels:{formatter:function(v){return '$'+Number(v).toFixed(v<1?2:0);}, style:{fontSize:'11px'}}, axisBorder:{show:false}, axisTicks:{show:false}},
      yaxis:{labels:{style:{fontSize:'11px'}}}
    }).render();
  }
  bar('chartModel', m, '#4f46e5');
  bar('chartAgent', a, '#0ea5e9');
})();
</script>
