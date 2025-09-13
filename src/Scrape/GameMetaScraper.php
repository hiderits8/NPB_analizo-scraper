<?php

declare(strict_types=1);

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use App\Resolver\Resolver;

final class GameMetaScraper
{
    private ClientInterface $http;
    private Resolver $resolver;

    public function __construct(ClientInterface $http, Resolver $resolver)
    {
        $this->http = $http;
        $this->resolver = $resolver;
    }

    /**
     * @param string $url
     * @param string $pageLevel First|Farm
     * @return array{
     *  url: string,
     *  date_label: ?string,
     *  time: ?string,
     *  stadium_raw: ?string,
     *  home_team_raw: ?string,
     *  away_team_raw: ?string,
     *  page_level: ?string, // First|Farm
     *  home_team_id: ?int,
     *  away_team_id: ?int,
     *  stadium_id: ?int,
     *  unresolved: array<string, string>,
     * }
     */
    public function scrape(string $url, string $pageLevel): array
    {
        $html = (string)$this->http->request('GET', $url)->getBody();
        $crawler = new Crawler($html);

        // 試合日時・球場名
        $date = $this->textOrNull($crawler, '#async-gameCard');
        if ($date !== null) {
            // 「日付／時間／球場」が同じブロックにいるので前側だけを抽出
            // 例: "9/7（日） 18:00 甲子園" → 最初の語を球場として扱う簡易版
            $date = trim(preg_replace('/^(\S+)\s+.*/u', '$1', preg_replace('/\s+/u', ' ', $date) ?? $date));
        }
        $time = $this->textOrNull($crawler, '#async-gameCard time');
        $stadium = $this->textOrNull($crawler, '#async-gameCard');
        if ($stadium !== null) {
            // 「日付／時間／球場」が同じブロックにいるので後側だけを抽出
            // 例: "9/7（日） 18:00 甲子園" → 最後の語を球場として扱う簡易版
            $stadium = trim(preg_replace('/.*\s+(\S+)$/u', '$1', preg_replace('/\s+/u', ' ', $stadium) ?? $stadium));
        }

        // チーム名（ホーム→アウェイの順で2ブロックある想定）
        $teamNodes = $crawler->filter('div.bb-gameTeam p.bb-gameTeam__name');
        $homeRaw = $teamNodes->count() >= 1 ? trim($teamNodes->eq(0)->text()) : null;
        $awayRaw = $teamNodes->count() >= 2 ? trim($teamNodes->eq(1)->text()) : null;

        // ID 解決（見つからなければログ用に未解決を記録）
        $unresolved = [];

        $stadiumName = null;
        if ($stadium !== null) {
            $stadiumName = $this->resolver->resolveStadiumNameFuzzy($stadium);
            if ($stadiumName === null) $unresolved['stadium'] = $stadium;
        }

        $homeTeamName = null;
        if ($homeRaw !== null && $pageLevel !== null) {
            $homeTeamName = $this->resolver->resolveTeamNameFuzzy($homeRaw, $pageLevel);
            if ($homeTeamName === null) $unresolved['home_team'] = $homeRaw . " (level={$pageLevel})";
        }

        $awayTeamName = null;
        if ($awayRaw !== null && $pageLevel !== null) {
            $awayTeamName = $this->resolver->resolveTeamNameFuzzy($awayRaw, $pageLevel);
            if ($awayTeamName === null) $unresolved['away_team'] = $awayRaw . " (level={$pageLevel})";
        }

        $unresolvedMap = [];
        if ($stadiumName  === null && !empty($stadium))    $unresolvedMap['stadium']   = $stadium;
        if ($homeTeamName === null && !empty($homeRaw))    $unresolvedMap['home_team'] = $homeRaw;
        if ($awayTeamName === null && !empty($awayRaw))    $unresolvedMap['away_team'] = $awayRaw;

        // 後方互換: unresolved は “rawの配列” のみにする（CLI の unresolved_keys と合成される）
        $unresolved = array_values($unresolvedMap);

        return [
            'url'            => $url,
            'date'           => $date,
            'time'           => $time,
            'home_team_name'   => $homeTeamName,
            'away_team_name'   => $awayTeamName,
            'stadium_name'     => $stadiumName,
            'home_team_raw'  => $homeRaw,
            'away_team_raw'  => $awayRaw,
            'stadium_raw'    => $stadium,
            'unresolved'     => $unresolved,     // 例: ["ロッテ"]
            'unresolved_map' => $unresolvedMap,  // 例: {"stadium":"ロッテ"}
        ];
    }

    private function textOrNull(Crawler $crawler, string $selector): ?string
    {
        $nodes = $crawler->filter($selector);
        if ($nodes->count() === 0) return null;
        $t = trim(preg_replace('/\s+/u', ' ', $nodes->first()->text() ?? ''));
        return $t === '' ? null : $t;
    }
}
