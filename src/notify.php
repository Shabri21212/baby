<?php
declare(strict_types=1);

/**
 * Уведомления и персональные настройки сотрудника.
 * Telegram: токен бота в portal_content (telegram_bot) или config.php → telegram.botToken.
 * Chat ID каждый сотрудник указывает у себя в профиле / владелец в доступах.
 */
final class Notify
{
    public const PREF_DEFAULTS = [
        'telegramChatId' => '',
        'notify' => [
            'telegram' => true,
            'issues' => true,
            'reviews' => true,
            'handover' => true,
            'swaps' => true,
            'stock' => false,
        ],
        'features' => [
            'inbox' => true,
            'swaps' => true,
            'reconcileAttention' => true,
            'dailyTasks' => true,
        ],
    ];

    public static function prefs(?array $userRowOrPrefs): array
    {
        $raw = $userRowOrPrefs['prefs'] ?? $userRowOrPrefs;
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            $decoded = is_array($j) ? $j : [];
        } elseif (is_array($raw) && !isset($raw['id'])) {
            $decoded = $raw;
        }
        return self::merge(self::PREF_DEFAULTS, $decoded);
    }

    public static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    public static function wants(array $prefs, string $channel): bool
    {
        return !empty($prefs['notify'][$channel]);
    }

    public static function feature(array $prefs, string $key): bool
    {
        return !empty($prefs['features'][$key]);
    }

    public static function botToken(): ?string
    {
        $cfg = app_config()['telegram']['botToken'] ?? null;
        if (is_string($cfg) && $cfg !== '') {
            return $cfg;
        }
        try {
            $row = Db::one('SELECT "valueJson" FROM portal_content WHERE "key" = ?', ['telegram_bot']);
        } catch (PDOException) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $j = json_decode((string) $row['valueJson'], true);
        $t = is_array($j) ? trim((string) ($j['token'] ?? '')) : '';
        return $t !== '' ? $t : null;
    }

    public static function saveBotToken(string $token): void
    {
        $token = trim($token);
        $json = json_encode(['token' => $token, 'updatedAt' => now_iso()], JSON_UNESCAPED_UNICODE);
        if (Db::one('SELECT "key" FROM portal_content WHERE "key" = ?', ['telegram_bot'])) {
            Db::exec('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ?', [$json, now_iso(), 'telegram_bot']);
        } else {
            Db::exec('INSERT INTO portal_content ("key", "valueJson", "updatedAt") VALUES (?,?,?)', ['telegram_bot', $json, now_iso()]);
        }
    }

    /** @param list<string> $userIds */
    public static function toUsers(array $userIds, string $event, string $text, string $href = '/'): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (!$userIds) {
            return;
        }
        $token = self::botToken();
        $place = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $rows = Db::all('SELECT id, prefs FROM "user" WHERE id IN (' . $place . ')', $userIds);
        } catch (PDOException) {
            try {
                $rows = Db::all('SELECT id FROM "user" WHERE id IN (' . $place . ')', $userIds);
            } catch (PDOException) {
                return;
            }
        }
        foreach ($rows as $r) {
            $prefs = self::prefs($r);
            if (!self::wants($prefs, 'telegram') || !self::wants($prefs, $event)) {
                continue;
            }
            $chat = trim((string) ($prefs['telegramChatId'] ?? ''));
            if ($chat === '' || $token === null) {
                continue;
            }
            $link = self::absoluteUrl($href);
            $msg = $text . ($link !== '' ? "\n" . $link : '');
            self::sendTelegram($token, $chat, $msg);
        }
    }

    /** Кому слать по филиалу и праву (или роли owner/manager). */
    public static function recipientsForPerm(string $perm, ?int $branchId = null): array
    {
        $ids = [];
        try {
            $rows = Db::all('SELECT id, role, permissions, "branchIds" FROM "user"');
        } catch (PDOException) {
            return [];
        }
        foreach ($rows as $r) {
            $u = [
                'id' => (string) $r['id'],
                'role' => normalize_role($r['role'] ?? 'admin'),
                'perms' => perms_from_row($r),
                'branchIds' => branch_ids_from_row($r, null),
                'isOwner' => normalize_role($r['role'] ?? '') === 'owner',
            ];
            if (!can($u, $perm, $branchId)) {
                continue;
            }
            $ids[] = $u['id'];
        }
        return $ids;
    }

    public static function absoluteUrl(string $path): string
    {
        $path = $path === '' ? '/' : $path;
        $base = app_config()['publicUrl'] ?? '';
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . (str_starts_with($path, '/') ? $path : '/' . $path);
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return $path;
        }
        return ($https ? 'https://' : 'http://') . $host . app_base() . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    public static function sendTelegram(string $token, string $chatId, string $text): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 3500),
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            error_log('[gamerpro] telegram notify failed: HTTP ' . $code);
            return false;
        }
        return true;
    }

    /** Анти-спам: одно событие на ключ в сутки. Сначала пишем ключ — чтобы параллельные запросы не слали дубли. */
    public static function once(string $key, callable $send): void
    {
        $day = date_key(now_tyumen());
        $k = 'notify_once:' . $day . ':' . $key;
        try {
            if (Db::one('SELECT k FROM app_meta WHERE k = ?', [$k])) {
                return;
            }
            try {
                Db::exec('INSERT INTO app_meta (k, v) VALUES (?, ?)', [$k, now_iso()]);
            } catch (PDOException) {
                return;
            }
            $send();
        } catch (PDOException $e) {
            error_log('[gamerpro] notify once: ' . $e->getMessage());
        }
    }
}
