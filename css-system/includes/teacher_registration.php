<?php
/**
 * Bridge between the Class Scheduling System and the Teacher Registration portal.
 * Copy this file to:  css-system/includes/teacher_registration.php
 *
 * The portal saves each teacher's declared availability and subjects into the shared
 * database (tables treg_availability / treg_subjects, keyed by teachers.id).
 * This file lets the scheduling system USE that data to avoid bad assignments:
 *
 *   checkTeacherRegistrationFit($slot)  -> list of problems (empty = OK)
 *   trAllProfiles()                     -> data for the "registered availability" panel in schedules.php
 *   trGetProfile($teacherId)            -> one teacher's availability + subjects
 *   trAvailabilityViolations($id)       -> already-scheduled classes that fall outside declared availability
 *
 * Teachers with NO registration data (e.g. added by hand in teachers.php) are never restricted.
 * If the treg_* tables don't exist yet, every function fails safe and returns "no restrictions".
 */

// Set to false if you want availability / subject mismatches to stop being blocking errors.
if (!defined('TR_ENFORCE_AVAILABILITY')) define('TR_ENFORCE_AVAILABILITY', true);
if (!defined('TR_ENFORCE_SUBJECTS'))     define('TR_ENFORCE_SUBJECTS', true);

function trDays(): array
{
    return ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday'];
}

/** "08:30", "08:30:00" -> 510 */
function trMinutes($time): int
{
    $p = explode(':', (string)$time);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

/** 510 -> "8:30 AM" */
function trClock(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $h12 = $h % 12 === 0 ? 12 : $h % 12;
    return sprintf('%d:%02d %s', $h12, $m, $h >= 12 ? 'PM' : 'AM');
}

/** Does [start,end] sit fully inside one declared window for that day? */
function trFits(array $availability, string $day, int $start, int $end): bool
{
    foreach ($availability[$day] ?? [] as $w) {
        if ($w['start'] <= $start && $w['end'] >= $end) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{name:string, availability:array<string,array<int,array{start:int,end:int}>>, subject_ids:int[], subjects:array}
 */
function trGetProfile(int $teacherId): array
{
    static $cache = [];
    if (isset($cache[$teacherId])) {
        return $cache[$teacherId];
    }
    $pdo = getDB();

    $availability = [];
    $st = $pdo->prepare("SELECT day_of_week, start_time, end_time FROM treg_availability WHERE teacher_id = ?
                          ORDER BY FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), start_time");
    $st->execute([$teacherId]);
    foreach ($st->fetchAll() as $r) {
        $availability[$r['day_of_week']][] = ['start' => trMinutes($r['start_time']), 'end' => trMinutes($r['end_time'])];
    }

    $st = $pdo->prepare("SELECT sub.id, sub.code, sub.title FROM treg_subjects ts
                           JOIN subjects sub ON sub.id = ts.subject_id WHERE ts.teacher_id = ? ORDER BY sub.code");
    $st->execute([$teacherId]);
    $subjects = $st->fetchAll();

    $st = $pdo->prepare('SELECT full_name FROM teachers WHERE id = ?');
    $st->execute([$teacherId]);

    return $cache[$teacherId] = [
        'name'         => (string)($st->fetchColumn() ?: 'This teacher'),
        'availability' => $availability,
        'subject_ids'  => array_map('intval', array_column($subjects, 'id')),
        'subjects'     => $subjects,
    ];
}

/**
 * Called from schedules.php with the same $slot array it already builds
 * (teacher_id, subject_id, day_of_week, start_time, end_time).
 * @return string[] human-readable problems; empty when the assignment fits the teacher's registration
 */
function checkTeacherRegistrationFit(array $slot): array
{
    $problems  = [];
    $teacherId = (int)($slot['teacher_id'] ?? 0);
    if ($teacherId <= 0) {
        return $problems;
    }

    try {
        $p = trGetProfile($teacherId);
    } catch (Throwable $ex) {
        error_log('Teacher registration check skipped: ' . $ex->getMessage());
        return $problems;
    }

    if (TR_ENFORCE_AVAILABILITY && !empty($p['availability'])) {
        $day   = (string)$slot['day_of_week'];
        $start = trMinutes($slot['start_time']);
        $end   = trMinutes($slot['end_time']);
        if (!trFits($p['availability'], $day, $start, $end)) {
            if (empty($p['availability'][$day])) {
                $problems[] = $p['name'] . ' did not list ' . (trDays()[$day] ?? $day) . ' as an available day';
            } else {
                $windows = array_map(fn($w) => trClock($w['start']) . ' to ' . trClock($w['end']), $p['availability'][$day]);
                $problems[] = $p['name'] . ' is only available on ' . (trDays()[$day] ?? $day) . ' ' . implode(' and ', $windows);
            }
        }
    }

    if (TR_ENFORCE_SUBJECTS && !empty($p['subject_ids']) && !in_array((int)($slot['subject_id'] ?? 0), $p['subject_ids'], true)) {
        $problems[] = $p['name'] . ' did not list this subject in their registration';
    }

    return $problems;
}

/**
 * Data for the "Registered availability & subjects" panel on the schedule form.
 * @return array<int, array{availability:array, subjects:string[]}>  keyed by teachers.id
 */
function trAllProfiles(): array
{
    try {
        $pdo = getDB();
        $raw = [];

        $rows = $pdo->query("SELECT teacher_id, day_of_week, start_time, end_time FROM treg_availability
                              ORDER BY teacher_id, FIELD(day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), start_time")->fetchAll();
        foreach ($rows as $r) {
            $raw[(int)$r['teacher_id']]['availability'][$r['day_of_week']][] =
                trClock(trMinutes($r['start_time'])) . ' to ' . trClock(trMinutes($r['end_time']));
        }

        $rows = $pdo->query("SELECT ts.teacher_id, sub.code, sub.title FROM treg_subjects ts
                               JOIN subjects sub ON sub.id = ts.subject_id ORDER BY ts.teacher_id, sub.code")->fetchAll();
        foreach ($rows as $r) {
            $raw[(int)$r['teacher_id']]['subjects'][] = $r['code'] . ' - ' . $r['title'];
        }

        $out = [];
        foreach ($raw as $tid => $p) {
            $avail = [];
            foreach (trDays() as $code => $label) {
                if (!empty($p['availability'][$code])) {
                    $avail[] = ['day' => $label, 'times' => implode(', ', $p['availability'][$code])];
                }
            }
            $out[$tid] = ['availability' => $avail, 'subjects' => $p['subjects'] ?? []];
        }
        return $out;
    } catch (Throwable $ex) {
        error_log('trAllProfiles failed: ' . $ex->getMessage());
        return [];
    }
}

/**
 * Active classes that fall OUTSIDE the teacher's declared availability
 * (e.g. the teacher shortened their hours after being scheduled).
 * @return array<int, array{day:string,start:string,end:string,subject:string,section:string}>
 */
function trAvailabilityViolations(int $teacherId): array
{
    try {
        $p = trGetProfile($teacherId);
        if (empty($p['availability'])) {
            return [];
        }
        $st = getDB()->prepare("SELECT s.day_of_week, s.start_time, s.end_time, sub.code AS subj_code, sec.name AS section_name
                                  FROM schedules s
                                  JOIN subjects sub ON sub.id = s.subject_id
                                  JOIN sections sec ON sec.id = s.section_id
                                 WHERE s.teacher_id = ? AND s.status = 'active'
                                 ORDER BY FIELD(s.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), s.start_time");
        $st->execute([$teacherId]);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (!trFits($p['availability'], $r['day_of_week'], trMinutes($r['start_time']), trMinutes($r['end_time']))) {
                $out[] = [
                    'day'     => trDays()[$r['day_of_week']] ?? $r['day_of_week'],
                    'start'   => trClock(trMinutes($r['start_time'])),
                    'end'     => trClock(trMinutes($r['end_time'])),
                    'subject' => $r['subj_code'],
                    'section' => $r['section_name'],
                ];
            }
        }
        return $out;
    } catch (Throwable $ex) {
        error_log('trAvailabilityViolations failed: ' . $ex->getMessage());
        return [];
    }
}
