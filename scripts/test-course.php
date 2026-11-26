<?php
declare(strict_types=1);

/**
 * scripts/test-course.php
 *
 * Проверка курса обучения без сервера: SQLite в памяти, импорт course_content/course.json,
 * последовательное открытие шагов, права редакторов, настройки курса, финальный экзамен,
 * удаление главы и защита правок с сайта от автоимпорта.
 *
 * Запуск: php scripts/test-course.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);

$tables = ['app_meta', 'portal_content', 'exams', 'exam_questions', 'exam_options', 'exam_attempts', 'exam_answers',
    'course_modules', 'course_chapters', 'course_steps', 'course_step_images', 'course_progress'];
foreach ($tables as $table) {
    $pdo->exec(Schema::sqliteCreate($table));
    foreach (Schema::indexes($table) as $name => $idx) {
        $pdo->exec('CREATE ' . ($idx['unique'] ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS "' . $name . '" ON "' . $table . '" ("' . implode('","', $idx['cols']) . '")');
    }
}

putenv('GAMERPRO_COURSE_IMAGES=' . sys_get_temp_dir() . '/gamerpro-course-test');

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('Провалена проверка: ' . $label);
    }
    $checks++;
}

try {
    $seed = Course::seed();
    check($seed !== null && $seed['version'] !== '', 'course_content/course.json читается');
    $seedSteps = 0;
    $seedChapters = 0;
    foreach ($seed['modules'] as $m) {
        foreach ($m['chapters'] as $c) {
            $seedChapters++;
            $seedSteps += count($c['steps']);
        }
    }

    $import = Course::importIfNeeded();
    check($import['imported'] === true, 'импорт выполнен');
    check($import['modules'] === count($seed['modules']), 'разделов столько же, сколько в файле');
    check($import['chapters'] === $seedChapters, 'глав: ' . $seedChapters . ' (получено ' . $import['chapters'] . ')');
    check($import['steps'] === $seedSteps, 'шагов: ' . $seedSteps . ' (получено ' . $import['steps'] . ')');

    $again = Course::importIfNeeded();
    check($again['imported'] === false && $again['reason'] === 'already', 'та же версия повторно не импортируется');

    $tree = Course::tree(true);
    check($tree[0]['kind'] === 'intro' && $tree[0]['title'] === 'Введение' && $tree[0]['number'] === 0, 'первый раздел — «Введение» без номера');
    check($tree[1]['title'] === 'Работа с ПО' && $tree[1]['number'] === 1, '«Работа с ПО» — модуль 1');
    check(Course::moduleLabel($tree[1]) === 'Модуль 1. Работа с ПО' && Course::moduleLabel($tree[0]) === 'Введение', 'подписи разделов');
    check($tree[count($tree) - 1]['kind'] === 'exam', 'последний раздел — экзамен');
    check($seedSteps === 206, 'в курсе 206 шагов, как в копии курса (получено ' . $seedSteps . ')');

    $chapters = Course::chapters();
    check($chapters[0]['number'] === '01', 'нумерация глав начинается с 01');
    $byTitle = [];
    foreach ($chapters as $c) {
        $byTitle[$c['title']] = $c;
    }
    foreach (['Бронирование' => 11, 'Игровые аккаунты' => 9, 'Заказы гостей' => 7, 'Взаимодействие с технической поддержкой' => 14, 'Типичные ошибки администратора' => 18] as $title => $count) {
        check(($byTitle[$title]['stepsCount'] ?? 0) === $count, '«' . $title . '» — ' . $count . ' шагов');
    }
    $ordersFull = Course::chapter((int) $byTitle['Заказы гостей']['id']);
    $lastHtml = (string) end($ordersFull['steps'])['contentHtml'];
    check(str_contains($lastHtml, 'data-type="cases"'), 'ситуации сохранились как карточки');
    check(!str_contains(json_encode($seed, JSON_UNESCAPED_UNICODE) ?: '', 'flip-card'), 'исходная разметка карточек не просочилась');
    $withImage = '';
    foreach ($ordersFull['steps'] as $s) {
        if (str_contains((string) $s['contentHtml'], '<img')) {
            $withImage = Course::renderHtml((string) $s['contentHtml']);
            break;
        }
    }
    check(str_contains($withImage, 'loading="lazy"') && preg_match('#ch\d{2}-\d+\.webp#', $withImage) === 1, 'картинка шага выводится через CourseMedia');
    check(!str_contains(Course::renderHtml('<p>ok</p><script>alert(1)</script>'), '<script'), 'скрипты вырезаются');

    /* ---------- последовательное прохождение ---------- */
    $userId = 'u-test';
    $flow = Course::flow($userId);
    check(count($flow['order']) === $seedSteps, 'в цепочке все шаги курса');
    check($flow['frontier'] === 0, 'новый сотрудник начинает с первого шага');
    $first = $flow['order'][0];
    $second = $flow['order'][1];
    check(Course::stepUnlocked($flow, $first['stepId']), 'первый шаг открыт');
    check(!Course::stepUnlocked($flow, $second['stepId']), 'второй шаг закрыт до завершения первого');
    check(Course::chapterState($flow, (int) $chapters[1]['id']) === 'locked', 'вторая глава закрыта');

    $jump = Course::completeStep($userId, $second['stepId']);
    check($jump['ok'] === false && $jump['reason'] === 'locked', 'закрытый шаг завершить нельзя');

    check(Course::markProgress($userId, $first['stepId'], 100)['isRead'] === false, 'скролл до конца сам шаг не завершает');
    $done = Course::completeStep($userId, $first['stepId']);
    check($done['ok'] === true && (int) $done['next']['stepId'] === $second['stepId'], '«Дальше» завершает шаг и ведёт к следующему');

    $flow = Course::flow($userId);
    check(Course::stepUnlocked($flow, $second['stepId']), 'после завершения открылся следующий шаг');
    check(Course::chapterState($flow, (int) $chapters[0]['id']) === 'current', 'глава в процессе, пока не пройдены все шаги');
    check(Course::completeStep($userId, $first['stepId'])['ok'] === true, 'пройденный шаг можно открыть повторно');
    check((int) Db::value('SELECT COUNT(*) FROM course_progress WHERE "userId" = ? AND "stepId" = ?', [$userId, $first['stepId']]) === 1, 'одна запись прогресса на шаг');

    $introSteps = (int) $chapters[0]['stepsCount'];
    foreach (array_slice($flow['order'], 1, $introSteps - 1) as $s) {
        Course::completeStep($userId, $s['stepId']);
    }
    $flow = Course::flow($userId);
    $nextFirst = $flow['order'][$introSteps];
    check(Course::chapterState($flow, (int) $chapters[0]['id']) === 'done', 'первая глава пройдена');
    check(Course::chapterState($flow, (int) $chapters[1]['id']) === 'current', 'вторая глава стала текущей');
    check(Course::chapterState($flow, (int) $chapters[2]['id']) === 'locked', 'третья глава ещё закрыта');

    $stats = Course::stats($userId);
    check($stats['stepsRead'] === $introSteps && $stats['chaptersDone'] === 1, 'сводка видит пройденные шаги и главу');
    check($stats['nextChapterId'] === (int) $chapters[1]['id'] && $stats['nextStepId'] === $nextFirst['stepId'], 'следующая цель — первый шаг второй главы');
    check(Course::completeStep('u-manager', $nextFirst['stepId'], true)['ok'] === true, 'руководитель может пройти шаг вне очереди');

    foreach (Course::flow($userId)['order'] as $s) {
        Course::completeStep($userId, $s['stepId']);
    }
    $stats = Course::stats($userId);
    check($stats['complete'] === true && $stats['percent'] === 100, 'после всех шагов курс пройден');
    check(Course::frontierStep(Course::flow($userId)) === null, 'текущего шага больше нет');

    /* ---------- права редакторов курса ---------- */
    $editor = ['id' => 'u-ed', 'perms' => ['course.edit']];
    $builder = ['id' => 'u-st', 'perms' => ['course.structure']];
    $full = ['id' => 'u-full', 'perms' => ['course.manage']];
    $plain = ['id' => 'u-plain', 'perms' => ['page.exams']];
    check(can_course($editor, 'edit') && !can_course($editor, 'structure') && !can_course($editor, 'settings'), '«тексты и фото» не даёт менять структуру и настройки');
    check(can_course($builder, 'structure') && !can_course($builder, 'edit'), '«модули и главы» не даёт править тексты');
    check(can_course($full, 'edit') && can_course($full, 'structure') && can_course($full, 'settings'), 'полный доступ покрывает все части');
    check(!can_course_any($plain) && can_course_any($editor), 'обычный сотрудник курс не редактирует');
    check(!can_course($full, 'unknown'), 'неизвестная часть курса не открывается');

    /* ---------- настройки курса ---------- */
    check(Course::settings()['passMode'] === 'strict' && Course::settings()['newYear'] === true, 'по умолчанию: строгий порядок, праздничное оформление включено');
    $saved = Course::saveSettings(['title' => '  Школа админов  ', 'passMode' => 'free', 'newYear' => false, 'requireInteractions' => false]);
    check($saved['title'] === 'Школа админов' && $saved['passMode'] === 'free' && $saved['newYear'] === false, 'настройки сохраняются и чистятся');
    check(Course::saveSettings(['passMode' => 'hack'])['passMode'] === 'strict', 'неизвестный режим прохождения → строгий');
    Course::saveSettings(['passMode' => 'free']);
    $fresh = Course::flow('u-new');
    $last = end($fresh['order']);
    check($fresh['free'] === true && Course::stepUnlocked($fresh, (int) $last['stepId']), 'свободный порядок: открыт даже последний шаг');
    check(Course::chapterState($fresh, (int) $last['chapterId']) === 'current', 'свободный порядок: глав «закрыто» нет');
    check(Course::completeStep('u-new', (int) $last['stepId'])['ok'] === true, 'свободный порядок: шаг можно пройти вне очереди');
    Course::saveSettings(['passMode' => 'strict', 'title' => '', 'newYear' => true, 'requireInteractions' => true]);
    check(Course::settings()['title'] === Course::SETTINGS_DEFAULTS['title'], 'пустое название возвращает стандартное');
    check(!Course::stepUnlocked(Course::flow('u-new2'), (int) $last['stepId']), 'строгий порядок снова закрывает шаги');

    /* ---------- фото в тексте шага ---------- */
    check(Course::normalizeImageSrc('<img src="/base/assets/course_images/step-ab12.webp?v=99" alt="x">') === '<img src="/assets/course_images/step-ab12.webp" alt="x">', 'адрес фото из редактора приводится к постоянному');
    check(Course::normalizeImageSrc('<img src="/media/course/step-ab12.png">') === '<img src="/assets/course_images/step-ab12.png">', 'адрес фото из storage приводится к постоянному');

    /* ---------- финальный экзамен ---------- */
    $examA = Db::insert('exams', ['title' => 'Финальный', 'slug' => 'final-test', 'passPercent' => 80, 'shuffle' => 0, 'isDraft' => 0, 'isFinal' => 1, 'sortOrder' => 1, 'xpReward' => 100, 'createdAt' => now_iso(), 'updatedAt' => now_iso()]);
    $examB = Db::insert('exams', ['title' => 'Другой', 'slug' => 'other-test', 'passPercent' => 70, 'shuffle' => 0, 'isDraft' => 0, 'isFinal' => 0, 'sortOrder' => 2, 'xpReward' => 50, 'createdAt' => now_iso(), 'updatedAt' => now_iso()]);
    Course::makeFinal($examB);
    check((int) Db::value('SELECT COUNT(*) FROM exams WHERE "isFinal" = 1') === 1, 'финальный экзамен ровно один');
    check((int) Course::finalExam()['id'] === $examB, 'финальным стал выбранный экзамен');
    check($examA !== $examB, 'экзамены различаются');

    /* ---------- удаление главы ---------- */
    $moduleId = (int) $chapters[0]['moduleId'];
    $chapterId = (int) $chapters[0]['id'];
    $nextId = Course::nextChapterId($chapterId);
    check($nextId !== null, 'у первой главы есть следующая');
    $wipe = new ReflectionMethod(Portal::class, 'courseWipeChapter');
    $wipe->setAccessible(true);
    $wipe->invoke(null, $chapterId);
    Course::reindexChapters($moduleId);
    check((int) Db::value('SELECT COUNT(*) FROM course_chapters WHERE id = ?', [$chapterId]) === 0, 'глава удалена');
    check((int) Db::value('SELECT COUNT(*) FROM course_steps WHERE "chapterId" = ?', [$chapterId]) === 0, 'шаги главы удалены');
    check((int) Db::value('SELECT COUNT(*) FROM course_progress WHERE "chapterId" = ?', [$chapterId]) === 0, 'прогресс по главе удалён');
    $after = Course::chapters();
    check(count($after) === count($chapters) - 1, 'после удаления глав на одну меньше');
    check($after[0]['number'] === '01' && (int) $after[0]['id'] === $nextId, 'нумерация пересчитана, первой стала следующая глава');
    check(Course::firstChapterIdOutsideModule($moduleId) !== null, 'после удаления раздела есть куда перейти');

    /* ---------- новая версия курса заменяет старую ---------- */
    Db::exec('UPDATE app_meta SET v = ? WHERE k = ?', ['old-version', 'course_seed_version']);
    $reimport = Course::importIfNeeded();
    check($reimport['imported'] === true && count(Course::chapters()) === count($chapters), 'новая версия файла переимпортирует курс целиком');
    check((int) Db::value('SELECT COUNT(*) FROM course_progress') === 0, 'прогресс по старым шагам сброшен');

    /* ---------- правки с сайта не затираются новой версией файла ---------- */
    Course::markCustomized();
    Db::exec('UPDATE app_meta SET v = ? WHERE k = ?', ['old-version', 'course_seed_version']);
    $guarded = Course::importIfNeeded();
    check($guarded['imported'] === false && $guarded['reason'] === 'customized', 'курс правили на сайте — автоимпорт не срабатывает');
    $manual = Course::importFromFiles(true);
    check($manual['imported'] === true && !Course::isCustomized(), 'импорт кнопкой заменяет курс и снимает отметку о правках');

    fwrite(STDOUT, '[course] OK: ' . $checks . ' проверок' . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[course] ОШИБКА: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
