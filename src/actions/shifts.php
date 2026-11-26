<?php
declare(strict_types=1);

trait ActionsShifts
{
    private static function fail(string $msg, string $back = '/'): never
    {
        flash_set('error', $msg);
        redirect_back($back);
    }

    /* ---------- задания на смену (сотрудник) ---------- */

    private static function doCompleteTask(array $user): void
    {
        $task = Db::one('SELECT id, "employeeId", date, title FROM staff_tasks WHERE id = ?', [self::postInt('taskId')]);
        if (!$task) {
            self::fail('Задание не найдено — возможно, его уже сняли.');
        }
        if ((int) $task['employeeId'] !== (int) ($user['employeeId'] ?? 0)) {
            self::fail('Это задание назначено другому сотруднику.');
        }
        $today = date_key(now_tyumen());
        $date = substr((string) $task['date'], 0, 10);
        if ($date !== $today && $date !== add_days($today, -1)) {
            self::fail('Отмечать можно только задания текущей смены.');
        }
        Db::insertIgnore('staff_task_done', [
            'taskId' => (int) $task['id'],
            'userId' => $user['id'],
            'userName' => self::displayName($user),
            'completedAt' => now_iso(),
        ]);
        flash_set('ok', 'Задание «' . mb_substr((string) $task['title'], 0, 60) . '» выполнено');
        redirect_back('/');
    }

    private static function doUncompleteTask(array $user): void
    {
        Db::exec('DELETE FROM staff_task_done WHERE "taskId" = ? AND "userId" = ?', [self::postInt('taskId'), $user['id']]);
        flash_set('ok', 'Отметка снята');
        redirect_back('/');
    }

    /* ---------- задания дня (по дням недели) ---------- */

    private static function doCompleteDailyTask(array $user): void
    {
        $task = Db::one('SELECT id, title, "branchId", "isActive" FROM daily_tasks WHERE id = ?', [self::postInt('taskId')]);
        if (!$task || !boolish($task['isActive'])) {
            self::fail('Задание дня не найдено');
        }
        $branchId = self::postInt('branchId');
        if ($branchId <= 0 || !sees_branch($user, $branchId)) {
            self::fail('Не удалось определить филиал для отметки');
        }
        if ($task['branchId'] !== null && (int) $task['branchId'] !== $branchId) {
            self::fail('Это задание для другого филиала');
        }
        $today = date_key(now_tyumen());
        $existing = Db::one('SELECT id FROM daily_task_completions WHERE "taskId" = ? AND date = ? AND "branchId" = ?', [(int) $task['id'], $today, $branchId]);
        if (!$existing) {
            Db::insert('daily_task_completions', [
                'taskId' => (int) $task['id'],
                'userId' => $user['id'],
                'userName' => self::displayName($user),
                'branchId' => $branchId,
                'date' => $today,
                'completedAt' => now_iso(),
            ]);
        }
        flash_set('ok', 'Задание дня отмечено: «' . mb_substr((string) $task['title'], 0, 60) . '»');
        redirect_back('/');
    }

    private static function doUncompleteDailyTask(array $user): void
    {
        $taskId = self::postInt('taskId');
        $branchId = self::postInt('branchId');
        $today = date_key(now_tyumen());
        Db::exec('DELETE FROM daily_task_completions WHERE "taskId" = ? AND date = ? AND "branchId" = ?', [$taskId, $today, $branchId]);
        flash_set('ok', 'Отметка снята');
        redirect_back('/');
    }

    private static function doSaveDailyTask(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $title = trim(self::postStr('title', 200));
        if (mb_strlen($title) < 4) {
            self::fail('Название задания дня — минимум 4 символа', '/manage/tasks#daily');
        }
        $weekday = self::postInt('weekday');
        if ($weekday < 0 || $weekday > 6) {
            self::fail('Выберите день недели', '/manage/tasks#daily');
        }
        $branchRaw = self::postStr('branchId', 16);
        $branchId = $branchRaw === '' || $branchRaw === '0' ? null : (int) $branchRaw;
        if ($branchId !== null && !self::branchById($branchId)) {
            self::fail('Филиал не найден', '/manage/tasks#daily');
        }
        $desc = self::postStr('description', 2000);
        $id = self::postInt('id');
        $now = now_iso();
        if ($id > 0) {
            $row = Db::one('SELECT id FROM daily_tasks WHERE id = ?', [$id]);
            if (!$row) {
                self::fail('Шаблон не найден', '/manage/tasks#daily');
            }
            Db::exec(
                'UPDATE daily_tasks SET weekday = ?, "branchId" = ?, title = ?, description = ?, "isActive" = ?, "sortOrder" = ? WHERE id = ?',
                [$weekday, $branchId, $title, $desc, self::postStr('isActive', 1) === '0' ? 0 : 1, max(0, self::postInt('sortOrder')), $id],
            );
            flash_set('ok', 'Шаблон задания дня обновлён');
        } else {
            Db::insert('daily_tasks', [
                'weekday' => $weekday,
                'branchId' => $branchId,
                'title' => $title,
                'description' => $desc,
                'isActive' => 1,
                'sortOrder' => max(0, self::postInt('sortOrder')),
                'createdAt' => $now,
            ]);
            flash_set('ok', 'Шаблон задания дня добавлен');
        }
        redirect_to('/manage/tasks#daily');
    }

    private static function doDeleteDailyTask(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $id = self::postInt('id');
        Db::exec('DELETE FROM daily_task_completions WHERE "taskId" = ?', [$id]);
        Db::exec('DELETE FROM daily_tasks WHERE id = ?', [$id]);
        flash_set('ok', 'Шаблон задания дня удалён');
        redirect_to('/manage/tasks#daily');
    }

    /* ---------- присутствие управляющего ---------- */

    private static function doSavePresence(array $user): void
    {
        $eid = (int) ($user['employeeId'] ?? 0);
        if (($user['role'] ?? '') !== 'manager' || $eid <= 0) {
            self::fail('Отмечать присутствие может только управляющий', '/schedule');
        }
        $date = self::postStr('date', 10);
        $today = date_key(now_tyumen());
        if (!is_date_key($date) || $date > add_days($today, 1) || $date < add_days($today, -62)) {
            self::fail('Выберите дату в пределах двух месяцев', '/schedule#presence');
        }
        $start = self::postStr('startTime', 5);
        $end = self::postStr('endTime', 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) {
            self::fail('Укажите время прихода и ухода', '/schedule#presence');
        }
        $activity = trim(self::postStr('activity', 2000));
        if (mb_strlen($activity) < 3) {
            self::fail('Опишите, чем занимались в клубе', '/schedule#presence');
        }
        $branchId = self::postInt('branchId');
        if (!self::branchById($branchId)) {
            self::fail('Филиал не найден', '/schedule#presence');
        }
        $dupe = Db::one('SELECT id FROM presence_logs WHERE "employeeId" = ? AND date = ? AND "startTime" = ?', [$eid, $date, $start]);
        if ($dupe) {
            self::fail('Такая отметка уже есть', '/schedule#presence');
        }
        $now = now_iso();
        Db::insert('presence_logs', [
            'employeeId' => $eid, 'branchId' => $branchId, 'date' => $date, 'startTime' => $start, 'endTime' => $end,
            'activity' => $activity, 'createdByUserId' => (string) $user['id'], 'createdAt' => $now, 'updatedAt' => $now,
        ]);
        flash_set('ok', 'Присутствие отмечено — смена попадёт в зарплату');
        redirect_to('/schedule', ['m' => substr($date, 0, 7)]);
    }

    private static function doDeletePresence(array $user): void
    {
        $row = Db::one('SELECT id, "employeeId", date FROM presence_logs WHERE id = ?', [self::postInt('id')]);
        $isOwner = !empty($user['isOwner']) || ($user['role'] ?? '') === 'owner';
        if (!$row || (!$isOwner && (int) $row['employeeId'] !== (int) ($user['employeeId'] ?? 0))) {
            self::fail('Отметка не найдена', '/schedule');
        }
        Db::exec('DELETE FROM presence_logs WHERE id = ?', [(int) $row['id']]);
        flash_set('ok', 'Отметка присутствия удалена');
        redirect_to('/schedule', ['m' => substr((string) $row['date'], 0, 7)]);
    }

    /* ---------- «сегодня без заданий» ---------- */

    private static function doSetTaskOffDay(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $date = self::postStr('date', 10);
        $week = self::postStr('week', 10);
        if (!is_date_key($date)) {
            self::fail('Неверная дата', '/manage/tasks');
        }
        $branchId = self::postInt('branchId');
        $branchId = $branchId > 0 && self::branchById($branchId) ? $branchId : 0;
        Db::exec('DELETE FROM task_off_days WHERE date = ? AND "branchId" = ?', [$date, $branchId]);
        Db::insert('task_off_days', [
            'date' => $date, 'branchId' => $branchId, 'note' => self::postStr('note', 255),
            'createdByUserId' => (string) $user['id'], 'createdByName' => (string) ($user['name'] ?? ''), 'createdAt' => now_iso(),
        ]);
        flash_set('ok', 'День отмечен: заданий нет');
        redirect_to('/manage/tasks', ['w' => is_date_key($week) ? $week : null]);
    }

    private static function doClearTaskOffDay(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        Db::exec('DELETE FROM task_off_days WHERE id = ?', [self::postInt('id')]);
        $week = self::postStr('week', 10);
        flash_set('ok', 'Отметка «без заданий» снята');
        redirect_to('/manage/tasks', ['w' => is_date_key($week) ? $week : null]);
    }

    /* ---------- график ---------- */

    private static function shiftSummary(string $name, string $type, string $date, array $branch): string
    {
        return $name . ' · ' . (SHIFT_LABELS[$type] ?? $type) . ' ' . shift_hint($type, (int) $branch['id']) . ' · ' . format_day_month($date) . ' · ' . $branch['name'];
    }

    private static function scheduleHref(array $branch, string $date): string
    {
        return url_to('/schedule', ['branch' => $branch['slug'], 'm' => substr($date, 0, 7)]);
    }

    private static function activeEmployee(int $id): ?array
    {
        $emp = Db::one('SELECT id, name, "homeBranchId", "isActive" FROM employees WHERE id = ?', [$id]);
        return $emp && boolish($emp['isActive']) ? $emp : null;
    }

    /**
     * Ставит сотрудника в смену (или меняет тип его смены в этот день).
     * Возвращает ['status' => created|updated|same|conflict, 'msg' => string].
     */
    private static function placeShift(array $user, array $branch, array $emp, string $date, string $type, bool $log = true): array
    {
        $name = (string) $emp['name'];
        $busy = Db::one('SELECT id, "branchId", "shiftType" FROM shifts WHERE "employeeId" = ? AND date = ?', [(int) $emp['id'], $date]);
        if ($busy && (int) $busy['branchId'] !== $branch['id']) {
            return ['status' => 'conflict', 'msg' => short_name($name) . ' ' . format_day_month($date) . ' уже стоит в филиале ' . self::branchName((int) $busy['branchId'])];
        }
        if ($busy) {
            if ($busy['shiftType'] === $type) {
                return ['status' => 'same', 'msg' => short_name($name) . ' уже стоит в эту смену'];
            }
            Db::exec('UPDATE shifts SET "shiftType" = ?, "updatedAt" = ? WHERE id = ?', [$type, now_iso(), (int) $busy['id']]);
            if ($log) {
                audit($user, 'schedule', 'update', 'Смена изменена: ' . self::shiftSummary($name, $type, $date, $branch), self::scheduleHref($branch, $date));
            }
            return ['status' => 'updated', 'msg' => 'Смена ' . short_name($name) . ' изменена на «' . SHIFT_LABELS[$type] . '»'];
        }
        Db::insert('shifts', [
            'date' => $date,
            'branchId' => $branch['id'],
            'employeeId' => (int) $emp['id'],
            'shiftType' => $type,
            'note' => '',
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ]);
        $guest = !self::worksAt($emp, $branch['id']);
        if ($log) {
            audit($user, 'schedule', 'create', ($guest ? 'Подмена: ' : 'Поставлен в смену: ') . self::shiftSummary($name, $type, $date, $branch), self::scheduleHref($branch, $date));
        }
        return ['status' => 'created', 'msg' => short_name($name) . ($guest ? ' поставлен подменой на ' . $branch['name'] : ' поставлен в смену') . ' · ' . format_day_month($date)];
    }

    private static function editableBranch(array $user): array
    {
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Филиал не найден', '/schedule');
        }
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        return $branch;
    }

    private static function doSaveShift(array $user): void
    {
        $branch = self::editableBranch($user);
        $date = self::postStr('date', 10);
        $type = self::postStr('shiftType', 16);
        if (!is_date_key($date)) {
            self::fail('Не выбрана дата смены', '/schedule');
        }
        if (!in_array($type, SHIFT_TYPES, true)) {
            self::fail('Выберите тип смены: день, ночь или сутки', '/schedule');
        }
        $emp = self::activeEmployee(self::postInt('employeeId'));
        if (!$emp) {
            self::fail('Выберите сотрудника из списка', '/schedule');
        }
        $r = self::placeShift($user, $branch, $emp, $date, $type);
        flash_set($r['status'] === 'conflict' ? 'error' : 'ok', $r['msg']);
        redirect_back('/schedule');
    }

    /** Диалог дня: дневной и ночной сотрудник одним сохранением. Один и тот же человек в обоих — сутки. */
    private static function doSaveDay(array $user): void
    {
        $branch = self::editableBranch($user);
        $date = self::postStr('date', 10);
        if (!is_date_key($date)) {
            self::fail('Не выбрана дата смены', '/schedule');
        }
        $dayId = self::postInt('dayEmployeeId');
        $nightId = self::postInt('nightEmployeeId');
        $plan = [];
        if ($dayId > 0 && $dayId === $nightId) {
            $plan[$dayId] = 'full';
        } else {
            if ($dayId > 0) {
                $plan[$dayId] = 'day';
            }
            if ($nightId > 0) {
                $plan[$nightId] = 'night';
            }
        }
        if (!$plan) {
            self::fail('Выберите, кто выходит днём или ночью', '/schedule');
        }
        $done = [];
        $errors = [];
        foreach ($plan as $id => $type) {
            $emp = self::activeEmployee($id);
            if (!$emp) {
                $errors[] = 'Сотрудник не найден';
                continue;
            }
            $r = self::placeShift($user, $branch, $emp, $date, $type);
            if ($r['status'] === 'conflict') {
                $errors[] = $r['msg'];
            } else {
                $done[] = short_name((string) $emp['name']) . ' — ' . mb_strtolower(SHIFT_LABELS[$type]);
            }
        }
        if ($errors) {
            flash_set('error', implode('. ', $errors) . ($done ? '. Сохранено: ' . implode(', ', $done) : ''));
        } else {
            flash_set('ok', format_day_month($date) . ': ' . implode(', ', $done));
        }
        redirect_back('/schedule');
    }

    /** «Кисть»: пачка изменений графика за один запрос. ops — JSON [{d, e, t, op: add|remove}]. */
    private static function doSaveShiftsBulk(array $user): void
    {
        $branch = self::editableBranch($user);
        $ops = json_decode((string) ($_POST['ops'] ?? ''), true);
        if (!is_array($ops) || !$ops) {
            self::fail('Нет изменений для сохранения', '/schedule');
        }
        if (count($ops) > 400) {
            self::fail('Слишком много изменений за раз — сохраните частями', '/schedule');
        }
        $emps = [];
        $res = Db::tx(static function () use ($ops, $user, $branch, &$emps): array {
            $res = ['added' => 0, 'removed' => 0, 'conflicts' => []];
            foreach ($ops as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $date = (string) ($op['d'] ?? '');
                $eid = (int) ($op['e'] ?? 0);
                if (!is_date_key($date) || $eid < 1) {
                    continue;
                }
                if (($op['op'] ?? '') === 'remove') {
                    $row = Db::one('SELECT id FROM shifts WHERE "employeeId" = ? AND date = ? AND "branchId" = ?', [$eid, $date, $branch['id']]);
                    if ($row) {
                        Db::exec('DELETE FROM shifts WHERE id = ?', [(int) $row['id']]);
                        $res['removed']++;
                    }
                    continue;
                }
                $type = (string) ($op['t'] ?? '');
                if (!in_array($type, SHIFT_TYPES, true)) {
                    continue;
                }
                $emps[$eid] ??= self::activeEmployee($eid);
                if (!$emps[$eid]) {
                    continue;
                }
                $r = self::placeShift($user, $branch, $emps[$eid], $date, $type, false);
                if ($r['status'] === 'conflict') {
                    $res['conflicts'][] = $r['msg'];
                } elseif ($r['status'] !== 'same') {
                    $res['added']++;
                }
            }
            return $res;
        });
        $parts = [];
        if ($res['added']) {
            $parts[] = 'поставлено ' . plural($res['added'], 'смена', 'смены', 'смен');
        }
        if ($res['removed']) {
            $parts[] = 'убрано ' . $res['removed'];
        }
        $month = '';
        foreach ($ops as $op) {
            if (is_array($op) && is_date_key((string) ($op['d'] ?? ''))) {
                $month = (string) $op['d'];
                break;
            }
        }
        if ($parts) {
            $who = implode(', ', array_map(static fn ($e) => short_name((string) $e['name']), array_filter($emps)));
            audit($user, 'schedule', 'bulk', 'Быстрое заполнение · ' . $branch['name'] . ': ' . implode(', ', $parts) . ($who !== '' ? ' · ' . $who : ''), $month !== '' ? self::scheduleHref($branch, $month) : url_to('/schedule'));
        }
        $msg = $parts ? 'Сохранено: ' . implode(', ', $parts) : 'Изменений нет';
        if ($res['conflicts']) {
            $msg .= '. Пропущено ' . count($res['conflicts']) . ': ' . implode('; ', array_slice($res['conflicts'], 0, 3)) . (count($res['conflicts']) > 3 ? '…' : '');
        }
        flash_set($res['conflicts'] && !$parts ? 'error' : 'ok', $msg);
        redirect_back('/schedule');
    }

    private static function doDeleteShift(array $user): void
    {
        $shift = Db::one(
            'SELECT s.id, s.date, s."branchId", s."shiftType", e.name AS "employeeName" FROM shifts s JOIN employees e ON e.id = s."employeeId" WHERE s.id = ?',
            [self::postInt('id')],
        );
        if (!$shift) {
            self::fail('Смена не найдена — возможно, её уже убрали.', '/schedule');
        }
        $branch = self::branchById((int) $shift['branchId']) ?? ['id' => (int) $shift['branchId'], 'name' => '—', 'slug' => ''];
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        Db::exec('DELETE FROM shifts WHERE id = ?', [(int) $shift['id']]);
        $date = substr((string) $shift['date'], 0, 10);
        audit($user, 'schedule', 'delete', 'Смена убрана: ' . self::shiftSummary((string) $shift['employeeName'], (string) $shift['shiftType'], $date, $branch), self::scheduleHref($branch, $date));
        flash_set('ok', 'Смена ' . short_name((string) $shift['employeeName']) . ' убрана');
        redirect_back('/schedule');
    }

    private static function doCopyWeek(array $user): void
    {
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Филиал не найден', '/schedule');
        }
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        $from = self::postStr('from', 10);
        $to = self::postStr('to', 10);
        if (!is_date_key($from) || !is_date_key($to)) {
            self::fail('Укажите обе недели — любой день каждой из них', '/schedule');
        }
        $a = monday_key(date_from_key($from));
        $b = monday_key(date_from_key($to));
        if ($a === $b) {
            self::fail('Выбрана одна и та же неделя — укажите другую неделю назначения', '/schedule');
        }
        $offset = (int) date_from_key($a)->diff(date_from_key($b))->format('%r%a');
        $source = Db::all(
            'SELECT s.date, s."employeeId", s."shiftType", e."isActive" FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ? ORDER BY s.date',
            [$branch['id'], $a, add_days($a, 6)],
        );
        if (!$source) {
            self::fail('На неделе ' . week_label($a) . ' в филиале ' . $branch['name'] . ' нет смен — копировать нечего.', '/schedule');
        }
        [$copied, $skipped] = Db::tx(static function () use ($source, $offset, $branch): array {
            $copied = 0;
            $skipped = 0;
            foreach ($source as $s) {
                $day = add_days(substr((string) $s['date'], 0, 10), $offset);
                if (!boolish($s['isActive']) || Db::one('SELECT id FROM shifts WHERE "employeeId" = ? AND date = ?', [(int) $s['employeeId'], $day])) {
                    $skipped++;
                    continue;
                }
                Db::insert('shifts', [
                    'date' => $day,
                    'branchId' => $branch['id'],
                    'employeeId' => (int) $s['employeeId'],
                    'shiftType' => (string) $s['shiftType'],
                    'note' => '',
                    'createdAt' => now_iso(),
                    'updatedAt' => now_iso(),
                ]);
                $copied++;
            }
            return [$copied, $skipped];
        });
        $summary = 'Неделя ' . week_label($a) . ' → ' . week_label($b) . ' · ' . $branch['name'] . ': ' . plural($copied, 'смена', 'смены', 'смен');
        if ($copied > 0) {
            audit($user, 'schedule', 'copy_week', $summary, self::scheduleHref($branch, $b));
        }
        $msg = $copied > 0 ? 'Скопировано ' . plural($copied, 'смена', 'смены', 'смен') . ' на ' . week_label($b) : 'Ничего не скопировано';
        if ($skipped > 0) {
            $msg .= ' · пропущено ' . $skipped . ' — сотрудник уже в графике или уволен';
        }
        flash_set($copied > 0 ? 'ok' : 'error', $msg);
        redirect_to('/schedule', ['branch' => $branch['slug'], 'm' => substr($b, 0, 7)]);
    }

    /* ---------- чистка клавиатур ---------- */

    private static function cleaningTarget(): array
    {
        $branch = self::branchById(self::postInt('branchId'));
        $pc = self::postInt('pcNumber');
        $week = self::postStr('weekStart', 10);
        if (!$branch || $pc < 1 || $pc > (int) $branch['pcCount']) {
            self::fail('Такого места нет в этом клубе', '/cleaning');
        }
        $user = self::user();
        if (!$user || !sees_branch($user, $branch['id'])) {
            self::fail('Чистку отмечают в своём филиале', '/cleaning');
        }
        if (!is_date_key($week)) {
            self::fail('Не выбрана неделя', '/cleaning');
        }
        return [$branch, $pc, monday_key(date_from_key($week))];
    }

    private static function doToggleClean(array $user): void
    {
        [$branch, $pc, $week] = self::cleaningTarget();
        if (self::postInt('employeeId') > 0) {
            self::assignSeat($user, $branch, $pc, $week, self::postInt('employeeId'));
            return;
        }
        $canAssign = can($user, 'cleaning.assign', $branch['id']);
        $asg = Db::one(
            'SELECT a."employeeId", e.name FROM cleaning_seat_assignments a JOIN employees e ON e.id = a."employeeId"
             WHERE a."branchId" = ? AND a."weekStart" = ? AND a."pcNumber" = ?',
            [$branch['id'], $week, $pc],
        );
        if ($asg && !$canAssign && (int) $asg['employeeId'] !== (int) ($user['employeeId'] ?? 0)) {
            self::fail('Место ' . $pc . ' на этой неделе закреплено за ' . $asg['name'], '/cleaning');
        }
        $mark = Db::one('SELECT id, "doneByUserId", "doneByName" FROM keyboard_cleanings WHERE "branchId" = ? AND "pcNumber" = ? AND "weekStart" = ?', [$branch['id'], $pc, $week]);
        if ($mark) {
            if ($mark['doneByUserId'] !== $user['id'] && !$canAssign) {
                self::fail('Отметку поставил(а) ' . $mark['doneByName'] . ' — снять её может он(а) или тот, кто распределяет чистку.', '/cleaning');
            }
            Db::exec('DELETE FROM keyboard_cleanings WHERE id = ?', [(int) $mark['id']]);
        } else {
            Db::insert('keyboard_cleanings', [
                'branchId' => $branch['id'],
                'pcNumber' => $pc,
                'weekStart' => $week,
                'doneByUserId' => $user['id'],
                'doneByName' => self::displayName($user),
                'doneAt' => now_iso(),
            ]);
        }
        redirect_back('/cleaning');
    }

    private static function doAssignSeats(array $user): void
    {
        [$branch, $pc, $week] = self::cleaningTarget();
        self::assignSeat($user, $branch, $pc, $week, self::postInt('employeeId'));
    }

    /** Закрепляет место за сотрудником; повторное нажатие тем же сотрудником снимает закрепление. */
    private static function assignSeat(array $user, array $branch, int $pc, string $week, int $employeeId): void
    {
        self::permOrFail($user, 'cleaning.assign', $branch['id'], '/cleaning');
        $emp = Db::one('SELECT id, "isActive" FROM employees WHERE id = ?', [$employeeId]);
        if (!$emp || !boolish($emp['isActive'])) {
            self::fail('Сотрудник не найден', '/cleaning');
        }
        $existing = Db::one('SELECT id, "employeeId" FROM cleaning_seat_assignments WHERE "branchId" = ? AND "weekStart" = ? AND "pcNumber" = ?', [$branch['id'], $week, $pc]);
        if ($existing && (int) $existing['employeeId'] === $employeeId) {
            Db::exec('DELETE FROM cleaning_seat_assignments WHERE id = ?', [(int) $existing['id']]);
        } elseif ($existing) {
            Db::exec('UPDATE cleaning_seat_assignments SET "employeeId" = ? WHERE id = ?', [$employeeId, (int) $existing['id']]);
        } else {
            Db::insert('cleaning_seat_assignments', [
                'branchId' => $branch['id'],
                'weekStart' => $week,
                'employeeId' => $employeeId,
                'pcNumber' => $pc,
                'createdAt' => now_iso(),
            ]);
        }
        redirect_back('/cleaning');
    }

    /* ---------- задания на смены (назначение) ---------- */

    private static function doAssignStaffTask(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $scope = perm_branches($user, 'tasks.assign');
        $week = self::postStr('week', 10);
        $back = ['w' => is_date_key($week) ? $week : monday_key(now_tyumen())];
        $go = static function (string $type, string $msg) use ($back): never {
            flash_set($type, $msg);
            redirect_to('/manage/tasks', $back);
        };
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 3) {
            $go('error', 'Напишите задание хотя бы парой слов');
        }
        $from = self::postStr('from', 10);
        $to = self::postStr('to', 10) ?: $from;
        $days = dates_between($from, $to, 31);
        if (!$days) {
            $go('error', 'Укажите даты: один день или промежуток до 31 дня');
        }
        $active = [];
        foreach (self::employees(true) as $e) {
            if (self::worksInAny($e, $scope)) {
                $active[(int) $e['id']] = (string) $e['name'];
            }
        }
        $who = self::postStr('who', 16);
        $byShift = $who === 'day' || $who === 'night';
        $types = $who === 'day' ? ['day', 'full'] : ['night', 'full'];
        $pairs = [];
        if ($byShift) {
            $rows = Db::all(
                'SELECT "employeeId", date FROM shifts WHERE date IN (' . implode(',', array_fill(0, count($days), '?')) . ') AND "shiftType" IN (?, ?)',
                array_merge($days, $types),
            );
            foreach ($rows as $r) {
                $eid = (int) $r['employeeId'];
                if (isset($active[$eid])) {
                    $pairs[] = [$eid, substr((string) $r['date'], 0, 10)];
                }
            }
            if (!$pairs) {
                $go('error', 'В графике на эти даты нет ' . ($who === 'day' ? 'дневных' : 'ночных') . ' смен' . ($scope !== null ? ' в ваших филиалах' : ''));
            }
        } else {
            $raw = $_POST['employees'] ?? [];
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn ($id) => isset($active[$id]))));
            if (!$ids) {
                $go('error', 'Отметьте, кому назначить задание');
            }
            foreach ($ids as $eid) {
                foreach ($days as $day) {
                    $pairs[] = [$eid, $day];
                }
            }
        }
        $desc = self::postStr('description', 2000);
        $author = self::displayName($user);
        $n = Db::tx(static function () use ($pairs, $title, $desc, $user, $author): int {
            $n = 0;
            foreach ($pairs as [$eid, $day]) {
                $dup = Db::one('SELECT id FROM staff_tasks WHERE "employeeId" = ? AND date = ? AND title = ?', [$eid, $day, $title]);
                if ($dup) {
                    Db::exec('UPDATE staff_tasks SET description = ? WHERE id = ?', [$desc, (int) $dup['id']]);
                } else {
                    Db::insert('staff_tasks', [
                        'employeeId' => $eid,
                        'date' => $day,
                        'title' => $title,
                        'description' => $desc,
                        'createdByUserId' => $user['id'],
                        'createdByName' => $author,
                        'createdAt' => now_iso(),
                    ]);
                }
                $n++;
            }
            return $n;
        });
        $people = array_values(array_unique(array_map(static fn ($p) => $active[$p[0]], $pairs)));
        $whom = $byShift ? ($who === 'day' ? 'дневным сменам' : 'ночным сменам') : implode(', ', array_map('short_name', $people));
        $range = count($days) > 1 ? format_day_month($days[0]) . ' – ' . format_day_month(end($days)) : format_day_month($days[0]);
        audit($user, 'tasks', 'assign', '«' . $title . '» · ' . $whom . ' · ' . $range, url_to('/manage/tasks', $back));
        $go('ok', 'Назначено ' . plural($n, 'задание', 'задания', 'заданий') . ' · ' . $whom);
    }

    private static function doDeleteStaffTask(array $user): void
    {
        $week = self::postStr('week', 10);
        $back = ['w' => is_date_key($week) ? $week : monday_key(now_tyumen())];
        $task = Db::one(
            'SELECT t.id, t.title, t.date, t."employeeId", e.name, e."homeBranchId" FROM staff_tasks t LEFT JOIN employees e ON e.id = t."employeeId" WHERE t.id = ?',
            [self::postInt('id')],
        );
        if (!$task) {
            flash_set('error', 'Задание уже снято');
            redirect_to('/manage/tasks', $back);
        }
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $own = $task['homeBranchId'] === null
            || self::worksInAny(['id' => $task['employeeId'], 'homeBranchId' => $task['homeBranchId']], perm_branches($user, 'tasks.assign'));
        if (!$own) {
            self::permOrFail($user, 'tasks.assign', (int) $task['homeBranchId'], '/manage/tasks');
        }
        Db::tx(static function () use ($task): void {
            Db::exec('DELETE FROM staff_task_done WHERE "taskId" = ?', [(int) $task['id']]);
            Db::exec('DELETE FROM staff_tasks WHERE id = ?', [(int) $task['id']]);
        });
        audit($user, 'tasks', 'delete', 'Снято задание «' . $task['title'] . '» · ' . ($task['name'] ?? '—') . ' · ' . format_day_month((string) $task['date']), url_to('/manage/tasks', $back));
        flash_set('ok', 'Задание снято');
        redirect_to('/manage/tasks', $back);
    }

    /* ---------- подмены ---------- */

    private static function doRequestShiftSwap(array $user): void
    {
        $back = '/shift/swaps';
        $prefs = $user['prefs'] ?? Notify::PREF_DEFAULTS;
        if (!Notify::feature($prefs, 'swaps')) {
            self::fail('Подмены для вашей учётки выключены в настройках профиля', $back);
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        if ($eid <= 0) {
            self::fail('Нужна карточка сотрудника', $back);
        }
        $shift = Db::one('SELECT * FROM shifts WHERE id = ?', [self::postInt('shiftId')]);
        if (!$shift || (int) $shift['employeeId'] !== $eid) {
            self::fail('Можно отдавать только свою смену', $back);
        }
        $date = substr((string) $shift['date'], 0, 10);
        $st = shift_state($date, (string) $shift['shiftType'], now_tyumen(), (int) $shift['branchId']);
        if ($st['state'] === 'done') {
            self::fail('Прошедшую смену отдать нельзя', $back);
        }
        $open = Db::one('SELECT id FROM shift_swaps WHERE "shiftId" = ? AND status = ?', [(int) $shift['id'], 'open']);
        if ($open) {
            self::fail('Заявка на эту смену уже висит', $back);
        }
        $reason = self::postStr('reason', 500);
        Db::insert('shift_swaps', [
            'shiftId' => (int) $shift['id'],
            'fromEmployeeId' => $eid,
            'toEmployeeId' => null,
            'status' => 'open',
            'reason' => $reason,
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
        ]);
        $label = format_day_month($date) . ' · ' . (SHIFT_LABELS[$shift['shiftType']] ?? $shift['shiftType']) . ' · ' . self::branchName((int) $shift['branchId']);
        audit($user, 'schedule', 'swap_request', 'Ищет подмену: ' . $label, url_to($back));
        $targets = [];
        foreach (Db::all('SELECT u.id, u.prefs FROM "user" u JOIN employees e ON e."userId" = u.id WHERE e."isActive" = 1 AND e.id != ?', [$eid]) as $r) {
            $p = Notify::prefs($r);
            if (Notify::feature($p, 'swaps') && Notify::wants($p, 'swaps')) {
                $targets[] = (string) $r['id'];
            }
        }
        Notify::toUsers($targets, 'swaps', "Нужна подмена\n" . $label . ($reason !== '' ? "\n" . $reason : ''), url_to($back));
        flash_set('ok', 'Заявка на подмену открыта — коллеги увидят её в разделе «Подмены»');
        redirect_to($back);
    }

    private static function doClaimShiftSwap(array $user): void
    {
        $back = '/shift/swaps';
        $prefs = $user['prefs'] ?? Notify::PREF_DEFAULTS;
        if (!Notify::feature($prefs, 'swaps')) {
            self::fail('Подмены для вас выключены', $back);
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        if ($eid <= 0) {
            self::fail('Нужна карточка сотрудника', $back);
        }
        $swap = Db::one('SELECT * FROM shift_swaps WHERE id = ?', [self::postInt('id')]);
        if (!$swap || $swap['status'] !== 'open') {
            self::fail('Заявка уже закрыта', $back);
        }
        if ((int) $swap['fromEmployeeId'] === $eid) {
            self::fail('Нельзя взять свою же заявку', $back);
        }
        $shift = Db::one('SELECT * FROM shifts WHERE id = ?', [(int) $swap['shiftId']]);
        if (!$shift) {
            self::fail('Смена уже удалена из графика', $back);
        }
        if ((int) $shift['employeeId'] !== (int) $swap['fromEmployeeId']) {
            self::fail('Смену уже перенесли в графике — заявку снимите', $back);
        }
        $date = substr((string) $shift['date'], 0, 10);
        $st = shift_state($date, (string) $shift['shiftType'], now_tyumen(), (int) $shift['branchId']);
        if ($st['state'] === 'done') {
            self::fail('Смена уже закончилась', $back);
        }
        $busy = Db::one('SELECT id FROM shifts WHERE "employeeId" = ? AND date = ? AND id != ?', [$eid, $date, (int) $shift['id']]);
        if ($busy) {
            self::fail('В этот день у вас уже есть смена', $back);
        }
        $who = self::displayName($user);
        $fromEid = (int) $swap['fromEmployeeId'];
        $ok = Db::tx(static function () use ($swap, $shift, $user, $eid, $who, $fromEid): bool {
            $n = Db::exec(
                'UPDATE shifts SET "employeeId" = ?, "updatedAt" = ? WHERE id = ? AND "employeeId" = ?',
                [$eid, now_iso(), (int) $shift['id'], $fromEid],
            );
            if ($n < 1) {
                return false;
            }
            $n2 = Db::exec(
                'UPDATE shift_swaps SET status = ?, "toEmployeeId" = ?, "resolvedByUserId" = ?, "resolvedByName" = ?, "resolvedAt" = ? WHERE id = ? AND status = ?',
                ['taken', $eid, $user['id'], $who, now_iso(), (int) $swap['id'], 'open'],
            );
            return $n2 >= 1;
        });
        if (!$ok) {
            self::fail('Заявка уже закрыта или смену успели перенести', $back);
        }
        $label = format_day_month($date) . ' · ' . (SHIFT_LABELS[$shift['shiftType']] ?? '') . ' · ' . self::branchName((int) $shift['branchId']);
        audit($user, 'schedule', 'swap_claim', 'Взял(а) подмену: ' . $label, url_to($back));
        $from = Db::one('SELECT "userId" FROM employees WHERE id = ?', [$fromEid]);
        if ($from && $from['userId']) {
            Notify::toUsers([(string) $from['userId']], 'swaps', 'Вашу смену забрали: ' . $label . "\n" . self::displayName($user), url_to('/schedule'));
        }
        flash_set('ok', 'Смена теперь ваша в графике');
        redirect_to($back);
    }

    private static function doCancelShiftSwap(array $user): void
    {
        $back = '/shift/swaps';
        $swap = Db::one('SELECT * FROM shift_swaps WHERE id = ?', [self::postInt('id')]);
        if (!$swap || $swap['status'] !== 'open') {
            self::fail('Заявка уже закрыта', $back);
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        $mine = (int) $swap['fromEmployeeId'] === $eid || $swap['createdByUserId'] === $user['id'];
        if (!$mine && !can($user, 'schedule.edit')) {
            self::fail('Можно отменить только свою заявку', $back);
        }
        Db::exec(
            'UPDATE shift_swaps SET status = ?, "resolvedByUserId" = ?, "resolvedByName" = ?, "resolvedAt" = ? WHERE id = ?',
            ['cancelled', $user['id'], self::displayName($user), now_iso(), (int) $swap['id']],
        );
        flash_set('ok', 'Заявка на подмену снята');
        redirect_to($back);
    }

    private static function doStockAssignTask(array $user): void
    {
        $back = '/bar';
        $backQ = ['tab' => 'goods'];
        self::permOrFail($user, 'tasks.assign', null, $back);
        $title = self::postStr('title', 200);
        $desc = self::postStr('description', 2000);
        $eid = self::postInt('employeeId');
        $day = self::postStr('date', 10);
        $go = static function (string $msg) use ($back, $backQ): never {
            flash_set('error', $msg);
            redirect_to($back, $backQ);
        };
        if (mb_strlen($title) < 3) {
            $go('Укажите название задания');
        }
        if (!is_date_key($day)) {
            $day = date_key(now_tyumen());
        }
        if ($eid <= 0) {
            $go('Выберите сотрудника на смене');
        }
        $emp = Db::one('SELECT id, name, "homeBranchId", "userId" FROM employees WHERE id = ? AND "isActive" = 1', [$eid]);
        if (!$emp) {
            $go('Сотрудник не найден');
        }
        Db::insert('staff_tasks', [
            'employeeId' => $eid,
            'date' => $day,
            'title' => $title,
            'description' => $desc,
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
        ]);
        if (!empty($emp['userId'])) {
            Notify::toUsers([(string) $emp['userId']], 'stock', 'Задание по складу: ' . $title, url_to('/'));
        }
        audit($user, 'tasks', 'stock', '«' . $title . '» · ' . $emp['name'] . ' · ' . format_day_month($day), url_to('/manage/tasks'));
        flash_set('ok', 'Задание назначено: ' . short_name((string) $emp['name']));
        redirect_to($back, $backQ);
    }
}
