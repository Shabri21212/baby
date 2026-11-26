<?php
declare(strict_types=1);

/**
 * Редактор курса и его настройки.
 *
 * Права (src/access.php): course.edit — тексты и фото, course.structure — модули, главы, шаги,
 * course.settings — настройки и оформление, course.manage — всё сразу.
 * Поведение редактора текста — assets/js/course-editor.js.
 */
trait PagesCourseEditor
{
    /* ---------- редактор главы: шаги слева, один шаг в работе ---------- */

    private static function pageCourseChapterEdit(array $user, int $chapterId): void
    {
        $caps = self::courseCapsFor($user);
        $chapter = Course::chapter($chapterId, true);
        if ($chapter === null) {
            self::notFound($user, 'Глава не найдена.');
            return;
        }
        $steps = $chapter['steps'];
        $requested = (int) ($_GET['step'] ?? 0);
        $current = 0;
        foreach ($steps as $i => $s) {
            if ((int) $s['id'] === $requested) {
                $current = $i;
            }
        }
        $step = $steps[$current] ?? null;
        $editUrl = '/exams/chapter/' . $chapterId . '/edit';

        $preview = $step !== null ? Course::chapterUrl($chapterId, (int) $step['id']) : Course::chapterUrl($chapterId);
        $html = back_link(url_to('/exams'), 'К курсу');
        $html .= page_head(
            (string) $chapter['title'],
            'Пишите как в обычном редакторе: фото вставляются кнопкой, из буфера или перетаскиванием, ситуации и вопросы — готовыми блоками.',
            '<a class="' . btn_class('outline') . '" href="' . h($preview) . '" target="_blank" rel="noopener">' . icon('eye', 'size-4') . 'Как видит сотрудник</a>',
            Course::moduleLabel(self::courseModuleOf((int) $chapter['moduleId'])),
        );

        $side = '';
        if ($caps['structure']) {
            $side .= self::courseEditorMetaCard($chapter);
        }
        $side .= self::courseEditorStepList($chapterId, $steps, $current, $caps['structure']);
        if ($caps['structure']) {
            $side .= '<div class="flex justify-end">' . form_open('delete_course_chapter', 'class="inline-flex" ' . confirm_attr('Удалить главу «' . $chapter['title'] . '»? Шаги и прогресс сотрудников по ней тоже удалятся.')) . hidden('id', $chapterId) . '<button class="' . btn_class('danger-ghost', 'sm') . '">' . icon('trash', 'size-4') . 'Удалить главу</button></form></div>';
        }

        if ($step === null) {
            $main = empty_state('book', 'В главе пока нет шагов', $caps['structure'] ? 'Добавьте первый шаг кнопкой слева.' : 'Попросите редактора структуры курса добавить шаг.', '');
        } elseif ($caps['edit']) {
            $main = self::courseStepForm($step, $current, count($steps), $editUrl);
        } else {
            $main = card('<div class="flex flex-col gap-3"><p class="text-sm text-muted-foreground">У вас право менять только структуру курса. Текст шага правит редактор с правом «Курс: тексты и фото».</p><div class="course-prose">' . Course::renderHtml((string) $step['contentHtml']) . '</div></div>', 'p-5');
        }

        $html .= '<div class="course-editor"><aside class="course-editor__side" aria-label="Глава и шаги">' . $side . '</aside><section class="course-editor__main" aria-label="Шаг в работе">' . $main . '</section></div>';
        self::layout($user, $html, [
            'title' => 'Редактор: ' . $chapter['title'],
            'width' => 'wide',
            'scripts' => ['course-editor'],
            'styles' => ['css/course.css'],
        ]);
    }

    /** Строка модуля для подписи над заголовком редактора. */
    private static function courseModuleOf(int $moduleId): array
    {
        foreach (Course::tree(true) as $module) {
            if ((int) $module['id'] === $moduleId) {
                return $module;
            }
        }
        return ['number' => 0, 'title' => 'Курс обучения'];
    }

    private static function courseEditorMetaCard(array $chapter): string
    {
        $moduleOptions = [];
        foreach (Course::tree(true) as $module) {
            if (($module['kind'] ?? 'lessons') !== 'exam') {
                $moduleOptions[(int) $module['id']] = Course::moduleLabel($module);
            }
        }
        $f = '<details class="editor-panel" open><summary class="editor-panel__head">' . icon('book', 'size-4') . '<span>Глава</span>' . icon('chevrondown', 'size-4 editor-panel__chevron') . '</summary>';
        $f .= form_open('save_course_meta', 'class="flex flex-col gap-3 p-4 pt-1"') . hidden('id', (int) $chapter['id']);
        $f .= ui_field('Название', '<input name="title" class="' . field_class() . '" maxlength="200" required value="' . h((string) $chapter['title']) . '">');
        $f .= ui_field('Модуль', ui_select('moduleId', $moduleOptions, (int) $chapter['moduleId']), 'Перенос сохраняет прогресс сотрудников.');
        $f .= ui_field('Описание', '<textarea name="summary" class="' . textarea_class('min-h-16') . '" maxlength="2000" placeholder="Необязательно">' . h((string) $chapter['summary']) . '</textarea>');
        $f .= '<button class="' . btn_class('outline', 'sm') . ' self-end">' . icon('save', 'size-4') . 'Сохранить главу</button></form></details>';
        return $f;
    }

    private static function courseEditorStepList(int $chapterId, array $steps, int $current, bool $structure): string
    {
        $total = count($steps);
        $html = '<nav class="editor-panel" aria-label="Шаги главы"><div class="editor-panel__head"><span>' . icon('list-ordered', 'size-4') . '</span><span>Шаги</span><span class="ml-auto text-xs font-normal text-muted-foreground tabular-nums">' . $total . '</span></div>';
        $html .= '<ol class="editor-steps">';
        $iconBtn = btn_class('quiet', 'icon-sm');
        foreach ($steps as $i => $s) {
            $id = (int) $s['id'];
            $title = trim((string) $s['title']) !== '' ? (string) $s['title'] : 'Без заголовка';
            $active = $i === $current;
            $html .= '<li class="editor-steps__item' . ($active ? ' is-active' : '') . '">';
            $html .= '<a class="editor-steps__link" href="' . h(url_to('/exams/chapter/' . $chapterId . '/edit?step=' . $id)) . '"' . ($active ? ' aria-current="step"' : '') . '><span class="editor-steps__num">' . ($i + 1) . '</span><span class="editor-steps__title">' . h($title) . '</span></a>';
            if ($structure) {
                $html .= '<span class="editor-steps__tools">';
                $html .= form_open('move_course_step', 'class="inline-flex"') . hidden('stepId', $id) . hidden('dir', -1) . '<button class="' . $iconBtn . '"' . ($i === 0 ? ' disabled' : '') . ' title="Выше" aria-label="Шаг ' . ($i + 1) . ' выше">' . icon('chevronup', 'size-4') . '</button></form>';
                $html .= form_open('move_course_step', 'class="inline-flex"') . hidden('stepId', $id) . hidden('dir', 1) . '<button class="' . $iconBtn . '"' . ($i >= $total - 1 ? ' disabled' : '') . ' title="Ниже" aria-label="Шаг ' . ($i + 1) . ' ниже">' . icon('chevrondown', 'size-4') . '</button></form>';
                $html .= form_open('delete_course_step', 'class="inline-flex" ' . confirm_attr('Удалить шаг №' . ($i + 1) . '? Прогресс сотрудников по нему тоже удалится.')) . hidden('stepId', $id) . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '"' . ($total <= 1 ? ' disabled' : '') . ' title="Удалить шаг" aria-label="Удалить шаг ' . ($i + 1) . '">' . icon('trash', 'size-4') . '</button></form>';
                $html .= '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ol>';
        if ($structure) {
            $html .= '<div class="p-3 pt-0">' . form_open('create_course_step') . hidden('chapterId', $chapterId) . '<button class="' . btn_class('outline', 'sm') . ' w-full">' . icon('plus', 'size-4') . 'Добавить шаг</button></form></div>';
        }
        return $html . '</nav>';
    }

    /** Форма одного шага: заголовок, текст с панелью инструментов, фото под текстом. */
    private static function courseStepForm(array $step, int $index, int $total, string $editUrl): string
    {
        $id = (int) $step['id'];
        $f = '<form method="post" enctype="multipart/form-data" class="flex flex-col gap-4" data-course-editor-form>' . csrf_field() . hidden('_action', 'save_course_step') . hidden('stepId', $id);
        $f .= '<div class="flex flex-col gap-1.5"><label class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase" for="step-title">Шаг ' . ($index + 1) . ' из ' . $total . '</label>';
        $f .= '<input id="step-title" name="title" class="editor-title" maxlength="200" value="' . h((string) $step['title']) . '" placeholder="Заголовок шага"></div>';
        $f .= self::courseRichEditor((string) $step['contentHtml'], $id);

        $images = $step['images'];
        $f .= '<details class="editor-panel"' . ($images ? ' open' : '') . '><summary class="editor-panel__head">' . icon('image', 'size-4') . '<span>Фото под текстом</span><span class="ml-auto text-xs font-normal text-muted-foreground">' . ($images ? count($images) . ' шт.' : 'нет') . '</span>' . icon('chevrondown', 'size-4 editor-panel__chevron') . '</summary><div class="flex flex-col gap-3 p-4 pt-1">';
        $f .= '<p class="text-xs leading-relaxed text-muted-foreground">Показываются в конце шага, по порядку. Чтобы поставить фото внутри текста, используйте кнопку «Фото» на панели.</p>';
        if ($images) {
            $f .= '<div class="course-gallery">';
            foreach ($images as $image) {
                $f .= '<label class="flex flex-col gap-2 rounded-lg border border-border bg-card p-2 text-xs"><img src="' . h((string) $image['url']) . '" alt="" class="course-thumb" loading="lazy" decoding="async"><span class="flex items-center gap-2"><input type="checkbox" name="removeImages[]" value="' . (int) $image['id'] . '" class="' . check_class() . '">Удалить</span></label>';
            }
            $f .= '</div>';
        }
        $f .= '<input type="file" name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="' . field_class('h-auto py-2') . '" aria-label="Добавить фото под текст"></div></details>';

        $f .= '<div class="editor-savebar"><p class="editor-savebar__status" data-editor-status aria-live="polite">Изменений нет</p>';
        $f .= '<span class="hidden text-xs text-muted-foreground sm:inline">Ctrl + S — сохранить</span>';
        $f .= '<button class="' . btn_class() . '" data-editor-save>' . icon('save', 'size-4') . 'Сохранить шаг</button></div>';
        return $f . '</form>';
    }

    /** Текстовый редактор шага. Команды панели обрабатывает course-editor.js. */
    private static function courseRichEditor(string $html, int $stepId): string
    {
        $groups = [
            'Текст' => [
                ['bold', 'bold', 'Жирный (Ctrl+B)'], ['italic', 'italic', 'Курсив (Ctrl+I)'],
                ['h2', 'heading', 'Заголовок'], ['h3', 'heading', 'Подзаголовок', 'is-small'],
                ['ul', 'list', 'Список'], ['ol', 'list-ordered', 'Нумерованный список'],
                ['note', 'quote', 'Заметка'], ['link', 'link', 'Ссылка'],
            ],
            'Вставка' => [
                ['image', 'image', 'Фото', 'with-label'],
                ['cases', 'layers', 'Ситуации', 'with-label'],
                ['quiz', 'help', 'Вопрос', 'with-label'],
                ['answer', 'eye-off', 'Скрытый ответ', 'with-label'],
            ],
        ];
        $out = '<div class="course-rich" data-course-editor data-step="' . $stepId . '">';
        $out .= '<div class="course-rich__toolbar" role="toolbar" aria-label="Оформление шага">';
        foreach ($groups as $group => $tools) {
            $out .= '<div class="course-rich__group" role="group" aria-label="' . h($group) . '">';
            foreach ($tools as $tool) {
                [$cmd, $ic, $label] = $tool;
                $variant = $tool[3] ?? '';
                $withLabel = $variant === 'with-label';
                $out .= '<button type="button" class="course-rich__btn' . ($variant === 'is-small' ? ' is-small' : '') . ($withLabel ? ' has-label' : '') . '" data-cmd="' . $cmd . '" title="' . h($label) . '"' . ($withLabel ? '' : ' aria-label="' . h($label) . '"') . '>' . icon($ic, 'size-4') . ($withLabel ? '<span>' . h($label) . '</span>' : '') . '</button>';
            }
            $out .= '</div>';
        }
        $out .= '<div class="course-rich__group ml-auto"><button type="button" class="course-rich__btn" data-cmd="clear" title="Убрать оформление" aria-label="Убрать оформление">' . icon('x', 'size-4') . '</button>';
        $out .= '<button type="button" class="course-rich__btn" data-editor-source aria-pressed="false" title="Править HTML" aria-label="Править HTML">' . icon('code', 'size-4') . '</button></div></div>';
        // Контекстная панель: появляется, когда курсор внутри блока ситуаций, вопроса, ответа или на фото.
        $out .= '<div class="course-rich__context" data-editor-context hidden></div>';
        $out .= '<div class="course-prose course-rich__area" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Текст шага" data-editor-area>' . Course::renderHtml($html) . '</div>';
        $out .= '<textarea name="contentHtml" class="course-rich__source hidden" aria-label="HTML шага" spellcheck="false" data-editor-input>' . h(sanitize_html($html)) . '</textarea>';
        $out .= '<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden data-editor-file>';
        $out .= '<p class="course-rich__hint">Фото можно вставить из буфера (Ctrl + V) или перетащить в текст. Блоки «Ситуации», «Вопрос» и «Скрытый ответ» у сотрудника становятся интерактивными.</p>';
        return $out . '</div>';
    }

    /* ---------- настройки курса ---------- */

    private static function pageCourseSettings(array $user): void
    {
        if (!can_course($user, 'settings')) {
            self::requirePerm($user, 'course.settings');
        }
        $s = Course::settings();
        $html = back_link(url_to('/exams'), 'К курсу');
        $html .= page_head('Настройки курса', 'Тексты, правила прохождения и праздничное оформление портала.', '', 'Обучение');

        $f = form_open('save_course_settings', 'class="flex flex-col gap-6"');

        $texts = '<div class="flex flex-col gap-4">';
        $texts .= ui_field('Название курса', '<input name="title" class="' . field_class() . '" maxlength="120" required value="' . h($s['title']) . '">');
        $texts .= ui_field('Описание под названием', '<textarea name="subtitle" class="' . textarea_class('min-h-20') . '" maxlength="400">' . h($s['subtitle']) . '</textarea>');
        $texts .= ui_field('Правило курса', '<textarea name="rule" class="' . textarea_class('min-h-24') . '" maxlength="600">' . h($s['rule']) . '</textarea>', 'Показывается сотруднику в правой колонке урока.');
        $texts .= '</div>';
        $f .= card(card_head('Тексты', 'Что видит сотрудник на странице курса', '', 'file-text') . '<div class="mt-4">' . $texts . '</div>', 'p-5');

        $modes = '<fieldset class="grid gap-2 sm:grid-cols-2"><legend class="mb-2 text-sm font-medium">Порядок прохождения</legend>';
        foreach ([
            'strict' => ['Строго по порядку', 'Следующий шаг открывается после завершения текущего. Перепрыгнуть главу нельзя.'],
            'free' => ['Свободный', 'Все главы открыты сразу, проходить можно в любом порядке. Прогресс считается так же.'],
        ] as $value => [$label, $hint]) {
            $modes .= '<label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors hover:bg-muted/50 has-[:checked]:border-foreground has-[:checked]:bg-muted/60"><input type="radio" name="passMode" value="' . $value . '" class="mt-0.5 ' . check_class() . '"' . ($s['passMode'] === $value ? ' checked' : '') . '>';
            $modes .= '<span class="flex flex-col gap-0.5"><span class="text-sm font-semibold">' . h($label) . '</span><span class="text-xs leading-relaxed text-muted-foreground">' . h($hint) . '</span></span></label>';
        }
        $modes .= '</fieldset>';
        $modes .= self::courseSettingSwitch('requireInteractions', $s['requireInteractions'], 'Проверять, что шаг пройден', 'Перед «Дальше» сотрудник дочитывает шаг до конца, открывает все ситуации и отвечает на вопросы.');
        $f .= card(card_head('Прохождение', 'Как сотрудник двигается по курсу', '', 'list-ordered') . '<div class="mt-4 flex flex-col gap-4">' . $modes . '</div>', 'p-5');

        $decor = self::courseSettingSwitch('newYear', $s['newYear'], 'Новогоднее оформление', 'Гирлянда в шапке, тихий снег на фоне и праздничные иллюстрации — на всём портале, для всех сотрудников. Снег не идёт, если в системе включено «Уменьшить движение».');
        $f .= card(card_head('Оформление', 'Праздничное настроение для всего портала', '', 'sparkles') . '<div class="mt-4">' . $decor . '</div>', 'p-5');

        $f .= '<div class="flex justify-end gap-2"><a class="' . btn_class('ghost') . '" href="' . h(url_to('/exams')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить настройки</button></div></form>';
        $html .= $f;

        $html .= card(
            card_head('Кто может редактировать курс', 'Права выдаёт владелец в «Доступах и правах»', '', 'shield')
            . '<ul class="mt-4 flex flex-col gap-2 text-sm leading-relaxed">'
            . '<li><strong>Курс: полный доступ</strong> — всё сразу, включая импорт курса из файла.</li>'
            . '<li><strong>Курс: тексты и фото</strong> — правка текста шагов, фото, ситуаций и вопросов.</li>'
            . '<li><strong>Курс: модули и главы</strong> — создание, порядок, скрытие и удаление модулей, глав и шагов.</li>'
            . '<li><strong>Курс: настройки и оформление</strong> — эта страница.</li></ul>'
            . (can($user, 'owner') ? '<a class="mt-4 ' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/accounts')) . '">' . icon('key', 'size-4') . 'Доступы и права</a>' : ''),
            'p-5',
        );

        self::layout($user, $html, ['title' => 'Настройки курса', 'width' => 'narrow', 'styles' => ['css/course.css']]);
    }

    private static function courseSettingSwitch(string $name, bool $on, string $label, string $hint): string
    {
        return '<label class="flex cursor-pointer items-start justify-between gap-4 rounded-md border border-border p-3 hover:bg-muted/50"><span class="flex flex-col gap-0.5"><span class="text-sm font-semibold">' . h($label) . '</span><span class="text-xs leading-relaxed text-muted-foreground">' . h($hint) . '</span></span>'
            . '<input type="checkbox" role="switch" name="' . h($name) . '" value="1" class="settings-switch"' . ($on ? ' checked' : '') . '></label>';
    }
}
