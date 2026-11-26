<?php
declare(strict_types=1);

/**
 * Картинки уроков курса.
 *
 * Файлы лежат в assets/course_images/ (так их отдаёт веб-сервер и так их собирает
 * scripts/build-course.cjs). Если папка на хостинге недоступна для записи, новые
 * загрузки уходят в storage/course_images/ и отдаются через /media/course/<имя>
 * (см. CourseMedia::serve) — как аватарки через /media/avatar/<id>.
 *
 * В базе хранится только имя файла: путь не зависит от того, куда именно его
 * положили на конкретном сервере.
 */
final class CourseMedia
{
    private const MAX_BYTES = 6_000_000;
    private const EXT_BY_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    private const MIME_BY_EXT = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** Папки-кандидаты: сначала записываемая, затем остальные. */
    public static function dirs(): array
    {
        $root = dirname(__DIR__);
        $env = getenv('GAMERPRO_COURSE_IMAGES');
        $dirs = [];
        if (is_string($env) && $env !== '') {
            $dirs[] = rtrim(str_replace('\\', '/', $env), '/');
        }
        $dirs[] = $root . '/assets/course_images';
        $dirs[] = $root . '/storage/course_images';
        return $dirs;
    }

    /** Куда писать новые файлы: первая существующая и записываемая папка. */
    public static function writeDir(): ?string
    {
        foreach (self::dirs() as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }
        $fallback = dirname(__DIR__) . '/storage/course_images';
        if (!is_dir($fallback) && !@mkdir($fallback, 0755, true)) {
            return null;
        }
        return is_writable($fallback) ? $fallback : null;
    }

    public static function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?: '';
    }

    /** Полный путь к существующему файлу или null. */
    public static function path(string $name): ?string
    {
        $name = self::safeName($name);
        if ($name === '' || str_contains($name, '..')) {
            return null;
        }
        foreach (self::dirs() as $dir) {
            $file = $dir . '/' . $name;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /** URL для <img>: assets отдаём напрямую, storage — через PHP-роут. */
    public static function url(string $name): string
    {
        $name = self::safeName($name);
        if ($name === '') {
            return '';
        }
        $env = getenv('GAMERPRO_COURSE_IMAGES');
        if (is_string($env) && $env !== '') {
            return url_to('/media/course/' . rawurlencode($name));
        }
        if (is_file(dirname(__DIR__) . '/assets/course_images/' . $name)) {
            return asset('course_images/' . $name);
        }
        return url_to('/media/course/' . rawurlencode($name));
    }

    /**
     * Сохраняет загруженную картинку, возвращает имя файла.
     * Проверки те же, что у аватарок: размер, реальный MIME, расширение по MIME.
     */
    public static function saveUpload(array $file, string $prefix = 'step'): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Файл не выбран');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Не удалось загрузить файл');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Картинка должна быть до 6 МБ');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Некорректная загрузка');
        }
        $info = @getimagesize($tmp);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('Нужна картинка PNG, JPG, WEBP или GIF');
        }
        $mime = strtolower((string) ($info['mime'] ?? ''));
        $ext = self::EXT_BY_MIME[$mime] ?? '';
        if ($ext === '') {
            throw new RuntimeException('Формат не поддерживается. Загрузите PNG, JPG или WEBP');
        }
        $dir = self::writeDir();
        if ($dir === null) {
            throw new RuntimeException('Нет папки для записи картинок — проверьте права на assets/course_images');
        }
        $prefix = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix)) ?: 'step';
        $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $dir . '/' . $name;
        if (!@move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Не удалось сохранить картинку');
        }
        @chmod($target, 0644);
        return $name;
    }

    /** Удаляет только файлы, созданные загрузкой из портала (префикс step-). */
    public static function delete(string $name): void
    {
        $name = self::safeName($name);
        if (!str_starts_with($name, 'step-')) {
            return;
        }
        $path = self::path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Отдача файла из storage/course_images (и любого кандидата) с кешем. */
    public static function serve(string $name): never
    {
        $path = self::path($name);
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_BY_EXT[$ext] ?? 'application/octet-stream';
        $mtime = (string) filemtime($path);
        header('Content-Type: ' . $mime);
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
