/* >>> PLACE AT: css-system/assets/js/page-calendar-admin.js (new)  [batch 8]
 * calendar.php (admin/registrar): clicking a day fills in the event's start date.
 * The form carries data-editing="1" when an existing event is being edited (then nothing is overwritten).
 */
(function () {
  var root = document.getElementById('cal-root'), s = document.getElementById('f-start'), en = document.getElementById('f-end'), form = document.getElementById('event-form');
  if (!root || !s || !en || !form || form.getAttribute('data-editing') === '1') return;
  root.addEventListener('cal:select', function (e) { s.value = e.detail.date; en.value = ''; });
})();