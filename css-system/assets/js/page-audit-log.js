/* >>> PLACE AT: css-system/assets/js/page-audit-log.js (new)  [batch 8]  (moved from the inline script) */
(function () {
  var q = document.getElementById('al-q'), rows = [].slice.call(document.querySelectorAll('#al-table tr[data-q]'));
  if (!q) return;
  q.addEventListener('input', function () {
    var v = q.value.trim().toLowerCase();
    rows.forEach(function (r) { r.hidden = v !== '' && r.getAttribute('data-q').indexOf(v) === -1; });
  });
  // Keep "Active now" fresh: reload every minute while nobody is typing a search
  if (document.querySelector('.al-active')) {
    setInterval(function () { if (q.value === '' && document.visibilityState === 'visible') location.reload(); }, 60000);
  }
})();