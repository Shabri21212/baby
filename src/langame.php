<?php
declare(strict_types=1);

final class LangameError extends RuntimeException
{
}

/**
 * Клиент открытого API Langame и синхронизация продаж бара.
 * Токен хранится в базе только в зашифрованном виде (AES-256-GCM);
 * ключ шифрования лежит в storage/, который закрыт от браузера.
 */
final class Langame
{
    public const BASE_EXAMPLE = 'https://1433.langame.ru/public_api';
    private const SALES_PAGE = 500;
    private const MAX_PAGES = 40;
    /** Как часто подтягивать продажи в фоне (сек). Страницу это больше не блокирует. */
    public const AUTO_SYNC_SECONDS = 180;
    /** Свежий live-кэш: отдаём сразу, API не трогаем. */
    public const LIVE_FRESH_SECONDS = 120;
    /** Устаревший, но ещё полезный live-кэш: отдаём сразу, обновление — в фоне. */
    public const LIVE_STALE_SECONDS = 900;

    /** Филиалы, которые страница просит обновить через JS beacon /api/langame-refresh. */
    private static array $pendingBranches = [];

    /* ---------- шифрование токена ---------- */

    private static function key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $dir = dirname(__DIR__) . '/storage';
        $file = $dir . '/langame.key';
        if (is_file($file)) {
            $raw = base64_decode(trim((string) file_get_contents($file)), true);
            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new LangameError('Не удалось создать папку storage для ключа шифрования');
        }
        $raw = random_bytes(32);
        if (@file_put_contents($file, base64_encode($raw), LOCK_EX) === false) {
            throw new LangameError('Не удалось сохранить ключ шифрования в storage/');
        }
        @chmod($file, 0600);
        return $key = $raw;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new LangameError('Не удалось зашифровать токен');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || !str_starts_with($enc, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /* ---------- настройки ---------- */

    public static function settings(int $branchId): ?array
    {
        try {
            $row = Db::one('SELECT * FROM langame_settings WHERE "branchId" = ?', [$branchId]);
        } catch (PDOException) {
            return null;
        }
        return $row ?: null;
    }

    /** Филиалы, где подключение включено и токен сохранён. @return int[] */
    public static function enabledBranchIds(): array
    {
        try {
            $rows = Db::all('SELECT "branchId" FROM langame_settings WHERE "isEnabled" = 1 AND "tokenEnc" IS NOT NULL');
        } catch (PDOException) {
            return [];
        }
        return array_map(static fn ($r) => (int) $r['branchId'], $rows);
    }

    public static function normalizeBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($url !== '' && !preg_match('#/public_api$#', $url)) {
            $url .= '/public_api';
        }
        return $url;
    }

    /* ---------- HTTP ---------- */

    private static function get(array $s, string $path, array $query = []): array
    {
        $token = self::decrypt($s['tokenEnc'] ?? null);
        $base = (string) ($s['baseUrl'] ?? '');
        if ($token === null || $base === '') {
            throw new LangameError('Не указан адрес клуба или токен');
        }
        return self::request($base, $token, $path, $query);
    }

    public static function request(string $base, string $token, string $path, array $query = [], ?array $jsonBody = null): array
    {
        if (!function_exists('curl_init')) {
            throw new LangameError('На сервере не включено расширение PHP cURL');
        }
        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['X-Request-Token: ' . $token, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'GamerPro-Portal/1.0',
        ];
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new LangameError('Langame не отвечает: ' . ($err !== '' ? $err : 'нет соединения'));
        }
        $json = json_decode((string) $body, true);
        if ($code === 401 || $code === 403) {
            throw new LangameError('Langame отклонил токен (код ' . $code . '). Проверьте, что ключ серверный и выдан для этого клуба.');
        }
        if ($code === 404) {
            throw new LangameError('Адрес не найден (404). Проверьте адрес клуба — он выглядит как ' . self::BASE_EXAMPLE);
        }
        if ($code >= 400 || !is_array($json)) {
            $detail = is_array($json) ? (string) ($json['detail'] ?? $json['title'] ?? '') : '';
            if (is_array($json) && !empty($json['violations']) && is_array($json['violations'])) {
                $bits = [];
                foreach ($json['violations'] as $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $bits[] = trim((string) ($v['field'] ?? '') . ': ' . (string) ($v['error'] ?? $v['message'] ?? ''));
                }
                if ($bits) {
                    $detail = ($detail !== '' ? $detail . ' — ' : '') . implode('; ', $bits);
                }
            }
            throw new LangameError('Ошибка Langame (код ' . $code . ')' . ($detail !== '' ? ': ' . $detail : ''));
        }
        return $json;
    }

    private static function post(array $s, string $path, array $jsonBody): array
    {
        $token = self::decrypt($s['tokenEnc'] ?? null);
        $base = (string) ($s['baseUrl'] ?? '');
        if ($token === null || $base === '') {
            throw new LangameError('Не указан адрес клуба или токен');
        }
        return self::request($base, $token, $path, [], $jsonBody);
    }

    /** Нормализация телефона для фильтра Langame (10 цифр без кода страны, как в OpenAPI). */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) >= 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        return $digits;
    }

    /**
     * Поиск гостей Langame по имени или телефону (баланс, даты, возраст).
     * @return list<array{id: int, name: string, phone: string, balance: ?float, bonus: ?float, birthday: ?string, age: ?int, registeredAt: ?string, lastActiveAt: ?string, gender: ?string}>
     */
    public static function searchGuests(int $branchId, string $query, int $limit = 20): array
    {
        $s = self::settings($branchId);
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            throw new LangameError('Langame не подключён для филиала');
        }
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $isPhone = $digits !== '' && strlen($digits) >= 7 && preg_match('/^[\d\s+\-()]+$/u', $q);
        $phone = $isPhone ? self::normalizePhone($q) : '';
        // +7… / 8… / просто 9… — сначала точный phone (10 цифр), иначе phone_like по хвосту.
        $filters = [];
        if ($isPhone) {
            $needle = strlen($phone) >= 7 ? $phone : $digits;
            if (strlen($needle) === 10) {
                $filters[] = ['phone' => $needle];
            }
            $filters[] = ['phone_like' => $needle];
        } else {
            $filters[] = ['query' => mb_substr($q, 0, 80)];
        }
        $featues = [
            'balance' => true,
            'bonus_balance' => true,
            'fields' => [
                'guest_id', 'fio', 'phone', 'email', 'gender', 'birthday',
                'date_insert', 'date_last_activity',
            ],
        ];
        $pagination = ['page' => 1, 'size' => max(1, min(50, $limit))];
        $rows = [];
        foreach ($filters as $filter) {
            $r = self::post($s, '/guests/search', [
                'filter' => $filter,
                'featues' => $featues,
                'pagination' => $pagination,
            ]);
            $rows = self::searchGuestRows($r);
            if ($rows) {
                break;
            }
        }
        $out = [];
        foreach ($rows as $g) {
            if (!is_array($g)) {
                continue;
            }
            $id = (int) ($g['guest_id'] ?? $g['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($g['fio'] ?? ''));
            if ($name === '') {
                $name = 'Гость #' . $id;
            }
            $bal = self::guestAmount($g['balance'] ?? null);
            $bonus = self::guestAmount($g['bonus_balance'] ?? null);
            $birthday = self::guestDate((string) ($g['birthday'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => mb_substr($name, 0, 120),
                'phone' => (string) ($g['phone'] ?? ''),
                'balance' => $bal,
                'bonus' => $bonus,
                'birthday' => $birthday,
                'age' => self::ageFromBirthday($birthday),
                'registeredAt' => self::guestDate((string) ($g['date_insert'] ?? '')),
                'lastActiveAt' => self::guestDate((string) ($g['date_last_activity'] ?? '')),
                'gender' => match ((string) ($g['gender'] ?? '')) {
                    'male' => 'мужской',
                    'female' => 'женский',
                    default => $g['gender'] !== null && $g['gender'] !== '' ? (string) $g['gender'] : null,
                },
            ];
        }
        return $out;
    }

    /** Достаёт список гостей из разных обёрток ответа API. @return list<array> */
    private static function searchGuestRows(array $r): array
    {
        if (isset($r['items']) && is_array($r['items'])) {
            return $r['items'];
        }
        if (isset($r['data']['items']) && is_array($r['data']['items'])) {
            return $r['data']['items'];
        }
        if (isset($r['data']) && is_array($r['data']) && array_is_list($r['data'])) {
            return $r['data'];
        }
        return [];
    }

    private static function guestAmount(mixed $v): ?float
    {
        if (is_array($v)) {
            $v = $v['amount'] ?? null;
        }
        if ($v === null || $v === '') {
            return null;
        }
        return (float) $v;
    }

    private static function guestDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }
        if (ctype_digit($raw) && strlen($raw) >= 9) {
            try {
                return (new DateTimeImmutable('@' . $raw))->setTimezone(new DateTimeZone(APP_TZ))->format('Y-m-d H:i:s');
            } catch (Exception) {
                return null;
            }
        }
        try {
            return (new DateTimeImmutable($raw, new DateTimeZone(APP_TZ)))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return mb_substr($raw, 0, 32);
        }
    }

    private static function ageFromBirthday(?string $birthday): ?int
    {
        if ($birthday === null) {
            return null;
        }
        try {
            $born = new DateTimeImmutable(substr($birthday, 0, 10), new DateTimeZone(APP_TZ));
            $now = now_tyumen();
            $age = (int) $born->diff($now)->y;
            return $age >= 0 && $age < 130 ? $age : null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Пополнения за сегодня (кэш 90 сек в liveJson-подобном поле topupsJson через app… нет — в langame_settings.liveJson нельзя смешивать.
     * Кладём рядом: используем файл-кэш в storage или расширяем settings.
     * Проще — статический request cache + короткий файл.
     * @return array{at: int, total: float, count: int, rows: list<array{name: string, phone: string, amount: float, at: string}>}
     */
    public static function todayTopups(int $branchId): array
    {
        $s = self::settings($branchId);
        $empty = ['at' => time(), 'total' => 0.0, 'count' => 0, 'rows' => []];
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            return $empty;
        }
        $cacheFile = dirname(__DIR__) . '/storage/topups_' . $branchId . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && time() - (int) ($cached['at'] ?? 0) < 90) {
                return $cached;
            }
        }
        $today = now_tyumen()->format('Y-m-d');
        $list = [];
        $total = 0.0;
        try {
            for ($p = 1, $pages = 1; $p <= $pages && $p <= 8; $p++) {
                [$rows, $pages] = self::page($s, '/balances/list', [
                    'date_from' => $today,
                    'date_to' => $today,
                    'page' => $p,
                    'page_limit' => 100,
                ]);
                foreach ($rows as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $amount = (float) ($r['amount'] ?? 0);
                    $total += $amount;
                    $list[] = [
                        'name' => mb_substr(trim((string) ($r['guest_name'] ?? '')) ?: ('Гость #' . (int) ($r['guest_id'] ?? 0)), 0, 80),
                        'phone' => (string) ($r['phone'] ?? ''),
                        'amount' => $amount,
                        'at' => mb_substr((string) ($r['date'] ?? ''), 0, 32),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[gamerpro] topups: ' . $e->getMessage());
            return is_array($cached ?? null) ? $cached : $empty;
        }
        $out = ['at' => time(), 'total' => $total, 'count' => count($list), 'rows' => array_slice($list, 0, 12)];
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $out;
    }

    /** @return array{0: array, 1: int} данные страницы и число страниц */
    private static function page(array $s, string $path, array $query): array
    {
        $r = self::get($s, $path, $query);
        $data = isset($r['data']) && is_array($r['data']) ? $r['data'] : (array_is_list($r) ? $r : []);
        return [$data, max(1, (int) ($r['total_pages'] ?? 1))];
    }

    /**
     * Пульс кассы за день: наличные продажи бара по сменам сотрудников + топ товаров.
     * @return array{
     *   cashTotal: int, cashlessTotal: int, salesCount: int,
     *   shifts: list<array{shiftExtId: int, staffName: string, employeeId: ?int, cash: int, cashless: int, lines: int, startedAt: ?string, goods: list<array{name: string, qty: int, amount: int}>}>,
     *   reports: list<array{shiftType: string, userName: string, cashExpected: ?int, cashActual: ?int}>
     * }
     */
    public static function cashPulse(int $branchId, string $day, ?int $highlightEmployeeId = null): array
    {
        $empty = ['cashTotal' => 0, 'cashlessTotal' => 0, 'salesCount' => 0, 'shifts' => [], 'reports' => []];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return $empty;
        }
        $from = $day . ' 00:00:00';
        $to = $day . ' 23:59:59';
        try {
            $agg = Db::all(
                'SELECT s."shiftExtId" AS sid,
                        COALESCE(MAX(e.name), MAX(l."langameName"), \'Смена\') AS staffName,
                        MAX(l."employeeId") AS eid,
                        MIN(w."dateStart") AS startedAt,
                        SUM(CASE WHEN s."isCancelled" = 0 AND s."isCashless" = 0 THEN s."amountX100" ELSE 0 END) AS cash,
                        SUM(CASE WHEN s."isCancelled" = 0 AND s."isCashless" = 1 THEN s."amountX100" ELSE 0 END) AS cashless,
                        SUM(CASE WHEN s."isCancelled" = 0 THEN 1 ELSE 0 END) AS lines
                 FROM bar_sales s
                 LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN employees e ON e.id = l."employeeId"
                 WHERE s."branchId" = ? AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY s."shiftExtId"
                 ORDER BY cash DESC, lines DESC',
                [$branchId, $from, $to],
            );
            $goods = Db::all(
                'SELECT s."shiftExtId" AS sid, COALESCE(p.name, \'Товар\') AS name, SUM(s.qty) AS qty, SUM(s."amountX100") AS amount
                 FROM bar_sales s
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE s."branchId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."isCashless" = 0
                   AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY s."shiftExtId", p.name
                 ORDER BY amount DESC',
                [$branchId, $from, $to],
            );
        } catch (PDOException) {
            return $empty;
        }
        $byShift = [];
        foreach ($goods as $g) {
            $sid = (int) $g['sid'];
            if (!isset($byShift[$sid])) {
                $byShift[$sid] = [];
            }
            if (count($byShift[$sid]) < 4) {
                $byShift[$sid][] = ['name' => (string) $g['name'], 'qty' => (int) $g['qty'], 'amount' => (int) $g['amount']];
            }
        }
        $shifts = [];
        $cashTotal = $cashlessTotal = $salesCount = 0;
        foreach ($agg as $r) {
            $cash = (int) $r['cash'];
            $cashless = (int) $r['cashless'];
            $cashTotal += $cash;
            $cashlessTotal += $cashless;
            $salesCount += (int) $r['lines'];
            $eid = $r['eid'] !== null ? (int) $r['eid'] : null;
            $shifts[] = [
                'shiftExtId' => (int) $r['sid'],
                'staffName' => (string) $r['staffName'],
                'employeeId' => $eid,
                'mine' => $highlightEmployeeId !== null && $eid === $highlightEmployeeId,
                'cash' => $cash,
                'cashless' => $cashless,
                'lines' => (int) $r['lines'],
                'startedAt' => $r['startedAt'] !== null && $r['startedAt'] !== '' ? (string) $r['startedAt'] : null,
                'goods' => $byShift[(int) $r['sid']] ?? [],
            ];
        }
        usort($shifts, static function ($a, $b) {
            if ($a['mine'] !== $b['mine']) {
                return $a['mine'] ? -1 : 1;
            }
            return $b['cash'] <=> $a['cash'];
        });
        $reports = [];
        try {
            foreach (Db::all(
                'SELECT "shiftType", "userName", "cashExpected", "cashActual" FROM shift_reports
                 WHERE "branchId" = ? AND date = ? AND phase = \'close\' ORDER BY "shiftType"',
                [$branchId, $day],
            ) as $rep) {
                $reports[] = [
                    'shiftType' => (string) $rep['shiftType'],
                    'userName' => (string) $rep['userName'],
                    'cashExpected' => $rep['cashExpected'] !== null ? (int) $rep['cashExpected'] : null,
                    'cashActual' => $rep['cashActual'] !== null ? (int) $rep['cashActual'] : null,
                ];
            }
        } catch (PDOException) {
        }
        return [
            'cashTotal' => $cashTotal,
            'cashlessTotal' => $cashlessTotal,
            'salesCount' => $salesCount,
            'shifts' => $shifts,
            'reports' => $reports,
        ];
    }

    /** Проверка подключения: возвращает число администраторов в Langame. */
    public static function test(string $base, string $token): int
    {
        $r = self::request($base, $token, '/users/list', ['page' => 1, 'page_limit' => 1]);
        return isset($r['data']) && is_array($r['data']) ? count($r['data']) : 0;
    }

    /* ---------- отложенная работа (не блокирует HTML) ---------- */

    /** Филиалы, которые нужно обновить после отдачи страницы (для JS beacon). @return int[] */
    public static function pendingBranchIds(): array
    {
        return array_values(array_unique(array_map('intval', self::$pendingBranches)));
    }

    private static function enqueue(int $branchId): void
    {
        if ($branchId > 0) {
            self::$pendingBranches[] = $branchId;
        }
    }

    /** Поставить филиал в очередь фонового refresh (для страниц сверки). */
    public static function requestRefresh(int $branchId): void
    {
        self::enqueue($branchId);
    }

    /**
     * Фоновый endpoint / cron: sync продаж + live-снимок.
     * Повторный вызов в окне AUTO_SYNC_SECONDS пропускает sync (анти-шторм), live обновляет по своему TTL.
     * @param int[] $branchIds
     * @return array{ok: bool, synced: int[], live: int[], errors: string[]}
     */
    public static function refreshNow(array $branchIds, bool $forceSync = false): array
    {
        $out = ['ok' => true, 'synced' => [], 'live' => [], 'busy' => [], 'errors' => []];
        @set_time_limit(120);
        @ignore_user_abort(true);
        foreach (array_unique(array_map('intval', $branchIds)) as $bid) {
            if ($bid <= 0) {
                continue;
            }
            $s = self::settings($bid);
            if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
                continue;
            }
            // Несколько вкладок и cron просыпаются одновременно: один процесс синхронизирует филиал,
            // остальные сразу отвечают тем, что уже лежит в базе, а не ставят второй такой же запрос в API.
            $lock = self::branchLock($bid);
            if ($lock === null) {
                $out['busy'][] = $bid;
                continue;
            }
            $s = self::settings($bid) ?? $s;
            $last = $s['lastSyncAt'] ? strtotime((string) $s['lastSyncAt']) : 0;
            $needSync = $forceSync || !$last || time() - $last >= self::AUTO_SYNC_SECONDS;
            if ($needSync) {
                try {
                    self::sync($bid);
                    $out['synced'][] = $bid;
                } catch (Throwable $e) {
                    $out['ok'] = false;
                    $out['errors'][] = 'sync#' . $bid . ': ' . $e->getMessage();
                }
            }
            $age = time() - (int) ($s['liveAt'] ?? 0);
            if ($forceSync || $age >= self::LIVE_FRESH_SECONDS || empty($s['liveJson'])) {
                try {
                    self::refreshLive($bid);
                    $out['live'][] = $bid;
                } catch (Throwable $e) {
                    $out['ok'] = false;
                    $out['errors'][] = 'live#' . $bid . ': ' . $e->getMessage();
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }

    /**
     * Неблокирующая блокировка синхронизации филиала через файл в storage.
     * @return resource|null null — филиал уже синхронизирует другой процесс
     */
    private static function branchLock(int $branchId)
    {
        $dir = dirname(__DIR__) . '/storage';
        $fh = @fopen($dir . '/langame-sync-' . $branchId . '.lock', 'c');
        if ($fh === false) {
            // Нет прав на запись — работаем как раньше, без защиты от параллельных запросов.
            return fopen('php://memory', 'r');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /* ---------- синхронизация ---------- */

    /**
     * Планирует синхронизацию продаж, если данные старше AUTO_SYNC_SECONDS.
     * Не ждёт API — страница сразу рисуется из уже сохранённых продаж.
     */
    public static function maybeSync(int $branchId): void
    {
        $s = self::settings($branchId);
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            return;
        }
        $last = $s['lastSyncAt'] ? strtotime((string) $s['lastSyncAt']) : 0;
        if ($last && time() - $last < self::AUTO_SYNC_SECONDS) {
            return;
        }
        self::enqueue($branchId);
    }

    /** @return array{sales: int, shifts: int, users: int} */
    public static function sync(int $branchId): array
    {
        $s = self::settings($branchId);
        if (!$s) {
            throw new LangameError('Подключение не настроено');
        }
        try {
            $users = self::syncUsers($s);
            self::syncProducts($s);
            [$sales, $shiftIds, $from] = self::syncSales($s);
            BarRules::apply(Db::pdo(), (int) $s['branchId']);
            $shifts = self::syncShifts($s, $shiftIds, $from);
            Db::exec('UPDATE langame_settings SET "lastSyncAt" = ?, "lastSyncOk" = 1, "lastError" = NULL, "syncedFrom" = COALESCE("syncedFrom", ?) WHERE id = ?', [now_iso(), $from, $s['id']]);
            return ['sales' => $sales, 'shifts' => $shifts, 'users' => $users];
        } catch (Throwable $e) {
            $msg = $e instanceof LangameError ? $e->getMessage() : 'Внутренняя ошибка синхронизации';
            if (!$e instanceof LangameError) {
                error_log('[gamerpro] langame sync: ' . $e->getMessage());
            }
            Db::exec('UPDATE langame_settings SET "lastSyncAt" = ?, "lastSyncOk" = 0, "lastError" = ? WHERE id = ?', [now_iso(), mb_substr($msg, 0, 500), $s['id']]);
            throw new LangameError($msg);
        }
    }

    private static function syncUsers(array $s): int
    {
        $bid = (int) $s['branchId'];
        $count = 0;
        for ($p = 1, $total = 1; $p <= $total && $p <= 10; $p++) {
            [$rows, $total] = self::page($s, '/users/list', ['page' => $p, 'page_limit' => 200]);
            foreach ($rows as $u) {
                $uid = (int) ($u['id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $count++;
                $name = mb_substr(trim((string) ($u['username'] ?? '')), 0, 191);
                $login = mb_substr(trim((string) ($u['email'] ?? '')), 0, 191);
                $status = mb_substr(trim((string) ($u['admin_status'] ?? '')), 0, 64);
                $verified = array_key_exists('verified', $u) && $u['verified'] !== null ? (int) $u['verified'] : 1;
                $active = $verified !== 0 && !preg_match('/увол|удал|архив|заблок|fired|deleted/iu', $status) ? 1 : 0;
                $lastLogin = (int) ($u['last_login'] ?? 0) ?: null;
                try {
                    Db::upsert(
                        'langame_staff',
                        ['branchId' => $bid, 'langameUserId' => $uid, 'langameName' => $name, 'langameLogin' => $login, 'isActive' => $active, 'adminStatus' => $status, 'lastLoginAt' => $lastLogin, 'updatedAt' => now_iso()],
                        ['branchId', 'langameUserId'],
                        ['langameName', 'langameLogin', 'isActive', 'adminStatus', 'lastLoginAt', 'updatedAt'],
                    );
                } catch (PDOException) {
                    Db::upsert(
                        'langame_staff',
                        ['branchId' => $bid, 'langameUserId' => $uid, 'langameName' => $name, 'langameLogin' => $login, 'updatedAt' => now_iso()],
                        ['branchId', 'langameUserId'],
                        ['langameName', 'langameLogin', 'updatedAt'],
                    );
                }
            }
        }
        return $count;
    }

    private static function syncProducts(array $s): void
    {
        $bid = (int) $s['branchId'];
        $known = [];
        foreach (Db::all('SELECT "extId", name FROM langame_products WHERE "branchId" = ?', [$bid]) as $r) {
            $known[(int) $r['extId']] = (string) $r['name'];
        }
        for ($p = 1, $total = 1; $p <= $total && $p <= 20; $p++) {
            [$rows, $total] = self::page($s, '/products/list', ['page' => $p, 'page_limit' => 500]);
            foreach ($rows as $g) {
                $id = (int) ($g['id'] ?? 0);
                $name = mb_substr(trim((string) ($g['name'] ?? '')), 0, 191);
                if ($id <= 0 || $name === '') {
                    continue;
                }
                if (!isset($known[$id])) {
                    Db::insertIgnore('langame_products', ['branchId' => $bid, 'extId' => $id, 'name' => $name]);
                    $known[$id] = $name;
                } elseif ($known[$id] !== $name) {
                    Db::exec('UPDATE langame_products SET name = ? WHERE "branchId" = ? AND "extId" = ?', [$name, $bid, $id]);
                    $known[$id] = $name;
                }
            }
        }
    }

    /** @return array{0: int, 1: int[], 2: string} число строк, id смен, дата начала окна */
    private static function syncSales(array $s): array
    {
        $bid = (int) $s['branchId'];
        $now = now_tyumen();
        $lastSale = Db::value('SELECT MAX("saleAt") FROM bar_sales WHERE "branchId" = ?', [$bid]);
        $from = $lastSale
            ? (new DateTimeImmutable(substr((string) $lastSale, 0, 10), new DateTimeZone(APP_TZ)))->modify('-2 days')->format('Y-m-d')
            : $now->modify('first day of last month')->format('Y-m-d');
        $to = $now->modify('+1 day')->format('Y-m-d');
        $shiftIds = [];
        $n = 0;
        for ($p = 1, $total = 1; $p <= $total && $p <= self::MAX_PAGES; $p++) {
            [$rows, $total] = self::page($s, '/products/expense', ['date_from' => $from, 'date_to' => $to, 'page' => $p, 'page_limit' => self::SALES_PAGE]);
            foreach ($rows as $r) {
                $ext = (int) ($r['id'] ?? 0);
                if ($ext <= 0) {
                    continue;
                }
                $qty = max(1, (int) ($r['count'] ?? 1));
                $unit = (int) round(((float) ($r['price_sale'] ?? 0)) * 100);
                $shift = (int) ($r['working_shift_id'] ?? 0);
                Db::upsert(
                    'bar_sales',
                    [
                        'branchId' => $bid,
                        'extId' => $ext,
                        'saleAt' => mb_substr((string) ($r['date'] ?? ''), 0, 32),
                        'goodsId' => (int) ($r['list_goods_id'] ?? 0),
                        'shiftExtId' => $shift,
                        'unitPriceX100' => $unit,
                        'qty' => $qty,
                        'amountX100' => $unit * $qty,
                        'isCancelled' => (int) ($r['cancel'] ?? 0) ? 1 : 0,
                        'isCashless' => (int) ($r['beznal'] ?? 0) ? 1 : 0,
                    ],
                    ['branchId', 'extId'],
                    ['saleAt', 'goodsId', 'shiftExtId', 'unitPriceX100', 'qty', 'amountX100', 'isCancelled', 'isCashless'],
                );
                if ($shift > 0) {
                    $shiftIds[$shift] = true;
                }
                $n++;
            }
        }
        return [$n, array_keys($shiftIds), $from];
    }

    /** Подтягивает смены, чтобы понять, кто из администраторов продал позицию. */
    private static function syncShifts(array $s, array $needIds, string $from): int
    {
        $bid = (int) $s['branchId'];
        // Закрытые смены тоже обновляем: нужны касса и комментарий для сверки с CRM.
        $need = array_fill_keys($needIds, true);
        $cutoff = (new DateTimeImmutable($from, new DateTimeZone(APP_TZ)))->modify('-2 days')->format('Y-m-d');
        [$first, $total] = self::page($s, '/working_shifts/list', ['page' => 1, 'page_limit' => 200]);
        $asc = count($first) > 1 && strcmp((string) ($first[0]['date_start'] ?? ''), (string) ($first[count($first) - 1]['date_start'] ?? '')) < 0;
        $order = $asc ? range($total, 1) : range(1, $total);
        $saved = 0;
        foreach (array_slice($order, 0, 25) as $i => $p) {
            $rows = $p === 1 ? $first : self::page($s, '/working_shifts/list', ['page' => $p, 'page_limit' => 200])[0];
            $oldest = null;
            foreach ($rows as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $ext = (int) ($w['id'] ?? 0);
                if ($ext <= 0) {
                    continue;
                }
                $start = mb_substr((string) ($w['date_start'] ?? ''), 0, 32);
                $stop = isset($w['date_stop']) && $w['date_stop'] !== null && $w['date_stop'] !== '' ? mb_substr((string) $w['date_stop'], 0, 32) : null;
                $uid = (int) ($w['user_id'] ?? 0);
                $oldest = $oldest === null || strcmp($start, $oldest) < 0 ? $start : $oldest;
                $row = [
                    'branchId' => $bid,
                    'extId' => $ext,
                    'langameUserId' => $uid,
                    'dateStart' => $start,
                    'dateStop' => $stop,
                    'cashStart' => self::shiftCashRub($w['start'] ?? null),
                    'cashNal' => self::shiftCashRub($w['nal'] ?? null),
                    'cashBeznal' => self::shiftCashRub($w['beznal'] ?? null),
                    'cashIncass' => self::shiftCashRub($w['incass'] ?? null),
                    'closeMessage' => mb_substr(trim((string) ($w['message'] ?? '')), 0, 1000) ?: null,
                ];
                try {
                    Db::upsert(
                        'langame_shifts',
                        $row,
                        ['branchId', 'extId'],
                        ['langameUserId', 'dateStart', 'dateStop', 'cashStart', 'cashNal', 'cashBeznal', 'cashIncass', 'closeMessage'],
                    );
                } catch (PDOException) {
                    Db::upsert(
                        'langame_shifts',
                        ['branchId' => $bid, 'extId' => $ext, 'langameUserId' => $uid, 'dateStart' => $start, 'dateStop' => $stop],
                        ['branchId', 'extId'],
                        ['langameUserId', 'dateStart', 'dateStop'],
                    );
                }
                unset($need[$ext]);
                $saved++;
            }
            if (!$need && ($oldest === null || strcmp(substr($oldest, 0, 10), $cutoff) < 0)) {
                break;
            }
        }
        return $saved;
    }

    private static function shiftCashRub(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (int) round((float) $v);
    }

    /* ---------- отчёты ---------- */

    /**
     * Продажи бара по сотрудникам портала за период [from, to] (даты Y-m-d включительно).
     * @return array<int, array{revenue: int, qty: int, lines: int, percentX10: int, earn: int}> ключ — employeeId; суммы в копейках
     */
    public static function totalsByEmployee(string $from, string $to, ?int $branchId = null): array
    {
        $params = [$from, $to . ' 23:59:59'];
        $where = '';
        if ($branchId !== null) {
            $where = ' AND s."branchId" = ?';
            $params[] = $branchId;
        }
        try {
            $rows = Db::all(
                'SELECT l."employeeId" AS eid, l."percentX10" AS pct, st."barPercentX10" AS def,
                        SUM(s."amountX100") AS revenue, SUM(s.qty) AS qty, COUNT(*) AS cnt
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 JOIN langame_settings st ON st."branchId" = s."branchId"
                 WHERE s."isCancelled" = 0 AND s."isExcluded" = 0 AND l."employeeId" IS NOT NULL AND s."saleAt" >= ? AND s."saleAt" <= ?' . $where . '
                 GROUP BY l."employeeId", l."percentX10", st."barPercentX10"',
                $params,
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $eid = (int) $r['eid'];
            $pct = $r['pct'] !== null ? (int) $r['pct'] : (int) $r['def'];
            $rev = (int) $r['revenue'];
            $cur = $out[$eid] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'percentX10' => $pct, 'earn' => 0];
            $cur['revenue'] += $rev;
            $cur['qty'] += (int) $r['qty'];
            $cur['lines'] += (int) $r['cnt'];
            $cur['earn'] += (int) round($rev * $pct / 1000);
            $cur['percentX10'] = $pct;
            $out[$eid] = $cur;
        }
        return $out;
    }

    /**
     * Общий рейтинг администраторов по всем филиалам: выручка, смены, филиалы, лучшая смена.
     * @return list<array{eid:int,revenue:int,qty:int,lines:int,shifts:int,branches:int,best:int}>
     */
    public static function crossBranchLeaders(string $from, string $to): array
    {
        try {
            $rows = Db::all(
                'SELECT l."employeeId" AS eid, s."branchId" AS bid, s."shiftExtId" AS sid,
                        SUM(s."amountX100") AS revenue, SUM(s.qty) AS qty, COUNT(*) AS cnt
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE s."isCancelled" = 0 AND s."isExcluded" = 0 AND l."employeeId" IS NOT NULL AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY l."employeeId", s."branchId", s."shiftExtId"',
                [$from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $eid = (int) $r['eid'];
            $rev = (int) $r['revenue'];
            $cur = $out[$eid] ?? ['eid' => $eid, 'revenue' => 0, 'qty' => 0, 'lines' => 0, 'shifts' => 0, 'branchSet' => [], 'best' => 0];
            $cur['revenue'] += $rev;
            $cur['qty'] += (int) $r['qty'];
            $cur['lines'] += (int) $r['cnt'];
            $cur['shifts']++;
            $cur['branchSet'][(int) $r['bid']] = true;
            $cur['best'] = max($cur['best'], $rev);
            $out[$eid] = $cur;
        }
        foreach ($out as &$o) {
            $o['branches'] = count($o['branchSet']);
            unset($o['branchSet']);
        }
        unset($o);
        $list = array_values($out);
        usort($list, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        return $list;
    }

    /** Последние продажи сотрудника. */
    public static function recentSales(int $employeeId, string $from, string $to, int $limit = 20, ?int $branchId = null): array
    {
        try {
            return Db::all(
                'SELECT s."saleAt", s.qty, s."unitPriceX100", s."amountX100", s."isCancelled", s."isCashless", s."branchId", p.name AS "goodsName"
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE l."employeeId" = ? AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?' . ($branchId !== null ? ' AND s."branchId" = ?' : '') . '
                 ORDER BY s."saleAt" DESC, s.id DESC LIMIT ' . max(1, min(100, $limit)),
                array_merge([$employeeId, $from, $to . ' 23:59:59'], $branchId !== null ? [$branchId] : []),
            );
        } catch (PDOException) {
            return [];
        }
    }

    /** Топ позиций сотрудника за период. */
    public static function topGoods(int $employeeId, string $from, string $to, int $limit = 5, ?int $branchId = null): array
    {
        try {
            return Db::all(
                'SELECT COALESCE(p.name, \'Товар\') AS name, SUM(s.qty) AS qty, SUM(s."amountX100") AS amount
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?' . ($branchId !== null ? ' AND s."branchId" = ?' : '') . '
                 GROUP BY p.name ORDER BY amount DESC LIMIT ' . max(1, min(20, $limit)),
                array_merge([$employeeId, $from, $to . ' 23:59:59'], $branchId !== null ? [$branchId] : []),
            );
        } catch (PDOException) {
            return [];
        }
    }

    /* ---------- сводка для владельца ---------- */

    /**
     * Продажи филиала по сотрудникам, включая отмены. Ключ 0 — продажи без привязки.
     * @return array<int, array{revenue: int, qty: int, lines: int, cancelAmount: int, cancelCount: int, cashless: int, percentX10: int, earn: int}>
     */
    public static function teamStats(int $branchId, string $from, string $to): array
    {
        try {
            $rows = Db::all(
                'SELECT l."employeeId" AS eid, l."percentX10" AS pct, st."barPercentX10" AS def,
                        SUM(CASE WHEN s."isCancelled" = 0 THEN s."amountX100" ELSE 0 END) AS revenue,
                        SUM(CASE WHEN s."isCancelled" = 0 THEN s.qty ELSE 0 END) AS qty,
                        SUM(CASE WHEN s."isCancelled" = 0 THEN 1 ELSE 0 END) AS cnt,
                        SUM(CASE WHEN s."isCancelled" = 1 THEN s."amountX100" ELSE 0 END) AS "cancelAmount",
                        SUM(CASE WHEN s."isCancelled" = 1 THEN 1 ELSE 0 END) AS "cancelCount",
                        SUM(CASE WHEN s."isCancelled" = 0 AND s."isCashless" = 1 THEN s."amountX100" ELSE 0 END) AS cashless
                 FROM bar_sales s
                 JOIN langame_settings st ON st."branchId" = s."branchId"
                 LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE s."branchId" = ? AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY l."employeeId", l."percentX10", st."barPercentX10"',
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $eid = $r['eid'] !== null ? (int) $r['eid'] : 0;
            $pct = $r['pct'] !== null ? (int) $r['pct'] : (int) $r['def'];
            $rev = (int) $r['revenue'];
            $cur = $out[$eid] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'cancelAmount' => 0, 'cancelCount' => 0, 'cashless' => 0, 'percentX10' => $pct, 'earn' => 0];
            $cur['revenue'] += $rev;
            $cur['qty'] += (int) $r['qty'];
            $cur['lines'] += (int) $r['cnt'];
            $cur['cancelAmount'] += (int) $r['cancelAmount'];
            $cur['cancelCount'] += (int) $r['cancelCount'];
            $cur['cashless'] += (int) $r['cashless'];
            $cur['earn'] += $eid > 0 ? (int) round($rev * $pct / 1000) : 0;
            $cur['percentX10'] = $pct;
            $out[$eid] = $cur;
        }
        return $out;
    }

    /** Число смен Langame за период по сотрудникам портала. @return array<int, int> */
    public static function shiftCounts(int $branchId, string $from, string $to): array
    {
        try {
            $rows = Db::all(
                'SELECT l."employeeId" AS eid, COUNT(*) AS n FROM langame_shifts w
                 JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE w."branchId" = ? AND l."employeeId" IS NOT NULL AND w."dateStart" >= ? AND w."dateStart" <= ?
                 GROUP BY l."employeeId"',
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['eid']] = (int) $r['n'];
        }
        return $out;
    }

    /** Продажи филиала с фильтром: отменённые или крупные (от $minKop). */
    public static function branchSales(int $branchId, string $from, string $to, string $kind, int $minKop = 0, int $limit = 8): array
    {
        $cond = $kind === 'cancelled' ? 's."isCancelled" = 1' : 's."isCancelled" = 0 AND s."amountX100" >= ' . max(0, $minKop);
        $order = $kind === 'cancelled' ? 's."saleAt" DESC' : 's."amountX100" DESC, s."saleAt" DESC';
        try {
            return Db::all(
                'SELECT s."saleAt", s.qty, s."amountX100", s."isCashless", p.name AS "goodsName", l."employeeId" AS eid, l."langameName" AS "staffName"
                 FROM bar_sales s
                 LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE s."branchId" = ? AND s."isExcluded" = 0 AND ' . $cond . ' AND s."saleAt" >= ? AND s."saleAt" <= ?
                 ORDER BY ' . $order . ' LIMIT ' . max(1, min(50, $limit)),
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
    }

    public static function salesLedger(int $branchId, string $from, string $to, string $status, string $search, int $page): array
    {
        $join = ' FROM bar_sales s
            LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
            LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
            LEFT JOIN employees e ON e.id = l."employeeId"
            LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"';
        $where = ' WHERE s."branchId" = ? AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?';
        $params = [$branchId, $from, $to . ' 23:59:59'];
        if (in_array($status, ['completed', 'cancelled'], true)) {
            $where .= ' AND s."isCancelled" = ?';
            $params[] = $status === 'cancelled' ? 1 : 0;
        }
        if ($search !== '') {
            if (Db::driver() === 'sqlite') {
                Db::pdo()->sqliteCreateFunction('LOWER', static fn ($value) => mb_strtolower((string) $value, 'UTF-8'), 1, PDO::SQLITE_DETERMINISTIC);
            }
            $where .= " AND (LOWER(COALESCE(p.name, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(e.name, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(l.\"langameName\", '')) LIKE ? ESCAPE '!')";
            $needle = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($params, $needle, $needle, $needle);
        }
        $total = (int) Db::value('SELECT COUNT(*)' . $join . $where, $params);
        $page = max(1, min($page, max(1, (int) ceil($total / 25))));
        $rows = Db::all('SELECT s."saleAt", s.qty, s."amountX100", s."isCashless", s."isCancelled", p.name AS "goodsName", e.name AS "employeeName", l."langameName" AS "staffName"' . $join . $where . ' ORDER BY s."saleAt" DESC, s.id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** Топ позиций всего филиала. */
    public static function branchTopGoods(int $branchId, string $from, string $to, int $limit = 8): array
    {
        try {
            return Db::all(
                'SELECT COALESCE(p.name, \'Товар\') AS name, SUM(s.qty) AS qty, SUM(s."amountX100") AS amount
                 FROM bar_sales s LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE s."branchId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY s."goodsId", p.name ORDER BY amount DESC LIMIT ' . max(1, min(30, $limit)),
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
    }

    /** Выручка бара по часам суток за период. @return array<int, int> час => копейки */
    public static function hourlyRevenue(int $branchId, string $from, string $to): array
    {
        $out = array_fill(0, 24, 0);
        try {
            $rows = Db::all(
                'SELECT SUBSTR(s."saleAt", 12, 2) AS hh, SUM(s."amountX100") AS amount FROM bar_sales s
                 WHERE s."branchId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY SUBSTR(s."saleAt", 12, 2)',
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return $out;
        }
        foreach ($rows as $r) {
            $h = (int) $r['hh'];
            if ($h >= 0 && $h < 24) {
                $out[$h] += (int) $r['amount'];
            }
        }
        return $out;
    }

    /**
     * Касса Langame по сменам клуба за даты [from, to] (по date_start).
     * Нал и безнал Langame пишет при закрытии смены. Пока смена открыта, они часто нули —
     * бар с этой смены лежит отдельно в barCashKop и в итог наличных не входит.
     * @return array{shifts: list<array<string,mixed>>, people: list<array<string,mixed>>}
     */
    public static function registerReport(int $branchId, string $from, string $to): array
    {
        $empty = ['shifts' => [], 'people' => []];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return $empty;
        }
        try {
            $rows = Db::all(
                'SELECT w."extId", w."langameUserId", w."dateStart", w."dateStop",
                        w."cashStart", w."cashNal", w."cashBeznal", w."cashIncass",
                        l."employeeId" AS eid, COALESCE(e.name, l."langameName") AS staffName
                 FROM langame_shifts w
                 LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN employees e ON e.id = l."employeeId"
                 WHERE w."branchId" = ? AND SUBSTR(w."dateStart", 1, 10) >= ? AND SUBSTR(w."dateStart", 1, 10) <= ?
                 ORDER BY w."dateStart"',
                [$branchId, $from, $to],
            );
            $bars = Db::all(
                'SELECT "shiftExtId" AS sid,
                        SUM(CASE WHEN "isCancelled" = 0 AND "isCashless" = 0 THEN "amountX100" ELSE 0 END) AS cash,
                        SUM(CASE WHEN "isCancelled" = 0 AND "isCashless" = 1 THEN "amountX100" ELSE 0 END) AS card
                 FROM bar_sales
                 WHERE "branchId" = ? AND "isExcluded" = 0 AND "saleAt" >= ? AND "saleAt" <= ?
                 GROUP BY "shiftExtId"',
                [$branchId, $from . ' 00:00:00', $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return $empty;
        }
        $barBy = [];
        foreach ($bars as $b) {
            $barBy[(int) $b['sid']] = ['cash' => (int) $b['cash'], 'card' => (int) $b['card']];
        }
        $shifts = [];
        $people = [];
        foreach ($rows as $r) {
            $stop = $r['dateStop'] ?? null;
            $open = $stop === null || $stop === '';
            $start = $r['cashStart'] !== null && $r['cashStart'] !== '' ? (int) $r['cashStart'] : null;
            $nal = $r['cashNal'] !== null && $r['cashNal'] !== '' ? (int) $r['cashNal'] : 0;
            $bez = $r['cashBeznal'] !== null && $r['cashBeznal'] !== '' ? (int) $r['cashBeznal'] : 0;
            $inc = $r['cashIncass'] !== null && $r['cashIncass'] !== '' ? (int) $r['cashIncass'] : 0;
            $ext = (int) $r['extId'];
            $bar = $barBy[$ext] ?? ['cash' => 0, 'card' => 0];
            $eid = $r['eid'] !== null && $r['eid'] !== '' ? (int) $r['eid'] : null;
            $name = trim((string) ($r['staffName'] ?? ''));
            if ($name === '') {
                $name = 'Langame #' . (int) $r['langameUserId'];
            }
            $key = $eid !== null ? 'e' . $eid : 'u' . (int) $r['langameUserId'];
            $shifts[] = [
                'extId' => $ext,
                'employeeId' => $eid,
                'staffName' => $name,
                'dateStart' => (string) $r['dateStart'],
                'dateStop' => $open ? null : (string) $stop,
                'open' => $open,
                'day' => substr((string) $r['dateStart'], 0, 10),
                'cashStart' => $start,
                'cashNal' => $nal,
                'cashBeznal' => $bez,
                'cashIncass' => $inc,
                'endDrawer' => !$open && $start !== null ? $start + $nal - $inc : null,
                'barCashKop' => $bar['cash'],
                'barCardKop' => $bar['card'],
            ];
            if (!isset($people[$key])) {
                $people[$key] = [
                    'key' => $key,
                    'name' => $name,
                    'employeeId' => $eid,
                    'shifts' => 0,
                    'nal' => 0,
                    'bez' => 0,
                    'open' => 0,
                ];
            }
            $people[$key]['shifts']++;
            $people[$key]['nal'] += $nal;
            $people[$key]['bez'] += $bez;
            if ($open) {
                $people[$key]['open']++;
            }
        }
        $people = array_values($people);
        usort($people, static function (array $a, array $b): int {
            return $b['nal'] <=> $a['nal'] ?: $b['bez'] <=> $a['bez'] ?: strcmp($a['name'], $b['name']);
        });
        return ['shifts' => $shifts, 'people' => $people];
    }

    /**
     * Суммы нал/безнал Langame за календарный день по клубам.
     * @param int[] $branchIds
     * @return array<int, array{nal: int, bez: int, open: int}>
     */
    public static function todayRegisterTotals(array $branchIds, string $day): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            $out[(int) $id] = ['nal' => 0, 'bez' => 0, 'open' => 0];
        }
        if (!$out || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return $out;
        }
        try {
            $rows = Db::all(
                'SELECT "branchId" AS bid,
                        SUM(COALESCE("cashNal", 0)) AS nal,
                        SUM(COALESCE("cashBeznal", 0)) AS bez,
                        SUM(CASE WHEN "dateStop" IS NULL OR "dateStop" = \'\' THEN 1 ELSE 0 END) AS openCount
                 FROM langame_shifts
                 WHERE "branchId" IN (' . implode(',', array_map('intval', array_keys($out))) . ')
                   AND SUBSTR("dateStart", 1, 10) = ?
                 GROUP BY "branchId"',
                [$day],
            );
        } catch (PDOException) {
            return $out;
        }
        foreach ($rows as $r) {
            $id = (int) $r['bid'];
            if (!isset($out[$id])) {
                continue;
            }
            $out[$id] = ['nal' => (int) $r['nal'], 'bez' => (int) $r['bez'], 'open' => (int) $r['openCount']];
        }
        return $out;
    }

    /**
     * Текущая касса одного клуба: последняя смена Langame за 36 часов.
     * Остаток закрытой смены = на открытии + нал − инкассация. У открытой смены итог ещё не записан.
     * @return array{found: bool, open: bool, staffName: string, startedAt: ?string, stoppedAt: ?string, cashStart: ?int, cashNal: int, cashBeznal: int, cashIncass: int, endDrawer: ?int, expectedOpen: ?int, expectedClose: ?int, barCashKop: int, barCardKop: int}
     */
    public static function drawerSnapshot(int $branchId): array
    {
        $blank = [
            'found' => false,
            'open' => false,
            'staffName' => '',
            'startedAt' => null,
            'stoppedAt' => null,
            'cashStart' => null,
            'cashNal' => 0,
            'cashBeznal' => 0,
            'cashIncass' => 0,
            'endDrawer' => null,
            'expectedOpen' => null,
            'expectedClose' => null,
            'barCashKop' => 0,
            'barCardKop' => 0,
        ];
        $since = now_tyumen()->modify('-36 hours')->format('Y-m-d H:i:s');
        try {
            $row = Db::one(
                'SELECT w."extId" AS extId, w."dateStart", w."dateStop", w."cashStart", w."cashNal", w."cashBeznal", w."cashIncass",
                        COALESCE(e.name, l."langameName") AS staffName
                 FROM langame_shifts w
                 LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN employees e ON e.id = l."employeeId"
                 WHERE w."branchId" = ? AND w."dateStart" >= ?
                 ORDER BY w."dateStart" DESC LIMIT 1',
                [$branchId, $since],
            );
        } catch (PDOException) {
            return $blank;
        }
        if (!$row) {
            return $blank;
        }
        $stop = $row['dateStop'] ?? null;
        $open = $stop === null || $stop === '';
        $start = $row['cashStart'] !== null && $row['cashStart'] !== '' ? (int) $row['cashStart'] : null;
        $nal = $row['cashNal'] !== null && $row['cashNal'] !== '' ? (int) $row['cashNal'] : 0;
        $bez = $row['cashBeznal'] !== null && $row['cashBeznal'] !== '' ? (int) $row['cashBeznal'] : 0;
        $inc = $row['cashIncass'] !== null && $row['cashIncass'] !== '' ? (int) $row['cashIncass'] : 0;
        $end = !$open && $start !== null ? $start + $nal - $inc : null;
        $barCash = $barCard = 0;
        try {
            $bar = Db::one(
                'SELECT SUM(CASE WHEN "isCancelled" = 0 AND "isCashless" = 0 THEN "amountX100" ELSE 0 END) AS cash,
                        SUM(CASE WHEN "isCancelled" = 0 AND "isCashless" = 1 THEN "amountX100" ELSE 0 END) AS card
                 FROM bar_sales
                 WHERE "branchId" = ? AND "shiftExtId" = ? AND "isExcluded" = 0',
                [$branchId, (int) $row['extId']],
            );
            if ($bar) {
                $barCash = (int) ($bar['cash'] ?? 0);
                $barCard = (int) ($bar['card'] ?? 0);
            }
        } catch (PDOException) {
        }
        return [
            'found' => true,
            'open' => $open,
            'staffName' => trim((string) ($row['staffName'] ?? '')),
            'startedAt' => (string) $row['dateStart'],
            'stoppedAt' => $open ? null : (string) $stop,
            'cashStart' => $start,
            'cashNal' => $nal,
            'cashBeznal' => $bez,
            'cashIncass' => $inc,
            'endDrawer' => $end,
            'expectedOpen' => $open ? $start : $end,
            'expectedClose' => $end,
            'barCashKop' => $barCash,
            'barCardKop' => $barCard,
        ];
    }

    /** Открытая сейчас смена Langame: кто на кассе и с какого времени. */
    public static function openShift(int $branchId): ?array
    {
        try {
            $row = Db::one(
                'SELECT w."dateStart", w."langameUserId", l."employeeId" AS eid, l."langameName" AS "staffName"
                 FROM langame_shifts w
                 LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE w."branchId" = ? AND w."dateStop" IS NULL AND w."dateStart" >= ?
                 ORDER BY w."dateStart" DESC LIMIT 1',
                [$branchId, now_tyumen()->modify('-2 days')->format('Y-m-d')],
            );
        } catch (PDOException) {
            return null;
        }
        return $row ?: null;
    }

    /**
     * Рабочие смены Langame за календарный день (по date_start).
     * @return list<array{extId: int, langameUserId: int, employeeId: ?int, staffName: string, dateStart: string, dateStop: ?string, open: bool, cashStart: ?int, cashNal: ?int, cashBeznal: ?int, cashIncass: ?int, closeMessage: ?string}>
     */
    public static function workingShiftsForDay(int $branchId, string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return [];
        }
        try {
            $rows = Db::all(
                'SELECT w."extId", w."langameUserId", w."dateStart", w."dateStop",
                        w."cashStart", w."cashNal", w."cashBeznal", w."cashIncass", w."closeMessage",
                        l."employeeId" AS eid, COALESCE(e.name, l."langameName") AS staffName
                 FROM langame_shifts w
                 LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN employees e ON e.id = l."employeeId"
                 WHERE w."branchId" = ? AND SUBSTR(w."dateStart", 1, 10) = ?
                 ORDER BY w."dateStart"',
                [$branchId, $day],
            );
        } catch (PDOException) {
            // Пока колонки кассы не смигрировали — читаем без них.
            try {
                $rows = Db::all(
                    'SELECT w."extId", w."langameUserId", w."dateStart", w."dateStop",
                            l."employeeId" AS eid, COALESCE(e.name, l."langameName") AS staffName
                     FROM langame_shifts w
                     LEFT JOIN langame_staff l ON l."branchId" = w."branchId" AND l."langameUserId" = w."langameUserId"
                     LEFT JOIN employees e ON e.id = l."employeeId"
                     WHERE w."branchId" = ? AND SUBSTR(w."dateStart", 1, 10) = ?
                     ORDER BY w."dateStart"',
                    [$branchId, $day],
                );
            } catch (PDOException) {
                return [];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $stop = $r['dateStop'] ?? null;
            $name = trim((string) ($r['staffName'] ?? ''));
            if ($name === '') {
                $name = 'LG #' . (int) $r['langameUserId'];
            }
            $out[] = [
                'extId' => (int) $r['extId'],
                'langameUserId' => (int) $r['langameUserId'],
                'employeeId' => $r['eid'] !== null ? (int) $r['eid'] : null,
                'staffName' => $name,
                'dateStart' => (string) $r['dateStart'],
                'dateStop' => $stop !== null && $stop !== '' ? (string) $stop : null,
                'open' => $stop === null || $stop === '',
                'cashStart' => isset($r['cashStart']) && $r['cashStart'] !== null ? (int) $r['cashStart'] : null,
                'cashNal' => isset($r['cashNal']) && $r['cashNal'] !== null ? (int) $r['cashNal'] : null,
                'cashBeznal' => isset($r['cashBeznal']) && $r['cashBeznal'] !== null ? (int) $r['cashBeznal'] : null,
                'cashIncass' => isset($r['cashIncass']) && $r['cashIncass'] !== null ? (int) $r['cashIncass'] : null,
                'closeMessage' => isset($r['closeMessage']) && $r['closeMessage'] !== null && $r['closeMessage'] !== '' ? (string) $r['closeMessage'] : null,
            ];
        }
        return $out;
    }

    /** Дата последней смены каждой учётки Langame. @return array<int, string> langameUserId => dateStart */
    public static function lastShiftByStaff(int $branchId): array
    {
        try {
            $rows = Db::all('SELECT "langameUserId" AS uid, MAX("dateStart") AS last FROM langame_shifts WHERE "branchId" = ? GROUP BY "langameUserId"', [$branchId]);
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['uid']] = (string) $r['last'];
        }
        return $out;
    }

    /** Служебные и удалённые учётки вида langame17 — их не показываем в основном списке. */
    public static function isPlaceholder(array $staff): bool
    {
        $name = trim((string) ($staff['langameName'] ?? ''));
        $login = trim((string) ($staff['langameLogin'] ?? ''));
        return $name === '' || (bool) preg_match('/^langame\d*$/i', $name) || (bool) preg_match('/^langame\d+$/i', $login);
    }

    private static function parseTs(?string $v): ?int
    {
        if ($v === null || trim($v) === '' || str_starts_with($v, '0000')) {
            return null;
        }
        try {
            return (new DateTimeImmutable($v, new DateTimeZone(APP_TZ)))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Живое состояние клуба: занятые ПК, загрузка по часам, остатки.
     * Stale-while-revalidate: свежий кэш отдаём сразу; устаревший — тоже сразу, API в фоне.
     * Без кэша страницу не блокируем — возвращаем null и ставим обновление в очередь.
     * @return array{at: int, active: ?int, sessionsToday: ?int, hourly: int[], lowStock: array, errors: string[], stale?: bool, refreshing?: bool}|null
     */
    public static function live(int $branchId): ?array
    {
        $s = self::settings($branchId);
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            return null;
        }
        $cached = isset($s['liveJson']) && $s['liveJson'] ? json_decode((string) $s['liveJson'], true) : null;
        $age = time() - (int) ($s['liveAt'] ?? 0);
        if (is_array($cached) && $age < self::LIVE_FRESH_SECONDS) {
            return $cached;
        }
        // Устарело или пусто — отдаём кэш (если есть) и просим JS/cron обновить в фоне.
        self::enqueue($branchId);
        if (is_array($cached) && $age < self::LIVE_STALE_SECONDS * 2) {
            $cached['stale'] = true;
            $cached['refreshing'] = true;
            return $cached;
        }
        return is_array($cached) ? $cached + ['stale' => true, 'refreshing' => true] : null;
    }

    /** Принудительно пересобирает live-снимок из API (фон / cron / ручное обновление). */
    public static function refreshLive(int $branchId): ?array
    {
        $s = self::settings($branchId);
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            return null;
        }
        // Помечаем «идёт обновление», чтобы параллельные вкладки не дублировали запрос.
        try {
            Db::exec('UPDATE langame_settings SET "liveAt" = ? WHERE id = ?', [time(), $s['id']]);
        } catch (PDOException) {
        }
        $now = now_tyumen();
        $nowTs = $now->getTimestamp();
        $today = $now->format('Y-m-d');
        $dayStart = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone(APP_TZ)))->getTimestamp();
        $out = ['at' => $nowTs, 'active' => null, 'sessionsToday' => null, 'guestsToday' => null, 'avgMinutes' => null, 'bookedToday' => 0, 'pcsSeen' => 0, 'hourly' => array_fill(0, 24, 0), 'lowStock' => [], 'errors' => []];

        try {
            $activePcs = [];
            $hourPcs = array_fill(0, 24, []);
            $todayCount = 0;
            $guests = [];
            $minutes = [];
            $booked = 0;
            $seenPcs = [];
            $from = $now->modify('-1 day')->format('Y-m-d');
            for ($p = 1, $total = 1; $p <= $total && $p <= 6; $p++) {
                [$rows, $total] = self::page($s, '/guests/sessions', ['date_from' => $from, 'date_to' => $today, 'page' => $p, 'page_limit' => 500]);
                foreach ($rows as $r) {
                    $start = self::parseTs(isset($r['date_start']) ? (string) $r['date_start'] : null);
                    if ($start === null) {
                        continue;
                    }
                    $stop = self::parseTs(isset($r['date_stop']) ? (string) $r['date_stop'] : null);
                    $pc = (string) ($r['UUID'] ?? ($r['id'] ?? ''));
                    $packet = (int) ($r['packet'] ?? 0);
                    if ($packet === -1) {
                        continue;
                    }
                    if ($start <= $nowTs && ($stop === null || $stop > $nowTs)) {
                        $activePcs[$pc] = true;
                    }
                    if ($start >= $dayStart) {
                        $todayCount++;
                        $guests[(string) ($r['guest_id'] ?? 'pc' . $pc)] = true;
                        $minutes[] = max(0, (int) round((min($stop ?? $nowTs, $nowTs) - $start) / 60));
                        if ((int) ($r['create_by_rezerv'] ?? 0) === 1) {
                            $booked++;
                        }
                    }
                    $seenPcs[$pc] = true;
                    $end = min($stop ?? $nowTs, $nowTs);
                    for ($h = 0; $h < 24; $h++) {
                        $hs = $dayStart + $h * 3600;
                        if ($hs > $nowTs) {
                            break;
                        }
                        if ($start < $hs + 3600 && $end > $hs) {
                            $hourPcs[$h][$pc] = true;
                        }
                    }
                }
            }
            $out['sessionsPartial'] = $total > 6;
            $out['active'] = count($activePcs);
            $out['sessionsToday'] = $todayCount;
            $out['hourly'] = array_map('count', $hourPcs);
            $out['guestsToday'] = count($guests);
            $out['avgMinutes'] = $minutes ? (int) round(array_sum($minutes) / count($minutes)) : null;
            $out['bookedToday'] = $booked;
            $out['pcsSeen'] = count($seenPcs);
        } catch (Throwable $e) {
            $out['errors'][] = 'Сессии: ' . ($e instanceof LangameError ? $e->getMessage() : 'нет данных');
        }

        try {
            $clubs = self::page($s, '/clubs/list', [])[0];
            $clubId = 0;
            foreach ($clubs as $c) {
                if ((int) ($c['active'] ?? 1) === 1 && (int) ($c['id'] ?? 0) > 0) {
                    $clubId = (int) $c['id'];
                    break;
                }
            }
            if ($clubId > 0) {
                $sold = [];
                foreach (Db::all('SELECT "goodsId", SUM(qty) AS q FROM bar_sales WHERE "branchId" = ? AND "isCancelled" = 0 AND "isExcluded" = 0 AND "saleAt" >= ? GROUP BY "goodsId"', [$branchId, $now->modify('-30 days')->format('Y-m-d')]) as $r) {
                    $sold[(int) $r['goodsId']] = (int) $r['q'];
                }
                $goods = self::page($s, '/goods/list', ['club_id' => $clubId])[0];
                $low = [];
                foreach ($goods as $g) {
                    $gid = (int) ($g['id'] ?? 0);
                    $cnt = (int) ($g['count'] ?? 0);
                    $perWeek = isset($sold[$gid]) ? $sold[$gid] / 30 * 7 : 0;
                    if ($perWeek >= 1 && $cnt <= max(3, (int) ceil($perWeek / 2))) {
                        $low[] = ['name' => mb_substr((string) ($g['name'] ?? 'Товар'), 0, 80), 'count' => $cnt, 'perWeek' => (int) round($perWeek)];
                    }
                }
                usort($low, static fn ($a, $b) => $a['count'] <=> $b['count'] ?: $b['perWeek'] <=> $a['perWeek']);
                $out['lowStock'] = array_slice($low, 0, 8);
                $out['stockAvailable'] = true;
            }
        } catch (Throwable $e) {
            $out['errors'][] = 'Склад: ' . ($e instanceof LangameError ? $e->getMessage() : 'нет данных');
        }

        try {
            Db::exec('UPDATE langame_settings SET "liveJson" = ?, "liveAt" = ? WHERE id = ?', [json_encode($out, JSON_UNESCAPED_UNICODE), $nowTs, $s['id']]);
        } catch (PDOException) {
        }
        return $out;
    }

    /**
     * Выручка бара по филиалам за период, копейки.
     * @param list<int> $branchIds
     * @return array<int, array{revenue: int, qty: int}>
     */
    public static function barRevenueByBranch(string $from, string $to, array $branchIds): array
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        if (!$branchIds) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($branchIds), '?'));
        try {
            $rows = Db::all(
                'SELECT s."branchId" AS bid, COALESCE(SUM(s."amountX100"), 0) AS revenue, COALESCE(SUM(s.qty), 0) AS qty
                 FROM bar_sales s
                 WHERE s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                   AND s."branchId" IN (' . $marks . ')
                 GROUP BY s."branchId"',
                array_merge([$from, $to . ' 23:59:59'], $branchIds),
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['bid']] = ['revenue' => (int) $r['revenue'], 'qty' => (int) $r['qty']];
        }
        return $out;
    }

    /** Сумма продаж, которые не удалось привязать к сотруднику портала (копейки). */
    public static function unlinkedRevenue(int $branchId, string $from, string $to): int
    {
        try {
            return (int) Db::value(
                'SELECT COALESCE(SUM(s."amountX100"), 0) FROM bar_sales s
                 LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE s."branchId" = ? AND s."isCancelled" = 0 AND s."isExcluded" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ? AND l."employeeId" IS NULL',
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return 0;
        }
    }
}

function kop_rub(int $kop): string
{
    $rub = $kop / 100;
    return abs($rub - round($rub)) < 0.005 ? money_rub((int) round($rub)) : number_format($rub, 2, ',', "\u{202F}") . "\u{00A0}₽";
}

function pct_label(int $x10): string
{
    return rtrim(rtrim(number_format($x10 / 10, 1, ',', ''), '0'), ',') . '%';
}
