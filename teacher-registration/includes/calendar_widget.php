<?php
/**
 * Read-only calendar for teachers. Included at the bottom of my_schedule.php.
 * Uses $rows (the teacher's classes for the selected term) already defined by my_schedule.php.
 * There is intentionally NO form or save code here: teachers cannot add or edit events.
 */
require_once __DIR__ . '/calendar_lib.php';
$__calRows = (isset($rows) && is_array($rows)) ? $rows : [];
$__term = (isset($selSem, $selSy) && $selSy !== '')
    ? (($semLabel[$selSem] ?? $selSem) . ', ' . $selSy) : '';
?>
<h2 class="section-title" style="margin-top:34px">Calendar</h2>
<p class="lede" style="margin-bottom:16px">
    Philippine holidays and school events posted by the Registrar<?= $__term ? '. Your classes are shown for ' . e($__term) : '' ?>.
    Select a day to see what is planned. This calendar is view only.
</p>
<?php calRenderWidget([
    'classes'    => calClassesByWeekday($__calRows),
    'canEdit'    => false,
    'classLabel' => 'Your classes',
    'termSem'    => $selSem ?? null,   // classes are drawn only between this semester's start and end dates
    'termSy'     => $selSy ?? null,
]); ?>
