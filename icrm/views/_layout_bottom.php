      </div>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('click', function(ev){
  var t = ev.target.closest('.tree-toggle');
  if(!t) return;
  var box = t.closest('.tree-node').querySelector(':scope > .child-box');
  if(!box) return;
  var hidden = box.style.display === 'none';
  box.style.display = hidden ? '' : 'none';
  t.textContent = hidden ? '−' : '+';
});
document.addEventListener('click', function(ev){
  var t = ev.target.closest('.tchev[data-toggle]');
  if(!t) return;
  var box = document.getElementById(t.getAttribute('data-toggle'));
  if(!box) return;
  var hidden = box.style.display === 'none';
  box.style.display = hidden ? '' : 'none';
  t.classList.toggle('collapsed', !hidden);
  // Состояние раскрытия держим в URL (?open=id,id) — по умолчанию всё свернуто.
  var id = parseInt(t.getAttribute('data-toggle').replace('kids-',''), 10);
  var url = new URL(location.href);
  var cur = (url.searchParams.get('open') || '').split(',')
      .filter(function(x){ return x !== ''; }).map(Number);
  if(hidden){
    if(cur.indexOf(id) === -1) cur.push(id);
  } else {
    cur = cur.filter(function(x){ return x !== id; });
  }
  if(cur.length) url.searchParams.set('open', cur.join(','));
  else url.searchParams.delete('open');
  history.replaceState(null, '', url.toString());
});
// Раскрытие форм добавления (задача, подпроект, проект).
// Сворачивание ветки (.tchev) обрабатывается выше — здесь только формы.
document.querySelectorAll('[data-toggle]').forEach(function(el){
  if(el.classList.contains('tchev') || el.classList.contains('tree-toggle')) return;
  el.addEventListener('click', function(){
    var box = document.getElementById(el.getAttribute('data-toggle'));
    if(!box) return;
    // начальное состояние задано в CSS, поэтому смотрим вычисленный стиль
    var hidden = getComputedStyle(box).display === 'none';
    box.style.display = hidden ? 'block' : 'none';
    if(hidden){
      var f = box.querySelector('input[name=title]');
      if(f) f.focus();
    }
  });
});
</script>
</body>
</html>
