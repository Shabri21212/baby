<?php
/*
 * Собирает базу SQLite из дампа localhost.sql, скачанного с боевого портала
 * («Управление» → «База данных» → «Скачать localhost.sql») или выгруженного из phpMyAdmin.
 *
 *   php scripts/import-mysql-dump.php путь/к/localhost.sql                    → storage/gamerpro.sqlite
 *   php scripts/import-mysql-dump.php путь/к/localhost.sql путь/к/базе.sqlite
 *
 * Данные переносятся как есть: учётные записи, смены, продажи и зашифрованные токены Langame.
 * Токены расшифруются, если рядом лежит тот же storage/langame.key, что и на сайте.
 * После переноса база доводится до текущей версии схемы — так же, как это делает портал при первом входе.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$source = $argv[1] ?? '';
$target = $argv[2] ?? $root . '/storage/gamerpro.sqlite';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Укажите файл дампа: php scripts/import-mysql-dump.php путь/к/localhost.sql\n");
    exit(1);
}
$sql = file_get_contents($source);
if ($sql === false || !str_contains($sql, 'INSERT INTO')) {
    fwrite(STDERR, "В файле нет данных: $source\n");
    exit(1);
}

/**
 * Читает кортежи «(…), (…);» после VALUES, начиная с $pos. Возвращает строки и ставит $pos за «;».
 * Строки MySQL экранированы обратной косой чертой, phpMyAdmin дополнительно удваивает кавычки.
 */
function read_tuples(string $sql, int &$pos): array
{
    static $escapes = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", 'b' => "\x08"];
    $len = strlen($sql);
    $rows = [];
    $row = null;
    while ($pos < $len) {
        $ch = $sql[$pos];
        if ($row === null) {
            if ($ch === '(') {
                $row = [];
            } elseif ($ch === ';') {
                $pos++;
                return $rows;
            }
            $pos++;
            continue;
        }
        if ($ch === ' ' || $ch === ',' || $ch === "\n" || $ch === "\r" || $ch === "\t") {
            $pos++;
            continue;
        }
        if ($ch === ')') {
            $rows[] = $row;
            $row = null;
            $pos++;
            continue;
        }
        if ($ch === "'") {
            $value = '';
            $pos++;
            while ($pos < $len) {
                $c = $sql[$pos];
                if ($c === '\\' && $pos + 1 < $len) {
                    $next = $sql[$pos + 1];
                    $value .= $escapes[$next] ?? $next;
                    $pos += 2;
                } elseif ($c === "'") {
                    if (($sql[$pos + 1] ?? '') === "'") {
                        $value .= "'";
                        $pos += 2;
                        continue;
                    }
                    $pos++;
                    break;
                } else {
                    $run = strcspn($sql, "\\'", $pos);
                    $value .= substr($sql, $pos, $run);
                    $pos += $run;
                }
            }
            $row[] = $value;
            continue;
        }
        $run = strcspn($sql, ",)", $pos);
        $token = trim(substr($sql, $pos, $run));
        $pos += $run;
        $row[] = strcasecmp($token, 'NULL') === 0 ? null : (preg_match('/^-?\d+$/', $token) ? (int) $token : $token);
    }
    throw new RuntimeException('Дамп оборван: не найден конец INSERT');
}

$tmp = $target . '.import';
@unlink($tmp);
$pdo = new PDO('sqlite:' . $tmp, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
foreach (array_keys(SCHEMA) as $table) {
    $pdo->exec(Schema::sqliteCreate($table));
}

$counts = [];
$skipped = [];
$pos = 0;
$pdo->beginTransaction();
while (preg_match('/^INSERT INTO `([A-Za-z0-9_]+)` \(([^)]*)\) VALUES\s*/m', $sql, $m, PREG_OFFSET_CAPTURE, $pos)) {
    $table = $m[1][0];
    $pos = $m[0][1] + strlen($m[0][0]);
    $cols = array_map(static fn (string $c): string => trim($c, " `\n\r\t"), explode(',', $m[2][0]));
    $rows = read_tuples($sql, $pos);
    if (!isset(SCHEMA[$table])) {
        $skipped[$table] = ($skipped[$table] ?? 0) + count($rows);
        continue;
    }
    // Колонки, которых нет в текущей схеме, пропускаются; новых колонок в дампе нет — они получат значения по умолчанию.
    $keep = [];
    foreach ($cols as $i => $col) {
        if (isset(SCHEMA[$table]['columns'][$col])) {
            $keep[$i] = $col;
        }
    }
    $st = $pdo->prepare('INSERT OR REPLACE INTO "' . $table . '" ("' . implode('","', $keep) . '") VALUES (' . implode(',', array_fill(0, count($keep), '?')) . ')');
    foreach ($rows as $row) {
        if (count($row) !== count($cols)) {
            throw new RuntimeException("Таблица $table: в строке " . count($row) . ' значений, а колонок ' . count($cols));
        }
        $n = 1;
        foreach ($keep as $i => $_) {
            $v = $row[$i];
            $st->bindValue($n++, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $st->execute();
    }
    $counts[$table] = ($counts[$table] ?? 0) + count($rows);
}
$pdo->commit();

if (!$counts) {
    @unlink($tmp);
    fwrite(STDERR, "Не удалось прочитать ни одной таблицы портала из $source\n");
    exit(1);
}

$from = Schema::storedVersion($pdo);
Schema::migrate($pdo, 'sqlite');
$to = Schema::storedVersion($pdo);
if ($to !== SCHEMA_VERSION) {
    @unlink($tmp);
    fwrite(STDERR, "Схема не обновилась до версии " . SCHEMA_VERSION . " (осталась $to) — база не заменена\n");
    exit(1);
}
$pdo->exec('VACUUM');
$pdo = null;
if (!rename($tmp, $target)) {
    fwrite(STDERR, "Не удалось записать $target\n");
    exit(1);
}

$total = array_sum($counts);
fwrite(STDOUT, 'Перенесено записей: ' . number_format($total, 0, '.', ' ') . ' в ' . count($counts) . " таблицах. Схема: версия $from → $to.\n");
foreach ($counts as $table => $n) {
    fwrite(STDOUT, sprintf("  %-28s %7d\n", $table, $n));
}
foreach ($skipped as $table => $n) {
    fwrite(STDOUT, sprintf("  %-28s %7d  (нет в схеме портала — пропущено)\n", $table, $n));
}
fwrite(STDOUT, "База: $target\n");
