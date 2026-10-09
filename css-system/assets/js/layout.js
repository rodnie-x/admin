/* >>> PLACE AT: css-system/assets/js/layout.js (new)  [batch 8]
 * Sidebar, clock and the small page behaviours that used to be inline onclick/onchange/onsubmit attributes.
 * Pages opt in with data attributes:
 *   data-goto="schedules.php?section_id="  on a <select>: go to that URL + the chosen value
 *   data-autosubmit                         on a <select>/<input>: submit its form when it changes
 *   data-print                              on a button: open the print dialog
 *   data-prompt-password                    on a form: ask for a new password first (needs a .np-field input inside)
 * (data-confirm on forms is handled by app.js.)
 */
(function () {
  'use strict';
  var sb = document.getElementById('sidebar'), b = document.getElementById('burger'), c = document.getElementById('clock');
  if (b) b.addEventListener('click', function (e) { e.stopPropagation(); sb.classList.toggle('open'); });
  document.addEventListener('click', function (e) {
    if (sb && !sb.contains(e.target)) sb.classList.remove('open');
    var p = e.target.closest ? e.target.closest('[data-print]') : null;
    if (p) window.print();
  });
  function tick() { if (c) c.textContent = new Date().toLocaleTimeString('en-US'); }
  tick(); setInterval(tick, 1000);

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.getAttribute) return;
    var g = t.getAttribute('data-goto');
    if (g) { window.location.href = g + encodeURIComponent(t.value); return; }
    if (t.hasAttribute('data-autosubmit') && t.form) t.form.submit();
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-prompt-password')) return;
    var pw = prompt('Enter a new password for this user (min. 8 characters):');
    if (!pw || pw.length < 8) {
      if (pw !== null) alert('Password must be at least 8 characters.');
      e.preventDefault();
      return;
    }
    f.querySelector('.np-field').value = pw;
  });
})();