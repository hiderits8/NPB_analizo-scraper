<?php

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 試合参加者をスクレイピングするクラス
 *
 * 役割:
 *  - /game/<id>/top から、スタメン（打順/守備位置）と（あれば）ベンチ入り選手の一覧を抽出する。
 *  - 別名辞書やID解決には依存せず、画面表記そのままを返す。
 *
 * 戻り値構造:
 *  [
 *    'home' => [
 *      'starters' => [ ['name'=>string,'slot'=>int,'position'=>?string], ... ],
 *      'bench'    => [ ['name'=>string], ... ],
 *    ],
 *    'away' => [ ... 同上 ... ]
 *  ]
 */
final class GameParticipantsScraper
{
    private ClientInterface $http;

    public function __construct(ClientInterface $http)
    {
        $this->http = $http;
    }

    /**
     * トップページから参加選手（スタメン＋ベンチ）を抽出
     *
     * @param string $url 例: https://baseball.yahoo.co.jp/npb/game/2021030952/top
     * @return array{
     *   home: array{starters: list<array{name:string,slot:int,position:?string}>, bench: list<array{name:string}>>,
     *   away: array{starters: list<array{name:string,slot:int,position:?string}>, bench: list<array{name:string}>>
     * }
     */
    public function scrape(string $url): array
    {
        $html = (string) $this->http->request('GET', $url)->getBody();
        $crawler = new Crawler($html);

        $home = ['starters' => [], 'bench' => []];
        $away = ['starters' => [], 'bench' => []];

        // --- スタメン（先発野手の打順テーブル） ---
        $startingBlocks = $this->findStartingBlocks($crawler);
        $home['starters'] = $this->scrapeStartingMenbers($startingBlocks, 'home') ?? [];
        $away['starters'] = $this->scrapeStartingMenbers($startingBlocks, 'away') ?? [];

        // --- ベンチ（一軍のみ。無ければ空） ---
        $benchBlocks = $this->findBenchBlocks($crawler);
        $home['bench'] = $this->scrapeBenchMenbers($benchBlocks, 'home') ?? [];
        $away['bench'] = $this->scrapeBenchMenbers($benchBlocks, 'away') ?? [];

        return ['home' => $home, 'away' => $away];
    }

    /**
     * スタメン領域（bb-splits__item を2つ含むコンテナ）を返す
     */
    private function findStartingBlocks(Crawler $crawler): Crawler
    {
        // #async-starting 配下の .bb-splits__item を2つ想定
        return $crawler->filter('#async-starting .bb-splits .bb-splits__item');
    }

    /**
     * ベンチ領域のルート（#async-bench 全体）を返す
     */
    private function findBenchBlocks(Crawler $crawler): Crawler
    {
        return $crawler->filter('#async-bench');
    }

    /**
     * スタメン（打順/守備位置/選手名）を抽出
     * @return list<array{name:string,slot:int,position:?string}>
     */
    private function scrapeStartingMenbers(Crawler $blocks, string $homeOrAway): array
    {
        $idx = ($homeOrAway === 'home') ? 0 : 1;
        $item = $blocks->eq($idx);
        if ($item->count() === 0) return [];

        // 当該チーム側ブロック内のテーブルを走査し、
        // thead に「打順」を含むものを打順テーブル、
        // thead に「投手」を含むものを投手テーブルとして拾う
        $tables = $item->filter('table.bb-splitsTable');
        $lineupTable = null; // 打順テーブル
        $pitcherTable = null; // 投手テーブル（先発投手）
        foreach ($tables as $node) {
            $t = new Crawler($node);
            $theadText = $this->norm($t->filter('thead')->text(''));
            if ($theadText === '') continue;
            if (mb_strpos($theadText, '打順') !== false) {
                $lineupTable = $t;
                continue;
            }
            if (mb_strpos($theadText, '投手') !== false) {
                $pitcherTable = $t;
                continue;
            }
        }

        if ($lineupTable === null) return [];

        $rows = [];
        foreach ($lineupTable->filter('tbody tr.bb-splitsTable__row') as $tr) {
            $r = new Crawler($tr);
            $slotTxt = $this->norm($r->filter('td')->eq(0)->text(''));
            if ($slotTxt === '' || !ctype_digit(preg_replace('/\D/u', '', $slotTxt))) continue;
            $slot = (int) preg_replace('/\D/u', '', $slotTxt);

            $pos = $this->norm($r->filter('td')->eq(1)->text(''));
            if ($pos === '') $pos = null;

            // 名前（link テキストが基本。なければ3列目テキスト）
            $name = $this->norm($r->filter('td.bb-splitsTable__data--text a')->text(''));
            if ($name === '') {
                $name = $this->norm($r->filter('td')->eq(2)->text(''));
            }
            if ($name === '') continue;

            // 1..9 のみ採用
            if ($slot >= 1 && $slot <= 9) {
                $rows[] = ['name' => $name, 'slot' => $slot, 'position' => $pos];
            }
        }


        // DHあり等で打順に投手が含まれない場合、投手テーブルから先発投手を補完
        $hasPitcherInLineup = false;
        foreach ($rows as $rr) {
            if (($rr['position'] ?? null) === '投') {
                $hasPitcherInLineup = true;
                break;
            }
        }
        if (!$hasPitcherInLineup && $pitcherTable !== null) {
            // 先発投手の行を探す（tbody の1行目 or "先発"のある行）
            $pName = '';
            foreach ($pitcherTable->filter('tbody tr.bb-splitsTable__row') as $trP) {
                $rp = new Crawler($trP);
                $roleTxt = $this->norm($rp->filter('td')->eq(0)->text(''));
                if ($roleTxt !== '' && mb_strpos($roleTxt, '先発') === false) {
                    // 先発以外はスキップ
                    continue;
                }
                $pName = $this->norm($rp->filter('td.bb-splitsTable__data--text a')->text(''));
                if ($pName === '') {
                    $pName = $this->norm($rp->filter('td')->eq(2)->text(''));
                }
                if ($pName !== '') break; // 最初に見つかった先発投手
            }
            if ($pName !== '') {
                $rows[] = ['name' => $pName, 'slot' => null, 'position' => '投'];
            }
        }

        return $rows;
    }

    /**
     * ベンチ（カテゴリー: 投手/捕手/内野手/外野手）から該当側の名前を全て集約
     * @return list<array{name:string}>
     */
    private function scrapeBenchMenbers(Crawler $blocks, string $homeOrAway): array
    {
        if ($blocks->count() === 0) return [];
        $idx = ($homeOrAway === 'home') ? 0 : 1;

        $names = [];
        // #async-bench 配下の各セクション（bb-modCommon03）を走査
        foreach ($blocks->filter('#async-bench section.bb-modCommon03') as $sec) {
            $s = new Crawler($sec);
            $sideItem = $s->filter('.bb-splits .bb-splits__item')->eq($idx);
            if ($sideItem->count() === 0) continue;

            // テーブルの tbody のリンクテキストを収集
            foreach ($sideItem->filter('tbody tr a') as $a) {
                $name = $this->norm((new Crawler($a))->text(''));
                if ($name !== '') $names[$name] = true; // 重複除去
            }
        }

        return array_map(fn($n) => ['name' => $n], array_keys($names));
    }

    /** 余計な空白・ゼロ幅スペース等を整理 */
    private function norm(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        return $s;
    }
}
