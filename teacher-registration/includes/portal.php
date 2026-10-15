<?php
// >>> PLACE AT: teacher-registration/includes/portal.php  (replace the old file)  [SQL review: fixed query]
/**
 * Registration form + schedule data functions.
 * This is where the portal writes into the Class Scheduling System's tables
 * (teachers, treg_availability, treg_subjects) and reads from schedules.
 */

function minutesOf(string $hhmm): int
{
    $p = explode(':', $hhmm);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

function hhmm(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function clock12(string $time): string
{
    return date('g:i A', strtotime($time));
}

function formatUnits($units): string
{
    $s = rtrim(rtrim(number_format((float)$units, 1, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/* ---------------------------------------------------------------
 * Teacher row that belongs to this login (in the scheduling system)
 * --------------------------------------------------------------- */
function getLinkedTeacher(array $account): ?array
{
    if (empty($account['teacher_id'])) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM teachers WHERE id = ?');
    $stmt->execute([$account['teacher_id']]);
    return $stmt->fetch() ?: null;   // null if the admin deleted it
}

/** @return array<int, array{day:string,start:string,end:string}> */
function getSavedAvailability(int $teacherId): array
{
    $stmt = getDB()->prepare(
        "SELECT day_of_week AS `day`, TIME_FORMAT(start_time, '%H:%i') AS `start`, TIME_FORMAT(end_time, '%H:%i') AS `end`
           FROM treg_availability WHERE teacher_id = ?
          ORDER BY FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), start_time"
    );
    $stmt->execute([$teacherId]);
    return $stmt->fetchAll();
}

/** @return int[] */
function getSavedSubjectIds(int $teacherId): array
{
    $stmt = getDB()->prepare('SELECT subject_id FROM treg_subjects WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------------------------------------------------------------
 * Validate the availability rows posted by the form
 * Returns [windows, errors]. Overlapping/adjacent windows on the same day are merged.
 * --------------------------------------------------------------- */
function parseAvailability(array $post): array
{
    $days   = $post['avail_day']   ?? [];
    $starts = $post['avail_start'] ?? [];
    $ends   = $post['avail_end']   ?? [];
    if (!is_array($days) || !is_array($starts) || !is_array($ends)) {
        return [[], ['The availability data was not valid. Reload the page and try again.']];
    }

    $errors = [];
    $byDay  = [];
    $min    = minutesOf(AVAIL_MIN);
    $max    = minutesOf(AVAIL_MAX);
    $timeRx = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    foreach ($days as $i => $day) {
        $start = $starts[$i] ?? '';
        $end   = $ends[$i]   ?? '';
        if (!is_string($day) || !is_string($start) || !is_string($end)) {
            $errors[] = 'The availability data was not valid. Reload the page and try again.';
            continue;
        }
        if ($day === '' && $start === '' && $end === '') {
            continue; // untouched blank row
        }
        if (!isset(DAYS[$day])) {
            $errors[] = 'Choose a day for every availability row you fill in.';
            continue;
        }
        if (!preg_match($timeRx, $start) || !preg_match($timeRx, $end)) {
            $errors[] = 'Enter both a start and an end time for ' . DAYS[$day] . '.';
            continue;
        }
        $s = minutesOf(substr($start, 0, 5));
        $e = minutesOf(substr($end, 0, 5));
        if ($s >= $e) {
            $errors[] = DAYS[$day] . ': the end time must be later than the start time.';
            continue;
        }
        if ($s < $min || $e > $max) {
            $errors[] = DAYS[$day] . ': choose times between ' . clock12(AVAIL_MIN) . ' and ' . clock12(AVAIL_MAX) . '.';
            continue;
        }
        $byDay[$day][] = [$s, $e];
    }

    $windows = [];
    foreach (array_keys(DAYS) as $day) {
        if (empty($byDay[$day])) {
            continue;
        }
        usort($byDay[$day], fn($a, $b) => $a[0] <=> $b[0]);
        $cur = null;
        foreach ($byDay[$day] as [$s, $e]) {
            if ($cur !== null && $s <= $cur[1]) {
                $cur[1] = max($cur[1], $e);
            } else {
                if ($cur !== null) {
                    $windows[] = ['day' => $day, 'start' => hhmm($cur[0]), 'end' => hhmm($cur[1])];
                }
                $cur = [$s, $e];
            }
        }
        if ($cur !== null) {
            $windows[] = ['day' => $day, 'start' => hhmm($cur[0]), 'end' => hhmm($cur[1])];
        }
    }

    if (!$errors && !$windows) {
        $errors[] = 'Add at least one day and time you are available.';
    }
    return [$windows, array_values(array_unique($errors))];
}

/** Keeps only subject IDs that really exist. @return int[] */
function parseSubjectIds(array $post): array
{
    $raw = $post['subject_ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    // Fixed query: load every real subject id once, then keep only the submitted ids that are in that list.
    $valid = array_map('intval', getDB()->query('SELECT id FROM subjects')->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_intersect($ids, $valid));
}

/* ---------------------------------------------------------------
 * Save the registration into the Class Scheduling System
 *   - creates (or updates) the row in `teachers`, inactive until an admin approves
 *   - replaces the teacher's availability windows and subject list
 *   - marks the login as "submitted"
 * --------------------------------------------------------------- */
function saveRegistration(array $account, array $windows, array $subjectIds): int
{
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $fullName = accountFullName($account);
        $teacher  = getLinkedTeacher($account);

        if ($teacher) {
            $teacherId = (int)$teacher['id'];
            $pdo->prepare('UPDATE teachers SET full_name = ?, department = ? WHERE id = ?')
                ->execute([$fullName, $account['program'], $teacherId]);
        } else {
            // Same columns the scheduling system's own teachers.php inserts. Inactive until approved.
            $pdo->prepare("INSERT INTO teachers (full_name, department, max_units, status) VALUES (?, ?, 24, 'inactive')")
                ->execute([$fullName, $account['program']]);
            $teacherId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM treg_availability WHERE teacher_id = ?')->execute([$teacherId]);
        $ins = $pdo->prepare('INSERT INTO treg_availability (teacher_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)');
        foreach ($windows as $w) {
            $ins->execute([$teacherId, $w['day'], $w['start'] . ':00', $w['end'] . ':00']);
        }

        $pdo->prepare('DELETE FROM treg_subjects WHERE teacher_id = ?')->execute([$teacherId]);
        $ins = $pdo->prepare('INSERT INTO treg_subjects (teacher_id, subject_id) VALUES (?,?)');
        foreach ($subjectIds as $sid) {
            $ins->execute([$teacherId, $sid]);
        }

        // NOTE: MySQL evaluates SET assignments left to right, so reg_status must be assigned LAST
        // (the CASE expressions above it still need to see the old status).
        // NOW() (MySQL time) is used for submitted/updated/reviewed stamps so the scheduling system,
        // which may run under a different PHP timezone, compares them on the same clock.
        $pdo->prepare(
            "UPDATE treg_accounts SET
                teacher_id         = ?,
                submitted_at       = CASE WHEN reg_status IN ('incomplete','rejected') THEN NOW() ELSE submitted_at END,
                review_note        = CASE WHEN reg_status = 'rejected' THEN NULL ELSE review_note END,
                profile_updated_at = NOW(),
                reg_status         = CASE WHEN reg_status IN ('incomplete','rejected') THEN 'submitted' ELSE reg_status END
              WHERE id = ?"
        )->execute([$teacherId, $account['id']]);

        $pdo->commit();
        return $teacherId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/* ---------------------------------------------------------------
 * Schedules assigned to a teacher by the admin (read from the scheduling system)
 * --------------------------------------------------------------- */
/** Terms (semester + school year) this teacher has classes in, newest first. */
function getTeacherTerms(int $teacherId): array
{
    $stmt = getDB()->prepare(
        "SELECT DISTINCT semester, school_year FROM schedules
          WHERE teacher_id = ? AND status = 'active'
          ORDER BY school_year DESC, FIELD(semester,'summer','2nd','1st')"
    );
    $stmt->execute([$teacherId]);
    return $stmt->fetchAll();
}

function getTeacherSchedule(int $teacherId, string $semester, string $schoolYear): array
{
    $stmt = getDB()->prepare(
        "SELECT s.id, s.section_id, s.subject_id, s.day_of_week, s.start_time, s.end_time,
                sub.code AS subj_code, sub.title AS subj_title, sub.units AS subj_units,
                r.name AS room_name,
                sec.name AS section_name, sec.program AS section_program, sec.year_level AS section_year
           FROM schedules s
           JOIN subjects sub ON sub.id = s.subject_id
           JOIN rooms r      ON r.id   = s.room_id
           JOIN sections sec ON sec.id = s.section_id
          WHERE s.teacher_id = ? AND s.status = 'active' AND s.semester = ? AND s.school_year = ?
          ORDER BY FIELD(s.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), s.start_time"
    );
    $stmt->execute([$teacherId, $semester, $schoolYear]);
    return $stmt->fetchAll();
}

function statusLabel(string $status): string
{
    return [
        'incomplete' => 'Not submitted',
        'submitted'  => 'Waiting for review',
        'approved'   => 'Approved',
        'rejected'   => 'Needs changes',
    ][$status] ?? $status;
}

if (!function_exists('teacherRegId')) {
    function teacherRegId($accountId): string
    {
        return 'TCH-' . str_pad((string)(int)$accountId, 4, '0', STR_PAD_LEFT);
    }
}
