<?php
// >>> PLACE AT: css-system/includes/csv_import.php (new)  [batch 13]
/**
 * Safe CSV upload reader for any future bulk import (subjects, sections, rooms, classes...).
 * The system has no import feature today; build new importers on this so they start out hardened.
 *
 * What it refuses: non-CSV files (including .xlsx/zip renamed to .csv), files over the size limit, NUL bytes,
 * non-UTF-8 text, unexpected or missing columns, too many rows, very long cells, control characters, and
 * cells that start with = or @ (or + - that are not numbers): these become formulas when the data is later opened in Excel.
 * It reads everything first and returns ALL errors with line numbers; insert only if there are none, inside one transaction.
 *
 * Example (subjects):
 *   $r = csvImportParse($_FILES['file'] ?? [], [
 *       'code'  => fn($v) => preg_match('/^[A-Z0-9-]{2,20}$/', $v) ? null : 'Code must be 2 to 20 letters, digits or dashes.',
 *       'title' => fn($v) => $v !== '' ? null : 'Title is required.',
 *       'units' => fn($v) => (is_numeric($v) && $v > 0 && $v <= 12) ? null : 'Units must be a number from 0.5 to 12.',
 *   ]);
 *   if (!$r['errors']) { $pdo->beginTransaction(); foreach ($r['rows'] as $row) { ...prepared INSERT... } $pdo->commit(); }
 *
 * @param array<string, callable(string):?string> $columns header name => validator returning an error text or null
 * @return array{rows:array<int,array<string,string>>, errors:string[]}
 */
function csvImportParse(array $file, array $columns, int $maxRows = 1000, int $maxBytes = 1048576): array
{
    $fail = fn(string $m): array => ['rows' => [], 'errors' => [$m]];

    if (!isset($file['error'], $file['tmp_name'], $file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return $fail('The upload failed. Choose the file again.');
    }
    if ((int)$file['size'] > $maxBytes) {
        return $fail('The file is too large (limit ' . round($maxBytes / 1048576, 1) . ' MB).');
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        return $fail('Only .csv files are accepted. In Excel use Save As, CSV UTF-8.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('The upload failed. Choose the file again.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
        return $fail('That does not look like a CSV text file.');
    }

    $raw = (string)file_get_contents($file['tmp_name']);
    if (substr($raw, 0, 2) === 'PK' || strpos($raw, "\0") !== false) {
        return $fail('That file is not plain CSV text (spreadsheets and zip files are not accepted).');
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        return $fail('The file must be saved as UTF-8 (Excel: CSV UTF-8).');
    }

    $h = fopen('php://temp', 'r+');
    fwrite($h, $raw);
    rewind($h);
    $header = fgetcsv($h, 0, ',', '"', '');
    $header = is_array($header) ? array_map(fn($c) => strtolower(trim((string)$c)), $header) : [];
    $want   = array_keys($columns);
    if (count($header) !== count($want) || array_diff($header, $want) || array_diff($want, $header)) {
        fclose($h);
        return $fail('The first row must contain exactly these columns: ' . implode(', ', $want) . '.');
    }

    $rows = [];
    $errors = [];
    $line = 1;
    while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $line++;
        if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
            continue;   // blank line
        }
        if (count($rows) >= $maxRows) {
            $errors[] = 'Too many rows (limit ' . $maxRows . ').';
            break;
        }
        if (count($cells) !== count($header)) {
            $errors[] = 'Line ' . $line . ': expected ' . count($header) . ' columns, found ' . count($cells) . '.';
            continue;
        }
        $row = [];
        foreach ($header as $i => $name) {
            $v = trim((string)$cells[$i]);
            $bad = null;
            if (mb_strlen($v) > 255) {
                $bad = 'is longer than 255 characters';
            } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
                $bad = 'contains control characters';
            } elseif ($v !== '' && (in_array($v[0], ['=', '@'], true) || (in_array($v[0], ['+', '-'], true) && !is_numeric($v)))) {
                $bad = 'starts with = + - or @ (spreadsheet formula risk)';
            } else {
                $bad = $columns[$name]($v);
                if ($bad !== null) { $bad = ': ' . $bad; }
            }
            if ($bad !== null) {
                $errors[] = 'Line ' . $line . ', column "' . $name . '" ' . ($bad[0] === ':' ? substr($bad, 1) : $bad);
                if (count($errors) >= 50) { break 2; }
            }
            $row[$name] = $v;
        }
        $rows[] = $row;
    }
    fclose($h);
    return ['rows' => $errors ? [] : $rows, 'errors' => $errors];
}

/** For CSV EXPORTS: stops Excel from running a cell that starts with = + - @ as a formula. */
function csvSafeCell($v): string
{
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
