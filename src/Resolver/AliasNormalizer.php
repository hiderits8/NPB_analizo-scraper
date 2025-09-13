<?php

namespace App\Resolver;

/**
 * 別名をdata/aliases.phpから正規化するクラス
 * 
 * 関数の戻り値がnullの場合は、正規化が失敗したということ
 */
final class AliasNormalizer
{

    /** @var array<string, array<string, string>> */
    private array $aliases;


    /**
     * @param array<string, array<string, string>> $aliases
     */
    public function __construct(array $aliases)
    {
        $this->aliases = $aliases;
    }

    public function normalizeTeamFirst(string $raw): ?string
    {
        return $this->normalizeBy('teams_first', $raw);
    }

    public function normalizeTeamFarm(string $raw): ?string
    {
        return $this->normalizeBy('teams_farm', $raw);
    }

    public function normalizeStadium(string $raw): ?string
    {
        return $this->normalizeBy('stadiums', $raw);
    }

    public function normalizeClub(string $raw): ?string
    {
        return $this->normalizeBy('clubs', $raw);
    }

    /**
     * 関数の戻り値がnullの場合は、正規化が失敗したということ
     * 
     * @param string $category
     * @return string|null
     */
    private function normalizeBy(string $category, string $raw): ?string
    {
        $needle = $this->keyize($raw);
        $map = $this->aliases[$category] ?? [];

        // 完全一致（鍵＝表記ゆれ）→ 正規名
        if (isset($map[$needle])) {
            return $map[$needle];
        }

        return null;
    }

    /**
     * 正規化前の文字列をキーにする
     * @param string $s
     * @return string
     */
    private function keyize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }
}
