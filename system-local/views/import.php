<?php
declare(strict_types=1);
/**
 * Импорт плана: вставить JSON (из экспорта) или выбрать файл.
 * Формат — как у экспорта: {"nodes":[{"id":1,"parent_id":null,"kind":"project",...}]}
 */
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Импорт плана</h1>
    <div class="page-sub">Вставь JSON из «Экспорт» или выбери файл. Идентификаторы сохраняются —
      для агента номер задачи это контракт.</div>
  </div>
  <a class="btn btn-light border" href="?page=tree">К дереву</a>
</div>

<div class="card mb-3">
  <div class="card-header">Файл</div>
  <div class="card-body">
    <input type="file" id="impfile" accept=".json,application/json" class="form-control form-control-sm mb-2">
    <div class="form-text mb-3">Выбранный файл подставится в поле ниже — можно править перед импортом.</div>
    <form method="post" action="?page=import">
      <input type="hidden" name="act" value="import_plan">
      <textarea class="form-control font-monospace" name="payload" rows="14"
                placeholder='{"nodes": [ ... ]}'></textarea>
      <div class="mt-2 d-flex gap-2">
        <button class="btn btn-primary btn-sm" type="submit">Импортировать</button>
        <button class="btn btn-light border btn-sm" type="button" id="btnfmt">Проверить JSON</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('impfile').addEventListener('change', function (e) {
  var f = e.target.files && e.target.files[0];
  if (!f) return;
  var rd = new FileReader();
  rd.onload = function () {
    var ta = document.querySelector('textarea[name=payload]');
    ta.value = rd.result;
    ta.focus();
  };
  rd.readAsText(f);
});
document.getElementById('btnfmt').addEventListener('click', function () {
  var ta = document.querySelector('textarea[name=payload]');
  try {
    var j = JSON.parse(ta.value);
    var nodes = Array.isArray(j) ? j : (j.nodes || []);
    ta.value = JSON.stringify({nodes: nodes}, null, 2);
    alert('JSON разобран, узлов: ' + nodes.length);
  } catch (e) {
    alert('JSON не разбирается: ' + e.message);
  }
});
</script>
