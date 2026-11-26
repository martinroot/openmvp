<?php
declare(strict_types=1);
/**
 * Графики — только графики.
 *
 * Фильтры: период отображения (сегодня / неделя) и период среза (1 мин / 5 мин / 1 час).
 * Четыре отдельных графика: задачи, токены, спенд, проекты.
 * Переключение фильтров — без перезагрузки страницы (данные берутся из fmt=json).
 */
require_once __DIR__ . '/../lib/charts.php';

$range = req_str('range');
if ($range !== 'today' && $range !== 'week') {
    $range = 'today';
}
$bucket = req_int('bucket');
if (!in_array($bucket, [1, 5, 60], true)) {
    $bucket = 60;
}
[$from, $to] = charts_period($range);
$data = charts_data($bucket * 60, $from, $to);

/** Ссылки фильтров: каждая группа сохраняет значение другой группы */
$rangeLink = static fn(string $r): string => '?page=charts&range=' . $r . '&bucket=' . $bucket;
$bucketLink = static fn(int $b): string => '?page=charts&range=' . $range . '&bucket=' . $b;

$axisFmt = ($range === 'week' || $bucket < 60) ? 'DD.MM HH:mm' : 'HH:mm';
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Графики</h1>
    <div class="page-sub" id="chartPeriod">
      <?= $range === 'today' ? 'сегодня' : 'неделя' ?> · срез <?= $bucket ?> мин
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <div class="btn-group btn-group-sm" role="group" id="rangeGroup">
      <a class="btn <?= $range === 'today' ? 'btn-primary' : 'btn-light border' ?>"
         data-range="today" href="<?= h($rangeLink('today')) ?>">Сегодня</a>
      <a class="btn <?= $range === 'week' ? 'btn-primary' : 'btn-light border' ?>"
         data-range="week" href="<?= h($rangeLink('week')) ?>">Неделя</a>
    </div>
    <div class="btn-group btn-group-sm" role="group" id="bucketGroup">
      <?php foreach ([1 => '1 мин', 5 => '5 мин', 60 => '1 час'] as $b => $lbl): ?>
        <a class="btn <?= $bucket === $b ? 'btn-primary' : 'btn-light border' ?>"
           data-bucket="<?= $b ?>" href="<?= h($bucketLink($b)) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">Задачи <span class="card-sub" id="subTasks">закрыто за период</span></div>
      <div class="card-body"><div id="chartTasks" style="min-height:300px"></div></div>
    </div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">Токены <span class="card-sub" id="subTokens">in / cache / out / reasoning</span></div>
      <div class="card-body"><div id="chartTokens" style="min-height:300px"></div></div>
    </div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">Спенд <span class="card-sub" id="subMoney">$ за срез</span></div>
      <div class="card-body"><div id="chartMoney" style="min-height:300px"></div></div>
    </div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">Проекты <span class="card-sub" id="subProjects">задачи по направлениям</span></div>
      <div class="card-body"><div id="chartProjects" style="min-height:300px"></div></div>
    </div>
  </div>
</div>

<script>
(function(){
  var AXIS_FMT = <?= json_encode($axisFmt) ?>;
  var charts = {};

  function fmtTok(v){
    var n = Number(v) || 0;
    if (n >= 1e9) return (n/1e9).toFixed(1).replace('.', ',') + 'B';
    if (n >= 1e6) return (n/1e6).toFixed(1).replace('.', ',') + 'M';
    if (n >= 1e3) return (n/1e3).toFixed(1).replace('.', ',') + 'K';
    return String(Math.round(n));
  }
  function fmtMoney(v){ return '$' + Number(v || 0).toFixed(4); }

  function baseOpts(){
    return {
      chart: {type:'area', height:300, toolbar:{show:false}, fontFamily:'inherit',
              foreColor:'#94a3b8', zoom:{enabled:false}, animations:{enabled:false}},
      grid: {borderColor:'#eef2f7', strokeDashArray:4, padding:{left:4, right:8}},
      dataLabels: {enabled:false},
      stroke: {curve:'smooth', width:2},
      xaxis: {type:'datetime', labels:{style:{fontSize:'10px'},
              datetime:{format:AXIS_FMT}}, axisBorder:{show:false}, axisTicks:{show:false}},
      tooltip: {theme:'light', x: {format:'dd.MM HH:mm'}},
      noData: {text: 'Нет данных за период', style:{color:'#94a3b8', fontSize:'12px'}}
    };
  }

  function render(id, series, opts){
    var el = document.getElementById(id);
    if (!el) return;
    if (charts[id]) { charts[id].destroy(); charts[id] = null; }
    var cfg = baseOpts();
    cfg.series = series;
    cfg.colors = opts.colors;
    if (opts.type) cfg.chart.type = opts.type;
    if (opts.stacked) { cfg.chart.stacked = true; cfg.stroke.width = 1; }
    if (opts.yfmt) cfg.yaxis = {labels:{formatter: opts.yfmt, style:{fontSize:'10px'}}};
    if (opts.legend) {
      // В режиме datetime ApexCharts подменяет имена серий на 'series-N' —
      // берём настоящие имена из конфига.
      cfg.legend = {position:'top', horizontalAlign:'right', fontSize:'11px',
                    markers:{size:5}, itemMargin:{horizontal:8},
                    formatter: function(name, o){
                      var ss = (o && o.w && o.w.config && o.w.config.series) || [];
                      var real = ss[o.seriesIndex] && ss[o.seriesIndex].name;
                      return real || name;
                    }};
    }
    if (opts.tooltipFmt) cfg.tooltip.y = {formatter: opts.tooltipFmt};
    charts[id] = new ApexCharts(el, cfg);
    charts[id].render();
  }

  function paint(D){
    var t = D.totals || {};
    render('chartTasks', [{name:'Задач', data:D.series.tasks}], {
      colors:['#4f46e5'], type:'bar',
      yfmt:function(v){ return String(Math.round(v)); },
      tooltipFmt:function(v){ return Math.round(v) + ' задач'; }
    });
    render('chartTokens', [
      {name:'in', data:D.series.tokens.in},
      {name:'cache', data:D.series.tokens.cache},
      {name:'out', data:D.series.tokens.out},
      {name:'reasoning', data:D.series.tokens.think}
    ], {colors:['#4f46e5','#c7d2fe','#0ea5e9','#f59e0b'], stacked:true, legend:true,
         yfmt:fmtTok, tooltipFmt:fmtTok});
    render('chartMoney', [{name:'$', data:D.series.money}], {
      colors:['#0ea5e9'], yfmt:function(v){ return '$'+Number(v).toFixed(v<1?4:2); },
      tooltipFmt:fmtMoney
    });
    render('chartProjects', D.series.projects, {
      colors:['#4f46e5','#0ea5e9','#22c55e','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#ef4444','#64748b'],
      type:'bar', stacked:true, legend:true,
      yfmt:function(v){ return String(Math.round(v)); },
      tooltipFmt:function(v){ return Math.round(v) + ' задач'; }
    });

    var subT = document.getElementById('subTasks');
    var subK = document.getElementById('subTokens');
    var subM = document.getElementById('subMoney');
    var subP = document.getElementById('subProjects');
    if (subT) subT.textContent = 'закрыто ' + (t.tasks || 0) + ' за период';
    if (subK) subK.textContent = 'всего ' + fmtTok(t.tokens || 0) + ' токенов';
    if (subM) subM.textContent = fmtMoney(t.money || 0) + ' за период';
    if (subP) subP.textContent = (t.projects || 0) + ' направлений в работе';
  }

  function setActive(group, attr, value){
    document.querySelectorAll('#' + group + ' .btn').forEach(function(b){
      var on = b.getAttribute(attr) === String(value);
      b.classList.toggle('btn-primary', on);
      b.classList.toggle('btn-light', !on);
      b.classList.toggle('border', !on);
    });
  }

  function currentRange(){
    var a = document.querySelector('#rangeGroup .btn-primary');
    return a ? a.getAttribute('data-range') : 'today';
  }
  function currentBucket(){
    var a = document.querySelector('#bucketGroup .btn-primary');
    return a ? a.getAttribute('data-bucket') : '60';
  }

  // Если страница открыта с логином в URL, относительный fetch наследует креды
  // и браузер его отклоняет — берём абсолютный адрес без учётных данных.
  var API_BASE = location.origin + location.pathname;

  // Ссылки фильтров должны отражать текущее состояние обеих групп.
  function syncLinks(range, bucket){
    document.querySelectorAll('#rangeGroup a').forEach(function(a){
      a.setAttribute('href', '?page=charts&range=' + a.getAttribute('data-range') + '&bucket=' + bucket);
    });
    document.querySelectorAll('#bucketGroup a').forEach(function(a){
      a.setAttribute('href', '?page=charts&range=' + range + '&bucket=' + a.getAttribute('data-bucket'));
    });
  }

  function load(url, push){
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    fetch(API_BASE + url + sep + 'fmt=json',
          {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(function(r){ return r.json(); })
      .then(function(D){
        paint(D);
        if (push) history.replaceState(null, '', push);
      })
      .catch(function(){ location.href = url; });   // fallback — обычная перезагрузка
  }

  document.addEventListener('click', function(ev){
    var a = ev.target.closest('a[data-range], a[data-bucket]');
    if (!a) return;
    ev.preventDefault();
    var url = a.getAttribute('href');
    var range = a.getAttribute('data-range');
    var bucket = a.getAttribute('data-bucket');
    if (range) {
      setActive('rangeGroup', 'data-range', range);
      setActive('bucketGroup', 'data-bucket', bucket || currentBucket());
    }
    if (bucket) {
      setActive('bucketGroup', 'data-bucket', bucket);
      setActive('rangeGroup', 'data-range', range || currentRange());
    }
    var per = document.getElementById('chartPeriod');
    var r = range || currentRange();
    var b = bucket || currentBucket();
    if (per) {
      per.textContent = (r === 'week' ? 'неделя' : 'сегодня') + ' · срез ' + b + ' мин';
    }
    syncLinks(r, b);
    load('?page=charts&range=' + r + '&bucket=' + b, '?page=charts&range=' + r + '&bucket=' + b);
  });

  // Если CDN недоступен — берём локальную копию.
  function start(){
    if (typeof ApexCharts === 'undefined') {
      var s = document.createElement('script');
      s.src = 'dist/apexcharts.min.js';
      s.onload = function(){ paint(<?= json_encode($data) ?>); };
      s.onerror = function(){ /* графиков не будет */ };
      document.body.appendChild(s);
      return;
    }
    paint(<?= json_encode($data) ?>);
  }
  start();
})();
</script>
