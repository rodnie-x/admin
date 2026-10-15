(function () {
  'use strict';
  var form = document.getElementById('profile-form');
  if (!form) return;
  var tabs = [].slice.call(form.querySelectorAll('[role=tab]'));
  var panels = [].slice.call(form.querySelectorAll('[role=tabpanel]'));

  function show(id) {
    tabs.forEach(function (t) {
      var on = t.dataset.tab === id;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
    });
    panels.forEach(function (p) { p.hidden = p.id !== 'panel-' + id; });
    if (history.replaceState) history.replaceState(null, '', '#' + id);
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.dataset.tab); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
      n.focus(); show(n.dataset.tab);
    });
  });

  var errs = (form.dataset.errorTabs || '').split(',').filter(Boolean);
  errs.forEach(function (id) {
    tabs.forEach(function (t) { if (t.dataset.tab === id) t.classList.add('has-error'); });
  });
  var start = errs[0] || (location.hash || '').slice(1);
  if (!tabs.some(function (t) { return t.dataset.tab === start; })) start = tabs[0].dataset.tab;
  show(start);

  var pi = document.getElementById('photo-input'), pv = document.getElementById('photo-preview');
  if (pi && pv) pi.addEventListener('change', function () {
    var f = pi.files[0];
    if (!f) return;
    if (!/^image\//.test(f.type)) { pi.value = ''; return; }
    pv.replaceChildren();
    var im = document.createElement('img');
    im.src = URL.createObjectURL(f); im.alt = 'New profile photo';
    pv.appendChild(im);
  });

  [].forEach.call(form.querySelectorAll('.pf-file input'), function (inp) {
    inp.addEventListener('change', function () {
      inp.parentNode.querySelector('span').textContent = inp.files[0] ? inp.files[0].name : 'Choose file';
    });
  });
})();
