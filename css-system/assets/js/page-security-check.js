/* >>> PLACE AT: css-system/assets/js/page-security-check.js (new)  [batch 11]
 * security_check.php: "Copy unfinished problems" button. Copies the text in the hidden #sc-copy-text box.
 * Uses the Clipboard API on HTTPS/localhost and falls back to select + copy on plain HTTP.
 */
(function () {
  'use strict';
  var btn = document.getElementById('sc-copy'),
      src = document.getElementById('sc-copy-text'),
      status = document.getElementById('sc-copy-status'),
      timer = null;
  if (!btn || !src) { return; }

  function say(msg) {
    if (!status) { return; }
    status.textContent = msg;
    clearTimeout(timer);
    timer = setTimeout(function () { status.textContent = ''; }, 4000);
  }

  function fallbackCopy() {
    var ok = false;
    try {
      src.select();
      src.setSelectionRange(0, src.value.length);
      ok = document.execCommand('copy');
      src.setSelectionRange(0, 0);
    } catch (err) { ok = false; }
    return ok;
  }

  btn.addEventListener('click', function () {
    var text = src.value;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () {
        say('Copied. Paste it into the chat.');
      }, function () {
        say(fallbackCopy() ? 'Copied. Paste it into the chat.' : 'Could not copy. Select the text manually.');
      });
    } else {
      say(fallbackCopy() ? 'Copied. Paste it into the chat.' : 'Could not copy. Select the text manually.');
    }
  });
})();