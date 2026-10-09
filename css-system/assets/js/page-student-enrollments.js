/* >>> PLACE AT: css-system/assets/js/page-student-enrollments.js (new)  [batch 8]  (moved from the inline script) */
(function () {
  var q = document.getElementById('en-q'), items = [].slice.call(document.querySelectorAll('#en-list .en-item'));
  if (!q) return;
  q.addEventListener('input', function () {
    var v = q.value.trim().toLowerCase();
    items.forEach(function (it) { it.hidden = v !== '' && it.getAttribute('data-q').indexOf(v) === -1; });
  });
})();