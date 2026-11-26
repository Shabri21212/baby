<?php
declare(strict_types=1);

/** Загрузка и отдача аватарок сотрудников (хранятся в storage/avatars/). */
final class Avatars
{
    private const MAX_BYTES = 3_500_000;
    private const SIZE = 320;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/storage/avatars';
    }

    public static function path(string $userId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) ?: 'x';
        return self::dir() . '/' . $safe . '.jpg';
    }

    public static function exists(string $userId): bool
    {
        return is_file(self::path($userId));
    }

    /** Сохраняет JPEG; возвращает версию для cache-bust (unix time). */
    public static function saveUpload(string $userId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Не удалось загрузить файл');
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Файл должен быть до 3.5 МБ');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Некорректная загрузка');
        }
        $info = @getimagesize($tmp);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('Нужна картинка JPG, PNG или WEBP');
        }
        $mime = (string) ($info['mime'] ?? '');
        $src = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
        if ($src === false) {
            throw new RuntimeException('Формат не поддерживается. Загрузите JPG или PNG');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int) (($w - $side) / 2);
        $sy = (int) (($h - $side) / 2);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            imagedestroy($dst);
            throw new RuntimeException('Не удалось создать папку для аватарок');
        }
        $path = self::path($userId);
        if (!@imagejpeg($dst, $path, 88)) {
            imagedestroy($dst);
            throw new RuntimeException('Не удалось сохранить аватар');
        }
        imagedestroy($dst);
        @chmod($path, 0644);
        return (string) time();
    }

    public static function delete(string $userId): void
    {
        $path = self::path($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function serve(string $userId): never
    {
        $path = self::path($userId);
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }
        $mtime = (string) filemtime($path);
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=604800, immutable');
        header('ETag: "' . $mtime . '"');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH'], '"') === $mtime) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
