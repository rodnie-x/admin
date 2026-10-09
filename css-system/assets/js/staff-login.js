/* >>> PLACE AT: css-system/assets/js/staff-login.js (new)  [batch 9]
 * "Send code" button on the staff log in form. Calls send_staff_code.php, which answers the same
 * message whether or not the Gmail has an account.
 */
(function () {
  'use strict';
  var btn = document.getElementById('send-code');
  if (!btn) return;
  var form = btn.closest('form'), status = document.getElementById('code-status'), timer = null;

  function cooldown(seconds) {
    var left = seconds;
    clearInterval(timer);
    btn.disabled = true;
    btn.textContent = 'Resend in ' + left + 's';
    timer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(timer);
        btn.disabled = false;
        btn.textContent = 'Resend code';
      } else {
        btn.textContent = 'Resend in ' + left + 's';
      }
    }, 1000);
  }

  btn.addEventListener('click', function () {
    var email = form.elements['email'].value.trim();
    if (!email) {
      status.textContent = 'Enter your Gmail first, then request a code.';
      form.elements['email'].focus();
      return;
    }
    btn.disabled = true;
    status.textContent = 'Sending...';

    var body = new URLSearchParams();
    body.append('email', email);
    body.append('csrf_token', form.elements['csrf_token'].value);

    fetch('send_staff_code.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      return res.json().then(function (data) { return { ok: res.ok, data: data }; });
    }).then(function (r) {
      var msg = r.data.message || 'Done.';
      if (r.data.debug_code) { msg += ' Dev mode: your code is ' + r.data.debug_code + '.'; }
      status.textContent = msg;
      if (r.ok) { cooldown(r.data.cooldown || 60); } else { btn.disabled = false; }
    }).catch(function () {
      status.textContent = 'Could not reach the server. Check your connection and try again.';
      btn.disabled = false;
    });
  });
})();