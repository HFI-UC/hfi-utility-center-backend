<?php

declare(strict_types=1);

use Hfiuc\Bootstrap;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import must run from the CLI\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $directory = substr($arg, 6);
    }
}
if ($directory === null || !is_dir($directory)) {
    fwrite(STDERR, "Usage: php bin/import-export.php --dir=<csv directory>\n");
    fwrite(STDERR, "Export each PostgreSQL table to a headered CSV named {table}.csv first.\n");
    exit(1);
}

$tables = [
    'campus' => ['columns' => ['id', 'name', 'isPrivileged', 'createdAt'], 'bool' => ['isPrivileged'], 'time' => ['createdAt'], 'nullable' => ['createdAt']],
    'class' => ['columns' => ['id', 'name', 'campusId', 'createdAt'], 'bool' => [], 'time' => ['createdAt'], 'nullable' => ['campusId', 'createdAt']],
    'room' => ['columns' => ['id', 'name', 'campusId', 'enabled', 'createdAt'], 'bool' => ['enabled'], 'time' => ['createdAt'], 'nullable' => ['campusId', 'enabled', 'createdAt']],
    'roompolicy' => ['columns' => ['id', 'roomId', 'days', 'startTime', 'endTime', 'enabled'], 'bool' => ['enabled'], 'time' => [], 'json' => ['days', 'startTime', 'endTime'], 'nullable' => []],
    'admin' => ['columns' => ['id', 'name', 'email', 'password', 'receiveReservationNotifications', 'createdAt'], 'bool' => ['receiveReservationNotifications'], 'time' => ['createdAt'], 'nullable' => ['createdAt']],
    'roomapprover' => ['columns' => ['roomId', 'adminId'], 'bool' => [], 'time' => [], 'nullable' => []],
    'adminlogin' => ['columns' => ['id', 'email', 'cookie', 'expiry'], 'bool' => [], 'time' => ['expiry'], 'nullable' => []],
    'tempadminlogin' => ['columns' => ['id', 'token', 'email', 'createdAt'], 'bool' => [], 'time' => ['createdAt'], 'nullable' => []],
    'reservation' => ['columns' => ['id', 'roomId', 'classId', 'startTime', 'endTime', 'studentName', 'studentId', 'email', 'reason', 'status', 'purposeType', 'needsMultimedia', 'editCount', 'latestExecutorId', 'cancelledAt', 'createdAt'], 'bool' => ['needsMultimedia'], 'time' => ['startTime', 'endTime', 'cancelledAt', 'createdAt'], 'nullable' => ['roomId', 'classId', 'studentId', 'purposeType', 'latestExecutorId', 'cancelledAt']],
    'reservationcanceltoken' => ['columns' => ['id', 'reservationId', 'tokenHash', 'expiresAt', 'usedAt', 'createdAt'], 'bool' => [], 'time' => ['expiresAt', 'usedAt', 'createdAt'], 'nullable' => ['usedAt']],
    'reservationoperationlog' => ['columns' => ['id', 'adminId', 'reservationId', 'operation', 'reason', 'createdAt'], 'bool' => [], 'time' => ['createdAt'], 'nullable' => ['adminId', 'reason']],
    'outboxjob' => ['columns' => ['id', 'kind', 'payload', 'status', 'attempts', 'availableAt', 'lockedAt', 'lockToken', 'lastError', 'createdAt', 'completedAt'], 'bool' => [], 'time' => ['availableAt', 'lockedAt', 'createdAt', 'completedAt'], 'json' => ['payload'], 'nullable' => ['lockedAt', 'lockToken', 'lastError', 'completedAt']],
    'announcement' => ['columns' => ['id', 'title', 'content', 'enabled', 'updatedAt', 'updatedBy'], 'bool' => ['enabled'], 'time' => ['updatedAt'], 'nullable' => ['updatedBy']],
    'analytic' => ['columns' => ['id', 'date', 'reservations', 'reservationCreations', 'requests', 'approvals', 'rejections'], 'bool' => [], 'time' => ['date'], 'nullable' => []],
];

try {
    $db = Bootstrap::database();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$pdo = $db->pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (array_reverse(array_keys($tables)) as $table) {
    $pdo->exec('TRUNCATE TABLE `' . $table . '`');
}

$summary = [];
foreach ($tables as $table => $meta) {
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $table . '.csv';
    if (!is_file($path)) {
        $summary[$table] = 'missing';
        continue;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot read $path\n");
        exit(1);
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        $summary[$table] = 0;
        continue;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $columns = array_map(static fn (string|null $name): string => trim((string) $name, " \t\n\r\0\x0B\""), $header);
    $seen = [];
    $count = 0;
    $skipped = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $record = [];
        foreach ($columns as $index => $column) {
            if ($column === '') {
                continue;
            }
            $record[$column] = $row[$index] ?? '';
        }
        if ($table === 'roomapprover') {
            $key = ($record['roomId'] ?? '') . ':' . ($record['adminId'] ?? '');
            if (isset($seen[$key])) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;
        }
        if ($table === 'outboxjob' && ($record['status'] ?? '') === 'processing') {
            $record['status'] = 'pending';
            $record['lockToken'] = '';
        }
        $insertColumns = [];
        $values = [];
        foreach ($record as $column => $raw) {
            if (!in_array($column, $meta['columns'], true)) {
                continue;
            }
            $insertColumns[] = '`' . str_replace('`', '', $column) . '`';
            $values[] = convert($raw, $column, $meta);
        }
        if ($insertColumns === []) {
            continue;
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $insertColumns) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        $count++;
    }
    fclose($handle);
    $summary[$table] = $skipped > 0 ? $count . ' inserted, ' . $skipped . ' duplicate roomapprover skipped' : (string) $count;
    if (in_array('id', $columns, true)) {
        $max = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS nextId FROM `' . $table . '`')->fetch();
        $next = (int) ($max['nextId'] ?? 1);
        $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . max(1, $next));
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

foreach ($summary as $table => $result) {
    fwrite(STDOUT, $table . ': ' . $result . "\n");
}

/** @param array{bool?: list<string>, time?: list<string>, json?: list<string>, nullable?: list<string>} $meta */
function convert(string $raw, string $column, array $meta): mixed
{
    $value = trim($raw);
    $nullable = in_array($column, $meta['nullable'] ?? [], true);
    $isBool = in_array($column, $meta['bool'] ?? [], true);
    $isTime = in_array($column, $meta['time'] ?? [], true);
    $isJson = in_array($column, $meta['json'] ?? [], true);
    if ($value === '' || $value === '\\N' || strcasecmp($value, 'null') === 0) {
        if ($nullable) {
            return null;
        }
        if ($isBool) {
            return 0;
        }
        if ($isJson) {
            return '[]';
        }

        return '';
    }
    if ($isBool) {
        return in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes'], true) ? 1 : 0;
    }
    if ($isTime) {
        $value = str_replace('T', ' ', $value);
        $value = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $value) ?? $value;

        return substr($value, 0, 19);
    }
    if ($isJson) {
        $decoded = json_decode($value, true);
        if (is_string($decoded)) {
            $nested = json_decode($decoded, true);
            if (is_array($nested)) {
                return (string) json_encode($nested, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (is_array($decoded)) {
            return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    return $value;
}
