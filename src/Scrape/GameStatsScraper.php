<?php

declare(strict_types=1);

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * GameStatsScraper
 * ----------------
 * /npb/game/{id}/stats を HTTP GET し、DomCrawler を 1 回だけ生成。
 * ここでは「オーケストレータ的に」下位抽出器を呼び出す。
 *
 * 現段階:
 * - ScoreboardExtractor を注入的に呼び出す形へ変更（DOM再パース回避）。
 * - 今後、打者・投手の成績抽出（/stats 専用ロジック）は本クラス内に追加予定。
 *
 * 戻り値の雛形:
 * [
 *   'scoreboard' => array,  // ScoreboardExtractor の結果（/stats に無ければ空の既定値）
 *   'stats'      => []      // 後続で打撃/投球の詳細を埋める
 * ]
 */
final class GameStatsScraper
{
    private ClientInterface $http;
    private string $scoreboardSelector;
    private string $batterSelector;
    private string $pitcherSelector;


    public function __construct(
        ClientInterface $http,
        string $scoreboardSelector = '#async-inning',
        string $batterSelector = '#async-gameBatterStats',
        string $pitcherSelector = '#async-gamePitcherStats',
    ) {
        $this->http = $http;
        $this->scoreboardSelector = $scoreboardSelector;
        $this->batterSelector = $batterSelector;
        $this->pitcherSelector = $pitcherSelector;
    }

    /**
     * @param string $url 例: https://baseball.yahoo.co.jp/npb/game/2021029839/stats
     */
    public function scrape(string $url): array
    {
        $html = (string) $this->http->request('GET', $url)->getBody();
        $crawler = new Crawler($html);

        // --- 1) スコアボード（抽出器に Crawler を渡す） -----------------------
        // /stats に #async-inning が無いケースでは、抽出器側が空の既定値を返す。
        $scoreboard = (new ScoreboardExtractor($this->scoreboardSelector))
            ->extract($crawler, /* meta */ []);

        // 試合のメタ情報を設定
        $gameMeta = [
            'away' => [
                'team_raw' => $scoreboard['away']['team_raw'],
                'team_code' => $scoreboard['away']['team_code'],
            ],
            'home' => [
                'team_raw' => $scoreboard['home']['team_raw'],
                'team_code' => $scoreboard['home']['team_code'],
            ],
        ];

        // --- 2) 成績抽出（打撃/投球）— 今後ここで GameStatsScraper 専用の解析を行う
        // 打者成績 
        $battingStats = new BatterStatsExtractor($gameMeta, $this->batterSelector)
            ->extract($crawler, /* meta */ []);

        // 投手成績
        $pitchingStats = new PitcherStatsExtractor($gameMeta, $this->pitcherSelector)
            ->extract($crawler, /* meta */ []);

        return [
            'game_meta' => $gameMeta,
            'scoreboard' => $scoreboard,
            'batting_stats'      => $battingStats,
            'pitching_stats'     => $pitchingStats,
        ];
    }
}
