<?php

declare(strict_types=1);

namespace App\Resolver;

use App\Resolver\AliasNormalizer;

/**
 * チーム名、球場名、クラブ名を受け取って正式名称を返すクラス
 */
final class Resolver
{
    private $teams;
    private $stadiums;
    private $clubs;
    private AliasNormalizer $alias;

    /**
     * @param array<string, mixed> $teams
     * @param array<string, mixed> $stadiums
     * @param array<string, mixed> $clubs
     */
    public function __construct(array $teams, array $stadiums, array $clubs, ?AliasNormalizer $alias = null)
    {
        $this->teams = $teams;
        $this->stadiums = $stadiums;
        $this->clubs = $clubs;
        $this->alias = $alias ?? new AliasNormalizer(['teams_first' => [], 'teams_farm' => [], 'stadiums' => [], 'clubs' => []]);
    }

    /**
     * チーム名を厳密に正規化して正式名称を解決
     * 
     * @param string $teamName
     * @return string|null 正式名称
     */
    public function resolveTeamNameStrict(string $teamName): ?string
    {
        $needle = $this->normalizeStrict($teamName);
        foreach ($this->teams as $team) {
            $name = $team['team_name'];
            if ($name === $needle) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 球場名を厳密に正規化して正式名称を解決
     * 
     * @param string $stadiumName
     * @return string|null 正式名称
     */
    public function resolveStadiumNameStrict(string $stadiumName): ?string
    {
        $needle = $this->normalizeStrict($stadiumName);
        foreach ($this->stadiums as $stadium) {
            $name = $stadium['stadium_name'];
            if ($name === $needle) {
                return $name;
            }
        }
        return null;
    }

    /**
     * クラブ名を厳密に正規化して正式名称を解決
     * 
     * @param string $clubName
     * @return string|null 正式名称
     */
    public function resolveClubNameStrict(string $clubName): ?string
    {
        $needle = $this->normalizeStrict($clubName);
        foreach ($this->clubs as $club) {
            $name = $club['club_name'];
            if ($name === $needle) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 文字列を厳密に正規化
     * @param string $s
     * @return string
     */
    private function normalizeStrict(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // 可視できないゼロ幅スペースなどを念のため除去
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        return $s;
    }

    /**
     * レベル（First|Farm）を明示してファジーにチームIDを解決
     * @param string $teamName
     * @param string $level First|Farm
     * @return int|null
     */
    public function resolveTeamNameFuzzy(string $teamName, string $level): ?string
    {
        // level に応じて first/farm の辞書で正規化 → strict
        if ($level === 'First') {
            $name = $this->alias->normalizeTeamFirst($teamName) ?? null;
        } elseif ($level === 'Farm') {
            $name = $this->alias->normalizeTeamFarm($teamName) ?? null;
        } else {
            $name = null;
        }
        return $name;
    }

    /**
     * 球場名をファジーに正規化して正式名称を解決
     * 
     * @param string $stadiumName
     * @return string|null 正式名称
     */
    public function resolveStadiumNameFuzzy(string $stadiumName): ?string
    {
        $name = $this->resolveStadiumNameStrict($stadiumName);
        if ($name !== null) return $name;

        $normalized = $this->alias->normalizeStadium($stadiumName);
        return $normalized;
    }

    /**
     * クラブ名をファジーに正規化して正式名称を解決
     * @param string $clubName
     * @return string|null 正式名称
     */
    public function resolveClubNameFuzzy(string $clubName): ?string
    {
        $name = $this->resolveClubNameStrict($clubName);
        if ($name !== null) return $name;

        $normalized = $this->alias->normalizeClub($clubName);
        return $normalized;
    }
}
