/* >>> PLACE AT: css-system/assets/js/idle-logout.js, teacher-registration/assets/js/idle-logout.js and
 *     student-portal/assets/js/idle-logout.js  (the SAME file in all 3 apps)  [batch 14]
 *
 * Signs the person out automatically when they stop using the page, so a forgotten tab on a shared computer
 * does not stay logged in. How it works:
 *  - Counts real activity (mouse, keys, touch, scroll) and shares it between tabs through localStorage.
 *  - Shows a "Still there?" warning a couple of minutes before the end, with a countdown.
 *  - At the end it POSTs to the app's logout page (with the CSRF token), which also records the sign-out.
 *  - While the person is active it pings the server now and then (keep-alive) so the SERVER session does not
 *    expire under someone who is busy reading or typing a long form. A 401 from the server means the session
 *    is already over, so the page goes straight to the log in page.
 * Settings come from data- attributes on the <script> tag (set in the footer), so no inline script is needed.
 */
(function () {
  'use strict';
  var tag = document.querySelector('script[data-idle]');
  if (!tag) { return; }

  var IDLE = (parseInt(tag.getAttribute('data-idle'), 10) || 1500) * 1000;      // ms without activity before sign-out
  var WARN = (parseInt(tag.getAttribute('data-warn'), 10) || 120) * 1000;       // ms of warning before that
  if (WARN >= IDLE) { WARN = Math.floor(IDLE / 2); }
  var PING_URL = tag.getAttribute('data-ping') || '';
  var LOGOUT_URL = tag.getAttribute('data-logout') || 'logout.php';
  var LOGIN_URL = tag.getAttribute('data-login') || 'login.php';
  var CSRF = tag.getAttribute('data-csrf') || '';
  var KEY = 'idle-last:' + (tag.getAttribute('data-key') || 'app');
  var PING_EVERY = Math.min(300000, Math.floor(IDLE / 3));

  var mem = Date.now(), lastWrite = 0, lastPing = Date.now(), leaving = false;
  var overlay = null, countEl = null, stayBtn = null;

  function lastActive() {
    var v = 0;
    try { v = parseInt(localStorage.getItem(KEY), 10) || 0; } catch (e) { v = 0; }
    return Math.max(v, mem);
  }

  function touch() {
    mem = Date.now();
    if (mem - lastWrite > 1000) {
      lastWrite = mem;
      try { localStorage.setItem(KEY, String(mem)); } catch (e) { /* private mode: this tab still works alone */ }
    }
  }

  function field(form, name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    form.appendChild(i);
  }

  /* mode: 'idle' = timed out here, 'manual' = pressed Sign out now, 'expired' = the server says it is already over */
  function signOut(mode) {
    if (leaving) { return; }
    leaving = true;
    if (mode === 'expired') { window.location.href = LOGIN_URL; return; }
    var f = document.createElement('form');
    f.method = 'POST'; f.action = LOGOUT_URL; f.style.display = 'none';
    field(f, 'csrf_token', CSRF);
    if (mode === 'idle') { field(f, 'timeout', '1'); }
    document.body.appendChild(f);
    f.submit();
  }

  function ping() {
    if (!PING_URL || leaving) { return; }
    lastPing = Date.now();
    fetch(PING_URL, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (r.status === 401) { signOut('expired'); } })
      .catch(function () { /* offline for a moment: the next check decides */ });
  }

  function fmt(ms) {
    var s = Math.max(0, Math.ceil(ms / 1000)), m = Math.floor(s / 60), r = s % 60;
    return m + ':' + (r < 10 ? '0' : '') + r;
  }

  function build() {
    overlay = document.createElement('div');
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'idle-title');
    overlay.setAttribute('aria-describedby', 'idle-desc');
    overlay.style.cssText = 'position:fixed;top:0;right:0;bottom:0;left:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(10,28,100,.55);padding:16px';

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;color:#1b1f33;max-width:420px;width:100%;border-radius:14px;padding:24px;box-shadow:0 20px 60px rgba(0,10,60,.4);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';

    var h = document.createElement('div');
    h.id = 'idle-title'; h.textContent = 'Are you still there?';
    h.style.cssText = 'font-weight:800;font-size:1.2rem;margin-bottom:8px';

    var p = document.createElement('p');
    p.id = 'idle-desc'; p.style.cssText = 'margin:0 0 18px';
    p.appendChild(document.createTextNode('There has been no activity. For your security you will be signed out in '));
    countEl = document.createElement('strong');
    p.appendChild(countEl);
    p.appendChild(document.createTextNode('.'));

    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:10px;flex-wrap:wrap';

    stayBtn = document.createElement('button');
    stayBtn.type = 'button'; stayBtn.textContent = 'Stay signed in';
    stayBtn.style.cssText = 'flex:1;min-width:140px;padding:11px 16px;border:0;border-radius:9px;background:#1f3a9d;color:#fff;font:inherit;font-weight:600;cursor:pointer';
    stayBtn.addEventListener('click', function () { touch(); hide(); ping(); });

    var out = document.createElement('button');
    out.type = 'button'; out.textContent = 'Sign out now';
    out.style.cssText = 'flex:1;min-width:140px;padding:11px 16px;border:1px solid #cfd6e8;border-radius:9px;background:#fff;color:#1f3a9d;font:inherit;font-weight:600;cursor:pointer';
    out.addEventListener('click', function () { signOut('manual'); });

    row.appendChild(stayBtn); row.appendChild(out);
    box.appendChild(h); box.appendChild(p); box.appendChild(row);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  function show(msLeft) {
    if (!overlay) { build(); }
    countEl.textContent = fmt(msLeft);
    if (overlay.style.display !== 'flex') {
      overlay.style.display = 'flex';
      stayBtn.focus();
    }
  }

  function hide() {
    if (overlay) { overlay.style.display = 'none'; }
  }

  function tick() {
    if (leaving) { return; }
    var idleFor = Date.now() - lastActive();   // uses the clock, so a laptop that slept is signed out the moment it wakes
    if (idleFor >= IDLE) { signOut('idle'); return; }
    if (idleFor >= IDLE - WARN) { show(IDLE - idleFor); } else { hide(); }
  }

  function activity(e) {
    if (leaving) { return; }
    if (overlay && e && e.target && overlay.contains(e.target)) { return; }          // the warning's own buttons decide
    var warning = overlay && overlay.style.display === 'flex';
    if (warning && (e.type === 'mousemove' || e.type === 'scroll' || e.type === 'wheel')) { return; }   // a bump of the mouse must not hide the warning
    touch();
    hide();
    if (Date.now() - lastPing >= PING_EVERY) { ping(); }
  }

  ['mousedown', 'keydown', 'touchstart', 'wheel', 'scroll', 'mousemove'].forEach(function (name) {
    window.addEventListener(name, activity, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', tick);
  window.addEventListener('pageshow', function (e) { if (e.persisted) { ping(); tick(); } });   // page restored from the back button

  touch();
  setInterval(tick, 1000);
})();
