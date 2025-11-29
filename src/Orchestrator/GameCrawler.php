<?php

declare(strict_types=1);

namespace App\Orchestrator;

use GuzzleHttp\ClientInterface;
use App\Scraper\Stats\GameStatsScraper;
use App\Scraper\Top\GameTopScraper;
use App\Orchestrator\ScoreWalker;
use App\Resolver\Resolver;

/**
 * GameCrawler
 * -----------
 * ゲーム単位で各種情報を収集するオーケストレーター。
 */
final class GameCrawler
{
    private GameTopScraper $topScraper;
    private GameStatsScraper $statsScraper;
    private ScoreWalker $scoreWalker;
    private Resolver $resolver;

    public function __construct(private ClientInterface $http)
    {
        $this->topScraper = new GameTopScraper($http, $resolver, $teamlevel = 'First');
        $this->statsScraper = new GameStatsScraper($http);
        $this->scoreWalker = new ScoreWalker($http);
    }

    /**
     * @param string $gameRootUrl ゲームルートURL 例: https://baseball.yahoo.co.jp/npb/game/2021029839/
     * @return {
     *   game_id: string,
     *   source_urls: {
     *     top: string,
     *     stats: string,
     *     score_walk: string
     *   },
     *   meta: array, // GameTopScraper 由来
     *   plays: array<{
     *     index: string, // 打席番号、例: '01101'
     *     page_indexes: array<string> // この打席に関連するページの index 一覧
     *     inning: int,
     *     half: 'top'|'bottom',
     *     batter_order: int | null, // イニング内の打者順(1始まり)、打者番号がないページではnull
     *     events: {
     *       main: {},
     *       advancements: array<{}>, // 盗塁や代走など       
     *     },
     *     counts_before: {
     *       b: int,
     *       s: int,
     *       o: int
     *     },
     *     counts_after: {
     *      b: int,
     *      s: int,
     *      o: int
     *     },
     *     pitches: array<{}>,
     *   }>,
     *   stats: array, // GameStatsScraper 由来
     * }
     */
    public function crawl(string $gameRootUrl): array {}
}
