<?php
/**
 * Фоновая синхронизация Langame (CLI).
 *
 * На reg.ru удобнее HTTP-cron (папка scripts закрыта .htaccess):
 *   https://ваш-сайт.ru/cron/langame?key=СЕКРЕТ_ИЗ_storage/cron.key
 * Ключ создаётся сам при первом запросе без ключа… нет — только при первом
 * успешном создании файла владельцем. Откройте раз через CLI:
 *   php scripts/cron-langame.php --init-key
 * и скопируйте storage/cron.key в URL cron-задачи (каждые 5 минут).
 *
 * CLI:
 *   php scripts/cron-langame.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$keyFile = dirname(__DIR__) . '/storage/cron.key';
if (in_array('--init-key', $argv ?? [], true) || !is_file($keyFile)) {
    $secret = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        if (@file_put_contents($keyFile, $secret . "\n", LOCK_EX) === false) {
            fwrite(STDERR, "Cannot write $keyFile\n");
            exit(1);
        }
        @chmod($keyFile, 0600);
    }
    fwrite(STDOUT, "Cron URL key: $secret\n");
    fwrite(STDOUT, "HTTP: /cron/langame?key=$secret\n");
    if (in_array('--init-key', $argv ?? [], true)) {
        exit(0);
    }
}

@set_time_limit(180);
$ids = Langame::enabledBranchIds();
$result = Langame::refreshNow($ids, true);
$line = sprintf(
    '[%s] langame cron: synced=%s live=%s errors=%d',
    date('c'),
    implode(',', $result['synced']) ?: '—',
    implode(',', $result['live']) ?: '—',
    count($result['errors']),
);
fwrite(STDOUT, $line . "\n");
foreach ($result['errors'] as $err) {
    fwrite(STDERR, $err . "\n");
}
exit($result['ok'] ? 0 : 1);
