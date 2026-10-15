<?php
// >>> PLACE AT: teacher-registration/includes/privacy_notice.php and student-portal/includes/privacy_notice.php  (the SAME file)  [batch 12]
/**
 * Data Privacy Notice + consent checkbox (Philippine Data Privacy Act of 2012, RA 10173).
 * Needs e() from the app. Set PRIVACY_CONTACT in the app config if the school has a Data Protection Officer email.
 */
if (!defined('PRIVACY_CONTACT')) {
    define('PRIVACY_CONTACT', 'the Registrar');
}

function privacyConsentBlock(bool $checked = false): void
{
    ?>
<details class="privacy-notice" style="margin:0 0 10px;font-size:.88rem">
    <summary style="cursor:pointer;font-weight:600">Data Privacy Notice</summary>
    <p style="margin:8px 0 0">Bestlink College of the Philippines collects your name, contact details and the information you submit here to run class scheduling and enrollment.
        Only authorized school staff (Admin, Registrar, Program Heads) can see them, and sensitive fields are stored encrypted.
        We keep records only as long as school operations and legal requirements need them.
        Under the Data Privacy Act of 2012 (RA 10173) you may ask to access or correct your data, or object to its processing, by contacting <?= e(PRIVACY_CONTACT) ?>.</p>
</details>
<label style="display:flex;gap:10px;align-items:flex-start;font-weight:500;margin:0 0 14px">
    <input type="checkbox" name="consent" value="1" required style="width:auto;margin-top:4px" <?= $checked ? 'checked' : '' ?>>
    <span>I have read the Data Privacy Notice and agree to the processing of my personal data.</span>
</label>
    <?php
}
