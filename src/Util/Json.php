<?php

declare(strict_types=1);

namespace App\Util;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function encode(mixed $v): string
    {
        return json_encode($v, self::FLAGS);
    }
}
