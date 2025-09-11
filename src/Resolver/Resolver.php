<?php

declare(strict_types=1);

namespace App\Resolver;

use App\Resolver\AliasNormalizer;


final class Resolver
{

    /** @var array<int, array{team_id:int, team_name:string, league:string, level:string, club_id:int}> */
    private $teams;
    /** @var array<int, array{stadium_id:int, stadium_name:string}> */
    private $stadiums;
    /** @var array<int, array{club_id:int, club_name:string}> */
    private $clubs;
    /** @var AliasNormalizer */
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
     * @param string $teamName
     * @return int|null
     */
    public function resolveTeamIdStrict(string $teamName): ?int
    {
        $needle = $this->normalizeStrict($teamName);
        foreach ($this->teams as $team) {
            $name = $this->normalizeStrict((string)$team['team_name']);
            if ($name === $needle) {
                return (int)$team['team_id'];
            }
        }
        return null;
    }

    /**
     * @param string $stadiumName
     * @return int|null
     */
    public function resolveStadiumIdStrict(string $stadiumName): ?int
    {
        $needle = $this->normalizeStrict($stadiumName);
        foreach ($this->stadiums as $stadium) {
            $name = $this->normalizeStrict((string)$stadium['stadium_name']);
            if ($name === $needle) {
                return (int)$stadium['stadium_id'];
            }
        }
        return null;
    }


    /**
     * @param string $clubName
     * @return int|null
     */
    public function resolveClubIdStrict(string $clubName): ?int
    {
        $needle = $this->normalizeStrict($clubName);
        foreach ($this->clubs as $club) {
            $name = $this->normalizeStrict((string)$club['club_name']);
            if ($name === $needle) {
                return (int)$club['club_id'];
            }
        }
        return null;
    }


    private function normalizeStrict(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // 可視できないゼロ幅スペースなどを念のため除去
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        return $s;
    }

    /**
     * @param int $clubId
     * @param string $level
     * @return int|null
     */
    private function pickTeamIdByClubAndLevel(int $clubId, string $level): ?int
    {
        foreach ($this->teams as $t) {
            if ((int)$t['club_id'] === $clubId && (string)$t['level'] === $level) {
                return (int)$t['team_id'];
            }
        }
        return null;
    }

    /**
     * レベル（First|Farm）を明示してチームIDを解決
     * @param string $teamName
     * @param string $level First|Farm
     * @return int|null
     */
    public function resolveTeamIdFuzzy(string $teamName, string $level): ?int
    {
        // 1) level に応じて first/farm の辞書で正規化 → strict
        $id = $level === 'First'
            ? $this->resolveTeamIdStrict($this->alias->normalizeTeamFirst($teamName))
            : $this->resolveTeamIdStrict($this->alias->normalizeTeamFarm($teamName));
        if ($id !== null) return $id;

        // 2) それでもダメなら「クラブ」辞書で club_id を当てて、レベルでチーム選定
        $clubId = $this->resolveClubIdFuzzy($teamName);
        if ($clubId !== null) {
            $picked = $this->pickTeamIdByClubAndLevel($clubId, $level);
            if ($picked !== null) return $picked;
        }

        // 3) 最後に strict そのまま（念のため）
        return $this->resolveTeamIdStrict($teamName);
    }

    /**
     * @param string $stadiumName
     * @return int|null
     */
    public function resolveStadiumIdFuzzy(string $stadiumName): ?int
    {
        $id = $this->resolveStadiumIdStrict($stadiumName);
        if ($id !== null) return $id;

        $normalized = $this->alias->normalizeStadium($stadiumName);
        return $this->resolveStadiumIdStrict($normalized);
    }

    /**
     * @param string $clubName
     * @return int|null
     */
    public function resolveClubIdFuzzy(string $clubName): ?int
    {
        $id = $this->resolveClubIdStrict($clubName);
        if ($id !== null) return $id;

        $normalized = $this->alias->normalizeClub($clubName);
        return $this->resolveClubIdStrict($normalized);
    }
}
