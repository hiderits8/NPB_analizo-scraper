<?php

declare(strict_types=1);

namespace App\Util;

final class Path
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, 'phar://');
    }

    public static function resolve(string $projectRoot, string $path): string
    {
        return self::isAbsolute($path)
            ? $path
            : rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
