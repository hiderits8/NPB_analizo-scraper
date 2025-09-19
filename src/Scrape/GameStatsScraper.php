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
    private const BASE = 'https://baseball.yahoo.co.jp';

    public function __construct(
        private ClientInterface $http,
        private ?string $scoreboardSelector = '#async-inning' // （/stats に同要素が無い試合もあるが、再パースせずに動く）
    ) {}

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

        // --- 2) 成績抽出（打撃/投球）— 今後ここで GameStatsScraper 専用の解析を行う
        // いまは雛形として空配列を返す。
        $stats = [
            'home' => ['batting' => [], 'pitching' => [], 'team' => ['batting_totals' => [], 'pitching_totals' => []]],
            'away' => ['batting' => [], 'pitching' => [], 'team' => ['batting_totals' => [], 'pitching_totals' => []]],
        ];

        return [
            'scoreboard' => $scoreboard,
            'stats'      => $stats,
        ];
    }
}
