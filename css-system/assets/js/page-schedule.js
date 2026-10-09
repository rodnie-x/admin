/* >>> PLACE AT: css-system/assets/js/page-schedules.js (new)  [batch 8]
 * schedules.php: show the selected teacher's declared availability + subjects while building a schedule.
 * The data comes from <script type="application/json" id="teacher-profiles"> (a data block, not executable script).
 */
(function () {
  'use strict';
  var data = document.getElementById('teacher-profiles');
  var select = document.getElementById('teacher-select');
  var box = document.getElementById('teacher-profile-box');
  if (!data || !select || !box) { return; }
  var profiles = {};
  try { profiles = JSON.parse(data.textContent) || {}; } catch (err) { profiles = {}; }

  function line(label, text) {
    var div = document.createElement('div');
    var b = document.createElement('strong');
    b.textContent = label + ' ';
    div.appendChild(b);
    div.appendChild(document.createTextNode(text));
    return div;
  }
  function render() {
    box.replaceChildren();
    var p = profiles[select.value];
    if (!p) { box.hidden = true; return; }
    box.hidden = false;
    var title = document.createElement('div');
    title.className = 'pp-title';
    title.textContent = 'Registered by the teacher (used to check this schedule)';
    box.appendChild(title);
    if (p.availability.length) {
      p.availability.forEach(function (a) { box.appendChild(line(a.day + ':', a.times)); });
    } else {
      box.appendChild(line('Availability:', 'none declared'));
    }
    box.appendChild(line('Subjects:', p.subjects.length ? p.subjects.join(', ') : 'none selected'));
  }
  select.addEventListener('change', render);
  render();
})();