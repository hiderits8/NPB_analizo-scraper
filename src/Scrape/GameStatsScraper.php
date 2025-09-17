<?php

declare(strict_types=1);

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * GameStatsScraper
 * ----------------
 * 役割:
 *   Yahoo! 野球の /game/<id>/stats から、ホーム/ビジターの
 *   1) 打者成績（個人） 2) 投手成績（個人） 3) チーム合計 を抽出する。
 *
 * 特徴:
 *   - マークアップの揺れに対応するため、.bb-splits（左右2カラム）構造を探索し、
 *     テーブルの thead 文字列から「打撃系/投手系」を判定する。
 *   - 列見出し（th）を日本語→標準キーにマッピングして、値を拾う。
 *   - 名前はリンクテキスト優先（/npb/player/...）。URLが相対のときは絶対URLへ昇格。
 *   - 最終行等の「計/合計」をチーム合計として抽出（個人からは除外）。
 *
 * 戻り値:
 *   [
 *     'home' => [
 *       'batting'  => list<array{ name:string, player_url:?string } & array<string,string|null>>,
 *       'pitching' => list<array{ name:string, player_url:?string } & array<string,string|null>>,
 *       'team'     => array{ batting_totals: array<string,string|null>, pitching_totals: array<string,string|null> },
 *     ],
 *     'away' => [...同様...]
 *   ]
 */
final class GameStatsScraper
{
    private const BASE = 'https://baseball.yahoo.co.jp';

    /** 打撃ヘッダ→キー */
    private const BATTING_MAP = [
        '打数' => 'ab', '得点' => 'r', '安打' => 'h', '二塁打' => '2b', '二' => '2b',
        '三塁打' => '3b', '三' => '3b', '本塁打' => 'hr', '打点' => 'rbi', '四球' => 'bb',
        '死球' => 'hbp', '三振' => 'so', '盗塁' => 'sb', '盗塁刺' => 'cs', '盗塁死' => 'cs',
        '犠打' => 'sh', '犠飛' => 'sf', '失策' => 'e', '打率' => 'avg', '出塁率' => 'obp',
        '長打率' => 'slg', 'ops' => 'ops', 'OPS' => 'ops',
        // 補助（あるかもしれない）
        '打' => 'bat_hand', '位置' => 'pos', '守備' => 'pos',
    ];

    /** 投球ヘッダ→キー */
    private const PITCHING_MAP = [
        '回' => 'ip', '打者' => 'bf', '球数' => 'np', '安打' => 'h', '本塁打' => 'hr',
        '三振' => 'so', '四球' => 'bb', '死球' => 'hbp', '失点' => 'r', '自責' => 'er', '自責点' => 'er',
        '暴投' => 'wp', 'ボーク' => 'bk', '防御率' => 'era',
        // 補助（勝敗等）
        '勝' => 'win', '敗' => 'loss', 'Ｓ' => 'save', 'S' => 'save'
    ];

    private ClientInterface $http;

    public function __construct(ClientInterface $http)
    {
        $this->http = $http;
    }

    /**
     * @param string $statsUrl 例: https://baseball.yahoo.co.jp/npb/game/2021030952/stats
     * @return array{
     *   home: array{batting:list<array<string,string|null>>, pitching:list<array<string,string|null>>, team: array{batting_totals:array<string,string|null>,pitching_totals:array<string,string|null>}},
     *   away: array{batting:list<array<string,string|null>>, pitching:list<array<string,string|null>>, team: array{batting_totals:array<string,string|null>,pitching_totals:array<string,string|null>}}
     * }
     */
    public function scrape(string $statsUrl): array
    {
        $html = (string) $this->http->request('GET', $statsUrl)->getBody();
        $c = new Crawler($html);

        $home = ['batting' => [], 'pitching' => [], 'team' => ['batting_totals' => [], 'pitching_totals' => []]];
        $away = ['batting' => [], 'pitching' => [], 'team' => ['batting_totals' => [], 'pitching_totals' => []]];

        // .bb-splits（2カラム）を走査し、ヘッダ文字列で打者/投手を判定
        foreach ($c->filter('.bb-splits') as $splits) {
            $sp = new Crawler($splits);
            $items = $sp->filter('.bb-splits__item');
            if ($items->count() < 2) continue;

            // この splits の代表テーブルの thead から種別を推定
            $kind = $this->guessKind($sp);
            if ($kind === null) continue;

            $homeItem = $items->eq(0);
            $awayItem = $items->eq(1);

            if ($kind === 'batting') {
                [$rowsH, $teamH] = $this->parseBatting($homeItem);
                [$rowsA, $teamA] = $this->parseBatting($awayItem);
                if ($rowsH) $home['batting'] = $rowsH;
                if ($rowsA) $away['batting'] = $rowsA;
                if ($teamH) $home['team']['batting_totals'] = $teamH;
                if ($teamA) $away['team']['batting_totals'] = $teamA;
            } elseif ($kind === 'pitching') {
                [$rowsH, $teamH] = $this->parsePitching($homeItem);
                [$rowsA, $teamA] = $this->parsePitching($awayItem);
                if ($rowsH) $home['pitching'] = $rowsH;
                if ($rowsA) $away['pitching'] = $rowsA;
                if ($teamH) $home['team']['pitching_totals'] = $teamH;
                if ($teamA) $away['team']['pitching_totals'] = $teamA;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * この .bb-splits が打撃/投手のどちらかを推定
     * @return 'batting'|'pitching'|null
     */
    private function guessKind(Crawler $splits): ?string
    {
        foreach ($splits->filter('table thead') as $thead) {
            $txt = $this->norm((new Crawler($thead))->text(''));
            if ($txt === '') continue;
            if ($this->containsAny($txt, ['打率','打数','安打','本塁打','打点','OPS','出塁率','長打率'])) return 'batting';
            if ($this->containsAny($txt, ['防御率','自責','自責点','失点','回','球数','打者'])) return 'pitching';
        }
        return null;
    }

    /**
     * 打者成績テーブルを解析
     * @return array{0: list<array<string,string|null>>, 1: array<string,string|null>} [rows, teamTotals]
     */
    private function parseBatting(Crawler $item): array
    {
        $rows = [];
        $team = [];

        foreach ($item->filter('table') as $tbl) {
            $t = new Crawler($tbl);
            $thead = $this->norm($t->filter('thead')->text(''));
            if ($thead === '' || !$this->containsAny($thead, ['打数','安打','本塁打','打率'])) continue;

            $map = $this->buildHeaderMap($t, self::BATTING_MAP);
            foreach ($t->filter('tbody tr') as $tr) {
                $r = new Crawler($tr);
                $name = $this->extractName($r);
                if ($name === '') continue;

                // 計/合計 はチーム合計として採用
                if (mb_strpos($name, '計') !== false || mb_strpos($name, '合計') !== false) {
                    $team = $this->extractRowByMap($r, $map);
                    continue;
                }

                $row = ['name' => $name, 'player_url' => $this->extractPlayerUrl($r)];
                $row += $this->extractRowByMap($r, $map);
                $rows[] = $row;
            }
            break; // 最初の該当テーブルだけ処理
        }
        return [$rows, $team];
    }

    /**
     * 投手成績テーブルを解析
     * @return array{0: list<array<string,string|null>>, 1: array<string,string|null>} [rows, teamTotals]
     */
    private function parsePitching(Crawler $item): array
    {
        $rows = [];
        $team = [];

        foreach ($item->filter('table') as $tbl) {
            $t = new Crawler($tbl);
            $thead = $this->norm($t->filter('thead')->text(''));
            if ($thead === '' || !$this->containsAny($thead, ['防御率','自責','自責点','失点','回','球数','打者'])) continue;

            $map = $this->buildHeaderMap($t, self::PITCHING_MAP);
            foreach ($t->filter('tbody tr') as $tr) {
                $r = new Crawler($tr);
                $name = $this->extractName($r);
                if ($name === '') continue;

                if (mb_strpos($name, '計') !== false || mb_strpos($name, '合計') !== false) {
                    $team = $this->extractRowByMap($r, $map);
                    continue;
                }

                $row = ['name' => $name, 'player_url' => $this->extractPlayerUrl($r)];
                $row += $this->extractRowByMap($r, $map);
                $rows[] = $row;
            }
            break; // 最初の該当テーブルだけ処理
        }
        return [$rows, $team];
    }

    /** th から列マップを構築（index => key） */
    private function buildHeaderMap(Crawler $table, array $dict): array
    {
        $map = [];
        $i = 0;
        foreach ($table->filter('thead th') as $th) {
            $txt = $this->norm((new Crawler($th))->text(''));
            if ($txt === '') { $i++; continue; }
            // 正規化（全角→半角、大文字小文字無視の簡易対応）
            $key = $dict[$txt] ?? ($dict[mb_strtoupper($txt)] ?? null);
            if ($key === null) {
                // 末尾の"率"や空白除去などの緩い一致
                $txt2 = str_replace([' ', '　'], '', $txt);
                $key = $dict[$txt2] ?? null;
            }
            if ($key !== null) $map[$i] = $key;
            $i++;
        }
        return $map;
    }

    /** テーブル行から、列マップに従って値を拾う */
    private function extractRowByMap(Crawler $row, array $map): array
    {
        $out = [];
        $i = 0;
        foreach ($row->filter('td') as $td) {
            if (array_key_exists($i, $map)) {
                $val = $this->norm((new Crawler($td))->text(''));
                $out[$map[$i]] = ($val === '' ? null : $val);
            }
            $i++;
        }
        return $out;
    }

    /** 行の選手名（リンクテキスト優先） */
    private function extractName(Crawler $row): string
    {
        $a = $row->filter('a');
        if ($a->count() > 0) {
            $name = $this->norm($a->first()->text(''));
            if ($name !== '') return $name;
        }
        return $this->norm($row->filter('td')->first()->text(''));
    }

    /** 行の選手リンクを絶対URLで取得 */
    private function extractPlayerUrl(Crawler $row): ?string
    {
        $a = $row->filter('a');
        if ($a->count() === 0) return null;
        $href = trim((string) $a->first()->attr('href'));
        if ($href === '') return null;
        if (str_starts_with($href, 'http')) return $href;
        if ($href[0] !== '/') $href = '/' . $href;
        return self::BASE . $href;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (mb_strpos($haystack, $n) !== false) return true;
        }
        return false;
    }

    private function norm(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        return $s;
    }
}





<?php
// ... existing code above ...

// --- stats (/stats) 収集と書き出し ----------------------------------------
try {
    $statsUrl = preg_replace('#/top$#', '/stats', $url);
    $statsScraper = new \App\Scrape\GameStatsScraper($client);
    $stats = $statsScraper->scrape($statsUrl);

    $batFile  = $outDir . '/game_stats_batting.ndjson';
    $pitFile  = $outDir . '/game_stats_pitching.ndjson';
    $teamFile = $outDir . '/game_stats_team.ndjson';

    $writeNd = function (string $file, array $row): void {
        file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    };

    $nowIso = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    foreach (['home' => 'HOME', 'away' => 'AWAY'] as $sideKey => $sideVal) {
        foreach ($stats[$sideKey]['batting'] as $r) {
            $writeNd($batFile, ['game_key' => $gameKey, 'team_side' => $sideVal, 'type' => 'batting', 'player_name_ja' => $r['name'] ?? null, 'player_url' => $r['player_url'] ?? null, 'stats' => $r, 'url' => $statsUrl, 'scraped_at' => $nowIso]);
        }
        foreach ($stats[$sideKey]['pitching'] as $r) {
            $writeNd($pitFile, ['game_key' => $gameKey, 'team_side' => $sideVal, 'type' => 'pitching', 'player_name_ja' => $r['name'] ?? null, 'player_url' => $r['player_url'] ?? null, 'stats' => $r, 'url' => $statsUrl, 'scraped_at' => $nowIso]);
        }
        if ($stats[$sideKey]['team']['batting_totals']) {
            $writeNd($teamFile, ['game_key' => $gameKey, 'team_side' => $sideVal, 'type' => 'batting_totals', 'totals' => $stats[$sideKey]['team']['batting_totals'], 'url' => $statsUrl, 'scraped_at' => $nowIso]);
        }
        if ($stats[$sideKey]['team']['pitching_totals']) {
            $writeNd($teamFile, ['game_key' => $gameKey, 'team_side' => $sideVal, 'type' => 'pitching_totals', 'totals' => $stats[$sideKey]['team']['pitching_totals'], 'url' => $statsUrl, 'scraped_at' => $nowIso]);
        }
    }
} catch (\Throwable $e) {
    // stats は任意。失敗しても致命にはしない（ログのみ）
    fwrite(STDERR, "[warn] stats scrape failed: " . $e->getMessage() . "\n");
}

// ... existing code below ...

exit(0);
