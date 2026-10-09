/* >>> PLACE AT: css-system/assets/js/page-teacher-registrations.js (new)  [batch 8]  (moved from the inline script) */
(function () {
  var box = document.getElementById('reg-search'), list = document.getElementById('reg-list');
  if (!box || !list) return;
  var items = [].slice.call(list.querySelectorAll('.reg-item')), count = document.getElementById('reg-count'), none = document.getElementById('reg-nomatch');
  function apply() {
    var q = box.value.trim().toLowerCase(), shown = 0;
    items.forEach(function (it) {
      var hit = !q || it.getAttribute('data-search').indexOf(q) !== -1;
      it.hidden = !hit;
      if (hit) shown++;
    });
    count.textContent = items.length ? (q ? shown + ' of ' + items.length + ' teachers' : items.length + ' teachers') : '';
    none.hidden = shown !== 0 || !items.length;
  }
  box.addEventListener('input', apply);
  apply();
})();