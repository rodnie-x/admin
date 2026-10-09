<?php
/**
 * Calendar helpers, shared by the Class Scheduling System and the Teacher Registration portal.
 * Same file in both apps: includes/calendar_lib.php   (needs getDB() and e() from your existing includes)
 */

function calManilaToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function calEaster(int $y): DateTimeImmutable
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
}

/**
 * Philippine national holidays, date => [['n'=>name,'t'=>type], ...]
 * type: regular | special (non-working) | working (special working day: classes continue)
 * Eid'l Fitr / Eid'l Adha and one-off proclamations are NOT computed: the admin adds them as events.
 */
function calPhHolidays(int $fromYear, int $toYear): array
{
    $fixed = [
        ['01-01', "New Year's Day", 'regular'],
        ['04-09', 'Araw ng Kagitingan', 'regular'],
        ['05-01', 'Labor Day', 'regular'],
        ['06-12', 'Independence Day', 'regular'],
        ['11-30', 'Bonifacio Day', 'regular'],
        ['12-25', 'Christmas Day', 'regular'],
        ['12-30', 'Rizal Day', 'regular'],
        ['08-21', 'Ninoy Aquino Day', 'special'],
        ['11-01', "All Saints' Day", 'special'],
        ['12-08', 'Feast of the Immaculate Conception', 'special'],
        ['12-24', 'Christmas Eve', 'special'],
        ['12-31', "Last Day of the Year", 'special'],
    ];
    // Year-specific items from the yearly proclamation (2026: Proclamation No. 1006)
    $extras = [
        2026 => [
            ['02-17', 'Chinese New Year', 'special'],
            ['02-25', 'EDSA People Power Anniversary', 'working'],
            ['11-02', "All Souls' Day", 'special'],
        ],
    ];
    $out = [];
    $add = function (string $date, string $name, string $type) use (&$out) {
        $out[$date][] = ['n' => $name, 't' => $type];
    };
    for ($y = $fromYear; $y <= $toYear; $y++) {
        foreach ($fixed as [$md, $name, $type]) { $add("$y-$md", $name, $type); }
        foreach ($extras[$y] ?? [] as [$md, $name, $type]) { $add("$y-$md", $name, $type); }
        $easter = calEaster($y);
        $add($easter->modify('-3 days')->format('Y-m-d'), 'Maundy Thursday', 'regular');
        $add($easter->modify('-2 days')->format('Y-m-d'), 'Good Friday', 'regular');
        $add($easter->modify('-1 day')->format('Y-m-d'), 'Black Saturday', 'special');
        $add((new DateTimeImmutable("last monday of august $y"))->format('Y-m-d'), 'National Heroes Day', 'regular');
    }
    ksort($out);
    return $out;
}

/** Admin-posted events overlapping [$from, $to]. Returns [] if the table does not exist yet. */
function calLoadEvents(string $from, string $to): array
{
    try {
        $st = getDB()->prepare(
            'SELECT id, title, description, event_type, start_date, end_date, start_time, end_time, no_classes
               FROM calendar_events WHERE end_date >= ? AND start_date <= ? ORDER BY start_date, start_time'
        );
        $st->execute([$from, $to]);
        return array_map(fn($r) => [
            'id'    => (int)$r['id'],
            'title' => $r['title'],
            'desc'  => (string)$r['description'],
            'type'  => $r['event_type'],
            'start' => $r['start_date'],
            'end'   => $r['end_date'],
            'st'    => $r['start_time'] ? substr($r['start_time'], 0, 5) : '',
            'et'    => $r['end_time'] ? substr($r['end_time'], 0, 5) : '',
            'nc'    => (int)$r['no_classes'] === 1,
        ], $st->fetchAll());
    } catch (Throwable $ex) {
        error_log('calLoadEvents: ' . $ex->getMessage());
        return [];
    }
}

/** Weekly schedule rows -> [weekdayIndex(0=Sun) => [class, ...]] */
function calClassesByWeekday(array $rows): array
{
    $idx = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
    $out = [];
    foreach ($rows as $r) {
        if (!isset($idx[$r['day_of_week']])) { continue; }
        $out[$idx[$r['day_of_week']]][] = [
            's'       => substr($r['start_time'], 0, 5),
            'e'       => substr($r['end_time'], 0, 5),
            'code'    => $r['subj_code'] ?? '',
            'title'   => $r['subj_title'] ?? '',
            'section' => $r['section_name'] ?? '',
            'room'    => $r['room_name'] ?? '',
        ];
    }
    foreach ($out as &$list) { usort($list, fn($a, $b) => strcmp($a['s'], $b['s'])); }
    return (array)$out;
}

function calSemLabel(string $sem): string
{
    return ['1st' => '1st semester', '2nd' => '2nd semester', 'summer' => 'Summer'][$sem] ?? $sem;
}

/** All semester date ranges. Returns [] if the table does not exist yet. */
function calLoadTerms(): array
{
    try {
        return getDB()->query('SELECT id, semester, school_year, start_date, end_date FROM academic_terms ORDER BY start_date DESC')->fetchAll();
    } catch (Throwable $ex) {
        error_log('calLoadTerms: ' . $ex->getMessage());
        return [];
    }
}

/** Prints the calendar (month grid + day detail). opts: classes, canEdit, classLabel */
function calRenderWidget(array $opts = []): void
{
    $today = calManilaToday();
    $year  = (int)substr($today, 0, 4);
    // Semester start/end markers + the date range of the term whose classes are being shown
    $hol = calPhHolidays($year - 1, $year + 2);
    $termRange = null;
    foreach (calLoadTerms() as $t) {
        $label = calSemLabel($t['semester']) . ' ' . $t['school_year'];
        $hol[$t['start_date']][] = ['n' => $label . ' starts', 't' => 'term'];
        $hol[$t['end_date']][]   = ['n' => $label . ' ends', 't' => 'term'];
        if (isset($opts['termSem'], $opts['termSy']) && $t['semester'] === $opts['termSem'] && $t['school_year'] === $opts['termSy']) {
            $termRange = ['start' => $t['start_date'], 'end' => $t['end_date'], 'label' => $label];
        }
    }
    $data  = [
        'today'      => $today,
        'holidays'   => (object)$hol,
        'events'     => calLoadEvents(($year - 1) . '-01-01', ($year + 2) . '-12-31'),
        'classes'    => (object)($opts['classes'] ?? []),
        'canEdit'    => !empty($opts['canEdit']),
        'classLabel' => $opts['classLabel'] ?? 'Classes',
        'termRange'  => $termRange,
    ];
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
<link rel="stylesheet" href="assets/css/calendar.css">
<div class="cal-wrap">
    <div id="cal-root" class="cal-card" aria-label="Calendar"></div>
    <aside id="cal-detail" class="cal-card cal-detail" aria-live="polite"></aside>
</div>
<div class="cal-legend" aria-label="Legend">
    <span><i class="cal-dot h-regular"></i>Regular holiday</span>
    <span><i class="cal-dot h-special"></i>Special non-working day</span>
    <span><i class="cal-dot h-working"></i>Special working day</span>
    <span><i class="cal-dot e-event"></i>School event</span>
    <span><i class="cal-dot e-exam"></i>Exam</span>
    <span><i class="cal-dot e-suspension"></i>Class suspension</span>
    <span><i class="cal-dot h-term"></i>Semester start / end</span>
    <?php if (!empty($opts['classes'])): ?><span><i class="cal-dot c-on"></i>Your classes</span><?php endif; ?>
</div>
<script type="application/json" id="cal-data"><?= $json ?></script>
<script src="assets/js/calendar.js" defer></script>
    <?php
}
