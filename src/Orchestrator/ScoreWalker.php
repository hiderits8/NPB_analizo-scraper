<?php

declare(strict_types=1);

namespace App\Orchestrator;

use GuzzleHttp\ClientInterface;
use App\Scraper\Score\GameScoreScraper;

/**
 * ScoreWalker
 * -----------
 * /score の「前へ/次へ」を辿って1打席(=ゲーム全体)の全ページを収集する開発用ユーティリティ。
 * - 入口URLがゲームルート(/npb/game/{id})や /score（indexなし）でもOK
 * - まず「前へ」を遡って最古ページを特定 → そこから「次へ」で順次前進
 * - ページ内容は GameScoreScraper の生結果を pages[] に積む（統合/重複排除は上位で実施）
 */
final class ScoreWalker
{
    private const BASE = 'https://baseball.yahoo.co.jp';

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
    public function walk(string $url): array
    {
        $currentUrl = $this->normalizeUrl($url);
        if (!str_contains($currentUrl, '/score')) {
            // ルートが来た場合は /score に寄せる（index なし＝最終状態）
            $currentUrl = rtrim($currentUrl, '/') . '/score';
        }

        // --- まず入口ページを取得 ---
        $page = $this->scraper->scrape($currentUrl);

        // --- prev で最古ページまで遡る ---
        $visited = [];
        $currentUrl = $page['url'] ?? $currentUrl;
        $visited[$this->pageKey($currentUrl)] = true;

        while (true) {
            $prevHref = $page['replay_nav']['prev']['href'] ?? null;
            if (!$prevHref) {
                break;
            }
            $prevUrl = $this->absolutize($prevHref);
            $key = $this->pageKey($prevUrl);
            if (isset($visited[$key])) {
                break; // ループ安全策
            }
            $visited[$key] = true;
            $page = $this->scraper->scrape($prevUrl);
            $currentUrl = $page['url'] ?? $prevUrl;
        }

        // --- ここが最古ページ。ここから next で前進収集 ---
        $pages = [];
        $firstUrl = $currentUrl;
        $firstIndex = $this->extractIndexFromUrl($firstUrl);

        while (true) {
            $pages[] = $page;

            $nextHref = $page['replay_nav']['next']['href'] ?? null;
            if (!$nextHref) {
                break;
            }
            $nextUrl = $this->absolutize($nextHref);
            $key = $this->pageKey($nextUrl);
            if (isset($visited[$key])) {
                break; // 念のため重複を防止
            }
            $visited[$key] = true;

            $page = $this->scraper->scrape($nextUrl);
            $currentUrl = $page['url'] ?? $nextUrl;
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

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return self::BASE . '/';
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }
        return self::BASE . $url;
    }

    private function absolutize(string $href): string
    {
        $href = trim($href);
        if ($href === '') return self::BASE . '/';
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (!str_starts_with($href, '/')) {
            $href = '/' . $href;
        }
        return self::BASE . $href;
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

    private function pageKey(string $url): string
    {
        // index があればそれをキーに、なければ URL 全体で
        return $this->extractIndexFromUrl($url) ?? $url;
    }
}
