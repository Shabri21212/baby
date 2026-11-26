<?php
declare(strict_types=1);

/**
 * Курс обучения: разделы (модули) → главы → шаги.
 *
 * Структура живёт в базе (course_modules / course_chapters / course_steps).
 * Исходный текст курса — course_content/course.json (собирается scripts/build-course.cjs);
 * он импортируется в базу при первом открытии «Обучения» и повторно — только при смене
 * версии файла (Course::importIfNeeded). Номера глав считаются по позиции, а не хранятся.
 *
 * Прохождение строго последовательное: шаги всех учебных глав выстроены в одну цепочку,
 * открыт только первый незавершённый шаг и всё, что до него. Следующий шаг открывается
 * кнопкой «Дальше» (Course::completeStep). Руководитель с course.manage видит всё.
 *
 * Прогресс хранится по шагам (course_progress), главы и модули — агрегаты.
 */
final class Course
{
    private const SEED_MARK = 'course_seed_version';

    /** Готовый курс в JSON (нужен только для импорта). */
    public static function seedFile(): string
    {
        return dirname(__DIR__) . '/course_content/course.json';
    }

    /** @return array{version: string, modules: list<array>}|null */
    public static function seed(): ?array
    {
        $file = self::seedFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['version'], $data['modules']) || !is_array($data['modules'])) {
            return null;
        }
        return ['version' => (string) $data['version'], 'modules' => $data['modules']];
    }

    /**
     * Дерево курса. $withHidden = true — для админки, со скрытыми разделами и главами.
     */
    public static function tree(bool $withHidden = false): array
    {
        $filter = $withHidden ? '' : ' WHERE "isActive" = 1';
        $modules = Db::all('SELECT * FROM course_modules' . $filter . ' ORDER BY "sortOrder", id');
        if (!$modules) {
            return [];
        }
        $chapters = Db::all('SELECT * FROM course_chapters' . $filter . ' ORDER BY "sortOrder", id');
        $counts = [];
        foreach (Db::all('SELECT "chapterId", COUNT(*) AS n FROM course_steps' . $filter . ' GROUP BY "chapterId"') as $r) {
            $counts[(int) $r['chapterId']] = (int) $r['n'];
        }
        $byModule = [];
        foreach ($chapters as $c) {
            $byModule[(int) $c['moduleId']][] = $c;
        }
        $out = [];
        $n = 0;
        $moduleNo = 0;
        foreach ($modules as $m) {
            $m['id'] = (int) $m['id'];
            $m['sortOrder'] = (int) $m['sortOrder'];
            $m['isActive'] = boolish($m['isActive']);
            // Введение идёт без номера: «Модуль 1» — первый учебный раздел, как в самом курсе.
            $m['number'] = ($m['kind'] ?? 'lessons') === 'intro' ? 0 : ++$moduleNo;
            // Главы нумеруются внутри раздела (01…N), как в исходном курсе.
            $n = 0;
            $list = [];
            foreach ($byModule[$m['id']] ?? [] as $c) {
                $n++;
                $c['number'] = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
                $c['id'] = (int) $c['id'];
                $c['moduleId'] = (int) $c['moduleId'];
                $c['sortOrder'] = (int) $c['sortOrder'];
                $c['isActive'] = boolish($c['isActive']);
                $c['stepsCount'] = $counts[$c['id']] ?? 0;
                $list[] = $c;
            }
            $m['chapters'] = $list;
            $m['stepsCount'] = array_sum(array_column($list, 'stepsCount'));
            $out[] = $m;
        }
        return $out;
    }

    /** Тип раздела: lessons — главы с номером, intro — введение без номера, exam — финальный экзамен. */
    public static function normalizeKind(string $kind): string
    {
        return in_array($kind, ['intro', 'exam'], true) ? $kind : 'lessons';
    }

    /** «Модуль 2. Экосистема продуктов» или просто «Введение». */
    public static function moduleLabel(array $module): string
    {
        $number = (int) ($module['number'] ?? 0);
        return $number > 0 ? 'Модуль ' . $number . '. ' . $module['title'] : (string) $module['title'];
    }

    /** Плоский список глав в порядке курса с готовыми номерами и ссылками. */
    public static function chapters(bool $withHidden = false): array
    {
        $out = [];
        foreach (self::tree($withHidden) as $module) {
            foreach ($module['chapters'] as $chapter) {
                $chapter['moduleNumber'] = (int) $module['number'];
                $chapter['moduleTitle'] = $module['title'];
                $chapter['moduleKind'] = (string) $module['kind'];
                $chapter['href'] = self::chapterUrl($chapter['id']);
                $out[] = $chapter;
            }
        }
        return $out;
    }

    /** Главы учебных разделов: раздел экзамена (kind=exam) в список чтения не входит. */
    public static function lessonChapters(bool $withHidden = false): array
    {
        return array_values(array_filter(
            self::chapters($withHidden),
            static fn (array $c): bool => ($c['moduleKind'] ?? 'lessons') !== 'exam',
        ));
    }

    public static function chapterUrl(int $chapterId, ?int $stepId = null): string
    {
        return url_to('/exams/chapter/' . $chapterId) . ($stepId !== null ? '?step=' . $stepId : '');
    }

    public static function chapter(int $id, bool $withHidden = false): ?array
    {
        $c = Db::one('SELECT * FROM course_chapters WHERE id = ?', [$id]);
        if (!$c || (!$withHidden && !boolish($c['isActive']))) {
            return null;
        }
        $c['id'] = (int) $c['id'];
        $c['moduleId'] = (int) $c['moduleId'];
        $c['sortOrder'] = (int) $c['sortOrder'];
        $c['isActive'] = boolish($c['isActive']);
        $module = Db::one('SELECT * FROM course_modules WHERE id = ?', [$c['moduleId']]);
        if (!$module) {
            return null;
        }
        $module['id'] = (int) $module['id'];
        $module['isActive'] = boolish($module['isActive']);
        $c['module'] = $module;
        $c['steps'] = self::steps($c['id'], $withHidden);
        $c['href'] = self::chapterUrl($c['id']);
        return $c;
    }

    public static function module(int $id, bool $withHidden = false): ?array
    {
        $m = Db::one('SELECT * FROM course_modules WHERE id = ?', [$id]);
        if (!$m || (!$withHidden && !boolish($m['isActive']))) {
            return null;
        }
        $m['id'] = (int) $m['id'];
        $m['isActive'] = boolish($m['isActive']);
        $m['chapters'] = array_values(array_filter(
            self::chapters($withHidden),
            static fn (array $c): bool => $c['moduleId'] === $m['id'],
        ));
        return $m;
    }

    public static function steps(int $chapterId, bool $withHidden = false): array
    {
        $rows = Db::all(
            'SELECT * FROM course_steps WHERE "chapterId" = ?' . ($withHidden ? '' : ' AND "isActive" = 1') . ' ORDER BY "sortOrder", id',
            [$chapterId],
        );
        if (!$rows) {
            return [];
        }
        $images = self::imagesFor(array_map(static fn ($r): int => (int) $r['id'], $rows));
        $out = [];
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            $r['id'] = (int) $r['id'];
            $r['chapterId'] = (int) $r['chapterId'];
            $r['sortOrder'] = (int) $r['sortOrder'];
            $r['isActive'] = boolish($r['isActive']);
            $r['number'] = $n;
            $r['images'] = $images[$r['id']] ?? [];
            $out[] = $r;
        }
        return $out;
    }

    public static function step(int $id): ?array
    {
        $s = Db::one('SELECT * FROM course_steps WHERE id = ?', [$id]);
        if (!$s) {
            return null;
        }
        $s['id'] = (int) $s['id'];
        $s['chapterId'] = (int) $s['chapterId'];
        $s['sortOrder'] = (int) $s['sortOrder'];
        $s['isActive'] = boolish($s['isActive']);
        $s['images'] = self::imagesFor([$s['id']])[$s['id']] ?? [];
        return $s;
    }

    /** @param list<int> $stepIds */
    public static function imagesFor(array $stepIds): array
    {
        $stepIds = array_values(array_filter(array_map('intval', $stepIds), static fn (int $v): bool => $v > 0));
        if (!$stepIds) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT * FROM course_step_images WHERE "stepId" IN (' . implode(',', $stepIds) . ') ORDER BY "sortOrder", id') as $r) {
            $out[(int) $r['stepId']][] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['path'],
                'alt' => (string) $r['alt'],
                'url' => CourseMedia::url((string) $r['path']),
            ];
        }
        return $out;
    }

    /**
     * HTML шага для показа: очистка + картинки курса через CourseMedia (портал может жить в подпапке,
     * а файлы — в storage/). Картинки грузятся лениво.
     */
    /**
     * Перед сохранением шага: адрес картинки курса из редактора (с базой, ?v= или через
     * /media/course/) приводится к постоянному /assets/course_images/<файл>.
     */
    public static function normalizeImageSrc(string $html): string
    {
        return preg_replace(
            '#src="[^"]*?/(?:assets/course_images|media/course)/([A-Za-z0-9._-]+)(?:\?[^"]*)?"#',
            'src="/assets/course_images/$1"',
            $html,
        ) ?? $html;
    }

    public static function renderHtml(string $html): string
    {
        $safe = sanitize_html($html);
        $safe = preg_replace_callback(
            '#<img src="/assets/course_images/([A-Za-z0-9._-]+)"#',
            static fn (array $m): string => '<img src="' . h(CourseMedia::url($m[1])) . '"',
            $safe,
        ) ?? $safe;
        return str_replace('<img ', '<img loading="lazy" decoding="async" ', $safe);
    }

    /* ---------- навигация ---------- */

    public static function firstChapterId(bool $withHidden = false): ?int
    {
        $list = self::lessonChapters($withHidden);
        return $list[0]['id'] ?? null;
    }

    public static function nextChapterId(int $chapterId, bool $withHidden = false): ?int
    {
        $list = self::lessonChapters($withHidden);
        foreach ($list as $i => $c) {
            if ($c['id'] === $chapterId) {
                return $list[$i + 1]['id'] ?? null;
            }
        }
        return null;
    }

    public static function prevChapterId(int $chapterId, bool $withHidden = false): ?int
    {
        $list = self::lessonChapters($withHidden);
        foreach ($list as $i => $c) {
            if ($c['id'] === $chapterId) {
                return $i > 0 ? $list[$i - 1]['id'] : null;
            }
        }
        return null;
    }

    /** Первая глава любого другого раздела — куда увести после удаления модуля. */
    public static function firstChapterIdOutsideModule(int $moduleId): ?int
    {
        foreach (self::lessonChapters() as $c) {
            if ($c['moduleId'] !== $moduleId) {
                return $c['id'];
            }
        }
        return null;
    }

    /* ---------- последовательное прохождение ---------- */

    /**
     * Цепочка всех видимых шагов учебных глав в порядке курса и «фронтир» —
     * индекс первого незавершённого шага. Открыто всё с индексом <= frontier.
     *
     * @return array{order: list<array{stepId: int, chapterId: int, read: bool}>, index: array<int, int>,
     *               frontier: int, chapterRange: array<int, array{0: int, 1: int}>, complete: bool}
     */
    public static function flow(string $userId): array
    {
        $chapterIds = array_map(static fn (array $c): int => (int) $c['id'], self::lessonChapters());
        $byChapter = [];
        if ($chapterIds) {
            $rows = Db::all(
                'SELECT id, "chapterId" FROM course_steps WHERE "isActive" = 1 AND "chapterId" IN (' . implode(',', $chapterIds) . ') ORDER BY "sortOrder", id',
            );
            foreach ($rows as $r) {
                $byChapter[(int) $r['chapterId']][] = (int) $r['id'];
            }
        }
        $progress = self::progressFor($userId);
        $order = [];
        $index = [];
        $range = [];
        $frontier = null;
        foreach ($chapterIds as $cid) {
            $ids = $byChapter[$cid] ?? [];
            if (!$ids) {
                continue;
            }
            $range[$cid] = [count($order), count($order) + count($ids) - 1];
            foreach ($ids as $sid) {
                $read = !empty($progress[$sid]['isRead']);
                $index[$sid] = count($order);
                if (!$read && $frontier === null) {
                    $frontier = count($order);
                }
                $order[] = ['stepId' => $sid, 'chapterId' => $cid, 'read' => $read];
            }
        }
        return [
            'order' => $order,
            'index' => $index,
            'frontier' => $frontier ?? count($order),
            'chapterRange' => $range,
            'complete' => $frontier === null && $order !== [],
            // Свободный порядок (настройка курса): открыто всё, «текущий» шаг — лишь подсказка.
            'free' => self::settings()['passMode'] === 'free',
        ];
    }

    public static function stepUnlocked(array $flow, int $stepId): bool
    {
        if (!isset($flow['index'][$stepId])) {
            return false;
        }
        return !empty($flow['free']) || $flow['index'][$stepId] <= $flow['frontier'];
    }

    /** done — все шаги главы пройдены, current — глава открыта и не пройдена, locked — глава ещё закрыта. */
    public static function chapterState(array $flow, int $chapterId): string
    {
        $range = $flow['chapterRange'][$chapterId] ?? null;
        if ($range === null) {
            return 'empty';
        }
        if (!empty($flow['free'])) {
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if (empty($flow['order'][$i]['read'])) {
                    return 'current';
                }
            }
            return 'done';
        }
        if ($range[1] < $flow['frontier']) {
            return 'done';
        }
        return $range[0] <= $flow['frontier'] ? 'current' : 'locked';
    }

    /** Текущий (первый незавершённый) шаг курса или null, если всё пройдено. */
    public static function frontierStep(array $flow): ?array
    {
        return $flow['order'][$flow['frontier']] ?? null;
    }

    /** Шаг, следующий за $stepId в цепочке курса. */
    public static function nextInFlow(array $flow, int $stepId): ?array
    {
        $i = $flow['index'][$stepId] ?? null;
        return $i === null ? null : ($flow['order'][$i + 1] ?? null);
    }

    /** Шаг перед $stepId в цепочке курса. */
    public static function prevInFlow(array $flow, int $stepId): ?array
    {
        $i = $flow['index'][$stepId] ?? null;
        return $i === null || $i === 0 ? null : ($flow['order'][$i - 1] ?? null);
    }

    /**
     * Завершает шаг и открывает следующий. Закрытый шаг завершить нельзя —
     * так нельзя «перепрыгнуть» главу, даже отправив форму вручную.
     *
     * @return array{ok: bool, reason?: string, next?: ?array}
     */
    public static function completeStep(string $userId, int $stepId, bool $bypassOrder = false): array
    {
        $flow = self::flow($userId);
        if (!isset($flow['index'][$stepId])) {
            return ['ok' => false, 'reason' => 'missing'];
        }
        if (!$bypassOrder && !self::stepUnlocked($flow, $stepId)) {
            return ['ok' => false, 'reason' => 'locked'];
        }
        // «Экватор» завершается только зачётом проверки — иначе дальше курс не откроется.
        if (Equator::isStep($stepId) && !Equator::passed($userId, $stepId)) {
            return ['ok' => false, 'reason' => 'quiz'];
        }
        self::markProgress($userId, $stepId, 100, true);
        return ['ok' => true, 'next' => self::nextInFlow($flow, $stepId)];
    }

    /* ---------- прогресс ---------- */

    /** @return array<int, array{isRead: bool, scrollPercent: int}> */
    public static function progressFor(string $userId): array
    {
        $out = [];
        foreach (Db::all('SELECT "stepId", "isRead", "scrollPercent" FROM course_progress WHERE "userId" = ?', [$userId]) as $r) {
            $out[(int) $r['stepId']] = ['isRead' => boolish($r['isRead']), 'scrollPercent' => (int) $r['scrollPercent']];
        }
        return $out;
    }

    /** @return array<int, array{total: int, read: int, percent: int}> */
    public static function chapterStats(string $userId): array
    {
        $out = [];
        $sql = 'SELECT s."chapterId" AS cid, COUNT(*) AS total, SUM(CASE WHEN p."isRead" = 1 THEN 1 ELSE 0 END) AS "readCount"
                FROM course_steps s
                LEFT JOIN course_progress p ON p."stepId" = s."id" AND p."userId" = ?
                WHERE s."isActive" = 1
                GROUP BY s."chapterId"';
        foreach (Db::all($sql, [$userId]) as $r) {
            $total = (int) $r['total'];
            $read = (int) $r['readCount'];
            $out[(int) $r['cid']] = [
                'total' => $total,
                'read' => min($read, $total),
                'percent' => $total > 0 ? (int) round($read / $total * 100) : 0,
            ];
        }
        return $out;
    }

    /**
     * Сводка по курсу для одного сотрудника.
     * «Следующая цель» — глава с текущим шагом цепочки.
     */
    public static function stats(string $userId): array
    {
        $chapters = self::lessonChapters();
        $perChapter = self::chapterStats($userId);
        $flow = self::flow($userId);
        $stepsTotal = count($flow['order']);
        $stepsRead = count(array_filter($flow['order'], static fn (array $s): bool => $s['read']));
        $chaptersDone = 0;
        $last = null;
        foreach ($chapters as $c) {
            $total = (int) $c['stepsCount'];
            if ($total > 0 && (int) ($perChapter[$c['id']]['read'] ?? 0) >= $total) {
                $chaptersDone++;
                $last = $c['id'];
            }
        }
        $frontier = self::frontierStep($flow);
        $modules = 0;
        $modulesDone = 0;
        foreach (self::tree() as $m) {
            if (($m['kind'] ?? 'lessons') === 'exam' || !$m['chapters']) {
                continue;
            }
            $modules++;
            $all = true;
            foreach ($m['chapters'] as $c) {
                $total = (int) $c['stepsCount'];
                if ($total <= 0 || (int) ($perChapter[$c['id']]['read'] ?? 0) < $total) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $modulesDone++;
            }
        }
        return [
            'stepsTotal' => $stepsTotal,
            'stepsRead' => $stepsRead,
            'chaptersTotal' => count($chapters),
            'chaptersDone' => $chaptersDone,
            'modulesTotal' => $modules,
            'modulesDone' => $modulesDone,
            'percent' => $stepsTotal > 0 ? (int) round($stepsRead / $stepsTotal * 100) : 0,
            'nextChapterId' => $frontier['chapterId'] ?? null,
            'nextStepId' => $frontier['stepId'] ?? null,
            'lastChapterId' => $last,
            'complete' => $flow['complete'],
        ];
    }

    /**
     * Запоминает позицию чтения шага. Прочитанным шаг становится только явно
     * ($forceRead — кнопка «Дальше»): скролл сам по себе ничего не открывает.
     */
    public static function markProgress(string $userId, int $stepId, int $percent, bool $forceRead = false): array
    {
        $step = self::step($stepId);
        if (!$step) {
            return ['ok' => false];
        }
        $percent = max(0, min(100, $percent));
        $now = now_iso();
        $row = Db::one('SELECT id, "isRead", "readAt", "scrollPercent" FROM course_progress WHERE "userId" = ? AND "stepId" = ?', [$userId, $stepId]);
        $read = $forceRead || ($row !== null && boolish($row['isRead']));
        $readAt = $read ? ((string) ($row['readAt'] ?? '') !== '' ? (string) $row['readAt'] : $now) : null;
        if ($row !== null) {
            Db::exec(
                'UPDATE course_progress SET "chapterId" = ?, "isRead" = ?, "scrollPercent" = ?, "readAt" = ?, "updatedAt" = ? WHERE id = ?',
                [(int) $step['chapterId'], $read ? 1 : 0, $read ? max($percent, (int) $row['scrollPercent']) : $percent, $readAt, $now, (int) $row['id']],
            );
        } else {
            try {
                Db::insert('course_progress', [
                    'userId' => $userId, 'stepId' => $stepId, 'chapterId' => (int) $step['chapterId'],
                    'isRead' => $read ? 1 : 0, 'scrollPercent' => $percent,
                    'openedAt' => $now, 'readAt' => $readAt, 'updatedAt' => $now,
                ]);
            } catch (Throwable $e) {
                // Параллельный запрос успел создать строку — тогда просто обновляем.
                Db::exec(
                    'UPDATE course_progress SET "chapterId" = ?, "isRead" = ?, "scrollPercent" = ?, "readAt" = ?, "updatedAt" = ? WHERE "userId" = ? AND "stepId" = ?',
                    [(int) $step['chapterId'], $read ? 1 : 0, $percent, $readAt, $now, $userId, $stepId],
                );
            }
        }
        return ['ok' => true, 'isRead' => $read, 'scrollPercent' => $percent];
    }

    public static function resetFor(string $userId): int
    {
        Equator::resetFor($userId);
        return Db::exec('DELETE FROM course_progress WHERE "userId" = ?', [$userId]);
    }

    /* ---------- финальный экзамен ---------- */

    public static function finalExam(bool $publishedOnly = true): ?array
    {
        $e = Db::one(
            'SELECT * FROM exams WHERE "isFinal" = 1' . ($publishedOnly ? ' AND "isDraft" = 0' : '') . ' ORDER BY "sortOrder", id LIMIT 1',
        );
        if (!$e) {
            return null;
        }
        $e['id'] = (int) $e['id'];
        $e['passPercent'] = (int) $e['passPercent'];
        $e['xpReward'] = max(1, (int) ($e['xpReward'] ?? 100));
        $e['isDraft'] = boolish($e['isDraft']);
        $e['isFinal'] = boolish($e['isFinal']);
        return $e;
    }

    /** Один финальный экзамен на курс: снимает флаг с остальных. */
    public static function makeFinal(int $examId): void
    {
        Db::exec('UPDATE exams SET "isFinal" = 0 WHERE id <> ?', [$examId]);
        Db::exec('UPDATE exams SET "isFinal" = 1 WHERE id = ?', [$examId]);
    }

    /* ---------- порядок ---------- */

    public static function reindexModules(): void
    {
        $i = 0;
        foreach (Db::all('SELECT id FROM course_modules ORDER BY "sortOrder", id') as $r) {
            $i++;
            Db::exec('UPDATE course_modules SET "sortOrder" = ? WHERE id = ?', [$i, (int) $r['id']]);
        }
    }

    public static function reindexChapters(int $moduleId): void
    {
        $i = 0;
        foreach (Db::all('SELECT id FROM course_chapters WHERE "moduleId" = ? ORDER BY "sortOrder", id', [$moduleId]) as $r) {
            $i++;
            Db::exec('UPDATE course_chapters SET "sortOrder" = ? WHERE id = ?', [$i, (int) $r['id']]);
        }
    }

    public static function reindexSteps(int $chapterId): void
    {
        $i = 0;
        foreach (Db::all('SELECT id FROM course_steps WHERE "chapterId" = ? ORDER BY "sortOrder", id', [$chapterId]) as $r) {
            $i++;
            Db::exec('UPDATE course_steps SET "sortOrder" = ? WHERE id = ?', [$i, (int) $r['id']]);
        }
    }

    public static function reindexImages(int $stepId): void
    {
        $i = 0;
        foreach (Db::all('SELECT id FROM course_step_images WHERE "stepId" = ? ORDER BY "sortOrder", id', [$stepId]) as $r) {
            $i++;
            Db::exec('UPDATE course_step_images SET "sortOrder" = ? WHERE id = ?', [$i, (int) $r['id']]);
        }
    }

    /* ---------- импорт из course_content/course.json ---------- */

    /**
     * Импорт курса, если версия файла отличается от импортированной.
     * Новая версия полностью заменяет разделы, главы, шаги и прогресс по ним:
     * шаги другие — старые отметки к ним не относятся.
     */
    /* ---------- настройки курса (portal_content.course_settings) ---------- */

    private const SETTINGS_KEY = 'course_settings';
    private const CUSTOMIZED_MARK = 'course_customized_at';

    public const SETTINGS_DEFAULTS = [
        'title' => 'Курс обучения',
        'subtitle' => 'Главы, шаги и финальный экзамен. Прогресс сохраняется за вашей учётной записью — можно продолжить с любого устройства.',
        'rule' => 'Все модули обязательные. Шаги открываются строго по порядку: следующий станет доступен после завершения текущего. Пройденные шаги можно перечитывать.',
        // strict — шаги по порядку, free — любые главы в любом порядке.
        'passMode' => 'strict',
        // Перед «Дальше» дочитать шаг, открыть все ситуации и решить вопросы.
        'requireInteractions' => true,
        // Новогоднее оформление портала: гирлянда, снег, праздничные иллюстрации.
        'newYear' => true,
    ];

    private static ?array $settingsCache = null;

    /** @return array{title: string, subtitle: string, rule: string, passMode: string, requireInteractions: bool, newYear: bool} */
    public static function settings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        $out = self::SETTINGS_DEFAULTS;
        try {
            $raw = Db::value('SELECT "valueJson" FROM portal_content WHERE "key" = ?', [self::SETTINGS_KEY]);
            $saved = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($saved)) {
                foreach ($out as $k => $default) {
                    if (array_key_exists($k, $saved)) {
                        $out[$k] = is_bool($default) ? (bool) $saved[$k] : (string) $saved[$k];
                    }
                }
            }
        } catch (Throwable) {
            // Таблицы ещё нет (первый запуск) — работаем на значениях по умолчанию.
        }
        $out['passMode'] = $out['passMode'] === 'free' ? 'free' : 'strict';
        return self::$settingsCache = $out;
    }

    public static function saveSettings(array $values): array
    {
        $next = self::settings();
        foreach (self::SETTINGS_DEFAULTS as $k => $default) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            $next[$k] = is_bool($default) ? (bool) $values[$k] : trim((string) $values[$k]);
        }
        $next['title'] = mb_substr($next['title'] !== '' ? $next['title'] : self::SETTINGS_DEFAULTS['title'], 0, 120);
        $next['subtitle'] = mb_substr($next['subtitle'], 0, 400);
        $next['rule'] = mb_substr($next['rule'], 0, 600);
        $next['passMode'] = $next['passMode'] === 'free' ? 'free' : 'strict';
        $json = json_encode($next, JSON_UNESCAPED_UNICODE);
        if (Db::one('SELECT "key" FROM portal_content WHERE "key" = ?', [self::SETTINGS_KEY]) !== null) {
            Db::exec('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ?', [$json, now_iso(), self::SETTINGS_KEY]);
        } else {
            Db::insert('portal_content', ['key' => self::SETTINGS_KEY, 'valueJson' => $json, 'updatedAt' => now_iso()]);
        }
        self::$settingsCache = null;
        return self::settings();
    }

    /**
     * Курс правили на сайте — новая версия course_content/course.json больше не
     * накатывается сама и не затирает правки. Заменить курс файлом можно только кнопкой импорта.
     */
    public static function markCustomized(): void
    {
        if (self::isCustomized()) {
            return;
        }
        Db::insert('app_meta', ['k' => self::CUSTOMIZED_MARK, 'v' => now_iso()]);
    }

    public static function isCustomized(): bool
    {
        try {
            return Db::value('SELECT v FROM app_meta WHERE k = ?', [self::CUSTOMIZED_MARK]) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function importIfNeeded(): array
    {
        $out = ['imported' => false, 'modules' => 0, 'chapters' => 0, 'steps' => 0, 'images' => 0, 'reason' => ''];
        $seed = self::seed();
        if ($seed === null) {
            $out['reason'] = 'no-files';
            return $out;
        }
        if (self::importMarker() === $seed['version']) {
            $out['reason'] = 'already';
            return $out;
        }
        if (self::importMarker() !== null && self::isCustomized()) {
            $out['reason'] = 'customized';
            return $out;
        }
        try {
            return self::importSeed($seed);
        } catch (Throwable $e) {
            error_log('[gamerpro] course import: ' . $e->getMessage());
            $out['reason'] = 'error';
            return $out;
        }
    }

    public static function isImported(): bool
    {
        return self::importMarker() !== null;
    }

    /** Импорт по кнопке в админке. $force — переимпортировать, даже если версия та же. */
    public static function importFromFiles(bool $force = false): array
    {
        $seed = self::seed();
        if ($seed === null) {
            return ['imported' => false, 'modules' => 0, 'chapters' => 0, 'steps' => 0, 'images' => 0, 'reason' => 'no-files'];
        }
        if (!$force && self::importMarker() === $seed['version']) {
            return ['imported' => false, 'modules' => 0, 'chapters' => 0, 'steps' => 0, 'images' => 0, 'reason' => 'already'];
        }
        return self::importSeed($seed);
    }

    /** @param array{version: string, modules: list<array>} $seed */
    private static function importSeed(array $seed): array
    {
        $out = ['imported' => false, 'modules' => 0, 'chapters' => 0, 'steps' => 0, 'images' => 0, 'reason' => ''];
        $uploads = array_map(static fn (array $r): string => (string) $r['path'], Db::all('SELECT path FROM course_step_images'));
        Db::tx(static function () use (&$out, $seed): void {
            foreach (['course_progress', 'course_step_images', 'course_steps', 'course_chapters', 'course_modules'] as $table) {
                Db::exec('DELETE FROM ' . $table);
            }
            $now = now_iso();
            $moduleOrder = 0;
            foreach ($seed['modules'] as $m) {
                $moduleOrder++;
                $kind = self::normalizeKind((string) ($m['kind'] ?? ''));
                $moduleId = Db::insert('course_modules', [
                    'title' => mb_substr(trim((string) ($m['title'] ?? 'Раздел')), 0, 160),
                    'summary' => mb_substr(trim((string) ($m['summary'] ?? '')), 0, 2000),
                    'kind' => $kind,
                    'sortOrder' => $moduleOrder,
                    'isActive' => 1,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
                $out['modules']++;
                if ($kind === 'exam') {
                    continue;
                }
                $chapterOrder = 0;
                foreach ((array) ($m['chapters'] ?? []) as $c) {
                    $chapterOrder++;
                    $chapterId = Db::insert('course_chapters', [
                        'moduleId' => $moduleId,
                        'title' => mb_substr(trim((string) ($c['title'] ?? 'Глава')), 0, 200),
                        'summary' => mb_substr(trim((string) ($c['summary'] ?? '')), 0, 2000),
                        'sortOrder' => $chapterOrder,
                        'sourceKey' => (string) ($c['key'] ?? ''),
                        'isActive' => 1,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]);
                    $out['chapters']++;
                    $stepOrder = 0;
                    foreach ((array) ($c['steps'] ?? []) as $s) {
                        $stepOrder++;
                        $html = sanitize_html((string) ($s['html'] ?? ''));
                        Db::insert('course_steps', [
                            'chapterId' => $chapterId,
                            'title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 200),
                            'contentHtml' => $html !== '' ? $html : '<p></p>',
                            'sortOrder' => $stepOrder,
                            'isActive' => 1,
                            'createdAt' => $now,
                            'updatedAt' => $now,
                        ]);
                        $out['steps']++;
                        $out['images'] += substr_count($html, '<img ');
                    }
                }
            }
        });
        // Загруженные руководителем картинки к удалённым шагам больше не нужны (кроме картинок самого курса).
        preg_match_all('#/assets/course_images/([A-Za-z0-9._-]+)#', json_encode($seed['modules'], JSON_UNESCAPED_SLASHES) ?: '', $used);
        foreach (array_diff($uploads, $used[1] ?? []) as $name) {
            CourseMedia::delete($name);
        }
        self::setImportMarker($seed['version']);
        $out['imported'] = true;
        return $out;
    }

    private static function importMarker(): ?string
    {
        try {
            $v = Db::value('SELECT v FROM app_meta WHERE k = ?', [self::SEED_MARK]);
            return $v === null ? null : (string) $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function setImportMarker(string $version): void
    {
        try {
            if (Db::one('SELECT k FROM app_meta WHERE k = ?', [self::SEED_MARK]) !== null) {
                Db::exec('UPDATE app_meta SET v = ? WHERE k = ?', [$version, self::SEED_MARK]);
            } else {
                Db::insert('app_meta', ['k' => self::SEED_MARK, 'v' => $version]);
            }
            // Курс заменён файлом — правок с сайта в нём больше нет.
            Db::exec('DELETE FROM app_meta WHERE k = ?', [self::CUSTOMIZED_MARK]);
        } catch (Throwable $e) {
            error_log('[gamerpro] course marker: ' . $e->getMessage());
        }
    }
}
