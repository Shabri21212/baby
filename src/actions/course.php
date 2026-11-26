<?php
declare(strict_types=1);

/**
 * Экшены курса обучения: прогресс чтения и админка разделов/глав/шагов.
 * Структура — в Course, картинки — в CourseMedia, тексты шагов — HTML
 * (тот же редактор и валидатор, что у базы знаний).
 */
trait ActionsCourse
{
    /**
     * Право на часть редактора курса: edit — тексты и фото, structure — модули/главы/шаги,
     * settings — настройки, full — только «полный доступ» (импорт курса из файла).
     */
    private static function coursePerm(array $user, string $area = 'structure', string $back = '/exams'): void
    {
        if ($area === 'full' ? can($user, 'course.manage') : can_course($user, $area)) {
            if ($area === 'edit' || $area === 'structure') {
                Course::markCustomized();
            }
            return;
        }
        self::permOrFail($user, $area === 'full' ? 'course.manage' : 'course.' . $area, null, $back);
    }

    /** Строка шага из формы: тот же лимит и очистка, что у статей базы знаний. */
    private static function courseHtmlFromPost(string $back): string
    {
        $html = (string) ($_POST['contentHtml'] ?? '');
        if (strlen($html) > 300000) {
            self::fail('Текст слишком большой — разбейте шаг на несколько', $back);
        }
        return sanitize_html($html);
    }

    /** @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}> */
    private static function courseUploadedImages(): array
    {
        $f = $_FILES['images'] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = [
                'name' => (string) ($f['name'][$i] ?? ''),
                'type' => (string) ($f['type'][$i] ?? ''),
                'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
                'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($f['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /* ---------- прогресс ---------- */

    /** Фоновое сохранение позиции чтения (fetch/beacon): отвечает JSON, без redirect. */
    private static function doCourseProgress(array $user): void
    {
        $stepId = self::postInt('stepId');
        $percent = self::postInt('percent');
        $result = Course::markProgress((string) $user['id'], $stepId, $percent);
        $stats = Course::stats((string) $user['id']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'isRead' => (bool) ($result['isRead'] ?? false),
            'percent' => (int) ($result['scrollPercent'] ?? 0),
            'stepsRead' => $stats['stepsRead'],
            'stepsTotal' => $stats['stepsTotal'],
            'coursePercent' => $stats['percent'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Кнопка «Дальше»: завершает текущий шаг и открывает следующий.
     * Порядок проверяется на сервере — закрытый шаг завершить нельзя.
     */
    private static function doCompleteStep(array $user): void
    {
        $stepId = self::postInt('stepId');
        $step = Course::step($stepId);
        if (!$step) {
            self::fail('Шаг не найден', '/exams');
        }
        $userId = (string) $user['id'];
        $result = Course::completeStep($userId, $stepId, can_course_any($user));
        if (!$result['ok'] && ($result['reason'] ?? '') === 'quiz') {
            flash_set('error', 'Чтобы открыть следующий модуль, наберите не меньше ' . Equator::PASS_PERCENT . '% в проверке «Экватор».');
            redirect_to(Course::chapterUrl((int) $step['chapterId'], $stepId));
        }
        if (!$result['ok']) {
            $frontier = Course::frontierStep(Course::flow($userId));
            flash_set('error', 'Этот шаг ещё закрыт. Шаги открываются по порядку — продолжите с текущего.');
            redirect_to($frontier !== null ? '/exams/chapter/' . $frontier['chapterId'] . '?step=' . $frontier['stepId'] : '/exams');
        }
        $next = $result['next'] ?? null;
        if ($next === null) {
            flash_set('ok', 'Курс пройден — все шаги завершены');
            redirect_to('/exams');
        }
        if ((int) $next['chapterId'] !== (int) $step['chapterId']) {
            $chapter = Course::chapter((int) $step['chapterId']);
            flash_set('ok', 'Глава «' . ($chapter['title'] ?? '') . '» пройдена — открыта следующая');
        }
        redirect_to('/exams/chapter/' . (int) $next['chapterId'] . '?step=' . (int) $next['stepId']);
    }

    /** Сброс прогресса сотрудника (руководитель). */
    /**
     * Итог попытки «Экватора» (fetch, JSON). Результат пересчитывается по журналу нажатий.
     * Зачёт завершает шаг и открывает следующий модуль; после зачёта повторно пройти нельзя.
     */
    private static function doSubmitEquator(array $user): void
    {
        $reply = static function (int $status, array $body): never {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code($status);
            echo json_encode($body, JSON_UNESCAPED_UNICODE);
            exit;
        };
        $userId = (string) $user['id'];
        $stepId = self::postInt('stepId');
        if (!Equator::isStep($stepId)) {
            $reply(404, ['ok' => false, 'error' => 'Проверка не найдена']);
        }
        $flow = Course::flow($userId);
        if (!can_course_any($user) && !Course::stepUnlocked($flow, $stepId)) {
            $reply(403, ['ok' => false, 'error' => 'Проверка пока закрыта']);
        }
        if (Equator::passed($userId, $stepId)) {
            $reply(409, ['ok' => false, 'error' => 'Проверка уже зачтена — повторно её пройти нельзя']);
        }
        $logs = json_decode((string) ($_POST['logs'] ?? ''), true);
        $eval = Equator::evaluate(is_array($logs) ? array_values($logs) : []);
        if (!$eval['ok']) {
            $reply(422, ['ok' => false, 'error' => 'Не удалось проверить ответы — пройдите проверку ещё раз']);
        }
        $saved = Equator::saveAttempt($userId, $stepId, $eval);
        $next = null;
        if ($eval['passed']) {
            $done = Course::completeStep($userId, $stepId, can_course_any($user));
            $nextStep = $done['next'] ?? null;
            if ($nextStep !== null) {
                $chapter = Course::chapter((int) $nextStep['chapterId']);
                $next = [
                    'href' => Course::chapterUrl((int) $nextStep['chapterId'], (int) $nextStep['stepId']),
                    'module' => (string) ($chapter['module']['title'] ?? ''),
                    'chapter' => (string) ($chapter['title'] ?? ''),
                ];
            }
        }
        $reply(200, [
            'ok' => true,
            'passed' => $eval['passed'],
            'percent' => $eval['percent'],
            'firstTry' => $eval['firstTry'],
            'mistakes' => $eval['mistakes'],
            'total' => $eval['total'],
            'attempts' => (int) ($saved['attempts'] ?? 1),
            'next' => $next,
            'courseHref' => url_to('/exams'),
        ]);
    }

    private static function doResetCourseProgress(array $user): void
    {
        if (!can($user, 'course.manage') && !can($user, 'exams.manage')) {
            self::fail('Недостаточно прав для сброса прогресса', '/exams');
        }
        $userId = self::postStr('userId', 64);
        if ($userId === '') {
            self::fail('Сотрудник не выбран', '/exams');
        }
        $name = (string) Db::value('SELECT COALESCE(e.name, u.name, ?) FROM "user" u LEFT JOIN employees e ON e."userId" = u.id WHERE u.id = ?', ['Сотрудник', $userId]);
        $removed = Course::resetFor($userId);
        audit($user, 'course', 'reset', 'Сброшен прогресс обучения: ' . $name, url_to('/exams'));
        flash_set('ok', 'Прогресс обучения сброшен — ' . plural($removed, 'запись', 'записи', 'записей'));
        redirect_to('/exams');
    }

    /* ---------- разделы ---------- */

    private static function doSaveCourseModule(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $title = self::postStr('title', 160);
        if (mb_strlen($title) < 2) {
            self::fail('Впишите название раздела', '/exams');
        }
        $summary = self::postStr('summary', 2000);
        $kind = Course::normalizeKind((string) ($_POST['kind'] ?? 'lessons'));
        if ($id > 0) {
            if (Course::module($id, true) === null) {
                self::fail('Раздел не найден', '/exams');
            }
            Db::exec(
                'UPDATE course_modules SET title = ?, summary = ?, kind = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?',
                [$title, $summary, $kind, $user['id'], now_iso(), $id],
            );
            audit($user, 'course', 'update', 'Раздел курса изменён: ' . $title, url_to('/exams'));
            flash_set('ok', 'Раздел «' . $title . '» сохранён');
        } else {
            $order = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM course_modules') + 1;
            Db::insert('course_modules', [
                'title' => $title, 'summary' => $summary, 'kind' => $kind,
                'sortOrder' => $order, 'isActive' => 1,
                'updatedByUserId' => $user['id'], 'createdAt' => now_iso(), 'updatedAt' => now_iso(),
            ]);
            audit($user, 'course', 'create', 'Новый раздел курса: ' . $title, url_to('/exams'));
            flash_set('ok', 'Раздел «' . $title . '» создан — добавьте в него главы');
        }
        redirect_to('/exams');
    }

    private static function doMoveCourseModule(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $dir = self::postInt('dir');
        $ids = array_map('intval', array_column(Db::all('SELECT id FROM course_modules ORDER BY "sortOrder", id'), 'id'));
        self::courseMoveIn($ids, $id, $dir, static function (int $rowId, int $order): void {
            Db::exec('UPDATE course_modules SET "sortOrder" = ? WHERE id = ?', [$order, $rowId]);
        });
        Course::reindexModules();
        redirect_to('/exams');
    }

    private static function doToggleCourseModule(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $module = Course::module($id, true);
        if (!$module) {
            self::fail('Раздел не найден', '/exams');
        }
        $hide = boolish($module['isActive']);
        Db::exec('UPDATE course_modules SET "isActive" = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?', [$hide ? 0 : 1, $user['id'], now_iso(), $id]);
        audit($user, 'course', $hide ? 'hide' : 'show', 'Раздел курса ' . ($hide ? 'скрыт' : 'открыт') . ': ' . $module['title'], url_to('/exams'));
        flash_set('ok', 'Раздел «' . $module['title'] . '» ' . ($hide ? 'скрыт от сотрудников' : 'снова виден'));
        redirect_to('/exams');
    }

    private static function doDeleteCourseModule(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $module = Course::module($id, true);
        if (!$module) {
            self::fail('Раздел не найден', '/exams');
        }
        $title = (string) $module['title'];
        $next = Course::firstChapterIdOutsideModule($id);
        Db::tx(static function () use ($id): void {
            foreach (Db::all('SELECT id FROM course_chapters WHERE "moduleId" = ?', [$id]) as $row) {
                self::courseWipeChapter((int) $row['id']);
            }
            Db::exec('DELETE FROM course_modules WHERE id = ?', [$id]);
        });
        Course::reindexModules();
        audit($user, 'course', 'delete', 'Удалён раздел курса: ' . $title . ' вместе с главами и прогрессом', url_to('/exams'));
        flash_set('ok', 'Раздел «' . $title . '» удалён вместе с главами и прогрессом');
        if ($next !== null && Course::chapter($next) !== null) {
            redirect_to('/exams/chapter/' . $next);
        }
        redirect_to('/exams');
    }

    /* ---------- служебное ---------- */

    /** @param list<int> $ids */
    private static function courseMoveIn(array $ids, int $id, int $dir, callable $save): void
    {
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return;
        }
        $target = (int) $pos + ($dir >= 0 ? 1 : -1);
        if ($target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
        foreach ($ids as $i => $rowId) {
            $save($rowId, $i + 1);
        }
    }

    /** Удаляет шаг, его картинки и прогресс по нему. */
    private static function courseWipeStep(int $stepId): void
    {
        Db::exec('DELETE FROM course_progress WHERE "stepId" = ?', [$stepId]);
        foreach (Db::all('SELECT id, path FROM course_step_images WHERE "stepId" = ?', [$stepId]) as $img) {
            CourseMedia::delete((string) $img['path']);
        }
        Db::exec('DELETE FROM course_step_images WHERE "stepId" = ?', [$stepId]);
        Db::exec('DELETE FROM course_steps WHERE id = ?', [$stepId]);
    }

    /** Удаляет главу со всеми шагами, картинками и прогрессом. */
    private static function courseWipeChapter(int $chapterId): void
    {
        Db::exec('DELETE FROM course_progress WHERE "chapterId" = ?', [$chapterId]);
        foreach (Db::all('SELECT id FROM course_steps WHERE "chapterId" = ?', [$chapterId]) as $row) {
            self::courseWipeStep((int) $row['id']);
        }
        Db::exec('DELETE FROM course_chapters WHERE id = ?', [$chapterId]);
    }

    /* ---------- главы ---------- */

    private static function doCreateCourseChapter(array $user): void
    {
        self::coursePerm($user);
        $moduleId = self::postInt('moduleId');
        $module = Course::module($moduleId, true);
        if (!$module) {
            self::fail('Раздел не найден', '/exams');
        }
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 2) {
            $title = 'Новая глава';
        }
        $order = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM course_chapters WHERE "moduleId" = ?', [$moduleId]) + 1;
        $chapterId = Db::insert('course_chapters', [
            'moduleId' => $moduleId, 'title' => $title, 'summary' => '',
            'sortOrder' => $order, 'isActive' => 1,
            'updatedByUserId' => $user['id'], 'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ]);
        // Пустой шаг: глава сразу открывается, текст и картинки добавляются в редакторе.
        Db::insert('course_steps', [
            'chapterId' => $chapterId, 'title' => '', 'contentHtml' => '<p></p>',
            'sortOrder' => 1, 'isActive' => 1, 'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ]);
        audit($user, 'course', 'create', 'Новая глава курса: ' . $title . ' (раздел «' . $module['title'] . '»)', url_to('/exams/chapter/' . $chapterId));
        flash_set('ok', 'Глава «' . $title . '» создана — добавьте текст и шаги');
        redirect_to('/exams/chapter/' . $chapterId . '/edit');
    }

    private static function doSaveCourseMeta(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $chapter = Course::chapter($id, true);
        if (!$chapter) {
            self::fail('Глава не найдена', '/exams');
        }
        $back = '/exams/chapter/' . $id . '/edit';
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 2) {
            self::fail('Впишите название главы', $back);
        }
        $moduleId = self::postInt('moduleId');
        if (Course::module($moduleId, true) === null) {
            $moduleId = (int) $chapter['moduleId'];
        }
        $oldModuleId = (int) $chapter['moduleId'];
        $order = (int) $chapter['sortOrder'];
        if ($moduleId !== $oldModuleId) {
            $order = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM course_chapters WHERE "moduleId" = ?', [$moduleId]) + 1;
        }
        Db::exec(
            'UPDATE course_chapters SET title = ?, summary = ?, "moduleId" = ?, "sortOrder" = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?',
            [$title, self::postStr('summary', 2000), $moduleId, $order, $user['id'], now_iso(), $id],
        );
        Course::reindexChapters($oldModuleId);
        Course::reindexChapters($moduleId);
        audit($user, 'course', 'update', 'Глава курса изменена: ' . $title, url_to('/exams/chapter/' . $id));
        flash_set('ok', 'Глава сохранена');
        redirect_to($back);
    }

    private static function doMoveCourseChapter(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $dir = self::postInt('dir');
        $chapter = Course::chapter($id, true);
        if (!$chapter) {
            self::fail('Глава не найдена', '/exams');
        }
        $moduleId = (int) $chapter['moduleId'];
        $ids = array_map('intval', array_column(
            Db::all('SELECT id FROM course_chapters WHERE "moduleId" = ? ORDER BY "sortOrder", id', [$moduleId]),
            'id',
        ));
        self::courseMoveIn($ids, $id, $dir, static function (int $rowId, int $order): void {
            Db::exec('UPDATE course_chapters SET "sortOrder" = ? WHERE id = ?', [$order, $rowId]);
        });
        Course::reindexChapters($moduleId);
        redirect_to('/exams');
    }

    private static function doToggleCourseChapter(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $chapter = Course::chapter($id, true);
        if (!$chapter) {
            self::fail('Глава не найдена', '/exams');
        }
        $hide = (bool) $chapter['isActive'];
        Db::exec('UPDATE course_chapters SET "isActive" = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?', [$hide ? 0 : 1, $user['id'], now_iso(), $id]);
        audit($user, 'course', $hide ? 'hide' : 'show', 'Глава курса ' . ($hide ? 'скрыта' : 'открыта') . ': ' . $chapter['title'], url_to('/exams'));
        flash_set('ok', 'Глава «' . $chapter['title'] . '» ' . ($hide ? 'скрыта от сотрудников' : 'снова видна'));
        redirect_to('/exams');
    }

    private static function doDeleteCourseChapter(array $user): void
    {
        self::coursePerm($user);
        $id = self::postInt('id');
        $chapter = Course::chapter($id, true);
        if (!$chapter) {
            self::fail('Глава не найдена', '/exams');
        }
        $title = (string) $chapter['title'];
        $moduleId = (int) $chapter['moduleId'];
        $next = Course::nextChapterId($id);
        if ($next === null) {
            $next = Course::prevChapterId($id);
        }
        Db::tx(static function () use ($id): void {
            self::courseWipeChapter($id);
        });
        Course::reindexChapters($moduleId);
        audit($user, 'course', 'delete', 'Удалена глава курса: ' . $title . ' вместе с шагами и прогрессом', url_to('/exams'));
        flash_set('ok', 'Глава «' . $title . '» удалена вместе с шагами и прогрессом');
        if ($next !== null && Course::chapter($next) !== null) {
            redirect_to('/exams/chapter/' . $next);
        }
        redirect_to('/exams');
    }

    /* ---------- шаги внутри главы ---------- */

    private static function doCreateCourseStep(array $user): void
    {
        self::coursePerm($user);
        $chapterId = self::postInt('chapterId');
        $chapter = Course::chapter($chapterId, true);
        if (!$chapter) {
            self::fail('Глава не найдена', '/exams');
        }
        $order = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM course_steps WHERE "chapterId" = ?', [$chapterId]) + 1;
        $newId = Db::insert('course_steps', [
            'chapterId' => $chapterId, 'title' => '', 'contentHtml' => '<p></p>',
            'sortOrder' => $order, 'isActive' => 1, 'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ]);
        audit($user, 'course', 'create', 'Новый шаг в главе: ' . $chapter['title'], url_to('/exams/chapter/' . $chapterId . '/edit'));
        flash_set('ok', 'Шаг добавлен — впишите текст');
        redirect_to('/exams/chapter/' . $chapterId . '/edit?step=' . (int) $newId);
    }

    private static function doSaveCourseStep(array $user): void
    {
        self::coursePerm($user, 'edit');
        $stepId = self::postInt('stepId');
        $step = Course::step($stepId);
        if (!$step) {
            self::fail('Шаг не найден', '/exams');
        }
        $back = '/exams/chapter/' . (int) $step['chapterId'] . '/edit?step=' . $stepId;
        $title = self::postStr('title', 200);
        $html = Course::normalizeImageSrc(self::courseHtmlFromPost($back));
        Db::exec(
            'UPDATE course_steps SET title = ?, "contentHtml" = ?, "updatedAt" = ? WHERE id = ?',
            [$title, $html, now_iso(), $stepId],
        );

        // Удаление отмеченных картинок: только свои и только из этой формы.
        $remove = array_map('intval', (array) ($_POST['removeImages'] ?? []));
        if ($remove) {
            $own = array_map(static fn (array $img): int => (int) $img['id'], Course::imagesFor([$stepId])[$stepId] ?? []);
            foreach (array_intersect($remove, $own) as $imageId) {
                $row = Db::one('SELECT path FROM course_step_images WHERE id = ? AND "stepId" = ?', [(int) $imageId, $stepId]);
                if ($row !== null) {
                    CourseMedia::delete((string) $row['path']);
                    Db::exec('DELETE FROM course_step_images WHERE id = ?', [(int) $imageId]);
                }
            }
        }

        // Новые картинки: загрузка проверяется в CourseMedia (размер, MIME, расширение).
        $added = 0;
        try {
            $order = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM course_step_images WHERE "stepId" = ?', [$stepId]);
            foreach (self::courseUploadedImages() as $file) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $name = CourseMedia::saveUpload($file, 'step');
                $order++;
                Db::insert('course_step_images', [
                    'stepId' => $stepId, 'path' => $name, 'alt' => $title !== '' ? $title : 'Иллюстрация к шагу',
                    'sortOrder' => $order, 'createdAt' => now_iso(),
                ]);
                $added++;
            }
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
            redirect_to($back);
        }
        Course::reindexImages($stepId);
        audit($user, 'course', 'update', 'Шаг главы изменён: ' . ($title !== '' ? $title : 'без названия') . ($added > 0 ? ', картинок: ' . $added : ''), url_to($back));
        flash_set('ok', 'Шаг сохранён' . ($added > 0 ? ' — картинок добавлено: ' . $added : ''));
        redirect_to($back);
    }

    private static function doDeleteCourseStep(array $user): void
    {
        self::coursePerm($user);
        $stepId = self::postInt('stepId');
        $step = Course::step($stepId);
        if (!$step) {
            self::fail('Шаг не найден', '/exams');
        }
        $chapterId = (int) $step['chapterId'];
        $total = (int) Db::value('SELECT COUNT(*) FROM course_steps WHERE "chapterId" = ?', [$chapterId]);
        if ($total <= 1) {
            self::fail('В главе должен остаться хотя бы один шаг. Удалите главу целиком.', '/exams/chapter/' . $chapterId . '/edit');
        }
        Db::tx(static function () use ($stepId): void {
            self::courseWipeStep($stepId);
        });
        Course::reindexSteps($chapterId);
        audit($user, 'course', 'delete', 'Удалён шаг главы №' . $chapterId, url_to('/exams/chapter/' . $chapterId . '/edit'));
        flash_set('ok', 'Шаг удалён');
        redirect_to('/exams/chapter/' . $chapterId . '/edit');
    }

    private static function doMoveCourseStep(array $user): void
    {
        self::coursePerm($user);
        $stepId = self::postInt('stepId');
        $dir = self::postInt('dir');
        $step = Course::step($stepId);
        if (!$step) {
            self::fail('Шаг не найден', '/exams');
        }
        $chapterId = (int) $step['chapterId'];
        $ids = array_map('intval', array_column(
            Db::all('SELECT id FROM course_steps WHERE "chapterId" = ? ORDER BY "sortOrder", id', [$chapterId]),
            'id',
        ));
        self::courseMoveIn($ids, $stepId, $dir, static function (int $rowId, int $order): void {
            Db::exec('UPDATE course_steps SET "sortOrder" = ? WHERE id = ?', [$order, $rowId]);
        });
        Course::reindexSteps($chapterId);
        redirect_to('/exams/chapter/' . $chapterId . '/edit?step=' . $stepId);
    }

    /**
     * Фото прямо в текст шага (кнопка «Фото», вставка из буфера, перетаскивание).
     * Отвечает JSON: src — постоянный адрес для HTML шага, url — адрес для показа сейчас.
     */
    private static function doUploadCourseImage(array $user): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $reply = static function (int $status, array $body): never {
            http_response_code($status);
            echo json_encode($body, JSON_UNESCAPED_UNICODE);
            exit;
        };
        if (!can_course($user, 'edit')) {
            $reply(403, ['ok' => false, 'error' => 'Нет права «Курс: тексты и фото»']);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            $reply(400, ['ok' => false, 'error' => 'Файл не выбран']);
        }
        try {
            $name = CourseMedia::saveUpload($file, 'step');
        } catch (RuntimeException $e) {
            $reply(400, ['ok' => false, 'error' => $e->getMessage()]);
        }
        Course::markCustomized();
        $size = @getimagesize((string) CourseMedia::path($name)) ?: [0, 0];
        audit($user, 'course', 'update', 'Фото в шаг курса: ' . $name, url_to('/exams'));
        $reply(200, [
            'ok' => true,
            'src' => '/assets/course_images/' . $name,
            'url' => CourseMedia::url($name),
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ]);
    }

    /* ---------- настройки курса ---------- */

    private static function doSaveCourseSettings(array $user): void
    {
        self::coursePerm($user, 'settings', '/exams/settings');
        $before = Course::settings();
        $saved = Course::saveSettings([
            'title' => self::postStr('title', 120),
            'subtitle' => self::postStr('subtitle', 400),
            'rule' => self::postStr('rule', 600),
            'passMode' => self::postStr('passMode', 16),
            'requireInteractions' => isset($_POST['requireInteractions']),
            'newYear' => isset($_POST['newYear']),
        ]);
        $changes = [];
        if ($before['passMode'] !== $saved['passMode']) {
            $changes[] = $saved['passMode'] === 'free' ? 'свободный порядок' : 'строго по порядку';
        }
        if ($before['newYear'] !== $saved['newYear']) {
            $changes[] = $saved['newYear'] ? 'новогоднее оформление включено' : 'новогоднее оформление выключено';
        }
        audit($user, 'course', 'update', 'Настройки курса сохранены' . ($changes ? ': ' . implode(', ', $changes) : ''), url_to('/exams/settings'));
        flash_set('ok', 'Настройки курса сохранены');
        redirect_to('/exams/settings');
    }

    /* ---------- импорт ---------- */

    private static function doImportCourse(array $user): void
    {
        self::coursePerm($user, 'full');
        $force = isset($_POST['force']);
        $result = Course::importFromFiles($force);
        if (!empty($result['imported'])) {
            audit($user, 'course', 'import', 'Импорт курса из course_content/course.json: разделов ' . $result['modules'] . ', глав ' . $result['chapters'] . ', шагов ' . $result['steps'], url_to('/exams'));
            flash_set('ok', 'Импорт готов: разделов ' . $result['modules'] . ', глав ' . $result['chapters'] . ', шагов ' . $result['steps'] . ', картинок ' . $result['images']);
        } else {
            $reason = match ((string) $result['reason']) {
                'already' => 'эта версия курса уже импортирована',
                'no-files' => 'не найден файл course_content/course.json',
                default => 'нет данных для импорта',
            };
            flash_set('error', 'Импорт не выполнен: ' . $reason);
        }
        redirect_to('/exams');
    }
}




