<?php

namespace App\Resolver;

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

    public function normalizeTeamFirst(string $raw): string
    {
        return $this->normalizeBy('teams_first', $raw);
    }

    public function normalizeTeamFarm(string $raw): string
    {
        return $this->normalizeBy('teams_farm', $raw);
    }

    public function normalizeStadium(string $raw): string
    {
        return $this->normalizeBy('stadiums', $raw);
    }

    public function normalizeClub(string $raw): string
    {
        return $this->normalizeBy('clubs', $raw);
    }

    private function normalizeBy(string $domain, string $raw): string
    {
        $needle = $this->keyize($raw);
        $map = $this->aliases[$domain] ?? [];

        // 完全一致（鍵＝表記ゆれ）→ 正規名
        if (isset($map[$needle])) {
            return $map[$needle];
        }

        // 軽い正規化
        $soft = $this->softNorm($raw);

        // 別名キーにも soft をかけた比較（ざっくり同一視）
        foreach ($map as $aliasKey => $canonical) {
            if ($this->softNorm($aliasKey) === $soft) {
                return $canonical;
            }
        }

        return $raw;
    }

    private function keyize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }

    private function softNorm(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // ゼロ幅スペース除去
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        // 丸括弧・全角括弧内の付記（例: （一軍）, (ファーム) など）を除去
        $s = preg_replace('/[（(].*?[)）]/u', '', $s) ?? $s;
        // 記号をざっくり除去（・やハイフン等）
        $s = preg_replace('/[・\-－—_]/u', '', $s) ?? $s;
        // “軍”の除去（例: 一軍/二軍 の語尾だけ落とす軽い処理）
        $s = preg_replace('/軍/u', '', $s) ?? $s;
        return $s;
    }
}
