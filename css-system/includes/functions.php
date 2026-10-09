<?php
// >>> PLACE AT: css-system/includes/functions.php  (replace the old file)  [batch 5]
/**
 * Shared helpers + scheduling automation logic.
 */
require_once __DIR__ . '/../config/db.php';

/**
 * Which server header carries the real visitor IP when the site sits behind a proxy.
 * Leave '' to log REMOTE_ADDR. If the Audit Log shows the same IP for everybody on the live site,
 * add  define('TRUST_PROXY_IP_HEADER', 'HTTP_X_FORWARDED_FOR');  to config/db.php (before this file loads).
 * Used for LOG DISPLAY ONLY. Never use it for a security decision (it can be forged).
 */
if (!defined('TRUST_PROXY_IP_HEADER')) {
    define('TRUST_PROXY_IP_HEADER', '');
}

/** Visitor IP for the audit log. */
function clientIp(): ?string
{
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $hdr = TRUST_PROXY_IP_HEADER;
    if ($hdr !== '' && !empty($_SERVER[$hdr]) && is_string($_SERVER[$hdr])) {
        $first = trim(explode(',', $_SERVER[$hdr])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return $ip !== null ? substr((string)$ip, 0, 45) : null;
}

/** Escape output for safe HTML display. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect to a local URL and stop execution. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Queue a one-time flash message, shown on the next page load. */
function flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Pop all queued flash messages (call once, e.g. from header.php). */
function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Insert a row into audit_log. Always records the visitor IP. */
function logAudit(?int $userId, string $action, ?string $table = null, $recordId = null, ?string $details = null): void
{
    $pdo = getDB();
    $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, details, ip_address) VALUES (?,?,?,?,?,?)")
        ->execute([$userId, $action, $table, $recordId, $details !== null ? mb_substr($details, 0, 255) : null, clientIp()]);
}

/* ================================================================
 * Schedule input validation (server side, authoritative)
 * ================================================================ */

/**
 * Cleans and validates the schedule form fields.
 * The returned $slot keeps this exact key order (schedules.php relies on it for its INSERT/UPDATE).
 * @return array{0:array,1:string[]} [slot, errors]
 */
function validateScheduleInput(array $in): array
{
    $str = static fn(string $k): string => (isset($in[$k]) && is_string($in[$k])) ? trim($in[$k]) : '';
    $time = static function (string $v): string {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
            return '';
        }
        return strlen($v) === 5 ? $v . ':00' : $v;   // always HH:MM:SS so text comparison is safe
    };

    $slot = [
        'section_id'  => (int)$str('section_id'),
        'subject_id'  => (int)$str('subject_id'),
        'teacher_id'  => (int)$str('teacher_id'),
        'room_id'     => (int)$str('room_id'),
        'day_of_week' => $str('day_of_week'),
        'start_time'  => $time($str('start_time')),
        'end_time'    => $time($str('end_time')),
        'semester'    => $str('semester'),
        'school_year' => $str('school_year'),
    ];

    $errors = [];
    foreach (['section_id' => 'section', 'subject_id' => 'subject', 'teacher_id' => 'teacher', 'room_id' => 'room'] as $k => $label) {
        if ($slot[$k] <= 0) {
            $errors[] = "Choose a $label.";
        }
    }
    if (!in_array($slot['day_of_week'], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], true)) {
        $errors[] = 'Choose a valid day.';
    }
    if ($slot['start_time'] === '' || $slot['end_time'] === '') {
        $errors[] = 'Enter a valid start and end time.';
    } elseif ($slot['start_time'] >= $slot['end_time']) {
        $errors[] = 'End time must be after start time.';
    }
    if (!in_array($slot['semester'], ['1st', '2nd', 'summer'], true)) {
        $errors[] = 'Choose a valid semester.';
    }
    if (!preg_match('/^(\d{4})-(\d{4})$/', $slot['school_year'], $m) || (int)$m[2] !== (int)$m[1] + 1) {
        $errors[] = 'School year must look like 2026-2027.';
    }
    return [$slot, $errors];
}

/* ================================================================
 * Atomic saves: lock the teacher, room and section rows, then check + write
 * ================================================================ */

/**
 * Starts a transaction for a schedule write. READ COMMITTED so the conflict check that runs
 * after the locks are taken always sees what other admins have committed in the meantime.
 */
function beginScheduleTransaction(PDO $pdo): void
{
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
}

/**
 * Inside a transaction: locks the section, teacher and room rows (always in this order, so two admins
 * can't deadlock) and checks the subject exists. Two admins saving for the same teacher, room or
 * section now wait for each other, so the second one's conflict check sees the first one's class.
 * @return string|null null = OK, otherwise a message for the admin
 */
function lockScheduleResources(PDO $pdo, array $slot): ?string
{
    $checks = [
        ['sections', (int)$slot['section_id'], 'The selected section no longer exists.'],
        ['teachers', (int)$slot['teacher_id'], 'The selected teacher no longer exists or is inactive.'],
        ['rooms',    (int)$slot['room_id'],    'The selected room no longer exists or is not available.'],
    ];
    foreach ($checks as [$table, $id, $message]) {   // each table has its own fixed query below (same lock order as before)
        if ($table === 'sections') {
            $st = $pdo->prepare('SELECT id FROM sections WHERE id = ? FOR UPDATE');
        } elseif ($table === 'teachers') {
            $st = $pdo->prepare("SELECT id FROM teachers WHERE id = ? AND status = 'active' FOR UPDATE");
        } else {
            $st = $pdo->prepare("SELECT id FROM rooms WHERE id = ? AND status = 'available' FOR UPDATE");
        }
        $st->execute([$id]);
        if (!$st->fetchColumn()) {
            return $message;
        }
    }
    $st = $pdo->prepare('SELECT id FROM subjects WHERE id = ?');
    $st->execute([(int)$slot['subject_id']]);
    return $st->fetchColumn() ? null : 'The selected subject no longer exists.';
}

// Shared part of the conflict query. A constant, so the SQL text never contains a variable.
if (!defined('SCHEDULE_CONFLICT_SQL')) {
    define('SCHEDULE_CONFLICT_SQL', "SELECT s.*, t.full_name AS teacher_name, r.name AS room_name, sec.name AS section_name
             FROM schedules s
             JOIN teachers t ON t.id = s.teacher_id
             JOIN rooms r ON r.id = s.room_id
             JOIN sections sec ON sec.id = s.section_id
             WHERE s.status = 'active'
               AND s.day_of_week = :day
               AND s.semester = :semester
               AND s.school_year = :sy
               AND s.start_time < :end_time
               AND :start_time < s.end_time");
}

/**
 * Authoritative server-side conflict check for a proposed schedule slot.
 * Checks teacher / room / section double-booking on overlapping time ranges,
 * within the same day, semester, and school year.
 *
 * @param array    $slot      Associative array with section_id, subject_id, teacher_id,
 *                             room_id, day_of_week, start_time, end_time, semester, school_year.
 * @param int|null $excludeId Schedule id to exclude (when updating an existing entry).
 * @return string[] Human-readable conflict messages (empty array = no conflicts).
 */
function detectScheduleConflicts(array $slot, ?int $excludeId = null): array
{
    $pdo = getDB();
    $conflicts = [];

    // Two time ranges [start1,end1) and [start2,end2) overlap when start1 < end2 AND start2 < end1.
    $params = [
        'day'        => $slot['day_of_week'],
        'semester'   => $slot['semester'],
        'sy'         => $slot['school_year'],
        'start_time' => $slot['start_time'],
        'end_time'   => $slot['end_time'],
    ];

    $checks = [
        'teacher_id' => 'Teacher %s is already scheduled at that time (%s, %s–%s, %s).',
        'room_id'    => 'Room %s is already booked at that time (%s, %s–%s, %s).',
        'section_id' => 'Section %s already has a class at that time (%s, %s–%s, %s).',
    ];

    foreach ($checks as $column => $template) {
        // one fixed query per column; "s.id != :exclude_id" with 0 matches every row, so it is a no-op when nothing is excluded
        if ($column === 'teacher_id') {
            $stmt = $pdo->prepare(SCHEDULE_CONFLICT_SQL . ' AND s.teacher_id = :val AND s.id != :exclude_id');
        } elseif ($column === 'room_id') {
            $stmt = $pdo->prepare(SCHEDULE_CONFLICT_SQL . ' AND s.room_id = :val AND s.id != :exclude_id');
        } else {
            $stmt = $pdo->prepare(SCHEDULE_CONFLICT_SQL . ' AND s.section_id = :val AND s.id != :exclude_id');
        }
        $stmt->execute($params + ['val' => $slot[$column], 'exclude_id' => (int)($excludeId ?? 0)]);

        foreach ($stmt->fetchAll() as $row) {
            $label = match ($column) {
                'teacher_id' => $row['teacher_name'],
                'room_id'    => $row['room_name'],
                'section_id' => $row['section_name'],
            };
            $conflicts[] = sprintf(
                $template,
                $label,
                $row['day_of_week'],
                substr($row['start_time'], 0, 5),
                substr($row['end_time'], 0, 5),
                $row['school_year']
            );
        }
    }

    return $conflicts;
}

/**
 * Find rooms with no active schedule overlapping the given day/time/term.
 * @return array Room rows (id, name, type, capacity, status).
 */
function findAvailableRooms(string $day, string $start, string $end, string $semester, string $schoolYear): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT * FROM rooms
         WHERE status = 'available'
           AND id NOT IN (
               SELECT room_id FROM schedules
               WHERE status = 'active'
                 AND day_of_week = :day
                 AND semester = :semester
                 AND school_year = :sy
                 AND start_time < :end_time
                 AND :start_time < end_time
           )
         ORDER BY name"
    );
    $stmt->execute([
        'day'        => $day,
        'semester'   => $semester,
        'sy'         => $schoolYear,
        'start_time' => $start,
        'end_time'   => $end,
    ]);
    return $stmt->fetchAll();
}

/**
 * Copy every active schedule entry from one section to another (or to a new term),
 * skipping and reporting any entry that would create a conflict at the destination.
 * Runs as ONE transaction: if anything unexpected fails, nothing is copied.
 * Each entry is also checked against the teacher's registered availability and subjects.
 *
 * @return array{cloned:int, skipped:string[]}
 * @throws Throwable after rolling back
 */
function cloneSectionSchedule(int $sourceSectionId, int $targetSectionId, string $targetSemester, string $targetSchoolYear, int $userId): array
{
    $pdo = getDB();
    $cloned = 0;
    $skipped = [];

    $stmt = $pdo->prepare("SELECT s.*, sub.code AS subj_code, t.full_name AS teacher_name, r.name AS room_name
                            FROM schedules s
                            JOIN subjects sub ON sub.id = s.subject_id
                            JOIN teachers t ON t.id = s.teacher_id
                            JOIN rooms r ON r.id = s.room_id
                            WHERE s.section_id = ? AND s.status = 'active'");
    $stmt->execute([$sourceSectionId]);
    $sourceRows = $stmt->fetchAll();

    $insert = $pdo->prepare("INSERT INTO schedules (section_id, subject_id, teacher_id, room_id, day_of_week, start_time, end_time, semester, school_year, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?)");

    beginScheduleTransaction($pdo);
    try {
        foreach ($sourceRows as $row) {
            $newSlot = [
                'section_id'  => $targetSectionId,
                'subject_id'  => $row['subject_id'],
                'teacher_id'  => $row['teacher_id'],
                'room_id'     => $row['room_id'],
                'day_of_week' => $row['day_of_week'],
                'start_time'  => $row['start_time'],
                'end_time'    => $row['end_time'],
                'semester'    => $targetSemester,
                'school_year' => $targetSchoolYear,
            ];

            $problem = lockScheduleResources($pdo, $newSlot);
            $conflicts = $problem !== null ? [$problem] : detectScheduleConflicts($newSlot);
            if ($problem === null && function_exists('checkTeacherRegistrationFit')) {
                $conflicts = array_merge($conflicts, checkTeacherRegistrationFit($newSlot));
            }

            if (!empty($conflicts)) {
                $skipped[] = sprintf(
                    '%s (%s, %s–%s) with %s in %s — %s',
                    $row['subj_code'],
                    $row['day_of_week'],
                    substr($row['start_time'], 0, 5),
                    substr($row['end_time'], 0, 5),
                    $row['teacher_name'],
                    $row['room_name'],
                    $conflicts[0]
                );
                continue;
            }

            $insert->execute([
                $newSlot['section_id'], $newSlot['subject_id'], $newSlot['teacher_id'], $newSlot['room_id'],
                $newSlot['day_of_week'], $newSlot['start_time'], $newSlot['end_time'],
                $newSlot['semester'], $newSlot['school_year'], $userId,
            ]);
            $cloned++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }

    logAudit($userId, 'CLONE', 'schedules', $targetSectionId, "Cloned schedule from section #$sourceSectionId to #$targetSectionId ($cloned entries, " . count($skipped) . " skipped)");

    return ['cloned' => $cloned, 'skipped' => $skipped];
}
if (!function_exists('teacherRegId')) {
    function teacherRegId($accountId): string
    {
        return 'TCH-' . str_pad((string)(int)$accountId, 4, '0', STR_PAD_LEFT);
    }
}