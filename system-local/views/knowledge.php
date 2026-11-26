<?php /* knowledge.php — дерево знаний (UI Pilot, API /tree/api_main.php) */ ?>
<style>
    .tree ul { list-style: none; padding-left: 1.1rem; margin: 0; }
    .tree .node-line { display:flex; align-items:center; gap:.5rem; padding:.25rem .25rem; border-radius:6px; }
    .tree .node-line:hover { background:#f8f9fa; }
    .tree .toggle { cursor:pointer; user-select:none; width:1.2rem; text-align:center; }
    .tree .title { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .muted { opacity:.6; }
    .badge-mono { font-variant-numeric: tabular-nums; }
    .todo-badge { background:#eef1f6; color:#445; border:1px solid #dde2ea; }
    li[data-depth="0"] > .node-line .title { font-weight:700; }
    li[data-depth="1"] > .node-line .title { font-weight:600; }
    li[data-depth="2"] > .node-line .title { font-weight:500; }
    .todo-item .title { cursor:pointer; }
    .prio-fire { cursor:pointer; user-select:none; letter-spacing: .05rem; }
    .prio-fire .dim { opacity:.35; }
    .text-mono { font-variant-numeric: tabular-nums; }
    .copy-hint { position:fixed; bottom:1.2rem; right:1.2rem; z-index:2000; background:#0f172a; color:#fff; padding:.45rem .8rem; border-radius:8px; font-size:.85rem; opacity:0; transition:opacity .25s; pointer-events:none; }
    .focus-highlight { outline: 3px solid #0d6efd; border-radius: 8px; transition: outline-color .8s ease; }
  
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="page-title">Дерево знаний</h1>
    <div class="page-sub">проекты → разделы → задачи · данные в БД pilot</div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">



  <ul class="nav nav-tabs" id="topTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-tree" data-bs-toggle="tab" data-bs-target="#pane-tree" type="button" role="tab">Дерево</button>
    </li>
  </ul>

  <div class="tab-content pt-3">
    <div class="tab-pane fade show active" id="pane-tree" role="tabpanel">
      <div class="d-flex gap-2 mb-3">
        <input id="rootTitle" class="form-control" placeholder="Название корневой темы">
        <button id="addRoot" class="btn btn-primary">+ Добавить в корень</button>
      </div>
      <div id="tree" class="tree"><ul id="root-list"></ul></div>
    </div>  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Узел</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="ed-id">

      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-node"  type="button" role="tab">Описание & ToDo</button></li>
      </ul>

      <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="pane-node" role="tabpanel">
          <div class="mb-3">
            <label class="form-label">Описание ветки</label>
            <textarea id="ed-desc" class="form-control" rows="6" placeholder="Опиши смысл/процесс этой ветки..."></textarea>
          </div>
          <div class="d-flex justify-content-end mb-3">
            <button id="ed-save-desc" class="btn btn-primary">Сохранить описание</button>
          </div>

          <hr>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="m-0">ToDo</h6>
            <div class="small text-muted">Чекбокс — закрыть; клик по названию — редактирование; 🔥 — приоритет</div>
          </div>

          <div class="row g-2 mb-2 align-items-center">
            <div class="col-md-5"><input id="todo-title" class="form-control" placeholder="Новый пункт (коротко)"></div>
            <div class="col-md-4"><input id="todo-desc" class="form-control" placeholder="Описание (опционально)"></div>
            <div class="col-md-1">
              <div id="todo-prio-ui" class="prio-fire" title="Приоритет (🔥 кликни)"></div>
              <input type="hidden" id="todo-prio" value="3">
            </div>
            <div class="col-md-2 d-grid">
              <button id="todo-add" class="btn btn-success">+ Добавить</button>
            </div>
          </div>

          <div id="todo-list" class="list-group"></div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
    </div>
  </div></div>
</div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const $ = (sel)=>document.querySelector(sel);

  function escapeHtml(s){ return (String(s ?? '')).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }
  function renderFires(n){ n=Math.max(1,Math.min(5,Number(n||1))); let s=''; for(let i=1;i<=5;i++){ s+=`<span class="${i<=n?'':'dim'}">🔥</span>`;} return s; }
  async function api(action, payload={}, method='POST'){
    const opts = {method, headers:{'Content-Type':'application/x-www-form-urlencoded'}};
    if(method==='POST') opts.body = new URLSearchParams({...payload, action});
    const url = '/tree/api_main.php' + (method==='GET' ? ('?action='+action+'&'+new URLSearchParams(payload)) : '');
    const r = await fetch(url, opts);
    let j; try { j = await r.json(); } catch(e){ throw new Error('API JSON parse failed'); }
    if(!j.ok) throw new Error(j.error||'API error');
    return j.data;
  }

  function nodeLi(node, depth){
    const li = document.createElement('li');
    li.dataset.id = node.id;
    li.dataset.depth = depth ?? 0;
    const hasChildren = !!node.has_children;
    const openCnt = Number(node.open_cnt||0);
    const doneCnt = Number(node.done_cnt||0);
    const botCnt  = Number(node.bot_cnt||0);

    li.innerHTML = `
      <div class="node-line">
        <span class="toggle ${hasChildren ? '' : 'muted'}">${hasChildren ? '▸' : '•'}</span>
        <span class="title">${escapeHtml(node.title)}</span>
        <span class="badge todo-badge badge-mono me-1" title="ToDo: открыто/выполнено">📝 <span class="todo-open">${openCnt}</span>/<span class="todo-done">${doneCnt}</span></span>
        <div class="btn-group ms-2">
          ${depth === 0 ? `<button class="btn btn-outline-secondary btn-sm act-copy" title="Скопировать эндпоинт направления">🔗</button><button class="btn btn-outline-secondary btn-sm act-dl" title="Скачать JSON направления">⬇️</button>` : ''}
          <button class="btn btn-outline-success btn-sm act-add"    title="Добавить дочерний">+</button>
          <button class="btn btn-outline-primary btn-sm act-edit"   title="Открыть настройки">⋯</button>
          <button class="btn btn-outline-secondary btn-sm act-rename" title="Переименовать">✎</button>
          <button class="btn btn-outline-danger btn-sm act-del"     title="Удалить ветку">×</button>
        </div>
      </div>
      <ul class="children d-none"></ul>
    `;
    return li;
  }

  async function loadChildren(parentId, ul, depth){
    const rows = await api('list_children', {parent_id: parentId ?? ''}, 'GET');
    ul.innerHTML = '';
    rows.forEach(n => ul.appendChild(nodeLi(n, depth)));
  }

  async function initRoot(){
    await loadChildren(null, document.getElementById('root-list'), 0);
  }

  // === Init after DOM ready
  document.addEventListener('DOMContentLoaded', ()=>{
    initRoot().catch(e=>alert(e.message||e));

    const addRootBtn = $('#addRoot');
    if (addRootBtn) addRootBtn.addEventListener('click', async ()=>{
      const title = $('#rootTitle').value.trim();
      if(!title) return;
      try{
        const data = await api('create', {title, parent_id:''});
        $('#root-list').appendChild(nodeLi({...data, has_children:false, open_cnt:0, done_cnt:0, bot_cnt:0}, 0));
        $('#rootTitle').value='';
      }catch(e){ alert(e.message||e); }
    });

    document.addEventListener('click', async (e)=>{
      const li = e.target.closest('li[data-id]');
      const id = li ? parseInt(li.dataset.id,10) : null;
      const depth = li ? (parseInt(li.dataset.depth,10) || 0) : 0;

      if (e.target.classList.contains('toggle') && li){
        const ul = li.querySelector(':scope > ul.children');
        if (ul.classList.contains('d-none')) {
          try { await loadChildren(id, ul, depth+1); } catch(ex){ alert(ex.message||ex); return; }
          ul.classList.remove('d-none'); e.target.textContent = '▾'; e.target.classList.remove('muted');
        } else { ul.classList.add('d-none'); e.target.textContent = '▸'; }
      }

      if (e.target.classList.contains('act-dl') && li){
        const title = li.querySelector('.title').textContent;
        try {
          const res = await fetch(`/tree/api_agent.php?id=${id}`);
          const blob = await res.blob();
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = `${title.replace(/[^\wа-яё .-]/gi,'_')}.json`;
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(()=>URL.revokeObjectURL(a.href), 3000);
        } catch(ex){ alert(ex.message||ex); }
      }

      if (e.target.classList.contains('act-copy') && li){
        const url = `${location.origin}/tree/api_agent.php?id=${id}`;
        try { await navigator.clipboard.writeText(url); } catch(ex) {
          const ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
        }
        let h = document.querySelector('.copy-hint');
        if (!h){ h = document.createElement('div'); h.className = 'copy-hint'; document.body.appendChild(h); }
        h.textContent = `Эндпоинт «${li.querySelector('.title').textContent}» скопирован`;
        h.style.opacity = '1'; setTimeout(()=>{ h.style.opacity = '0'; }, 1600);
      }

      if (e.target.classList.contains('act-add') && li){
        const title = prompt('Название нового узла:'); if(!title) return;
        try{
          const data = await api('create', {title, parent_id:id});
          const ul = li.querySelector(':scope > ul.children');
          if (ul.classList.contains('d-none')) ul.classList.remove('d-none');
          ul.appendChild(nodeLi({...data, has_children:false, open_cnt:0, done_cnt:0, bot_cnt:0}, depth+1));
          const toggle = li.querySelector('.toggle'); toggle.textContent = '▾'; toggle.classList.remove('muted');
        }catch(ex){ alert(ex.message||ex); }
      }

      if (e.target.classList.contains('act-rename') && li){
        const current = li.querySelector('.title').textContent.trim();
        const title = prompt('Новое название:', current);
        if(!title || title===current) return;
        try{ await api('rename', {id, title}); li.querySelector('.title').textContent = title; }catch(ex){ alert(ex.message||ex); }
      }

      if (e.target.classList.contains('act-del') && li){
        if(!confirm('Удалить ветку и всех её потомков?')) return;
        try{ await api('delete', {id}); li.remove(); }catch(ex){ alert(ex.message||ex); }
      }

      if (e.target.classList.contains('act-edit') && li){
        openEditModal(id, li).catch(ex=>alert(ex.message||ex));
      }
    });

    // ===== Modal logic =====
    function drawPrioPicker(n){
      const box = document.getElementById('todo-prio-ui');
      if (box) box.innerHTML = renderFires(n);
    }
    drawPrioPicker(3);

    const prioBox = document.getElementById('todo-prio-ui');
    if (prioBox) prioBox.addEventListener('click', ()=>{
      let n = parseInt(document.getElementById('todo-prio').value||'3',10);
      n = n>=5 ? 1 : (n+1);
      document.getElementById('todo-prio').value = String(n);
      drawPrioPicker(n);
    });

    async function openEditModal(id, li){
      const d = await api('get', {id}, 'GET');
      document.getElementById('ed-id').value = d.id;
      document.getElementById('ed-desc').value = d.desc_md || '';
      await loadTodos(d.id, li);
      const modalEl = document.getElementById('editModal');
      const m = bootstrap.Modal.getOrCreateInstance(modalEl);
      m.show();
    }

    const saveDescBtn = document.getElementById('ed-save-desc');
    if (saveDescBtn) saveDescBtn.addEventListener('click', async ()=>{
      const id   = document.getElementById('ed-id').value;
      const desc = document.getElementById('ed-desc').value;
      try{ await api('save_topic', {id, desc_md: desc}); }catch(ex){ alert(ex.message||ex); }
    });

    async function loadTodos(topicId, liForCounters){
      const rows = await api('list_todos', {topic_id: topicId}, 'GET');
      const box = document.getElementById('todo-list');
      box.innerHTML = '';
      let open=0, done=0;
      rows.forEach(t => {
        if (t.is_done) done++; else open++;
        const item = document.createElement('div');
        item.className = 'list-group-item d-flex align-items-start gap-2 todo-item';
        item.dataset.id = t.id;
        item.innerHTML = `
          <input class="form-check-input mt-1 todo-check" type="checkbox" ${t.is_done?'checked':''} title="Отметить выполненным">
          <div class="flex-grow-1">
            <div class="title ${t.is_done?'text-decoration-line-through text-muted':''}" title="Кликни для редактирования">${escapeHtml(t.title)}</div>
            ${t.desc_md ? `<div class="small text-muted">${escapeHtml(t.desc_md)}</div>` : ''}
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="prio-fire small" data-prio="${t.priority||1}" title="Приоритет (кликни)">${renderFires(t.priority||1)}</span>
            <button class="btn btn-sm btn-outline-secondary todo-edit" title="Редактировать"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger todo-del" title="Удалить"><i class="bi bi-x"></i></button>
          </div>`;
        box.appendChild(item);
      });
      if (liForCounters){
        const openEl = liForCounters.querySelector('.todo-open');
        const doneEl = liForCounters.querySelector('.todo-done');
        if (openEl) openEl.textContent = open;
        if (doneEl) doneEl.textContent = done;
      }
    }

    const todoAddBtn = document.getElementById('todo-add');
    if (todoAddBtn) todoAddBtn.addEventListener('click', async ()=>{
      const topicId = document.getElementById('ed-id').value;
      const title   = document.getElementById('todo-title').value.trim();
      const desc    = document.getElementById('todo-desc').value.trim();
      const prio    = parseInt(document.getElementById('todo-prio').value||'3',10);
      if (!title) return;
      try{
        const data = await api('create_todo', {topic_id: topicId, title, desc_md: desc, priority: prio});
        const li = document.querySelector(`li[data-id="${topicId}"]`);
        await loadTodos(topicId, li);
        document.getElementById('todo-title').value=''; document.getElementById('todo-desc').value='';
      }catch(ex){ alert(ex.message||ex); }
    });

    const todoList = document.getElementById('todo-list');
    if (todoList) todoList.addEventListener('click', async (e)=>{
      const item = e.target.closest('.todo-item');
      if (!item) return;
      const id = parseInt(item.dataset.id,10);
      const topicId = document.getElementById('ed-id').value;
      const li = document.querySelector(`li[data-id="${topicId}"]`);

      try{
        if (e.target.classList.contains('todo-check')){
          const is_done = e.target.checked ? 1 : 0;
          await api('toggle_todo', {id, is_done});
          await loadTodos(topicId, li);
          return;
        }
        if (e.target.closest('.prio-fire')){
          const prioEl = e.target.closest('.prio-fire');
          let p = parseInt(prioEl.getAttribute('data-prio')||'3',10);
          p = p>=5 ? 1 : (p+1);
          await api('update_todo', {id, priority: p});
          await loadTodos(topicId, li);
          return;
        }
        if (e.target.classList.contains('todo-edit') || e.target.classList.contains('title')){
          const current = item.querySelector('.title').textContent.trim();
          const newTitle = prompt('Изменить текст пункта:', current);
          if (!newTitle || newTitle===current) return;
          await api('update_todo', {id, title: newTitle});
          await loadTodos(topicId, li);
          return;
        }
        if (e.target.classList.contains('todo-del')){
          if(!confirm('Удалить пункт?')) return;
          await api('delete_todo', {id});
          await loadTodos(topicId, li);
          return;
        }
      }catch(ex){ alert(ex.message||ex); }
    });
  });
})();

</script>
