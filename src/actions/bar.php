<?php
declare(strict_types=1);

trait ActionsBar
{
    private static function langameBranchFromPost(string $back): array
    {
        $b = self::branchById(self::postInt('branchId'));
        if (!$b) {
            flash_set('error', 'Филиал не найден');
            redirect_to($back);
        }
        return $b;
    }

    private static function doSaveLangame(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $base = Langame::normalizeBase(self::postStr('baseUrl', 255));
        if ($base !== '' && !preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/[A-Za-z0-9._/-]*)?$#i', $base)) {
            flash_set('error', 'Адрес должен быть вида ' . Langame::BASE_EXAMPLE);
            redirect_to($back);
        }
        $pcCount = filter_var($_POST['pcCount'] ?? $b['pcCount'], FILTER_VALIDATE_INT);
        if ($pcCount === false || $pcCount < 0 || $pcCount > 500) {
            flash_set('error', 'Количество компьютеров — целое число от 0 до 500');
            redirect_to($back);
        }
        $dayStart = filter_var($_POST['dayStartHour'] ?? branch_day_start($b['id']), FILTER_VALIDATE_INT);
        if ($dayStart === false || $dayStart < 0 || $dayStart > 11) {
            flash_set('error', 'Дневная смена начинается с 00:00 до 11:00');
            redirect_to($back);
        }
        $exclude = implode(', ', BarRules::words(self::postStr('excludeGoods', 500)));
        $pctRaw = str_replace(',', '.', self::postStr('barPercent', 8));
        $pct = $pctRaw === '' ? 0 : (int) round(((float) $pctRaw) * 10);
        if ($pct < 0 || $pct > 1000) {
            flash_set('error', 'Процент — от 0 до 100');
            redirect_to($back);
        }
        $token = preg_replace('/\s+/', '', (string) ($_POST['token'] ?? '')) ?? '';
        if (strlen($token) > 512) {
            flash_set('error', 'Токен слишком длинный');
            redirect_to($back);
        }
        $enabled = !empty($_POST['isEnabled']) ? 1 : 0;
        $s = Langame::settings($b['id']);
        try {
            $enc = $token !== '' ? Langame::encrypt($token) : ($s['tokenEnc'] ?? null);
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
            redirect_to($back);
        }
        $hint = $token !== '' ? '…' . substr($token, -4) : ($s['tokenHint'] ?? null);
        if ($enabled && ($enc === null || $base === '')) {
            flash_set('error', 'Чтобы включить подключение, укажите адрес клуба и токен');
            redirect_to($back);
        }
        $row = ['baseUrl' => $base, 'tokenEnc' => $enc, 'tokenHint' => $hint, 'barPercentX10' => $pct, 'isEnabled' => $enabled, 'excludeGoods' => $exclude !== '' ? $exclude : null, 'updatedAt' => now_iso()];
        if ($s) {
            Db::exec('UPDATE langame_settings SET "baseUrl" = ?, "tokenEnc" = ?, "tokenHint" = ?, "barPercentX10" = ?, "isEnabled" = ?, "excludeGoods" = ?, "updatedAt" = ? WHERE id = ?', [...array_values($row), $s['id']]);
        } else {
            Db::insert('langame_settings', ['branchId' => $b['id']] + $row);
        }
        Db::exec('UPDATE branches SET "pcCount" = ?, "dayStartHour" = ? WHERE id = ?', [$pcCount, $dayStart, $b['id']]);
        BarRules::apply(Db::pdo(), $b['id']);
        audit($user, 'content', 'langame', 'Настройки Langame: ' . $b['name'] . ($token !== '' ? ' (новый токен)' : ''), url_to($back));
        if ($enabled && $token !== '') {
            try {
                $r = Langame::sync($b['id']);
                flash_set('success', 'Подключено. Загружено продаж: ' . $r['sales'] . ', администраторов: ' . $r['users'] . '. Теперь привяжите их к сотрудникам ниже.');
            } catch (LangameError $e) {
                flash_set('error', 'Сохранено, но синхронизация не прошла: ' . $e->getMessage());
            }
        } else {
            flash_set('success', 'Настройки ' . $b['name'] . ' сохранены');
        }
        redirect_to($back);
    }

    private static function doTestLangame(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $s = Langame::settings($b['id']);
        $token = $s ? Langame::decrypt($s['tokenEnc'] ?? null) : null;
        if (!$s || $token === null || (string) $s['baseUrl'] === '') {
            flash_set('error', 'Сначала сохраните адрес клуба и токен');
            redirect_to($back);
        }
        try {
            Langame::test((string) $s['baseUrl'], $token);
            flash_set('success', 'Связь с Langame есть — токен принят');
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
        }
        redirect_to($back);
    }

    private static function doSyncLangame(array $user): void
    {
        $back = self::postStr('back', 200);
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/bar';
        $b = self::langameBranchFromPost($back);
        $s = Langame::settings($b['id']);
        if (!$s || !boolish($s['isEnabled'])) {
            flash_set('error', 'Подключение к Langame для ' . $b['name'] . ' выключено');
            redirect_to($back);
        }
        $last = $s['lastSyncAt'] ? strtotime((string) $s['lastSyncAt']) : 0;
        if (!$user['isOwner'] && $last && time() - $last < 30) {
            flash_set('success', 'Данные только что обновились');
            redirect_to($back);
        }
        try {
            $r = Langame::sync($b['id']);
            try {
                Langame::refreshLive($b['id']);
            } catch (Throwable) {
            }
            flash_set('success', 'Обновлено: ' . plural($r['sales'], 'продажа', 'продажи', 'продаж') . ' за последние дни');
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
        }
        redirect_to($back);
    }

    /**
     * Тихий фоновый refresh после отрисовки страницы (fetch keepalive).
     * Не редиректит — отвечает JSON, чтобы вкладки открывались мгновенно.
     */
    private static function doRefreshLangame(array $user): void
    {
        $raw = (string) ($_POST['branches'] ?? '');
        $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $raw) ?: []), static fn ($id) => $id > 0));
        $allowed = [];
        foreach (self::visibleBranches($user) as $b) {
            $allowed[(int) $b['id']] = true;
        }
        // Свои продажи: филиалы с привязанной учёткой Langame тоже можно обновлять.
        if ($user['employeeId'] !== null) {
            foreach (Db::all('SELECT DISTINCT "branchId" FROM langame_staff WHERE "employeeId" = ?', [(int) $user['employeeId']]) as $r) {
                $allowed[(int) $r['branchId']] = true;
            }
        }
        if ($user['isOwner']) {
            foreach (Langame::enabledBranchIds() as $bid) {
                $allowed[$bid] = true;
            }
        }
        $ids = array_values(array_filter($ids, static fn ($id) => isset($allowed[$id])));
        if (!$ids) {
            self::jsonOut(['ok' => true, 'synced' => [], 'live' => [], 'errors' => []]);
        }
        $force = !empty($_POST['force']) && $_POST['force'] !== '0';
        self::jsonOut(Langame::refreshNow($ids, $force));
    }

    /** @param array<string, mixed> $payload */
    private static function jsonOut(array $payload): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function doLinkLangameStaff(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $links = is_array($_POST['link'] ?? null) ? $_POST['link'] : [];
        $pcts = is_array($_POST['pct'] ?? null) ? $_POST['pct'] : [];
        $valid = array_fill_keys(array_map(static fn ($e) => (int) $e['id'], self::employees()), true);
        $used = [];
        $n = 0;
        Db::tx(static function () use ($b, $links, $pcts, $valid, &$used, &$n): void {
            foreach ($links as $lid => $eidRaw) {
                $lid = (int) $lid;
                $eid = (int) $eidRaw;
                $eid = isset($valid[$eid]) ? $eid : null;
                if ($eid !== null) {
                    if (isset($used[$eid])) {
                        $eid = null;
                    } else {
                        $used[$eid] = true;
                        $n++;
                    }
                }
                $p = str_replace(',', '.', trim((string) ($pcts[$lid] ?? '')));
                $pct = $p === '' ? null : max(0, min(1000, (int) round(((float) $p) * 10)));
                Db::exec('UPDATE langame_staff SET "employeeId" = ?, "percentX10" = ?, "updatedAt" = ? WHERE "branchId" = ? AND "langameUserId" = ?', [$eid, $pct, now_iso(), $b['id'], $lid]);
            }
        });
        audit($user, 'staff', 'langame_link', 'Привязка учёток Langame (' . $b['name'] . '): ' . plural($n, 'сотрудник', 'сотрудника', 'сотрудников'), url_to($back));
        flash_set('success', 'Привязки сохранены' . (count($used) < count(array_filter($links, static fn ($v) => (int) $v > 0)) ? '. Один сотрудник может быть привязан только к одной учётке — дубли сброшены.' : ''));
        redirect_to($back);
    }

    private static function doForgetLangameToken(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        Db::exec('UPDATE langame_settings SET "tokenEnc" = NULL, "tokenHint" = NULL, "isEnabled" = 0, "updatedAt" = ? WHERE "branchId" = ?', [now_iso(), $b['id']]);
        audit($user, 'content', 'langame', 'Токен Langame удалён: ' . $b['name'], url_to($back));
        flash_set('success', 'Токен удалён, подключение выключено');
        redirect_to($back);
    }

    private static function doInitLangameCron(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            flash_set('error', 'Не удалось создать папку storage/');
            redirect_to($back);
        }
        $secret = bin2hex(random_bytes(16));
        if (@file_put_contents($dir . '/cron.key', $secret . "\n", LOCK_EX) === false) {
            flash_set('error', 'Не удалось записать storage/cron.key');
            redirect_to($back);
        }
        @chmod($dir . '/cron.key', 0600);
        audit($user, 'content', 'langame_cron', 'Ключ HTTP-cron Langame выпущен', url_to($back));
        flash_set('success', 'Ключ cron создан — скопируйте URL ниже в панель reg.ru');
        redirect_to($back);
    }
}
