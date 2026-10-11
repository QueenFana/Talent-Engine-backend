<?php
declare(strict_types=1);

final class Config
{
    private static array $vals = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                self::$vals[trim($k)] = trim($v, " \t\"'");
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') return $env;
        $v = self::$vals[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function debug(): bool
    {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    public static function cvDir(): string
    {
        $dir = self::get('CV_STORAGE_PATH') ?: dirname(__DIR__) . '/storage/cv';
        return rtrim($dir, '/\\');
    }

    public static function tmpDir(): string
    {
        return dirname(__DIR__) . '/storage/tmp';
    }
}
?>