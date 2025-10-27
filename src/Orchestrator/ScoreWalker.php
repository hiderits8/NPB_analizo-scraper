<?php

declare(strict_types=1);

namespace App\Orchestrator;

use GuzzleHttp\ClientInterface;
use App\Scraper\Score\GameScoreScraper;

/**
 * ScoreWalker
 * -----------
 * /score の「前へ/次へ」を辿って1打席(=ゲーム全体)の全ページを収集する開発用ユーティリティ。
 * - 入口URLはゲームルート
 * - ページ内容は GameScoreScraper の生結果を pages[] に積む（統合/重複排除は上位で実施）
 */
final class ScoreWalker
{
    private const START_INDEX = '0110100'; // '01' = 1回 + '1' = 表（'2' = 裏） + '01' = 1番打者 + '00' = index

    private GameScoreScraper $scraper;

    public function __construct(private ClientInterface $http)
    {
        $this->scraper = new GameScoreScraper($http);
    }

    /**
     * @param string $url  入口URL（ゲームルート or /score or /score?index=...）
     * @return array{
     *   game_id: string|null,
     *   start_url: string,
     *   end_url: string,
     *   start_index: string|null,
     *   end_index: string|null,
     *   pages_count: int,
     *   pages: array<int, array>
     * }
     */
    public function walk(string $gameRoot): array
    {
        $currentUrl = $this->indexToUrl($gameRoot, self::START_INDEX);

        $pages = [];
        $firstUrl = $currentUrl;
        $firstIndex = $this->extractIndexFromUrl($firstUrl);

        while (true) {
            $currentIndex = $this->extractIndexFromUrl($currentUrl);
            $data = $this->scraper->scrape($currentUrl);

            $pages[] = [
                'url'   => $currentUrl,
                'index' => $currentIndex,
                'data'  => $data,
            ];

            $nextHref = $data['replay_nav']['next']['href'] ?? null;
            $nextIndex = $this->extractIndexFromUrl((string)$nextHref);
            if (!$nextHref) {
                break;
            }

            $currentUrl = $this->indexToUrl($gameRoot, $nextIndex);
        }

        $lastUrl   = $currentUrl;
        $lastIndex = $this->extractIndexFromUrl($lastUrl);

        return [
            'game_id'     => $this->extractGameId($firstUrl),
            'start_url'   => $firstUrl,
            'end_url'     => $lastUrl,
            'start_index' => $firstIndex,
            'end_index'   => $lastIndex,
            'pages_count' => count($pages),
            'pages'       => $pages,
        ];
    }

    // ---------------- helpers ----------------

    private function indexToUrl(string $gameRoot, string $index): string
    {
        return rtrim($gameRoot, '/') . '/score?index=' . $index;
    }

    private function extractIndexFromUrl(string $url): ?string
    {
        if (preg_match('/[?&]index=([0-9]{7})\b/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractGameId(string $url): ?string
    {
        // 例: https://.../npb/game/2021030952/score?index=...
        if (preg_match('#/game/(\d{10})/#', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
