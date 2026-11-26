<?php
declare(strict_types=1);

require_once __DIR__ . '/course-editor.php';

/**
 * Страницы курса обучения: хаб и чтение главы. Редактор и настройки — в course-editor.php.
 * Сетка урока повторяет design_ref.html: контент + правая колонка-подсказки.
 */
trait PagesCourse
{
    use PagesCourseEditor;

    /** Что текущему пользователю можно в редакторе курса (заполняется в начале страницы). */
    private static array $courseCaps = ['edit' => false, 'structure' => false, 'settings' => false, 'full' => false];

    private static function courseCapsFor(array $user): array
    {
        return self::$courseCaps = [
            'edit' => can_course($user, 'edit'),
            'structure' => can_course($user, 'structure'),
            'settings' => can_course($user, 'settings'),
            'full' => can($user, 'course.manage'),
        ];
    }

    private static function pageCourse(array $user): void
    {
        $manage = can_course_any($user);
        $caps = self::courseCapsFor($user);
        $imported = Course::importIfNeeded();
        if (!empty($imported['imported']) && $manage) {
            flash_set('ok', 'Курс загружен из файла: разделов ' . $imported['modules'] . ', глав ' . $imported['chapters'] . ', шагов ' . $imported['steps']);
        }
        $settings = Course::settings();
        $modules = Course::tree($manage);
        $flow = Course::flow((string) $user['id']);
        $stats = Course::stats((string) $user['id']);
        $chapterStats = Course::chapterStats((string) $user['id']);
        $final = Course::finalExam(!$manage);
        $examProgress = $final !== null
            ? self::examMyProgress((int) $final['id'], (string) $user['id'])
            : ['status' => null, 'bestScore' => null, 'attemptCount' => 0, 'openAttemptId' => null, 'bestAttemptId' => null];
        $next = $stats['nextChapterId'] !== null ? Course::chapter($stats['nextChapterId'], $manage) : null;

        $actions = '';
        if ($caps['structure']) {
            $actions .= '<button type="button" class="' . btn_class() . '" data-dialog-open="course-module-new">' . icon('plus', 'size-4') . 'Модуль</button>';
        }
        if ($caps['full'] && !$modules && !Course::isImported()) {
            $actions .= form_open('import_course') . '<button class="' . btn_class('outline') . '">' . icon('download', 'size-4') . 'Импорт курса</button></form>';
        }
        if ($caps['settings']) {
            $actions .= '<a class="' . btn_class('outline') . '" href="' . h(url_to('/exams/settings')) . '">' . icon('sliders', 'size-4') . 'Настройки</a>';
        }
        $actions .= '<a class="' . btn_class($manage ? 'outline' : 'ghost') . '" href="' . h(url_to('/exams/tests')) . '">' . icon('clipboard-check', 'size-4') . ($manage ? 'Тесты' : 'Практические тесты') . '</a>';
        $html = page_head($settings['title'], $settings['subtitle'], $actions, null);

        if ($caps['full'] && ($imported['reason'] ?? '') === 'customized') {
            $html .= '<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm">';
            $html .= '<p class="flex min-w-0 items-start gap-2 leading-relaxed text-muted-foreground">' . icon('info', 'size-4 mt-0.5 shrink-0') . '<span>В файле курса есть новая версия. Курс правили на сайте, поэтому сам он не обновился — импорт заменит правки и сбросит прогресс.</span></p>';
            $html .= form_open('import_course', 'class="inline-flex" ' . confirm_attr('Заменить курс версией из файла? Правки, сделанные на сайте, и прогресс сотрудников пропадут.')) . hidden('force', 1) . '<button class="' . btn_class('outline', 'sm') . '">' . icon('download', 'size-4') . 'Импортировать файл</button></form></div>';
        }

        $continueHref = $next !== null ? Course::chapterUrl((int) $next['id'], $stats['nextStepId']) : url_to('/exams');
        $hero = '<div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">';
        $hero .= '<div class="flex items-center gap-5"><div class="cut flex size-20 shrink-0 flex-col items-center justify-center bg-primary text-primary-foreground" style="--cut:14px"><span class="text-[10px] font-semibold tracking-[0.18em] uppercase">Курс</span><span class="font-display text-3xl leading-none font-semibold tabular-nums">' . $stats['percent'] . '</span></div>';
        $hero .= '<div class="flex flex-col gap-1"><p class="font-display text-2xl font-semibold tabular-nums">' . $stats['stepsRead'] . ' <span class="text-base text-sidebar-muted">/ ' . $stats['stepsTotal'] . ' шагов</span></p>';
        $hero .= '<p class="text-sm text-sidebar-foreground">' . ($stats['complete']
            ? ($final !== null ? 'Все шаги прочитаны — финальный экзамен открыт' : 'Все шаги прочитаны — экзамен ещё готовится')
            : ($next !== null ? 'Следующая цель: глава «' . h((string) $next['title']) . '»' : 'Осталось прочитать главы')) . '</p></div></div>';
        $hero .= '<dl class="course-hero__stats"><div><dt class="text-sidebar-muted">Главы</dt><dd class="font-display text-xl font-semibold tabular-nums">' . $stats['chaptersDone'] . ' из ' . $stats['chaptersTotal'] . '</dd></div>';
        $hero .= '<div><dt class="text-sidebar-muted">Разделы</dt><dd class="font-display text-xl font-semibold tabular-nums">' . $stats['modulesDone'] . ' из ' . $stats['modulesTotal'] . '</dd></div>';
        $hero .= '<div><dt class="text-sidebar-muted">Финальный экзамен</dt><dd class="font-display text-xl font-semibold">' . ($final === null ? '—' : ($stats['complete'] ? 'открыт' : 'закрыт')) . '</dd></div></dl></div>';
        $hero .= '<div class="course-hero__bar" role="progressbar" aria-label="Прогресс курса" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $stats['percent'] . '"><div class="course-hero__bar-fill" style="width:' . $stats['percent'] . '%"></div></div>';
        $hero .= '<div class="flex flex-wrap items-center gap-3">';
        if (!$stats['complete'] && $next !== null) {
            $hero .= '<a class="' . btn_class('primary') . '" href="' . h($continueHref) . '">' . icon('book', 'size-4') . 'Продолжить обучение</a>';
        } elseif ($stats['complete'] && $final !== null) {
            $hero .= '<a class="' . btn_class('primary') . '" href="' . h(url_to('/exams/' . rawurlencode((string) $final['slug']))) . '">' . icon('grad', 'size-4') . 'Финальный экзамен</a>';
        }
        $hero .= '<span class="text-xs text-sidebar-muted">Шаги открываются по порядку: следующий — после завершения текущего</span></div>';
        $art = $settings['newYear'] ? newyear_art('course') : '';
        $html .= '<section class="cut hero-panel flex flex-col gap-5 p-5 sm:p-7" style="--cut:22px" aria-label="Ваш прогресс">' . $art . $hero . '</section>';

        if (!$modules) {
            if ($manage && Course::isImported()) {
                $emptyAction = '<button type="button" class="' . btn_class() . '" data-dialog-open="course-module-new">' . icon('plus', 'size-4') . 'Новый раздел</button>';
            } elseif ($manage) {
                $emptyAction = form_open('import_course') . '<button class="' . btn_class() . '">' . icon('download', 'size-4') . 'Импортировать главы</button></form>';
            } else {
                $emptyAction = '';
            }
            $html .= empty_state('grad', 'Курс пока пуст', $manage ? 'Импортируйте курс из course_content/course.json или создайте раздел вручную.' : 'Когда руководитель наполнит курс, главы появятся здесь.', $emptyAction);
        }

        $moduleNumber = 0;
        $hasExamModule = false;
        foreach ($modules as $module) {
            $moduleNumber++;
            if (($module['kind'] ?? 'lessons') === 'exam') {
                $hasExamModule = true;
            }
            $html .= self::courseModuleCard($module, $moduleNumber, count($modules), $chapterStats, $stats, $manage, $examProgress, $final, $flow);
        }

        // Раздел экзамена могли удалить, а сам финальный экзамен остался — показываем его отдельной карточкой.
        if (!$hasExamModule && $final !== null) {
            $html .= card(
                card_head('Финальный экзамен', 'Финальный экзамен курса', '', 'grad')
                . '<div class="mt-4">' . self::courseFinalExamCard($stats, $examProgress, $manage, $final) . '</div>',
                'p-5',
            );
        }

        if ($final === null && $manage) {
            $html .= empty_state(
                'grad',
                'Финальный экзамен не выбран',
                'Откройте тест в разделе «Тесты» и включите в редакторе флажок «Финальный экзамен курса».',
                '<a class="' . btn_class('outline') . '" href="' . h(url_to('/exams/tests')) . '">' . icon('clipboard-check', 'size-4') . 'К тестам</a>',
            );
        }

        if ($caps['structure']) {
            $html .= self::courseModuleDialog('course-module-new', null);
            foreach ($modules as $module) {
                $html .= self::courseModuleDialog('course-module-' . (int) $module['id'], $module);
            }
        }

        self::layout($user, $html, ['title' => $settings['title'], 'styles' => ['css/course.css']]);
    }

    /** Карточка раздела: прогресс, главы и (для админа) кнопки управления. */
    private static function courseModuleCard(array $module, int $number, int $total, array $chapterStats, array $stats, bool $manage, array $examProgress, ?array $final, array $flow): string
    {
        $isExam = ($module['kind'] ?? 'lessons') === 'exam';
        $chaptersTotal = 0;
        $chaptersRead = 0;
        foreach ($module['chapters'] as $c) {
            $chaptersTotal++;
            if ((int) $c['stepsCount'] > 0 && (int) ($chapterStats[$c['id']]['read'] ?? 0) >= (int) $c['stepsCount']) {
                $chaptersRead++;
            }
        }
        $percent = $chaptersTotal > 0 ? (int) round($chaptersRead / $chaptersTotal * 100) : 0;

        $head = '<div class="flex flex-wrap items-start justify-between gap-3">';
        $shown = (int) ($module['number'] ?? $number);
        $badge = $shown > 0 ? str_pad((string) $shown, 2, '0', STR_PAD_LEFT) : icon('book', 'size-4');
        $kicker = $shown > 0 ? 'Модуль ' . $shown : 'Перед началом';
        $head .= '<div class="flex min-w-0 items-start gap-3"><span class="cut flex size-10 shrink-0 items-center justify-center bg-muted font-display text-sm font-semibold tabular-nums" style="--cut:8px">' . $badge . '</span>';
        $head .= '<div class="flex min-w-0 flex-col gap-1"><p class="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">' . $kicker . ($module['isActive'] ? '' : ' · скрыт') . '</p>';
        $head .= '<h2 class="font-display text-lg leading-snug font-semibold text-pretty">' . h((string) $module['title']) . '</h2>';
        if (trim((string) $module['summary']) !== '') {
            $head .= '<p class="max-w-xl text-sm leading-relaxed text-muted-foreground">' . h((string) $module['summary']) . '</p>';
        }
        $head .= '<p class="text-xs text-muted-foreground">' . $chaptersTotal . ' ' . plural_word($chaptersTotal, 'глава', 'главы', 'глав') . ' · ' . (int) $module['stepsCount'] . ' ' . plural_word((int) $module['stepsCount'], 'шаг', 'шага', 'шагов') . '</p>';
        $head .= '</div></div>';
        if (self::$courseCaps['structure']) {
            $head .= self::courseModuleActions($module, $number, $total);
        }
        $head .= '</div>';

        if ($isExam) {
            $body = self::courseFinalExamCard($stats, $examProgress, $manage, $final);
        } elseif ($module['chapters']) {
            $body = '<div class="flex items-center gap-3">' . progress_bar($percent, 'Прогресс раздела', 'flex-1') . '<span class="text-xs font-semibold text-muted-foreground tabular-nums">' . $percent . '%</span></div>';
            $body .= '<ol class="course-rail">';
            foreach ($module['chapters'] as $chapter) {
                $body .= self::courseChapterRow($chapter, $chapterStats, $stats, $manage, $flow);
            }
            $body .= '</ol>';
        } else {
            $body = '<p class="text-sm text-muted-foreground">В модуле пока нет глав.' . (self::$courseCaps['structure'] ? ' Добавьте первую кнопкой «Глава».' : '') . '</p>';
        }
        return card($head . '<div class="mt-4 flex flex-col gap-3">' . $body . '</div>', 'p-5');
    }

    /** Строка главы в списке раздела. */
    private static function courseChapterRow(array $chapter, array $chapterStats, array $stats, bool $manage, array $flow): string
    {
        $id = (int) $chapter['id'];
        $total = (int) $chapter['stepsCount'];
        $read = (int) ($chapterStats[$id]['read'] ?? 0);
        $flowState = Course::chapterState($flow, $id);
        $isNext = (int) ($stats['nextChapterId'] ?? 0) === $id;
        $locked = $flowState === 'locked' && !$manage;
        $state = match (true) {
            $total === 0 => chip('Пустая', 'outline'),
            $flowState === 'done' => chip('Пройдено', 'ink', 'check'),
            $flowState === 'current' => chip($read > 0 ? 'Шаг ' . ($read + 1) . ' из ' . $total : 'Открыта', 'day', 'book'),
            default => chip('Закрыта', 'outline', 'lock'),
        };
        $row = '<li class="course-rail__item' . ($isNext ? ' is-next' : '') . ($locked ? ' is-locked' : '') . ($chapter['isActive'] ? '' : ' is-hidden') . '">';
        $inner = '<span class="course-rail__num">' . h((string) $chapter['number']) . '</span>';
        $inner .= '<span class="course-rail__body"><span class="course-rail__title">' . h((string) $chapter['title']) . '</span>';
        $inner .= '<span class="course-rail__meta">' . plural($total, 'шаг', 'шага', 'шагов') . ($locked ? ' · откроется после предыдущих глав' : '') . '</span></span>';
        $inner .= '<span class="course-rail__state">' . $state . '</span>';
        $row .= $locked
            ? '<span class="course-rail__link" aria-disabled="true">' . $inner . '</span>'
            : '<a class="course-rail__link" href="' . h(Course::chapterUrl($id, $isNext ? $stats['nextStepId'] : null)) . '">' . $inner . '</a>';
        if (self::$courseCaps['edit'] || self::$courseCaps['structure']) {
            $row .= self::courseChapterActions($chapter);
        }
        return $row . '</li>';
    }

    /** Узел финального экзамена: доступен после прочтения всех шагов. */
    private static function courseFinalExamCard(array $stats, array $examProgress, bool $manage, ?array $final): string
    {
        if ($final === null) {
            return '<p class="text-sm leading-relaxed text-muted-foreground">' . ($manage
                ? 'Выберите финальный экзамен: «Тесты» → редактор экзамена → флажок «Финальный экзамен курса».'
                : 'Экзамен ещё готовится — загляните позже.') . '</p>';
        }
        $passed = ($examProgress['status'] ?? '') === 'passed';
        $unlocked = $stats['complete'] || $manage;
        $href = url_to('/exams/' . rawurlencode((string) $final['slug']));
        $questions = (int) Db::value('SELECT COUNT(*) FROM exam_questions WHERE "examId" = ?', [(int) $final['id']]);
        $inner = '<div class="flex flex-wrap items-center justify-between gap-3">';
        $inner .= '<div class="flex min-w-0 items-center gap-3"><span class="icon-tile size-10">' . icon('grad', 'size-5') . '</span>';
        $inner .= '<div class="flex min-w-0 flex-col"><p class="font-display text-base font-semibold">' . h((string) $final['title']) . '</p>';
        $inner .= '<p class="text-xs text-muted-foreground">' . plural($questions, 'вопрос', 'вопроса', 'вопросов') . ' · проходной ' . (int) $final['passPercent'] . '% · +' . (int) $final['xpReward'] . ' XP</p></div></div>';
        $inner .= '<div class="flex flex-wrap items-center gap-2">';
        if ($passed) {
            $inner .= chip('Сдан · ' . (int) ($examProgress['bestScore'] ?? 0) . '%', 'ink', 'check');
        } elseif (!$unlocked) {
            $inner .= chip('Закрыт до прочтения глав', 'outline', 'lock');
        } else {
            $inner .= chip('Можно сдавать', 'day', 'clock');
        }
        if ($unlocked) {
            $label = $passed ? 'Пересдать' : (($examProgress['attemptCount'] ?? 0) > 0 ? 'Попробовать снова' : 'Сдать экзамен');
            $inner .= '<a class="' . btn_class($passed ? 'outline' : 'primary', 'sm') . '" href="' . h($href) . '">' . h($label) . icon('arrow', 'size-4') . '</a>';
        } else {
            $inner .= '<span class="' . btn_class('outline', 'sm') . ' pointer-events-none opacity-50">' . icon('lock', 'size-4') . 'Закрыт</span>';
        }
        return $inner . '</div></div>';
    }

    /** Кнопки управления разделом (только course.manage). */
    private static function courseModuleActions(array $module, int $number, int $total): string
    {
        $id = (int) $module['id'];
        $small = btn_class('outline', 'xs');
        $iconBtn = btn_class('quiet', 'icon-sm');
        $html = '<div class="flex flex-wrap items-center gap-1">';
        $html .= form_open('move_course_module', 'class="inline-flex"') . hidden('id', $id) . hidden('dir', -1) . '<button class="' . $iconBtn . '"' . ($number <= 1 ? ' disabled' : '') . ' aria-label="Раздел выше" title="Выше">' . icon('chevronup', 'size-4') . '</button></form>';
        $html .= form_open('move_course_module', 'class="inline-flex"') . hidden('id', $id) . hidden('dir', 1) . '<button class="' . $iconBtn . '"' . ($number >= $total ? ' disabled' : '') . ' aria-label="Раздел ниже" title="Ниже">' . icon('chevrondown', 'size-4') . '</button></form>';
        $html .= '<button type="button" class="' . $small . '" data-dialog-open="course-module-' . $id . '">' . icon('pencil', 'size-3.5') . 'Изменить</button>';
        if (($module['kind'] ?? 'lessons') !== 'exam') {
            $html .= form_open('create_course_chapter', 'class="inline-flex"') . hidden('moduleId', $id) . '<button class="' . $small . '">' . icon('plus', 'size-3.5') . 'Глава</button></form>';
        }
        $html .= form_open('toggle_course_module', 'class="inline-flex"') . hidden('id', $id) . '<button class="' . $iconBtn . '" title="' . ($module['isActive'] ? 'Скрыть раздел' : 'Показать раздел') . '" aria-label="' . ($module['isActive'] ? 'Скрыть раздел' : 'Показать раздел') . '">' . icon($module['isActive'] ? 'eye-off' : 'eye', 'size-4') . '</button></form>';
        $html .= form_open('delete_course_module', 'class="inline-flex" ' . confirm_attr('Удалить раздел «' . $module['title'] . '» вместе с главами и прогрессом сотрудников?')) . hidden('id', $id) . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '" title="Удалить раздел" aria-label="Удалить раздел">' . icon('trash', 'size-4') . '</button></form>';
        return $html . '</div>';
    }

    /** Кнопки управления главой в списке. */
    private static function courseChapterActions(array $chapter): string
    {
        $id = (int) $chapter['id'];
        $iconBtn = btn_class('quiet', 'icon-sm');
        $html = '<div class="course-rail__admin">';
        $html .= '<a class="' . $iconBtn . '" href="' . h(url_to('/exams/chapter/' . $id . '/edit')) . '" aria-label="Изменить главу" title="Изменить главу">' . icon('pencil', 'size-4') . '</a>';
        if (!self::$courseCaps['structure']) {
            return $html . '</div>';
        }
        $html .= form_open('move_course_chapter', 'class="inline-flex"') . hidden('id', $id) . hidden('dir', -1) . '<button class="' . $iconBtn . '" aria-label="Глава выше" title="Выше">' . icon('chevronup', 'size-4') . '</button></form>';
        $html .= form_open('move_course_chapter', 'class="inline-flex"') . hidden('id', $id) . hidden('dir', 1) . '<button class="' . $iconBtn . '" aria-label="Глава ниже" title="Ниже">' . icon('chevrondown', 'size-4') . '</button></form>';
        $html .= form_open('toggle_course_chapter', 'class="inline-flex"') . hidden('id', $id) . '<button class="' . $iconBtn . '" title="' . ($chapter['isActive'] ? 'Скрыть главу' : 'Показать главу') . '" aria-label="' . ($chapter['isActive'] ? 'Скрыть главу' : 'Показать главу') . '">' . icon($chapter['isActive'] ? 'eye-off' : 'eye', 'size-4') . '</button></form>';
        $html .= form_open('delete_course_chapter', 'class="inline-flex" ' . confirm_attr('Удалить главу «' . $chapter['title'] . '»? Шаги и прогресс сотрудников по ней тоже удалятся.')) . hidden('id', $id) . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '" title="Удалить главу" aria-label="Удалить главу">' . icon('trash', 'size-4') . '</button></form>';
        return $html . '</div>';
    }

    /** Диалог создания/правки раздела. */
    private static function courseModuleDialog(string $id, ?array $module): string
    {
        $f = form_open('save_course_module', 'class="flex flex-col gap-4"') . hidden('id', $module !== null ? (int) $module['id'] : 0);
        $f .= ui_field('Название', '<input name="title" class="' . field_class() . '" maxlength="160" required value="' . h((string) ($module['title'] ?? '')) . '" placeholder="Например: Работа с ПО">');
        $f .= ui_field('Описание', '<textarea name="summary" class="' . textarea_class('min-h-20') . '" maxlength="2000" placeholder="Коротко: о чём раздел">' . h((string) ($module['summary'] ?? '')) . '</textarea>');
        $f .= ui_field('Тип раздела', ui_select('kind', ['lessons' => 'Главы и шаги', 'intro' => 'Введение (без номера модуля)', 'exam' => 'Финальный экзамен'], (string) ($module['kind'] ?? 'lessons')));
        $f .= '<div class="flex justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></div></form>';
        return dialog_html($id, $module !== null ? 'Раздел курса' : 'Новый раздел', $f, 'max-w-md');
    }

    /* ---------- чтение главы ---------- */

    private static function pageCourseChapter(array $user, int $chapterId): void
    {
        $manage = can_course_any($user);
        $userId = (string) $user['id'];
        $chapter = Course::chapter($chapterId, $manage);
        if ($chapter === null) {
            self::notFound($user, 'Глава не найдена — возможно, её удалили. Откройте курс и выберите другую.');
            return;
        }
        $flow = Course::flow($userId);
        $frontier = Course::frontierStep($flow);
        $requested = (int) ($_GET['step'] ?? 0);

        // Закрытые главы и шаги не показываем даже по прямой ссылке — ведём к текущему шагу.
        if (!$manage) {
            $locked = Course::chapterState($flow, $chapterId) === 'locked'
                || ($requested > 0 && isset($flow['index'][$requested]) && !Course::stepUnlocked($flow, $requested));
            if ($locked) {
                flash_set('error', 'Этот материал пока закрыт. Шаги открываются по порядку — продолжите с текущего.');
                redirect_to($frontier !== null ? '/exams/chapter/' . $frontier['chapterId'] . '?step=' . $frontier['stepId'] : '/exams');
            }
        }

        $steps = $chapter['steps'];
        $progress = Course::progressFor($userId);
        $stats = Course::stats($userId);
        $number = '';
        $moduleNumber = 0;
        foreach (Course::chapters($manage) as $flat) {
            if ((int) $flat['id'] === $chapterId) {
                $number = (string) $flat['number'];
                $moduleNumber = (int) $flat['moduleNumber'];
            }
        }
        $total = count($steps);
        $current = 0;
        $found = false;
        foreach ($steps as $i => $row) {
            if ((int) $row['id'] === $requested) {
                $current = $i;
                $found = true;
            }
        }
        if (!$found && $frontier !== null && (int) $frontier['chapterId'] === $chapterId) {
            foreach ($steps as $i => $row) {
                if ((int) $row['id'] === (int) $frontier['stepId']) {
                    $current = $i;
                }
            }
        }
        $step = $steps[$current] ?? null;
        $layoutOpts = ['title' => (string) $chapter['title'], 'width' => 'wide', 'scripts' => ['course'], 'styles' => ['css/course.css']];

        $top = '<div class="lesson-topline"><div class="flex min-w-0 flex-col gap-2">';
        $top .= '<p class="lesson-eyebrow"><span class="cut-mark" aria-hidden="true"></span>' . h(Course::moduleLabel(['number' => $moduleNumber, 'title' => (string) $chapter['module']['title']])) . '</p>';
        $top .= '<h1 class="font-display text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[1.9rem]">' . h((string) $chapter['title']) . '</h1></div>';
        $top .= '<div class="flex flex-wrap items-center gap-2">';
        if ($step !== null) {
            $top .= '<span class="step-pill">Шаг ' . ($current + 1) . ' из ' . $total . '</span>';
        }
        if (can_course($user, 'edit') || can_course($user, 'structure')) {
            $editHref = url_to('/exams/chapter/' . $chapterId . '/edit') . ($step !== null ? '?step=' . (int) $step['id'] : '');
            $top .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h($editHref) . '">' . icon('pencil', 'size-4') . 'Редактировать шаг</a>';
        }
        $top .= '</div></div>';

        if ($step === null) {
            $action = $manage
                ? '<a class="' . btn_class() . '" href="' . h(url_to('/exams/chapter/' . $chapterId . '/edit')) . '">' . icon('plus', 'size-4') . 'Добавить шаг</a>'
                : '';
            $main = $top . empty_state('book', 'В главе пока нет шагов', $manage ? 'Добавьте первый шаг в редакторе главы.' : 'Материал ещё готовится.', $action);
            self::layout($user, self::courseLessonShell($user, $flow, $stats, $chapterId, $main, ''), $layoutOpts);
            return;
        }

        $stepId = (int) $step['id'];
        $isRead = !empty($progress[$stepId]['isRead']);
        if (Equator::isChapter($chapter)) {
            $layoutOpts['scripts'] = ['equator'];
            $main = self::courseDashboardStrip($stats, $manage) . $top;
            $main .= self::equatorCard($userId, $stepId, $isRead);
            $main .= self::equatorActions($flow, $stepId, $isRead || Equator::passed($userId, $stepId), $manage);
            $aside = self::equatorCoachPanel();
            self::layout($user, self::courseLessonShell($user, $flow, $stats, $chapterId, $main, $aside), $layoutOpts);
            return;
        }
        $main = self::courseDashboardStrip($stats, $manage);
        $main .= $top;
        $main .= self::courseStepMap($steps, $current, $chapterId, $flow, $manage);
        if ($manage && !$isRead && !Course::stepUnlocked($flow, $stepId)) {
            $main .= '<p class="lesson-notice">' . icon('eye', 'size-4') . 'Режим редактора: шаг открыт для просмотра, у сотрудников он пока закрыт.</p>';
        }

        $card = '<article class="lesson-card" data-course-reader data-step="' . $stepId . '" data-read="' . ($isRead ? '1' : '0') . '" data-scroll="' . (int) ($progress[$stepId]['scrollPercent'] ?? 0) . '"' . (Course::settings()['requireInteractions'] ? '' : ' data-free-reading') . '>';
        if (trim((string) $step['title']) !== '') {
            $card .= '<h2 class="lesson-card__title">' . h((string) $step['title']) . '</h2>';
        }
        $content = trim((string) $step['contentHtml']);
        $card .= '<div class="course-prose">' . ($content !== '' && $content !== '<p></p>' ? Course::renderHtml($content) : '<p class="text-muted-foreground">Текст шага пока не заполнен.</p>') . '</div>';
        foreach ($step['images'] as $image) {
            $card .= '<figure class="course-media"><img src="' . h((string) $image['url']) . '" alt="' . h((string) $image['alt']) . '" loading="lazy" decoding="async">';
            if (trim((string) $image['alt']) !== '') {
                $card .= '<figcaption>' . h((string) $image['alt']) . '</figcaption>';
            }
            $card .= '</figure>';
        }
        $card .= '</article>';
        $main .= $card;
        $main .= self::courseLessonActions($flow, $stepId, $isRead, $manage);

        $aside = self::courseCoachPanel($flow, $stats, $stepId, $manage);
        self::layout($user, self::courseLessonShell($user, $flow, $stats, $chapterId, $main, $aside), $layoutOpts);
    }

    /** «Экватор»: экран старта / итог. Сами задания рисует assets/js/equator.js. */
    private static function equatorCard(string $userId, int $stepId, bool $isRead): string
    {
        $result = Equator::result($userId, $stepId);
        $passed = $result !== null && $result['passed'];
        $data = Equator::clientData();
        $count = count($data['tasks']);

        $html = '<article class="lesson-card equator" data-equator data-step="' . $stepId . '" data-passed="' . ($passed ? '1' : '0') . '">';
        if ($passed) {
            $s = $result['stats'];
            $html .= '<p class="eq-eyebrow">Проверка зачтена</p>';
            $html .= '<h2 class="eq-title">Экватор пройден</h2>';
            $html .= '<p class="eq-lead">Результат зафиксирован. Повторно пройти проверку нельзя — переходите к следующему модулю.</p>';
            $html .= self::equatorStats($result['bestPercent'], (int) ($s['firstTry'] ?? 0), (int) ($s['mistakes'] ?? 0), (int) ($s['total'] ?? $count));
            $html .= '<p class="eq-note">Попыток: ' . $result['attempts'] . ($result['passedAt'] ? ' · зачтено ' . h(format_date_human(substr((string) $result['passedAt'], 0, 10))) : '') . '</p>';
            return $html . '</article>';
        }

        $html .= '<div class="eq-intro" data-eq-intro>';
        $html .= '<div class="eq-intro__main"><p class="eq-eyebrow">Экваторная проверка знаний</p>';
        $html .= '<h2 class="eq-title">Проверьте себя в рабочих ситуациях</h2>';
        $html .= '<p class="eq-lead">Принимайте решения как администратор: выбирайте действие, исправляйте ошибки и собирайте смену в правильной последовательности.</p>';
        $html .= '<dl class="eq-facts"><div><dt>' . $count . '</dt><dd>заданий</dd></div><div><dt>2</dt><dd>формата</dd></div><div><dt>' . Equator::PASS_PERCENT . '%</dt><dd>для зачёта</dd></div></dl>';
        if ($result !== null) {
            $html .= '<p class="eq-note">Прошлая попытка: ' . $result['lastPercent'] . '% с первой попытки. Попыток: ' . $result['attempts'] . '.</p>';
        }
        $html .= '<button type="button" class="' . btn_class('primary') . ' self-start" data-eq-start>' . ($result !== null ? 'Пройти ещё раз' : 'Начать') . '</button></div>';
        $html .= '<div class="eq-intro__how"><p class="eq-eyebrow">Как проходит проверка</p><ul>';
        foreach ([
            ['Решайте рабочие ситуации', 'Вопросы проверяют действия, а не память на названия.'],
            ['Исправляйте ошибки', 'Разбор объясняет риск, после чего можно ответить снова.'],
            ['Разные форматы', 'Выбор правильного действия и сборка последовательности.'],
            ['Результат с первой попытки', 'Нужно ' . Equator::PASS_PERCENT . '% верных ответов с первого раза. Пока результат ниже — следующие модули закрыты.'],
        ] as [$t, $d]) {
            $html .= '<li><strong>' . h($t) . '</strong><span>' . h($d) . '</span></li>';
        }
        $html .= '</ul></div></div>';
        $html .= '<div data-eq-stage hidden></div>';
        $html .= '<script type="application/json" data-eq-data>' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        return $html . '</article>';
    }

    private static function equatorStats(int $percent, int $firstTry, int $mistakes, int $total): string
    {
        return '<dl class="eq-stats"><div><dt>' . $percent . '%</dt><dd>с первой попытки</dd></div><div><dt>' . $firstTry . '</dt><dd>верных сразу</dd></div>'
            . '<div><dt>' . $mistakes . '</dt><dd>ошибок исправлено</dd></div><div><dt>' . $total . '</dt><dd>заданий завершено</dd></div></dl>';
    }

    /** «Назад / Дальше» для «Экватора»: пока нет зачёта, «Дальше» закрыта. */
    private static function equatorActions(array $flow, int $stepId, bool $done, bool $manage): string
    {
        $prev = Course::prevInFlow($flow, $stepId);
        $next = Course::nextInFlow($flow, $stepId);
        $final = Course::finalExam(!$manage);
        $html = '<div class="lesson-actions">';
        $html .= $prev !== null
            ? '<a class="' . btn_class('outline') . '" href="' . h(Course::chapterUrl((int) $prev['chapterId'], (int) $prev['stepId'])) . '">' . icon('arrow-left', 'size-4') . 'Назад</a>'
            : '<span class="' . btn_class('outline') . ' pointer-events-none opacity-40" aria-disabled="true">' . icon('arrow-left', 'size-4') . 'Назад</span>';
        if ($done) {
            $href = $next !== null
                ? Course::chapterUrl((int) $next['chapterId'], (int) $next['stepId'])
                : ($final !== null ? url_to('/exams/' . rawurlencode((string) $final['slug'])) : url_to('/exams'));
            $html .= '<a class="' . btn_class('primary') . '" href="' . h($href) . '" data-eq-next>Дальше' . icon('arrow', 'size-4') . '</a>';
        } else {
            $html .= '<span class="' . btn_class('outline') . ' pointer-events-none opacity-60" aria-disabled="true" data-eq-next>Завершите проверку</span>';
        }
        $html .= '</div>';
        $html .= $done
            ? '<p class="course-hint">Проверка зачтена — следующий модуль открыт.</p>'
            : '<p class="course-hint">Следующий модуль откроется, когда вы наберёте не меньше ' . Equator::PASS_PERCENT . '% верных ответов с первой попытки.</p>';
        return $html;
    }

    private static function equatorCoachPanel(): string
    {
        $html = '<aside class="coach-panel" aria-label="Подсказки по курсу">';
        $html .= '<section><p class="eyebrow"><span class="cut-mark" aria-hidden="true"></span>Следующая цель</p><h3>Пройти Экватор</h3>';
        $html .= '<p>Промежуточная проверка по Админ ПО. Результат хранится отдельно от финального экзамена.</p></section>';
        $html .= '<section><p class="eyebrow"><span class="cut-mark" aria-hidden="true"></span>Правило курса</p><p class="quiet-text">' . h(Course::settings()['rule']) . '</p></section>';
        return $html . '</aside>';
    }

    /** Сетка урока: слева содержание курса, по центру шаг, справа подсказки. */
    private static function courseLessonShell(array $user, array $flow, array $stats, int $chapterId, string $main, string $aside): string
    {
        $html = '<div class="lesson-shell">';
        $html .= '<div class="lesson-shell__nav">' . self::courseNavPanel($user, $flow, $stats, $chapterId) . '</div>';
        // data-live-nav: шаги этой главы открываются без перезагрузки — меняется только карточка урока (live.js, course.js).
        $html .= '<div class="lesson-shell__main" data-live-nav>' . $main . '</div>';
        if ($aside !== '') {
            $html .= '<div class="lesson-shell__coach" data-live-nav>' . $aside . '</div>';
        }
        return $html . '</div>';
    }

    /** Левая колонка: прогресс и модули с главами (пройдено / сейчас / закрыто). */
    private static function courseNavPanel(array $user, array $flow, array $stats, int $activeChapterId): string
    {
        $manage = can_course_any($user);
        $perChapter = Course::chapterStats((string) $user['id']);
        $html = '<section class="course-level" aria-label="Прогресс курса">';
        $html .= '<div class="flex items-end justify-between gap-3"><div class="flex flex-col gap-1"><p class="course-level__label">Пройдено</p><p class="course-level__value">' . $stats['percent'] . '<span>%</span></p></div>';
        $html .= '<div class="flex flex-col items-end gap-1"><p class="course-level__label">Шаги</p><p class="course-level__value course-level__value--sm">' . $stats['stepsRead'] . '<span>/' . $stats['stepsTotal'] . '</span></p></div></div>';
        $html .= '<div class="course-level__bar" role="progressbar" aria-label="Прогресс курса" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $stats['percent'] . '"><div style="width:' . $stats['percent'] . '%"></div></div>';
        $html .= '<p class="course-level__meta">Глав пройдено: ' . $stats['chaptersDone'] . ' из ' . $stats['chaptersTotal'] . '</p>';
        $html .= '<a class="course-level__back" href="' . h(url_to('/exams')) . '">' . icon('arrow-left', 'size-3.5') . 'Все модули курса</a>';
        $html .= '</section>';

        $html .= '<nav class="course-nav" aria-label="Содержание курса">';
        foreach (Course::tree($manage) as $module) {
            $moduleNumber = (int) $module['number'];
            if (($module['kind'] ?? 'lessons') === 'exam') {
                continue;
            }
            $states = [];
            $hasActive = false;
            foreach ($module['chapters'] as $c) {
                $states[(int) $c['id']] = Course::chapterState($flow, (int) $c['id']);
                $hasActive = $hasActive || (int) $c['id'] === $activeChapterId;
            }
            $real = array_filter($states, static fn (string $s): bool => $s !== 'empty');
            $moduleState = $real && !in_array('current', $real, true) && !in_array('locked', $real, true)
                ? 'done'
                : (in_array('current', $real, true) ? 'current' : 'locked');
            $label = match ($moduleState) {
                'done' => 'Готово',
                'current' => 'Сейчас',
                default => 'Закрыто',
            };
            $count = count($module['chapters']);
            $html .= '<details class="course-module is-' . $moduleState . '"' . ($hasActive ? ' open' : '') . '>';
            $html .= '<summary><span class="course-module__index">' . ($moduleState === 'locked' && !$manage ? icon('lock', 'size-4') : ($moduleNumber > 0 ? str_pad((string) $moduleNumber, 2, '0', STR_PAD_LEFT) : icon('book', 'size-4'))) . '</span>';
            $html .= '<span class="course-module__copy"><strong>' . h((string) $module['title']) . '</strong><span>' . plural($count, 'глава', 'главы', 'глав') . '</span></span>';
            $html .= '<span class="course-module__state">' . $label . '</span>' . icon('chevrondown', 'size-4 course-module__toggle') . '</summary>';
            $html .= '<ol class="course-module__lessons">';
            foreach ($module['chapters'] as $c) {
                $cid = (int) $c['id'];
                $state = $states[$cid];
                $num = '<span class="lesson-link__index">' . h((string) $c['number']) . '</span>';
                $title = '<span class="lesson-link__title">' . h((string) $c['title']) . '</span>';
                $read = (int) ($perChapter[$cid]['read'] ?? 0);
                $stateText = match ($state) {
                    'done' => 'готово',
                    'current' => $cid === $activeChapterId ? 'сейчас' : $read . '/' . (int) $c['stepsCount'],
                    'empty' => '—',
                    default => '',
                };
                $classes = 'lesson-link is-' . $state . ($cid === $activeChapterId ? ' is-active' : '');
                if ($state === 'locked' && !$manage) {
                    $html .= '<li><span class="' . $classes . '" aria-disabled="true" title="Откроется после предыдущих глав">' . $num . $title . '<span class="lesson-link__state">' . icon('lock', 'size-3.5') . '<span class="sr-only">Закрыто</span></span></span></li>';
                } else {
                    $html .= '<li><a class="' . $classes . '" href="' . h(Course::chapterUrl($cid)) . '"' . ($cid === $activeChapterId ? ' aria-current="page"' : '') . '>' . $num . $title . '<span class="lesson-link__state">' . h($stateText) . '</span></a></li>';
                }
            }
            $html .= '</ol></details>';
        }
        return $html . '</nav>';
    }

    /** Плашки над уроком: главы, шаги, экзамен (как dashboard-strip в референсе). */
    private static function courseDashboardStrip(array $stats, bool $manage): string
    {
        $final = Course::finalExam(!$manage);
        $exam = $final === null ? '—' : ($stats['complete'] ? 'открыт' : 'закрыт');
        $html = '<section class="dashboard-strip" aria-label="Сводка обучения">';
        $html .= '<article><span class="metric">' . $stats['chaptersDone'] . '/' . $stats['chaptersTotal'] . '</span><p>глав завершено</p></article>';
        $html .= '<article><span class="metric">' . $stats['stepsRead'] . '</span><p>' . plural_word($stats['stepsRead'], 'шаг пройден', 'шага пройдено', 'шагов пройдено') . '</p></article>';
        $html .= '<article><span class="metric">' . h($exam) . '</span><p>финальный экзамен</p></article>';
        return $html . '</section>';
    }

    /** Карта шагов главы: пройденные и текущий — ссылки, будущие — закрытые сегменты. */
    private static function courseStepMap(array $steps, int $current, int $chapterId, array $flow, bool $manage): string
    {
        $html = '<ol class="step-map" aria-label="Шаги главы">';
        foreach ($steps as $i => $s) {
            $sid = (int) $s['id'];
            $index = $flow['index'][$sid] ?? null;
            $done = $index !== null && $index < $flow['frontier'];
            $open = $manage || Course::stepUnlocked($flow, $sid);
            $class = 'step-dot' . ($done ? ' is-done' : '') . ($i === $current ? ' is-active' : '') . ($open ? '' : ' is-locked');
            $label = 'Шаг ' . ($i + 1) . ($done ? ' — пройден' : ($open ? ' — текущий' : ' — закрыт'));
            if ($open && $i !== $current) {
                $html .= '<li><a class="' . $class . '" href="' . h(Course::chapterUrl($chapterId, $sid)) . '" aria-label="' . h($label) . '" title="' . h($label) . '"></a></li>';
            } else {
                $html .= '<li><span class="' . $class . '" role="img" aria-label="' . h($label) . '" title="' . h($label) . '"' . ($i === $current ? ' aria-current="step"' : '') . '></span></li>';
            }
        }
        return $html . '</ol>';
    }

    /** «Назад / Дальше». Непройденный шаг завершается POST-ом, пройденный — обычной ссылкой. */
    private static function courseLessonActions(array $flow, int $stepId, bool $isRead, bool $manage): string
    {
        $prev = Course::prevInFlow($flow, $stepId);
        $next = Course::nextInFlow($flow, $stepId);
        $final = Course::finalExam(!$manage);
        $html = '<div class="lesson-actions">';
        $html .= $prev !== null
            ? '<a class="' . btn_class('outline') . '" href="' . h(Course::chapterUrl((int) $prev['chapterId'], (int) $prev['stepId'])) . '">' . icon('arrow-left', 'size-4') . 'Назад</a>'
            : '<span class="' . btn_class('outline') . ' pointer-events-none opacity-40" aria-disabled="true">' . icon('arrow-left', 'size-4') . 'Назад</span>';
        if ($next === null) {
            $label = $final !== null ? 'Завершить и перейти к экзамену' : 'Завершить курс';
        } elseif ((int) $next['chapterId'] !== (int) ($flow['order'][$flow['index'][$stepId] ?? 0]['chapterId'] ?? 0)) {
            $label = 'Следующая глава';
        } else {
            $label = 'Дальше';
        }
        if ($isRead) {
            $href = $next !== null
                ? Course::chapterUrl((int) $next['chapterId'], (int) $next['stepId'])
                : ($final !== null ? url_to('/exams/' . rawurlencode((string) $final['slug'])) : url_to('/exams'));
            $html .= '<a class="' . btn_class('primary') . '" href="' . h($href) . '">' . h($next === null && $final === null ? 'К курсу' : $label) . icon('arrow', 'size-4') . '</a>';
        } else {
            $html .= form_open('complete_step', 'class="inline-flex"') . hidden('stepId', $stepId)
                . '<button class="' . btn_class('primary') . '" data-step-next>' . h($label) . icon('arrow', 'size-4') . '</button></form>';
        }
        $html .= '</div>';
        $html .= $isRead
            ? '<p class="course-hint">Шаг пройден — его можно перечитывать в любое время.</p>'
            : '<p class="course-hint" data-step-hint>Следующий шаг откроется после того, как вы завершите этот.</p>';
        return $html;
    }

    /** Правая колонка: следующая цель и правило курса. */
    private static function courseCoachPanel(array $flow, array $stats, int $stepId, bool $manage): string
    {
        $html = '<aside class="coach-panel" aria-label="Подсказки по курсу">';
        $frontier = Course::frontierStep($flow);
        $html .= '<section><p class="eyebrow"><span class="cut-mark" aria-hidden="true"></span>Следующая цель</p>';
        if ($frontier === null) {
            $final = Course::finalExam(!$manage);
            $html .= '<h3>' . ($final !== null ? 'Финальный экзамен' : 'Режим чтения') . '</h3>';
            $html .= '<p>' . ($final !== null ? 'Все шаги пройдены — экзамен открыт.' : 'Курс пройден. Материалы можно перечитывать в любое время.') . '</p>';
        } else {
            $chapter = Course::chapter((int) $frontier['chapterId']);
            $range = $flow['chapterRange'][(int) $frontier['chapterId']] ?? [0, 0];
            $stepNo = $flow['frontier'] - $range[0] + 1;
            $html .= '<h3>' . ((int) $frontier['stepId'] === $stepId ? 'Завершите этот шаг' : 'Вернитесь к текущему шагу') . '</h3>';
            $html .= '<p>Глава «' . h((string) ($chapter['title'] ?? '')) . '», шаг ' . $stepNo . ' из ' . ($range[1] - $range[0] + 1) . '.</p>';
            if ((int) $frontier['stepId'] !== $stepId) {
                $html .= '<a class="' . btn_class('outline', 'sm') . ' mt-2 self-start" href="' . h(Course::chapterUrl((int) $frontier['chapterId'], (int) $frontier['stepId'])) . '">Продолжить' . icon('arrow', 'size-4') . '</a>';
            }
        }
        $html .= '</section>';
        $html .= '<section><p class="eyebrow"><span class="cut-mark" aria-hidden="true"></span>Правило курса</p><p class="quiet-text">' . h(Course::settings()['rule']) . '</p></section>';
        $html .= '<section><p class="eyebrow"><span class="cut-mark" aria-hidden="true"></span>Прогресс</p><p class="quiet-text">Сохраняется за вашей учётной записью — можно продолжить с любого устройства. Пройдено ' . $stats['stepsRead'] . ' из ' . $stats['stepsTotal'] . ' ' . plural_word($stats['stepsTotal'], 'шага', 'шагов', 'шагов') . '.</p></section>';
        return $html . '</aside>';
    }
}
