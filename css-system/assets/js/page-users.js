document.addEventListener('submit', function (e) {
    if (e.target && e.target.classList.contains('form-reset-password')) {
        const pw = prompt('Enter a new password for this user (min. 8 characters):');
        if (!pw || pw.length < 8) {
            if (pw !== null) alert('Password must be at least 8 characters.');
            e.preventDefault();
            return;
        }
        e.target.querySelector('.np-field').value = pw;
    }
});
