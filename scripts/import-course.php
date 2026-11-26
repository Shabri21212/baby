<?php
declare(strict_types=1);

/**
 * scripts/import-course.php
 *
 * Импорт курса из course_content/course.json в базу.
 * На сайте то же самое происходит автоматически при открытии «Обучения»:
 * версия файла сравнивается с app_meta.course_seed_version, при смене версии
 * разделы, главы, шаги и прогресс по ним заменяются целиком.
 *
 * Запуск:
 *   php scripts/import-course.php            # импорт, если версия файла новая
 *   php scripts/import-course.php --force    # переимпортировать текущую версию
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);
$result = $force ? Course::importFromFiles(true) : Course::importIfNeeded();

if (!empty($result['imported'])) {
    fwrite(STDOUT, '[course] импорт готов: разделов ' . $result['modules']
        . ', глав ' . $result['chapters'] . ', шагов ' . $result['steps']
        . ', картинок ' . $result['images'] . PHP_EOL);
    exit(0);
}

if (($result['reason'] ?? '') === 'already') {
    fwrite(STDOUT, '[course] эта версия курса уже импортирована' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, '[course] импорт не выполнен: ' . $result['reason'] . PHP_EOL);
exit(1);
