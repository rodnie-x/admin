/* >>> PLACE AT: teacher-registration/assets/js/app.js  (replace the old file)  [batch 8: adds print button + term picker] */
/* Teacher Registration portal - progressive enhancement only.
   Every page still works (server-side) if this file fails to load. */
(function () {
    'use strict';

    /* ---------- Log in / Sign up tabs (no reload) ---------- */
    var tabLinks = document.querySelectorAll('[data-tab-link]');
    if (tabLinks.length) {
        var showTab = function (name) {
            document.querySelectorAll('[data-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== name;
            });
            document.querySelectorAll('.tabs [data-tab-link]').forEach(function (link) {
                var on = link.getAttribute('data-tab-link') === name;
                link.classList.toggle('is-active', on);
                link.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            if (window.history && history.replaceState) {
                history.replaceState(null, '', 'index.php?tab=' + name);
            }
        };
        tabLinks.forEach(function (link) {
            link.addEventListener('click', function (ev) {
                ev.preventDefault();
                showTab(link.getAttribute('data-tab-link'));
            });
        });
    }

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-pw'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', (show ? 'Hide' : 'Show') + (btn.getAttribute('aria-label') || '').replace(/^(Show|Hide)/, ''));
        });
    });

    /* ---------- "Send code" button ---------- */
    var sendBtn = document.getElementById('send-code');
    if (sendBtn) {
        var form = sendBtn.closest('form');
        var status = document.getElementById('code-status');
        var timer = null;

        var cooldown = function (seconds) {
            var left = seconds;
            clearInterval(timer);
            sendBtn.disabled = true;
            sendBtn.textContent = 'Resend in ' + left + 's';
            timer = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(timer);
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Resend code';
                } else {
                    sendBtn.textContent = 'Resend in ' + left + 's';
                }
            }, 1000);
        };

        sendBtn.addEventListener('click', function () {
            var email = form.elements['email'].value.trim();
            if (!email) {
                status.textContent = 'Enter your Gmail first, then request a code.';
                form.elements['email'].focus();
                return;
            }
            sendBtn.disabled = true;
            status.textContent = 'Sending...';

            var body = new URLSearchParams();
            body.append('email', email);
            body.append('csrf_token', form.elements['csrf_token'].value);

            fetch('send_code.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            }).then(function (r) {
                var msg = r.data.message || 'Done.';
                if (r.data.debug_code) {
                    msg += ' Dev mode: your code is ' + r.data.debug_code + '.';
                }
                status.textContent = msg;
                if (r.ok) { cooldown(r.data.cooldown || 60); } else { sendBtn.disabled = false; }
            }).catch(function () {
                status.textContent = 'Could not reach the server. Check your connection and try again.';
                sendBtn.disabled = false;
            });
        });
    }

    /* ---------- Registration form: availability rows + week strip ---------- */
    var rowsWrap = document.getElementById('avail-rows');
    if (rowsWrap) {
        var tpl = document.getElementById('avail-row-tpl');
        var strip = document.getElementById('week-strip');
        var days = JSON.parse(rowsWrap.getAttribute('data-days') || '[]');
        var toMin = function (t) {
            var p = t.split(':');
            return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
        };
        var MIN = toMin(rowsWrap.getAttribute('data-min'));
        var MAX = toMin(rowsWrap.getAttribute('data-max'));
        var SPAN = MAX - MIN;

        var hourLabel = function (mins) {
            var h = Math.floor(mins / 60);
            var suffix = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12 === 0 ? 12 : h % 12;
            return h12 + ' ' + suffix;
        };

        var countValid = function () {
            var n = 0;
            rowsWrap.querySelectorAll('.avail-row').forEach(function (row) {
                var d = row.querySelector('select').value;
                var s = row.querySelector('[name="avail_start[]"]').value;
                var e = row.querySelector('[name="avail_end[]"]').value;
                if (d && s && e && toMin(e) > toMin(s)) { n += 1; }
            });
            return n;
        };

        var renderStrip = function () {
            if (!strip) { return; }
            strip.replaceChildren();

            // hour axis
            var axis = document.createElement('div');
            axis.className = 'strip-axis';
            axis.appendChild(document.createElement('span'));
            var axisTrack = document.createElement('div');
            axisTrack.className = 'strip-axis-track';
            for (var m = MIN; m <= MAX; m += 180) {
                var tick = document.createElement('span');
                tick.textContent = hourLabel(m);
                tick.style.left = ((m - MIN) / SPAN * 100) + '%';
                axisTrack.appendChild(tick);
            }
            axis.appendChild(axisTrack);
            strip.appendChild(axis);

            days.forEach(function (d) {
                var line = document.createElement('div');
                line.className = 'strip-day';
                var name = document.createElement('span');
                name.textContent = d[0];
                var track = document.createElement('div');
                track.className = 'strip-track';

                rowsWrap.querySelectorAll('.avail-row').forEach(function (row) {
                    if (row.querySelector('select').value !== d[0]) { return; }
                    var s = row.querySelector('[name="avail_start[]"]').value;
                    var e = row.querySelector('[name="avail_end[]"]').value;
                    if (!s || !e) { return; }
                    var a = Math.max(toMin(s), MIN);
                    var b = Math.min(toMin(e), MAX);
                    if (b <= a) { return; }
                    var bar = document.createElement('i');
                    bar.style.left = ((a - MIN) / SPAN * 100) + '%';
                    bar.style.width = ((b - a) / SPAN * 100) + '%';
                    bar.title = s + ' to ' + e;
                    track.appendChild(bar);
                });

                line.appendChild(name);
                line.appendChild(track);
                strip.appendChild(line);
            });
        };

        var updateCounts = function () {
            var w = countValid();
            var wEl = document.getElementById('bar-windows');
            if (wEl) { wEl.textContent = w + (w === 1 ? ' time' : ' times'); }
            renderStrip();
        };

        var addRow = function (day, start, end) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.querySelector('select').value = day || '';
            node.querySelector('[name="avail_start[]"]').value = start || '';
            node.querySelector('[name="avail_end[]"]').value = end || '';
            rowsWrap.appendChild(node);
        };

        rowsWrap.addEventListener('input', updateCounts);
        rowsWrap.addEventListener('change', updateCounts);
        rowsWrap.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-remove]');
            if (!btn) { return; }
            btn.closest('.avail-row').remove();
            if (!rowsWrap.querySelector('.avail-row')) { addRow(); }
            updateCounts();
        });

        var addBtn = document.getElementById('add-window');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addRow();
                updateCounts();
                var last = rowsWrap.querySelector('.avail-row:last-child select');
                if (last) { last.focus(); }
            });
        }

        var presetBtn = document.getElementById('preset-weekdays');
        if (presetBtn) {
            presetBtn.addEventListener('click', function () {
                // drop untouched blank rows, then add Mon-Fri
                rowsWrap.querySelectorAll('.avail-row').forEach(function (row) {
                    var empty = !row.querySelector('select').value
                        && !row.querySelector('[name="avail_start[]"]').value
                        && !row.querySelector('[name="avail_end[]"]').value;
                    if (empty) { row.remove(); }
                });
                ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].forEach(function (d) { addRow(d, '08:00', '17:00'); });
                updateCounts();
            });
        }

        updateCounts();
    }

    /* ---------- Registration form: subject search + counter ---------- */
    var grid = document.getElementById('subject-grid');
    if (grid) {
        var search = document.getElementById('subject-search');
        var countEl = document.getElementById('subject-count');
        var barEl = document.getElementById('bar-subjects');
        var emptyEl = document.getElementById('subject-empty');
        var items = grid.querySelectorAll('.subject-item');

        var refreshSubjects = function () {
            var n = grid.querySelectorAll('input[type=checkbox]:checked').length;
            if (countEl) { countEl.textContent = n + ' selected'; }
            if (barEl) { barEl.textContent = n + (n === 1 ? ' subject' : ' subjects'); }
        };
        var filterSubjects = function () {
            var q = (search.value || '').trim().toLowerCase();
            var shown = 0;
            items.forEach(function (item) {
                var match = !q || item.getAttribute('data-search').indexOf(q) !== -1;
                item.hidden = !match;
                if (match) { shown += 1; }
            });
            if (emptyEl) { emptyEl.hidden = shown !== 0; }
        };

        grid.addEventListener('change', refreshSubjects);
        if (search) { search.addEventListener('input', filterSubjects); }
        refreshSubjects();
    }

    /* ---------- My schedules: print button + term picker ---------- */
    document.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-print]')) { window.print(); }
    });
    var termSelect = document.getElementById('term-select');
    if (termSelect && termSelect.form) {
        termSelect.addEventListener('change', function () {
            var v = termSelect.value.split('|');
            termSelect.form.elements['sem'].value = v[0];
            termSelect.form.elements['sy'].value = v[1];
            termSelect.form.submit();
        });
    }
})();