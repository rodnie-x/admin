(function () {
  'use strict';
  var sb = document.getElementById('sidebar'), b = document.getElementById('burger'), c = document.getElementById('clock');
  if (b) b.addEventListener('click', function (e) { e.stopPropagation(); sb.classList.toggle('open'); });
  document.addEventListener('click', function (e) { if (sb && !sb.contains(e.target)) sb.classList.remove('open'); });
  function tick() {
    if (!c) return;
    c.textContent = new Date().toLocaleTimeString('en-GB', {hour12: false}).slice(0, 8) + ' ' + (new Date().getHours() >= 12 ? 'PM' : 'AM');
  }
  tick(); setInterval(tick, 1000);
})();
