/**
 * Live AJAX conflict checker for schedules.php.   [batch 5: sends the CSRF token]
 * Watches the schedule form fields and pings ajax/check_conflict.php
 * on every change, showing the result inline before the user submits.
 * The server still re-checks authoritatively on save — this is UX only.
 */
(function () {
    const form = document.getElementById('schedule-form');
    if (!form) return;

    const resultBox = document.getElementById('conflict-result');
    const submitBtn = document.getElementById('submit-btn');
    const fields = ['section_id', 'subject_id', 'teacher_id', 'room_id', 'day_of_week', 'start_time', 'end_time', 'semester', 'school_year'];

    let debounceTimer = null;

    function checkConflicts() {
        const data = new FormData();
        fields.forEach((name) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) data.append(name, el.value);
        });
        const idField = form.querySelector('[name="id"]');
        if (idField) data.append('exclude_id', idField.value);
        const csrf = form.querySelector('[name="csrf_token"]');
        if (csrf) data.append('csrf_token', csrf.value);

        fetch('ajax/check_conflict.php', { method: 'POST', body: data, credentials: 'same-origin' })
            .then((res) => res.json())
            .then((json) => {
                if (!json.ready) {
                    resultBox.replaceChildren();
                    if (submitBtn) submitBtn.disabled = false;
                    return;
                }
                if (json.conflicts && json.conflicts.length > 0) {
                    const box = document.createElement('div');
                    box.className = 'conflict-box';
                    const title = document.createElement('strong');
                    title.textContent = '⚠ Conflict detected:';
                    const list = document.createElement('ul');
                    json.conflicts.forEach((c) => {
                        const li = document.createElement('li');
                        li.textContent = c;
                        list.appendChild(li);
                    });
                    box.appendChild(title);
                    box.appendChild(list);
                    resultBox.replaceChildren(box);
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    const ok = document.createElement('div');
                    ok.className = 'alert alert-success';
                    ok.textContent = '✓ No conflicts — this slot is free.';
                    resultBox.replaceChildren(ok);
                    if (submitBtn) submitBtn.disabled = false;
                }
            })
            .catch(() => {
                resultBox.replaceChildren();
                if (submitBtn) submitBtn.disabled = false;
            });
    }

    fields.forEach((name) => {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el) return;
        el.addEventListener('change', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(checkConflicts, 200);
        });
    });

    // Run once on load in case fields are pre-filled (edit mode).
    checkConflicts();
})();

/** Generic confirm() wrapper for forms with data-confirm="message". */
document.addEventListener('submit', function (e) {
    const msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});