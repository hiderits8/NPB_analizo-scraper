<?php

declare(strict_types=1);

namespace App\Util;

final class TextNormalizer
{
    /** 全角/半角スペースを1個に正規化、前後トリム、ゼロ幅除去 */
    public static function normalizeJaName(string $s): string
    {
        $s = trim($s);
        // 全角スペースも対象に
        $s = preg_replace('/[\\h\\p{Zs}]+/u', ' ', $s) ?? $s;
        // ゼロ幅類
        $s = preg_replace('/[\\x{200B}-\\x{200D}\\x{FEFF}]/u', '', $s) ?? $s;
        return $s;
    }
}
