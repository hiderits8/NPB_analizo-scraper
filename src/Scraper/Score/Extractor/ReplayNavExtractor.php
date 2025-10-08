<?php

declare(strict_types=1);

namespace App\Scraper\Score\Extractor;

use Symfony\Component\DomCrawler\Crawler;

/**
 * ReplayNavExtractor
 * ------------------
 * 対象: <dl id="replay"> ブロック（前へ/次へ のページ内リンク）
 *
 * 返り値例:
 * [
 *   'prev' => ['href' => '/npb/game/.../score?index=0910300', 'index' => '0910300'] | null,
 *   'next' => ['href' => '/npb/game/.../score?index=0910401', 'index' => '0910401'] | null,
 *   'batter_ordinal' => 4 | null,  // <dt>打者4</dt> などから抽出。無ければ null
 * ]
 *
 * 振る舞い:
 * - 「試合終了」ページなどで「次へ」リンクが無効（<dd class="nextgr">…）なら 'next' は null
 * - イニング開始（<dt></dt>）などで打者番号が表示されない場合は batter_ordinal は null
 * - 先頭の打席等で「前へ」が無い場合は 'prev' は null
 */
final class ReplayNavExtractor
{
    public function __construct(private string $rootSelector = '#replay') {}

    public function extract(Crawler $root): array
    {
        $nav = $root->filter($this->rootSelector)->first();
        if ($nav->count() === 0) {
            return [
                'prev' => null,
                'next' => null,
                'batter_ordinal' => null,
            ];
        }

        // prev
        $prev = null;
        $prevA = $nav->filter('dd.back a[href], a#btn_prev[href]')->first();
        if ($prevA->count() > 0) {
            $prev = [
                'href'  => $prevA->attr('href') ?? null,
                'index' => $prevA->attr('index') ?? self::extractIndexFromHref($prevA->attr('href') ?? ''),
            ];
        }

        // next（無効なときは dd.nextgr になり <a> に href が無い）
        $next = null;
        $nextA = $nav->filter('dd.next a[href], a#btn_next[href]')->first();
        if ($nextA->count() > 0) {
            $next = [
                'href'  => $nextA->attr('href') ?? null,
                'index' => $nextA->attr('index') ?? self::extractIndexFromHref($nextA->attr('href') ?? ''),
            ];
        }

        // <dt>打者4</dt> などから番号抽出（全角数字にも対応）
        $batterOrdinal = null;
        $dtText = trim($nav->filter('dt')->first()->text(''));
        if ($dtText !== '') {
            if (preg_match('/打者\s*([0-9０-９]+)/u', $dtText, $m)) {
                $n = self::toAsciiDigits($m[1]);
                if (ctype_digit($n)) {
                    $batterOrdinal = (int) $n;
                }
            }
        }

        return [
            'prev' => $prev,
            'next' => $next,
            'batter_ordinal' => $batterOrdinal,
        ];
    }

    private static function extractIndexFromHref(string $href): ?string
    {
        if ($href === '') return null;
        if (preg_match('/[?&]index=([0-9]{7})/', $href, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function toAsciiDigits(string $s): string
    {
        return strtr($s, [
            '０' => '0',
            '１' => '1',
            '２' => '2',
            '３' => '3',
            '４' => '4',
            '５' => '5',
            '６' => '6',
            '７' => '7',
            '８' => '8',
            '９' => '9',
        ]);
    }
}
