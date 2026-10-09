<?php
// >>> PLACE AT: css-system/ajax/check_conflict.php  (replace the old file)  [batch 5: CSRF + shared validation]
/**
 * AJAX endpoint: live conflict check as the schedule form is filled in.
 * Returns JSON. Always re-checked authoritatively server-side on actual save
 * (see schedules.php) — this endpoint is for instant UI feedback only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST']);
    exit;
}

if (!isLoggedIn() || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

// Same CSRF token the forms use (app.js sends it)
$sent = $_POST['csrf_token'] ?? '';
if (!is_string($sent) || $sent === '' || !hash_equals(csrfToken(), $sent)) {
    http_response_code(419);
    echo json_encode(['error' => 'Session expired. Refresh the page.']);
    exit;
}

$required = ['section_id', 'subject_id', 'teacher_id', 'room_id', 'day_of_week', 'start_time', 'end_time', 'semester', 'school_year'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || !is_string($_POST[$field]) || $_POST[$field] === '') {
        echo json_encode(['ready' => false]);
        exit;
    }
}

[$slot, $errors] = validateScheduleInput($_POST);
if ($errors) {
    echo json_encode(['ready' => true, 'conflicts' => $errors]);
    exit;
}

$excludeId = isset($_POST['exclude_id']) && is_string($_POST['exclude_id']) && (int)$_POST['exclude_id'] > 0 ? (int)$_POST['exclude_id'] : null;

echo json_encode(['ready' => true, 'conflicts' => detectScheduleConflicts($slot, $excludeId)]);