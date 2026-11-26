<?php
declare(strict_types=1);

trait ActionsManage
{
    /* ---------- сотрудники ---------- */

    private static function cleanName(string $key): string
    {
        return preg_replace('/\s+/u', ' ', self::postStr($key, 60)) ?? '';
    }

    /** Поля карточки сотрудника из формы. При ошибке — сообщение и возврат. */
    private static function employeeFromPost(string $back, ?string $stashKey = null): array
    {
        $name = self::cleanName('name');
        $branch = self::postInt('homeBranchId');
        $gradeRaw = self::postStr('gradeId', 12);
        $fail = static function (string $msg) use ($back, $stashKey, $name, $branch, $gradeRaw): never {
            if ($stashKey !== null) {
                form_stash_set($stashKey, ['name' => $name, 'homeBranchId' => (string) $branch, 'gradeId' => $gradeRaw]);
            }
            self::fail($msg, $back);
        };
        if (mb_strlen($name) < 2) {
            $fail('Впишите фамилию и имя');
        }
        if (!self::branchById($branch)) {
            $fail('Выберите филиал');
        }
        $grade = null;
        if ($gradeRaw !== '') {
            $grade = Db::one('SELECT id, title, "shiftRate" FROM employee_grades WHERE id = ?', [(int) $gradeRaw]);
            if (!$grade) {
                $fail('Такого уровня нет — обновите страницу');
            }
        }
        return ['name' => $name, 'branch' => $branch, 'grade' => $grade];
    }

    private static function logGrade(array $user, int $employeeId, ?array $grade): void
    {
        Db::insert('employee_grade_history', [
            'employeeId' => $employeeId,
            'gradeTitle' => $grade ? (string) $grade['title'] : 'Без уровня',
            'shiftRate' => $grade ? (int) $grade['shiftRate'] : 0,
            'changedByUserId' => $user['id'],
            'changedByName' => self::displayName($user),
            'changedAt' => now_iso(),
        ]);
    }

    private static function doSaveEmployee(array $user): void
    {
        self::permOrFail($user, 'staff.manage', null, '/manage/employees');
        $d = self::employeeFromPost('/manage/employees', 'employee-new');
        $max = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM employees WHERE "homeBranchId" = ?', [$d['branch']]);
        Db::tx(static function () use ($d, $max, $user): void {
            $id = Db::insert('employees', [
                'name' => $d['name'],
                'homeBranchId' => $d['branch'],
                'isActive' => 1,
                'gradeId' => $d['grade'] ? (int) $d['grade']['id'] : null,
                'sortOrder' => $max + 1,
                'createdAt' => now_iso(),
            ]);
            if ($d['grade']) {
                self::logGrade($user, $id, $d['grade']);
            }
        });
        audit($user, 'staff', 'create', 'Новый сотрудник: ' . $d['name'] . ' (' . self::branchName($d['branch']) . ')', url_to('/manage/employees'));
        flash_set('ok', 'Сотрудник «' . $d['name'] . '» добавлен — можно ставить в график');
        redirect_back('/manage/employees');
    }

    private static function doUpdateEmployee(array $user): void
    {
        self::permOrFail($user, 'staff.manage', null, '/manage/employees');
        $id = self::postInt('id');
        $e = Db::one('SELECT id, name, "homeBranchId", "gradeId", "userId", "sortOrder" FROM employees WHERE id = ?', [$id]);
        if (!$e) {
            self::fail('Сотрудник не найден — возможно, его уже удалили', '/manage/employees');
        }
        $d = self::employeeFromPost('/manage/employees');
        $newGrade = $d['grade'] ? (int) $d['grade']['id'] : null;
        $oldGrade = $e['gradeId'] !== null ? (int) $e['gradeId'] : null;
        $moved = (int) $e['homeBranchId'] !== $d['branch'];
        $sort = $moved
            ? (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM employees WHERE "homeBranchId" = ?', [$d['branch']]) + 1
            : (int) $e['sortOrder'];
        Db::tx(static function () use ($e, $d, $id, $sort, $newGrade, $oldGrade, $user): void {
            Db::exec('UPDATE employees SET name = ?, "homeBranchId" = ?, "gradeId" = ?, "sortOrder" = ? WHERE id = ?', [$d['name'], $d['branch'], $newGrade, $sort, $id]);
            if (!empty($e['userId'])) {
                Db::exec('UPDATE "user" SET name = ?, "updatedAt" = ? WHERE id = ?', [$d['name'], now_iso(), (string) $e['userId']]);
                // Доступ без своего списка филиалов следует за карточкой — старую колонку держим в том же состоянии.
                Db::exec('UPDATE "user" SET "scopeBranchId" = ? WHERE id = ? AND "branchIds" IS NULL AND "scopeBranchId" IS NOT NULL', [$d['branch'], (string) $e['userId']]);
            }
            if ($newGrade !== $oldGrade) {
                self::logGrade($user, $id, $d['grade']);
            }
        });
        $changes = [];
        if ($d['name'] !== (string) $e['name']) {
            $changes[] = 'имя: ' . $e['name'] . ' → ' . $d['name'];
        }
        if ($moved) {
            $changes[] = 'филиал: ' . self::branchName($d['branch']);
        }
        if ($newGrade !== $oldGrade) {
            $changes[] = 'уровень: ' . ($d['grade'] ? $d['grade']['title'] . ', ' . money_rub((int) $d['grade']['shiftRate']) : 'без уровня');
        }
        if ($changes) {
            audit($user, 'staff', 'update', $d['name'] . ' — ' . implode('; ', $changes), url_to('/manage/employees'));
        }
        flash_set('ok', $changes ? 'Карточка «' . $d['name'] . '» сохранена' : 'Изменений не было');
        redirect_back('/manage/employees');
    }

    private static function doToggleEmployee(array $user): void
    {
        self::permOrFail($user, 'staff.manage', null, '/manage/employees');
        $e = Db::one('SELECT id, name, "isActive" FROM employees WHERE id = ?', [self::postInt('id')]);
        if (!$e) {
            self::fail('Сотрудник не найден', '/manage/employees');
        }
        $eid = (int) $e['id'];
        $activate = !boolish($e['isActive']);
        $now = now_tyumen();
        $tomorrow = add_days(date_key($now), 1);
        $removed = (int) Db::tx(static function () use ($eid, $activate, $tomorrow, $now): int {
            Db::exec('UPDATE employees SET "isActive" = ? WHERE id = ?', [$activate ? 1 : 0, $eid]);
            if ($activate) {
                return 0;
            }
            $n = (int) Db::value('SELECT COUNT(*) FROM shifts WHERE "employeeId" = ? AND date >= ?', [$eid, $tomorrow]);
            Db::exec('DELETE FROM shifts WHERE "employeeId" = ? AND date >= ?', [$eid, $tomorrow]);
            Db::exec('DELETE FROM staff_task_done WHERE "taskId" IN (SELECT id FROM staff_tasks WHERE "employeeId" = ? AND date >= ?)', [$eid, $tomorrow]);
            Db::exec('DELETE FROM staff_tasks WHERE "employeeId" = ? AND date >= ?', [$eid, $tomorrow]);
            Db::exec('DELETE FROM cleaning_seat_assignments WHERE "employeeId" = ? AND "weekStart" > ?', [$eid, monday_key($now)]);
            return $n;
        });
        $name = (string) $e['name'];
        if ($activate) {
            audit($user, 'staff', 'restore', 'Возвращён из архива: ' . $name, url_to('/manage/employees'));
            flash_set('ok', '«' . $name . '» снова в списке — можно ставить в график');
        } else {
            audit($user, 'staff', 'archive', 'В архив: ' . $name . ($removed ? ', убрано смен из графика: ' . $removed : ''), url_to('/manage/employees'));
            flash_set('ok', '«' . $name . '» в архиве' . ($removed ? ' — из графика убрано ' . plural($removed, 'смена', 'смены', 'смен') : ''));
        }
        redirect_back('/manage/employees');
    }

    private static function doDeleteEmployee(array $user): void
    {
        self::permOrFail($user, 'staff.manage', null, '/manage/employees');
        $e = Db::one('SELECT id, name, "isActive", "userId" FROM employees WHERE id = ?', [self::postInt('id')]);
        if (!$e) {
            self::fail('Сотрудник не найден', '/manage/employees');
        }
        if (boolish($e['isActive'])) {
            self::fail('Сначала отправьте сотрудника в архив', '/manage/employees');
        }
        if (!empty($e['userId'])) {
            self::fail('У «' . $e['name'] . '» остался вход в портал — сначала владелец удаляет доступ в разделе «Доступы и права»', '/manage/employees');
        }
        $eid = (int) $e['id'];
        Db::tx(static function () use ($eid): void {
            Db::exec('DELETE FROM staff_task_done WHERE "taskId" IN (SELECT id FROM staff_tasks WHERE "employeeId" = ?)', [$eid]);
            Db::exec('DELETE FROM staff_tasks WHERE "employeeId" = ?', [$eid]);
            Db::exec('DELETE FROM shifts WHERE "employeeId" = ?', [$eid]);
            Db::exec('DELETE FROM cleaning_seat_assignments WHERE "employeeId" = ?', [$eid]);
            Db::exec('DELETE FROM employee_grade_history WHERE "employeeId" = ?', [$eid]);
            Db::exec('DELETE FROM employee_fines WHERE "employeeId" = ?', [$eid]);
            Db::exec('DELETE FROM employees WHERE id = ?', [$eid]);
        });
        audit($user, 'staff', 'delete', 'Удалён сотрудник: ' . $e['name'], url_to('/manage/employees'));
        flash_set('ok', '«' . $e['name'] . '» удалён вместе с историей');
        redirect_back('/manage/employees');
    }

    /* ---------- уровни и ставки ---------- */

    private static function doAssignGrade(array $user): void
    {
        self::permOrFail($user, 'staff.manage', null, '/manage/employees');
        $id = self::postInt('employeeId');
        $e = Db::one('SELECT id, name, "gradeId" FROM employees WHERE id = ?', [$id]);
        if (!$e) {
            self::fail('Сотрудник не найден', '/manage/employees');
        }
        $raw = self::postStr('gradeId', 12);
        $grade = $raw === '' ? null : Db::one('SELECT id, title, "shiftRate" FROM employee_grades WHERE id = ?', [(int) $raw]);
        if ($raw !== '' && !$grade) {
            self::fail('Такого уровня нет — обновите страницу', '/manage/employees');
        }
        $new = $grade ? (int) $grade['id'] : null;
        $old = $e['gradeId'] !== null ? (int) $e['gradeId'] : null;
        $label = $grade ? $grade['title'] . ' · ' . money_rub((int) $grade['shiftRate']) . ' за смену' : 'без уровня';
        if ($new !== $old) {
            Db::tx(static function () use ($id, $new, $grade, $user): void {
                Db::exec('UPDATE employees SET "gradeId" = ? WHERE id = ?', [$new, $id]);
                self::logGrade($user, $id, $grade);
            });
            audit($user, 'staff', 'grade', $e['name'] . ' — уровень: ' . $label, url_to('/manage/employees'));
        }
        flash_set('ok', short_name((string) $e['name']) . ': ' . $label);
        redirect_back('/manage/employees');
    }

    private static function doSaveGrade(array $user): void
    {
        $back = '/manage/grades';
        self::permOrFail($user, 'staff.manage', null, $back);
        $id = self::postInt('id');
        $title = preg_replace('/\s+/u', ' ', self::postStr('title', 40)) ?? '';
        if (mb_strlen($title) < 2) {
            self::fail('Впишите название уровня', $back);
        }
        $rateRaw = self::postStr('shiftRate', 10);
        if (!preg_match('/^\d{1,6}$/', $rateRaw) || (int) $rateRaw > 100000) {
            self::fail('Ставка — целое число от 0 до 100 000 ₽', $back);
        }
        $rate = (int) $rateRaw;
        if (Db::one('SELECT id FROM employee_grades WHERE title = ? AND id != ?', [$title, $id])) {
            self::fail('Уровень «' . $title . '» уже есть', $back);
        }
        if ($id > 0) {
            $g = Db::one('SELECT id, title, "shiftRate" FROM employee_grades WHERE id = ?', [$id]);
            if (!$g) {
                self::fail('Уровень не найден — обновите страницу', $back);
            }
            if ($title === (string) $g['title'] && $rate === (int) $g['shiftRate']) {
                flash_set('ok', 'Изменений не было');
                redirect_back($back);
            }
            Db::exec('UPDATE employee_grades SET title = ?, "shiftRate" = ? WHERE id = ?', [$title, $rate, $id]);
            $was = (int) $g['shiftRate'] !== $rate ? ' (было ' . money_rub((int) $g['shiftRate']) . ')' : '';
            audit($user, 'staff', 'update', 'Уровень «' . $title . '»: ' . money_rub($rate) . ' за смену' . $was, url_to($back));
            flash_set('ok', 'Уровень «' . $title . '» сохранён');
        } else {
            $sort = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM employee_grades') + 1;
            Db::insert('employee_grades', ['title' => $title, 'shiftRate' => $rate, 'sortOrder' => $sort, 'createdAt' => now_iso()]);
            audit($user, 'staff', 'create', 'Новый уровень «' . $title . '»: ' . money_rub($rate) . ' за смену', url_to($back));
            flash_set('ok', 'Уровень «' . $title . '» добавлен');
        }
        redirect_back($back);
    }

    private static function doDeleteGrade(array $user): void
    {
        $back = '/manage/grades';
        self::permOrFail($user, 'staff.manage', null, $back);
        $id = self::postInt('id');
        $g = Db::one('SELECT id, title FROM employee_grades WHERE id = ?', [$id]);
        if (!$g) {
            self::fail('Уровень не найден', $back);
        }
        $n = (int) Db::value('SELECT COUNT(*) FROM employees WHERE "gradeId" = ?', [$id]);
        Db::tx(static function () use ($id): void {
            Db::exec('UPDATE employees SET "gradeId" = NULL WHERE "gradeId" = ?', [$id]);
            Db::exec('DELETE FROM employee_grades WHERE id = ?', [$id]);
        });
        audit($user, 'staff', 'delete', 'Удалён уровень «' . $g['title'] . '»' . ($n ? ', снят у ' . plural($n, 'сотрудника', 'сотрудников', 'сотрудников') : ''), url_to($back));
        flash_set('ok', 'Уровень «' . $g['title'] . '» удалён' . ($n ? ' — у ' . plural($n, 'сотрудника', 'сотрудников', 'сотрудников') . ' уровень теперь пустой' : ''));
        redirect_back($back);
    }

    /* ---------- доступы (только владелец) ---------- */

    /**
     * Филиалы учётной записи из формы доступа.
     * Возвращает [что записать в "user"."branchIds", итоговый список филиалов (null — все)].
     * В базе хранится только отличие от правила роли: если выбор совпал с ним или ничего не отмечено,
     * пишется NULL — тогда при переводе сотрудника в другой филиал доступ переезжает вместе с карточкой.
     */
    private static function branchesFromPost(string $role, ?int $homeBranchId): array
    {
        $default = default_branch_ids($role, $homeBranchId);
        if ($role === 'owner') {
            return [null, null];
        }
        $valid = array_column(self::branches(), 'id');
        $posted = array_map('intval', array_filter((array) ($_POST['branchIds'] ?? []), 'is_scalar'));
        $picked = array_values(array_intersect($valid, $posted));
        if (!empty($_POST['branchAll']) || ($valid && count($picked) === count($valid))) {
            $chosen = null;
        } elseif ($picked) {
            $chosen = $picked;
        } else {
            return [null, $default];
        }
        if ($chosen === $default) {
            return [null, $chosen];
        }
        return [$chosen === null ? '*' : (string) json_encode($chosen), $chosen];
    }

    /** Старая колонка scopeBranchId: один филиал — его id, иначе пусто. Портал её не читает, она нужна старым копиям базы. */
    private static function legacyScope(?array $branchIds): ?int
    {
        return $branchIds !== null && count($branchIds) === 1 ? (int) $branchIds[0] : null;
    }

    private static function branchesLabel(?array $branchIds): string
    {
        if ($branchIds === null) {
            return 'все филиалы';
        }
        return $branchIds ? 'филиалы: ' . self::branchNames($branchIds) : 'филиал не назначен';
    }

    private static function accountFail(string $msg): never
    {
        form_stash_set('account-new', [
            'employeeId' => self::postStr('employeeId', 12),
            'name' => self::cleanName('name'),
            'homeBranchId' => self::postStr('homeBranchId', 12),
            'onShifts' => !empty($_POST['onShifts']),
            'login' => self::postStr('login', 32),
            'role' => normalize_role(self::postStr('role', 16)),
            'branchAll' => !empty($_POST['branchAll']),
            'branchIds' => array_map('intval', array_filter((array) ($_POST['branchIds'] ?? []), 'is_scalar')),
        ]);
        self::fail($msg, '/manage/accounts');
    }

    private static function doSaveAccount(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/accounts');
        $login = strtolower(self::postStr('login', 32));
        $password = (string) ($_POST['password'] ?? '');
        $role = normalize_role(self::postStr('role', 16));
        $linkId = self::postInt('employeeId');

        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
            self::accountFail('Логин: 3–32 символа — латиница, цифры, точка, дефис или подчёркивание');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            self::accountFail('Пароль — от 8 до 72 символов');
        }
        $email = $login . '@' . LOGIN_DOMAIN;
        if (Db::one('SELECT id FROM "user" WHERE email = ?', [$email])) {
            self::accountFail('Логин «' . $login . '» уже занят — придумайте другой');
        }
        $link = null;
        if ($linkId > 0) {
            $link = Db::one('SELECT id, name, "userId", "homeBranchId" FROM employees WHERE id = ?', [$linkId]);
            if (!$link) {
                self::accountFail('Сотрудник не найден — обновите страницу');
            }
            if (!empty($link['userId'])) {
                self::accountFail('У «' . $link['name'] . '» уже есть вход в портал');
            }
            $name = (string) $link['name'];
        } else {
            $name = self::cleanName('name');
            if (mb_strlen($name) < 2) {
                self::accountFail('Впишите фамилию и имя или выберите сотрудника из списка');
            }
        }
        $needCard = !$link && !empty($_POST['onShifts']);
        $home = self::postInt('homeBranchId');
        if ($needCard && !self::branchById($home)) {
            self::accountFail('Выберите филиал — сотрудник появится в его графике');
        }
        $cardBranch = $link ? (int) $link['homeBranchId'] : ($needCard ? $home : null);
        [$branchIds, $branches] = self::branchesFromPost($role, $cardBranch);
        $scope = self::legacyScope($branches);

        $uid = new_id();
        $now = now_iso();
        try {
            Db::tx(static function () use ($uid, $name, $email, $role, $scope, $branchIds, $now, $password, $link, $needCard, $home): void {
                Db::exec(
                    'INSERT INTO "user" (id, name, email, "emailVerified", role, permissions, "scopeBranchId", "branchIds", "createdAt", "updatedAt") VALUES (?, ?, ?, 0, ?, NULL, ?, ?, ?, ?)',
                    [$uid, $name, $email, $role, $scope, $branchIds, $now, $now],
                );
                Db::exec(
                    'INSERT INTO account (id, "accountId", "providerId", "userId", password, password_php, "createdAt", "updatedAt") VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [new_id(), $email, 'credential', $uid, '', password_hash($password, PASSWORD_DEFAULT), $now, $now],
                );
                if ($link) {
                    Db::exec('UPDATE employees SET "userId" = ?, "isActive" = 1 WHERE id = ?', [$uid, (int) $link['id']]);
                } elseif ($needCard) {
                    $max = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM employees WHERE "homeBranchId" = ?', [$home]);
                    Db::insert('employees', ['name' => $name, 'homeBranchId' => $home, 'userId' => $uid, 'isActive' => 1, 'sortOrder' => $max + 1, 'createdAt' => $now]);
                }
            });
        } catch (Throwable $e) {
            error_log('[gamerpro] save account: ' . $e->getMessage());
            self::accountFail('Не удалось создать доступ — попробуйте ещё раз');
        }
        audit($user, 'access', 'create', 'Новый доступ: ' . $name . ' (' . $login . '), роль «' . role_label($role) . '»' . ($role !== 'owner' ? ', ' . self::branchesLabel($branches) : ''), url_to('/manage/accounts'));
        flash_set('ok', 'Доступ создан. Логин «' . $login . '» — передайте его сотруднику вместе с паролем.');
        redirect_to('/manage/accounts');
    }

    private static function doUpdateAccess(array $user): void
    {
        $back = '/manage/accounts';
        self::permOrFail($user, 'owner', null, $back);
        $uid = self::postStr('userId', 64);
        if ($uid === $user['id']) {
            self::fail('Свои права поменять нельзя — это может сделать другой владелец', $back);
        }
        $target = Db::one('SELECT id, name, role FROM "user" WHERE id = ?', [$uid]);
        if (!$target) {
            self::fail('Доступ не найден — обновите страницу', $back);
        }
        $role = normalize_role(self::postStr('role', 16));
        if (normalize_role($target['role']) === 'owner' && $role !== 'owner' && (int) Db::value('SELECT COUNT(*) FROM "user" WHERE role = ?', ['owner']) <= 1) {
            self::fail('Это единственный владелец — сначала назначьте другого', $back);
        }
        $perms = null;
        $picked = [];
        if ($role !== 'owner') {
            $posted = array_filter((array) ($_POST['perms'] ?? []), 'is_string');
            $picked = array_values(array_intersect(array_keys(PERMISSIONS), $posted));
            $a = $picked;
            $b = role_preset($role);
            sort($a);
            sort($b);
            $perms = $a === $b ? null : (string) json_encode([...$picked, PERMS_MARKER]);
        }
        $card = Db::one('SELECT name, "homeBranchId" FROM employees WHERE "userId" = ?', [$uid]);
        [$branchIds, $branches] = self::branchesFromPost($role, $card ? (int) $card['homeBranchId'] : null);
        Db::exec(
            'UPDATE "user" SET role = ?, permissions = ?, "scopeBranchId" = ?, "branchIds" = ?, "updatedAt" = ? WHERE id = ?',
            [$role, $perms, self::legacyScope($branches), $branchIds, now_iso(), $uid],
        );
        $name = (string) ($card['name'] ?? $target['name']);
        $details = role_label($role);
        if ($role !== 'owner') {
            $details .= $perms !== null ? ', свои права: ' . count($picked) : ', права роли';
            $details .= ', ' . self::branchesLabel($branches);
        }
        audit($user, 'access', 'update', 'Права «' . $name . '»: ' . $details, url_to($back));
        flash_set('ok', 'Права «' . $name . '» сохранены — действуют сразу');
        redirect_back($back);
    }

    private static function doResetPassword(array $user): void
    {
        $back = '/manage/accounts';
        self::permOrFail($user, 'owner', null, $back);
        $uid = self::postStr('userId', 64);
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            self::fail('Пароль — от 8 до 72 символов', $back);
        }
        $target = Db::one('SELECT id, name, email FROM "user" WHERE id = ?', [$uid]);
        if (!$target) {
            self::fail('Доступ не найден — обновите страницу', $back);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = now_iso();
        $email = (string) $target['email'];
        if (Db::one('SELECT id FROM account WHERE "userId" = ? AND "providerId" = ?', [$uid, 'credential'])) {
            Db::exec('UPDATE account SET password = ?, password_php = ?, "updatedAt" = ? WHERE "userId" = ? AND "providerId" = ?', ['', $hash, $now, $uid, 'credential']);
        } else {
            Db::exec(
                'INSERT INTO account (id, "accountId", "providerId", "userId", password, password_php, "createdAt", "updatedAt") VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [new_id(), $email, 'credential', $uid, '', $hash, $now, $now],
            );
        }
        try {
            Db::exec('DELETE FROM login_attempts WHERE login = ?', [self::loginOf($email)]);
        } catch (PDOException) {
        }
        $name = (string) (Db::value('SELECT name FROM employees WHERE "userId" = ?', [$uid]) ?? $target['name']);
        audit($user, 'access', 'password', 'Новый пароль для «' . $name . '»', url_to($back));
        flash_set('ok', 'Пароль для «' . $name . '» изменён');
        redirect_back($back);
    }

    private static function doDeleteAccount(array $user): void
    {
        $back = '/manage/accounts';
        self::permOrFail($user, 'owner', null, $back);
        $uid = self::postStr('userId', 64);
        if ($uid === '' || $uid === $user['id']) {
            self::fail('Нельзя удалить доступ, под которым вы вошли', $back);
        }
        $target = Db::one('SELECT id, name, email, role FROM "user" WHERE id = ?', [$uid]);
        if (!$target) {
            self::fail('Доступ не найден — обновите страницу', $back);
        }
        if (normalize_role($target['role']) === 'owner' && (int) Db::value('SELECT COUNT(*) FROM "user" WHERE role = ? AND id != ?', ['owner', $uid]) < 1) {
            self::fail('Это последний владелец — его нельзя удалить', $back);
        }
        $name = (string) (Db::value('SELECT name FROM employees WHERE "userId" = ?', [$uid]) ?? $target['name']);
        try {
            Db::tx(static function () use ($uid): void {
                Db::exec('DELETE FROM account WHERE "userId" = ?', [$uid]);
                Db::exec('UPDATE employees SET "userId" = NULL, "isActive" = 0 WHERE "userId" = ?', [$uid]);
                Db::exec('DELETE FROM "user" WHERE id = ?', [$uid]);
            });
        } catch (Throwable $e) {
            error_log('[gamerpro] delete account: ' . $e->getMessage());
            self::fail('Не удалось удалить доступ — попробуйте ещё раз', $back);
        }
        audit($user, 'access', 'delete', 'Удалён доступ: ' . $name . ' (' . self::loginOf((string) $target['email']) . ')', url_to($back));
        flash_set('ok', 'Доступ «' . $name . '» удалён, карточка в архиве');
        redirect_back($back);
    }

    private static function doChangePassword(array $user): void
    {
        $back = '/account';
        $current = (string) ($_POST['current'] ?? '');
        $next = (string) ($_POST['next'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        if ($current === '') {
            self::fail('Введите текущий пароль', $back);
        }
        if (strlen($next) < 8 || strlen($next) > 72) {
            self::fail('Новый пароль — от 8 до 72 символов', $back);
        }
        if ($next !== $confirm) {
            self::fail('Новый пароль и повтор не совпадают', $back);
        }
        if ($next === $current) {
            self::fail('Новый пароль совпадает с текущим', $back);
        }
        $acc = Db::one('SELECT id, password, password_php FROM account WHERE "userId" = ? AND "providerId" = ?', [$user['id'], 'credential']);
        $ok = false;
        if ($acc) {
            if (!empty($acc['password_php']) && password_verify($current, (string) $acc['password_php'])) {
                $ok = true;
            } elseif (!empty($acc['password'])) {
                @ini_set('memory_limit', '256M');
                $ok = Scrypt::verifyBetterAuth((string) $acc['password'], $current);
            }
        }
        if (!$ok) {
            self::fail('Текущий пароль неверный', $back);
        }
        Db::exec('UPDATE account SET password = ?, password_php = ?, "updatedAt" = ? WHERE id = ?', ['', password_hash($next, PASSWORD_DEFAULT), now_iso(), $acc['id']]);
        session_regenerate_id(true);
        audit($user, 'access', 'password', self::displayName($user) . ' сменил(а) свой пароль', url_to($back));
        flash_set('ok', 'Пароль изменён');
        redirect_to($back);
    }

    private static function doSaveAvatar(array $user): void
    {
        $back = '/account';
        if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            self::fail('Выберите фото', $back);
        }
        try {
            $ver = Avatars::saveUpload((string) $user['id'], $_FILES['avatar']);
            Db::exec('UPDATE "user" SET image = ?, "updatedAt" = ? WHERE id = ?', [$ver, now_iso(), $user['id']]);
        } catch (Throwable $e) {
            self::fail($e->getMessage(), $back);
        }
        audit($user, 'access', 'avatar', self::displayName($user) . ' обновил(а) аватар', url_to($back));
        flash_set('ok', 'Аватар обновлён — коллеги увидят его в меню и в команде');
        redirect_to($back);
    }

    private static function doRemoveAvatar(array $user): void
    {
        $back = '/account';
        Avatars::delete((string) $user['id']);
        Db::exec('UPDATE "user" SET image = NULL, "updatedAt" = ? WHERE id = ?', [now_iso(), $user['id']]);
        audit($user, 'access', 'avatar', self::displayName($user) . ' убрал(а) аватар', url_to($back));
        flash_set('ok', 'Фото убрано');
        redirect_to($back);
    }

    /* ---------- персональные настройки / Telegram ---------- */

    /** @return array{telegramChatId: string, notify: array<string,bool>, features: array<string,bool>} */
    private static function prefsFromPost(): array
    {
        $base = Notify::PREF_DEFAULTS;
        $base['telegramChatId'] = preg_replace('/[^\d\-]/', '', self::postStr('telegramChatId', 32)) ?? '';
        foreach (array_keys($base['notify']) as $k) {
            $base['notify'][$k] = !empty($_POST['notify'][$k]);
        }
        foreach (array_keys($base['features']) as $k) {
            $base['features'][$k] = !empty($_POST['features'][$k]);
        }
        return $base;
    }

    private static function writeUserPrefs(string $userId, array $prefs): void
    {
        $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        try {
            Db::exec('UPDATE "user" SET prefs = ?, "updatedAt" = ? WHERE id = ?', [$json, now_iso(), $userId]);
        } catch (PDOException $e) {
            throw $e;
        }
    }

    private static function doSaveMyPrefs(array $user): void
    {
        $back = '/account';
        try {
            self::writeUserPrefs($user['id'], self::prefsFromPost());
        } catch (PDOException) {
            self::fail('Не удалось сохранить настройки — обновите схему БД (зайдите на любую страницу от владельца)', $back);
        }
        flash_set('ok', 'Настройки уведомлений сохранены');
        redirect_to($back . '#prefs');
    }

    private static function doSaveUserPrefs(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/accounts');
        $uid = self::postStr('userId', 64);
        $target = Db::one('SELECT id, name FROM "user" WHERE id = ?', [$uid]);
        if (!$target) {
            self::fail('Учётная запись не найдена', '/manage/accounts');
        }
        try {
            self::writeUserPrefs($uid, self::prefsFromPost());
        } catch (PDOException) {
            self::fail('Не удалось сохранить — сначала откройте сайт под владельцем, чтобы схема обновилась', '/manage/accounts');
        }
        audit($user, 'access', 'prefs', 'Настройки уведомлений: ' . $target['name'], url_to('/manage/accounts'));
        flash_set('ok', 'Настройки для «' . $target['name'] . '» сохранены');
        redirect_to('/manage/accounts');
    }

    private static function doSaveTelegramBot(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/notify');
        $token = self::postStr('token', 200);
        $clear = isset($_POST['clearToken']);
        if ($token === '' && !$clear) {
            flash_set('ok', 'Токен не менялся (поле было пустым). Чтобы удалить — отметьте «Сбросить токен».');
            redirect_to('/manage/notify');
        }
        if ($clear) {
            $token = '';
        }
        Notify::saveBotToken($token);
        audit($user, 'access', 'telegram', $token === '' ? 'Токен Telegram-бота очищен' : 'Обновлён токен Telegram-бота', url_to('/manage/notify'));
        flash_set('ok', $token === '' ? 'Токен бота удалён' : 'Токен бота сохранён');
        redirect_to('/manage/notify');
    }

    private static function doTestTelegram(array $user): void
    {
        $back = self::postStr('back', 80) ?: '/account';
        $prefs = Notify::prefs($user);
        $chat = trim((string) ($prefs['telegramChatId'] ?? ''));
        $token = Notify::botToken();
        if ($token === null) {
            self::fail('Сначала владелец укажет токен бота в «Telegram и уведомления»', $back);
        }
        if ($chat === '') {
            self::fail('Укажите свой Chat ID и сохраните настройки', $back);
        }
        $ok = Notify::sendTelegram($token, $chat, 'GAMER PRO CRM: тест уведомления для ' . self::displayName($user));
        if (!$ok) {
            self::fail('Telegram не принял сообщение — проверьте токен и Chat ID (напишите боту /start)', $back);
        }
        flash_set('ok', 'Тестовое сообщение отправлено в Telegram');
        redirect_to($back);
    }

    private static function doSaveChecklistItem(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/checklist');
        $title = trim(self::postStr('title', 255));
        if (mb_strlen($title) < 3) {
            self::fail('Текст пункта — минимум 3 символа', '/manage/checklist');
        }
        $phase = self::postStr('phase', 16);
        if (!isset(HANDOVER_PHASES[$phase])) {
            self::fail('Выберите фазу: приём или сдача', '/manage/checklist');
        }
        $id = self::postInt('id');
        $active = empty($_POST['isActive']) ? 0 : 1;
        $sort = max(0, self::postInt('sortOrder'));
        if ($id > 0) {
            Db::exec(
                'UPDATE shift_checklist_items SET title = ?, phase = ?, "sortOrder" = ?, "isActive" = ? WHERE id = ?',
                [$title, $phase, $sort, $active, $id],
            );
            flash_set('ok', 'Пункт чек-листа обновлён');
        } else {
            Db::insert('shift_checklist_items', [
                'title' => $title,
                'phase' => $phase,
                'sortOrder' => $sort,
                'isActive' => 1,
            ]);
            flash_set('ok', 'Пункт добавлен');
        }
        redirect_to('/manage/checklist');
    }

    private static function doDeleteChecklistItem(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/checklist');
        $id = self::postInt('id');
        Db::exec('UPDATE shift_checklist_items SET "isActive" = 0 WHERE id = ?', [$id]);
        flash_set('ok', 'Пункт скрыт (старые отчёты сохранят историю)');
        redirect_to('/manage/checklist');
    }

    /* ---------- база данных ---------- */

    private static function doDownloadDump(array $user): void
    {
        self::permOrFail($user, 'owner', null, '/manage/database');
        $driver = Db::driver();
        audit($user, 'access', 'export', 'Скачана копия базы данных (localhost.sql)', url_to('/manage/database'));
        @set_time_limit(300);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="localhost.sql"');
        header('Cache-Control: no-store, max-age=0');
        Schema::dumpMysql(Db::pdo(), $driver, static function (string $chunk): void {
            echo $chunk;
        }, $driver === 'mysql' ? 'MySQL на хостинге' : basename(Db::path()));
        exit;
    }

    /* ---------- выплаты и реквизиты ---------- */

    private static function doMarkPayout(array $user): void
    {
        $eid = self::postInt('employeeId');
        $month = self::postStr('month', 7);
        $period = self::postInt('period') === 1 ? 1 : 2;
        $e = Db::one('SELECT id, name, "homeBranchId" FROM employees WHERE id = ?', [$eid]);
        if (!$e || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            self::fail('Сотрудник или месяц не найдены');
        }
        self::permOrFail($user, 'page.bar', (int) $e['homeBranchId']);
        $existing = Db::one('SELECT id FROM salary_payouts WHERE "employeeId" = ? AND month = ? AND period = ?', [$eid, $month, $period]);
        $label = ($period === 1 ? '1–15' : '16–конец') . ' ' . $month;
        if ($existing) {
            Db::exec('DELETE FROM salary_payouts WHERE id = ?', [(int) $existing['id']]);
            audit($user, 'salary', 'unpay', 'Снята отметка перевода: ' . $e['name'] . ', ' . $label);
            flash_set('success', 'Отметка о переводе снята');
            redirect_back('/bar');
        }
        $data = Payroll::employee($eid, $month);
        $amount = $data ? max(0, (int) $data['periods'][$period]['total']) : 0;
        if ($amount <= 0) {
            self::fail('За эту половину переводить нечего');
        }
        Db::insert('salary_payouts', [
            'employeeId' => $eid, 'month' => $month, 'period' => $period, 'amount' => $amount,
            'paidByUserId' => (string) $user['id'], 'paidByName' => (string) ($user['name'] ?? ''), 'paidAt' => now_iso(),
        ]);
        $canSeeMgr = !empty($user['isOwner']) || (int) ($user['employeeId'] ?? 0) === $eid;
        $hasMgr = $data && $period === 2 && (int) $data['periods'][2]['mgrBar'] > 0;
        audit($user, 'salary', 'pay', 'Перевод ' . $e['name'] . ', ' . $label . ($hasMgr && !$canSeeMgr ? '' : ': ' . money_rub($amount)));
        flash_set('success', 'Отмечено: ' . short_name((string) $e['name']) . ' — переведено');
        redirect_back('/bar');
    }

    private static function doSavePayDetails(array $user): void
    {
        $self = (int) ($user['employeeId'] ?? 0);
        $eid = self::postInt('employeeId') ?: $self;
        if ($eid !== $self) {
            self::permOrFail($user, 'staff.manage', null, '/salary');
        }
        if (!$eid || !Db::one('SELECT id FROM employees WHERE id = ?', [$eid])) {
            self::fail('Карточка сотрудника не найдена', '/salary');
        }
        $digits = preg_replace('/\D+/', '', self::postStr('payPhone', 32)) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }
        if ($digits !== '' && strlen($digits) !== 10) {
            self::fail('Телефон должен быть из 10 цифр после +7', '/salary');
        }
        $bank = self::postStr('payBank', 64);
        if ($bank !== '' && !in_array($bank, Payroll::BANKS, true)) {
            self::fail('Выберите банк из списка', '/salary');
        }
        if ($digits !== '' && $bank === '') {
            self::fail('Укажите банк, на который переводить', '/salary');
        }
        $note = self::postStr('payNote', 191);
        Db::exec('UPDATE employees SET "payPhone" = ?, "payBank" = ?, "payNote" = ?, "payUpdatedAt" = ? WHERE id = ?', [$digits, $bank, $note, now_iso(), $eid]);
        audit($user, 'employee', 'pay_details', 'Обновлены реквизиты для перевода');
        flash_set('success', 'Реквизиты сохранены');
        redirect_back('/salary');
    }

    private static function doSaveMgrBar(array $user): void
    {
        if (empty($user['isOwner'])) {
            self::fail('Процент управляющего меняет только владелец', '/manage/employees');
        }
        $eid = self::postInt('employeeId');
        $e = Db::one('SELECT id, name FROM employees WHERE id = ?', [$eid]);
        if (!$e) {
            self::fail('Сотрудник не найден', '/manage/employees');
        }
        $pct = (float) str_replace(',', '.', self::postStr('mgrBarPct', 8));
        if ($pct < 0 || $pct > 20) {
            self::fail('Процент — от 0 до 20', '/manage/employees');
        }
        $ids = [];
        foreach ((array) ($_POST['mgrBarBranches'] ?? []) as $b) {
            if (ctype_digit((string) $b)) {
                $ids[] = (int) $b;
            }
        }
        $ids = array_values(array_unique($ids));
        Db::exec('UPDATE employees SET "mgrBarPctX10" = ?, "mgrBarBranches" = ? WHERE id = ?', [(int) round($pct * 10), implode(',', $ids), $eid]);
        audit($user, 'employee', 'mgr_bar', 'Процент управляющего с бара изменён: ' . $e['name']);
        flash_set('success', 'Процент управляющего сохранён');
        redirect_back('/manage/employees');
    }
}
