<!doctype html>
<html lang="ru" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SYSTEM · трекер проектов</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.0/dist/apexcharts.min.js"></script>
<style>
:root{
  --sys-bg:#f4f6fb; --sys-surface:#ffffff; --sys-surface-2:#f8fafc;
  --sys-ink:#0f172a; --sys-ink-2:#475569; --sys-ink-3:#94a3b8;
  --sys-line:#e6eaf2; --sys-line-2:#eef2f7;
  --sys-accent:#4f46e5; --sys-accent-2:#6366f1; --sys-accent-soft:#eef2ff;
  --sys-ok:#059669; --sys-ok-soft:#ecfdf5;
  --sys-warn:#b45309; --sys-warn-soft:#fffbeb;
  --sys-danger:#dc2626; --sys-danger-soft:#fef2f2;
  --sys-radius:14px; --sys-radius-sm:10px;
  --sys-shadow:0 1px 2px rgba(15,23,42,.04), 0 8px 24px -12px rgba(15,23,42,.12);
  --sys-shadow-lg:0 2px 4px rgba(15,23,42,.04), 0 20px 48px -20px rgba(15,23,42,.20);
}
*{ -webkit-font-smoothing:antialiased; }
body{
  background:var(--sys-bg); color:var(--sys-ink);
  font-family:"Inter","SF Pro Text",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  font-size:14px; line-height:1.5;
}
h1,h2,h3,h4,h5{ letter-spacing:-.015em; font-weight:700; }
code,.mono{ font-family:"JetBrains Mono",ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.86em; }
a{ text-decoration:none; }

/* ---------- sidebar ---------- */
.sidebar{
  background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
  border-right:1px solid var(--sys-line);
  min-height:100vh; position:sticky; top:0;
}
.brandbox{ padding:.9rem .85rem; }
.brandrow{ display:flex; align-items:center; gap:.65rem; }
.brandmark{
  width:38px; height:38px; border-radius:11px; display:grid; place-items:center;
  background:linear-gradient(135deg,var(--sys-accent),#8b5cf6);
  color:#fff; font-weight:800; font-size:.95rem; letter-spacing:-.03em;
  box-shadow:0 6px 16px -6px rgba(79,70,229,.55);
}
.brandtitle{ font-weight:800; font-size:1.02rem; line-height:1.15; }
.brandsub{ font-size:.7rem; color:var(--sys-ink-3); font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
.nav-section{
  font-size:.68rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
  color:var(--sys-ink-3); padding:1.05rem .6rem .4rem;
}
.sidebar .nav-link{
  color:var(--sys-ink-2); border-radius:var(--sys-radius-sm);
  padding:.5rem .6rem; font-weight:600; font-size:.885rem;
  display:flex; align-items:center; gap:.6rem; transition:background .13s ease,color .13s ease;
}
.sidebar .nav-link i{ font-size:1rem; width:1.15rem; text-align:center; color:var(--sys-ink-3); transition:color .13s ease; }
.sidebar .nav-link:hover{ background:var(--sys-surface-2); color:var(--sys-ink); }
.sidebar .nav-link:hover i{ color:var(--sys-accent); }
.sidebar .nav-link.active{ background:var(--sys-accent-soft); color:var(--sys-accent); }
.sidebar .nav-link.active i{ color:var(--sys-accent); }
.badge-pill-count{ margin-left:auto; background:var(--sys-line-2); color:var(--sys-ink-2); font-size:.7rem; font-weight:700; padding:.12rem .4rem; border-radius:20px; }

/* ---------- cards ---------- */
.card{
  background:var(--sys-surface); border:1px solid var(--sys-line);
  border-radius:var(--sys-radius); box-shadow:var(--sys-shadow);
}
.card-header{ background:transparent; border-bottom:1px solid var(--sys-line-2); font-weight:700; padding:.85rem 1.05rem; }
.card-sub{ font-weight:500; color:var(--sys-ink-3); font-size:.78rem; margin-left:.4rem; }
.card-body{ padding:1.05rem; }
.kpi-label{ font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--sys-ink-3); }
.kpi-value{ font-size:1.65rem; font-weight:800; letter-spacing:-.03em; line-height:1.15; }
.kpi-sub{ font-size:.78rem; color:var(--sys-ink-3); font-weight:600; }

/* ---------- tree ---------- */
.tree-row{
  display:flex; align-items:center; gap:.7rem;
  padding:.62rem .85rem; border-radius:var(--sys-radius-sm);
  background:var(--sys-surface); border:1px solid var(--sys-line-2);
  transition:border-color .13s ease, box-shadow .13s ease, background .13s ease;
}
.tree-row:hover{ border-color:#cdd6e4; box-shadow:0 1px 3px rgba(15,23,42,.05); }
.tree-row.is-done{ background:#fcfdfd; }
.tree-row.is-done .tree-title{ color:var(--sys-ink-3); text-decoration:line-through; }
.node-icon{
  width:32px; height:32px; flex:0 0 32px; border-radius:9px;
  display:grid; place-items:center; font-size:1rem;
  background:var(--sys-surface-2); border:1px solid var(--sys-line-2);
}
.tree-title{ font-weight:650; font-size:.93rem; }
.tree-meta{ font-size:.76rem; color:var(--sys-ink-3); font-weight:600; }
.tree-toggle{
  width:22px; height:22px; border-radius:6px; border:1px solid var(--sys-line);
  background:#fff; color:var(--sys-ink-2); font-size:.7rem; line-height:1;
  display:grid; place-items:center; cursor:pointer; flex:0 0 22px;
}
.tree-toggle:hover{ border-color:var(--sys-accent); color:var(--sys-accent); }
.tree-toggle.leaf{ visibility:hidden; }
.child-box{ margin-left:1.6rem; border-left:1px dashed var(--sys-line); padding-left:.7rem; margin-top:.4rem; }
.chip{
  display:inline-flex; align-items:center; gap:.3rem;
  font-size:.72rem; font-weight:700; padding:.16rem .48rem; border-radius:7px;
  background:var(--sys-surface-2); border:1px solid var(--sys-line-2); color:var(--sys-ink-2);
}
.chip-accent{ background:var(--sys-accent-soft); border-color:#dfe3ff; color:var(--sys-accent); }
.chip-ok{ background:var(--sys-ok-soft); border-color:#c9f0df; color:var(--sys-ok); }
.chip-warn{ background:var(--sys-warn-soft); border-color:#fde9b8; color:var(--sys-warn); }
.chip-danger{ background:var(--sys-danger-soft); border-color:#fbcfcf; color:var(--sys-danger); }

/* ---------- goal bar ---------- */
.goal-track{ height:9px; border-radius:20px; background:var(--sys-line-2); overflow:hidden; }
.goal-fill{ height:100%; border-radius:20px; background:linear-gradient(90deg,var(--sys-accent),#8b5cf6); transition:width .4s ease; }
.goal-fill.is-ok{ background:linear-gradient(90deg,#10b981,#059669); }
.goal-fill.is-over{ background:linear-gradient(90deg,#f59e0b,#d97706); }

/* ---------- table ---------- */
.table thead th{
  font-size:.7rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
  color:var(--sys-ink-3); border-bottom:1px solid var(--sys-line); background:var(--sys-surface-2);
}
.table > :not(caption) > * > *{ padding:.6rem .7rem; border-bottom-color:var(--sys-line-2); }
.table td{ font-size:.88rem; }

/* ---------- timeline ---------- */
.timeline{ position:relative; padding-left:1.4rem; }
.timeline::before{ content:""; position:absolute; left:5px; top:4px; bottom:4px; width:2px; background:var(--sys-line-2); }
.tl-item{ position:relative; padding-bottom:1rem; }
.tl-dot{ position:absolute; left:-1.4rem; top:.28rem; width:12px; height:12px; border-radius:50%; background:#fff; border:2px solid var(--sys-accent); }
.tl-dot.ok{ border-color:var(--sys-ok); }
.tl-dot.warn{ border-color:#f59e0b; }
.tl-dot.err{ border-color:var(--sys-danger); }
.tl-time{ font-size:.72rem; color:var(--sys-ink-3); font-weight:700; }
.tl-text{ font-size:.88rem; white-space:pre-wrap; word-break:break-word; }

/* ---------- misc ---------- */
.btn{ font-weight:650; border-radius:9px; font-size:.87rem; }
.btn-primary{ background:var(--sys-accent); border-color:var(--sys-accent); }
.btn-primary:hover{ background:var(--sys-accent-2); border-color:var(--sys-accent-2); }
.form-control,.form-select{ border-radius:9px; border-color:var(--sys-line); font-size:.89rem; }
.form-control:focus,.form-select:focus{ border-color:var(--sys-accent); box-shadow:0 0 0 .2rem rgba(79,70,229,.12); }
.badge{ font-weight:700; }
.progress{ background:var(--sys-line-2); border-radius:20px; }
.empty-state{ text-align:center; padding:3rem 1rem; color:var(--sys-ink-3); }
.empty-state i{ font-size:2.2rem; opacity:.35; display:block; margin-bottom:.5rem; }
.main-pad{ padding:1.4rem 1.5rem 3rem; }
.page-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; }
.page-title{ font-size:1.3rem; font-weight:800; letter-spacing:-.025em; margin:0; }
.page-sub{ color:var(--sys-ink-3); font-size:.85rem; font-weight:600; }
.stat-inline{ display:flex; align-items:baseline; gap:.35rem; }
.stat-inline b{ font-weight:800; letter-spacing:-.02em; }
.stat-inline span{ font-size:.72rem; color:var(--sys-ink-3); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
@media (max-width:991.98px){ .sidebar{ position:static; min-height:auto; border-right:0; border-bottom:1px solid var(--sys-line); } }

/* ---------- простой трекер задач ---------- */
.trow{
  display:flex; align-items:center; gap:.6rem;
  padding:.5rem .6rem; border-radius:9px;
  transition:background .12s ease;
}
.trow:hover{ background:var(--sys-surface-2); }
.trow.is-done .ttext{ color:var(--sys-ink-3); text-decoration:line-through; }
.tchev{ width:14px; text-align:center; color:var(--sys-ink-3); font-size:.75rem; flex:0 0 14px; }
.tchev-leaf{ visibility:hidden; }
.tcheck{
  width:18px; height:18px; flex:0 0 18px; border-radius:5px;
  border:1.5px solid var(--sys-line); background:#fff; cursor:pointer;
  display:inline-block; position:relative;
}
.tcheck:hover{ border-color:var(--sys-accent); }
.tcheck.on{ background:var(--sys-ok); border-color:var(--sys-ok); }
.tcheck.on::after{
  content:""; position:absolute; left:5px; top:1.5px;
  width:5px; height:10px; border:solid #fff; border-width:0 2px 2px 0;
  transform:rotate(45deg);
}
.tcheck-folder{ border-radius:4px; background:var(--sys-accent-soft); border-color:#dfe3ff; cursor:default; }
.ttext{ font-weight:600; font-size:.93rem; }
.tsum{
  font-size:.74rem; color:var(--sys-ink-3); font-weight:600; white-space:nowrap;
  margin-left:.55rem; padding:.12rem .45rem; border:1px solid var(--sys-line);
  border-radius:999px; background:var(--sys-surface-2);
}
.tstate{
  margin-left:auto; font-size:.72rem; font-weight:700; letter-spacing:.02em;
  color:var(--sys-ink-3); text-transform:lowercase;
}
.tstate-done{ color:var(--sys-ok); }
.tprio{
  font-size:.62rem; font-weight:800; letter-spacing:.02em;
  background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe;
  border-radius:5px; padding:.05rem .28rem; flex:0 0 auto;
}

/* папка (проект) — иконка + жирный заголовок */
.trow-folder{ margin-top:.2rem; }
/* Направление, где все задачи закрыты — красная подсветка. */
.trow.is-spent{ background:#fff5f5; box-shadow:inset 3px 0 0 #dc2626; border-radius:6px; }
.trow.is-spent .ttext{ color:#b91c1c; }
.tred{ font-size:.72rem; font-weight:700; color:#b91c1c; background:#fee2e2;
       border:1px solid #fecaca; border-radius:999px; padding:.12rem .45rem; margin-left:.45rem;
       white-space:nowrap; }
.trow-folder .ttext{ font-weight:750; font-size:.98rem; }
.tficon{ font-size:1.05rem; line-height:1; flex:0 0 auto; }
.chatdot{ flex:0 0 auto; font-size:10px; line-height:1; }
.chatdot.ok{ color:#1ea95c; }
.chatdot.no{ color:#e5484d; }
.tchev{
  border:0; background:transparent; padding:0; cursor:pointer;
  width:16px; flex:0 0 16px; text-align:center;
  color:var(--sys-ink-3); font-size:.8rem; line-height:1;
  transition:transform .12s ease, color .12s ease;
}
.tchev:hover{ color:var(--sys-accent); }
.tchev.collapsed{ transform:rotate(-90deg); }
.tkids{ margin-left:.55rem; }

/* ID задачи в квадратных скобках */
.tid{ font-weight:800; color:var(--sys-ink-3); font-size:.82rem; margin-right:.35rem;
      font-variant-numeric:tabular-nums; }

/* плюс у направления: добавить задачу */
.tplus{
  width:20px; height:20px; flex:0 0 20px; border-radius:6px; cursor:pointer;
  border:1px solid var(--sys-line); background:#fff; color:var(--sys-ink-3);
  font-size:.85rem; line-height:1; font-weight:800; padding:0;
  display:inline-flex; align-items:center; justify-content:center;
}
.tplus:hover{ border-color:var(--sys-accent); color:var(--sys-accent); }

/* крестик у задачи: удалить */
.tdelfrm{ display:inline-flex; margin-left:.35rem; }
.tdel{
  width:18px; height:18px; flex:0 0 18px; border-radius:5px; cursor:pointer;
  border:0; background:transparent; color:var(--sys-ink-3);
  font-size:.72rem; line-height:1; padding:0; opacity:0;
  transition:opacity .12s ease, color .12s ease;
}
.trow:hover .tdel{ opacity:1; }
.tdel:hover{ color:#dc2626; }

/* периодические задачи направления: строки под проектом */
.trow.trec{
  padding-left:2.2rem; font-size:.8rem; color:var(--sys-ink-2);
  border-top:1px dashed var(--sys-line); background:transparent;
}
.trow.trec .ttext{ font-weight:400; }
.trow.trec .tdel{
  width:auto; height:auto; flex:none; opacity:1; padding:0 .45rem;
  border:1px solid var(--sys-line); font-size:.68rem; color:var(--sys-ink-3);
}
.trow.trec .tdel:hover{ color:#dc2626; border-color:#fca5a5; }

/* карандаш: правка узла прямо в дереве */
.tedit{
  width:20px; height:20px; flex:0 0 20px; border-radius:6px;
  display:inline-flex; align-items:center; justify-content:center;
  color:var(--sys-ink-3); font-size:.8rem; line-height:1; text-decoration:none;
  opacity:.55; transition:opacity .12s ease, color .12s ease, background .12s ease;
}
.trow:hover .tedit{ opacity:1; }
.tedit:hover{ color:var(--sys-accent); background:var(--sys-accent-soft); }

/* форма добавления задачи под направлением */
.taddbox{ display:none; margin:.15rem 0 .5rem .55rem; }
.taddform{ max-width:720px; }
.taddrow{ display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; }


</style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <aside class="col-12 col-md-3 col-lg-2 sidebar p-2">
      <div class="brandbox">
        <div class="brandrow">
          <div class="brandmark">SY</div>
          <div class="min-w-0">
            <div class="brandtitle">SYSTEM</div>
            <div class="brandsub">трекер проектов</div>
          </div>
        </div>
      </div>
      <nav class="nav flex-column gap-1 px-1 pb-3">
        <div class="nav-section">Задачи</div>
        <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i class="bi bi-grid-1x2"></i>Дашборд</a>
        <a class="nav-link <?= $page==='tree'?'active':'' ?>" href="?page=tree"><i class="bi bi-list-check"></i>Список задач</a>
        <a class="nav-link <?= $page==='knowledge'?'active':'' ?>" href="?page=knowledge"><i class="bi bi-diagram-2"></i>Дерево знаний</a>
        <a class="nav-link <?= $page==='events'?'active':'' ?>" href="?page=events"><i class="bi bi-activity"></i>Лента</a>
        <a class="nav-link <?= $page==='usage'?'active':'' ?>" href="?page=usage"><i class="bi bi-graph-up"></i>Учёт вызовов</a>
        <a class="nav-link <?= $page==='charts'?'active':'' ?>" href="?page=charts"><i class="bi bi-bar-chart-line"></i>Графики</a>
        <a class="nav-link <?= $page==='gantt'?'active':'' ?>" href="?page=gantt"><i class="bi bi-align-start"></i>Гант</a>
        <a class="nav-link <?= $page==='models'?'active':'' ?>" href="?page=models"><i class="bi bi-cpu"></i>Модели</a>
        <a class="nav-link <?= $page==='gml'?'active':'' ?>" href="?page=gml"><i class="bi bi-globe2"></i>Глобальный MLL</a>

        <div class="nav-section">Сервисы</div>
        <a class="nav-link <?= $page==='svc_domains'?'active':'' ?>" href="?page=svc_domains"><i class="bi bi-diagram-3"></i>Домены ADULT</a>

        <div class="nav-section">Деньги</div>
        <a class="nav-link <?= $page==='money_servers'?'active':'' ?>" href="?page=money_servers"><i class="bi bi-hdd-network"></i>IP / Сервисы</a>
        <a class="nav-link <?= $page==='money_days'?'active':'' ?>" href="?page=money_days"><i class="bi bi-calendar3"></i>По дням</a>
        <a class="nav-link <?= $page==='money_table'?'active':'' ?>" href="?page=money_table"><i class="bi bi-table"></i>Сервисы-таблица</a>

        <div class="nav-section">Прочее</div>
        <a class="nav-link <?= $page==='dispatch'?'active':'' ?>" href="?page=dispatch"><i class="bi bi-send"></i>Диспетчер</a>
        <a class="nav-link <?= $page==='finance'?'active':'' ?>" href="?page=finance"><i class="bi bi-bullseye"></i>Цели</a>
        <a class="nav-link <?= $page==='settings'?'active':'' ?>" href="?page=settings"><i class="bi bi-sliders"></i>Настройки</a>
      </nav>
    </aside>

    <main class="col-12 col-md-9 col-lg-10">
      <div class="main-pad">
        <?php foreach (flash_get() as $f): ?>
          <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show py-2 px-3">
            <?= h($f['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endforeach; ?>
