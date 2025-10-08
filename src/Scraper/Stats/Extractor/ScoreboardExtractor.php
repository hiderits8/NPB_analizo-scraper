<?php

declare(strict_types=1);

namespace App\Scraper\Stats\Extractor;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * ScoreboardExtractor
 * -------------------
 * 役割:
 *   /game/{id}/top のスコアボード (#async-inning > #ing_brd) から
 *   1〜N回の得点、合計(R/H/E)、打席ページのリンクを抽出し、
 *   home/away に正規化して返す。
 *
 * 入力:
 *   - $root  : パース済みの $root を受け取り
 *   - $meta? : ['home_team_raw'=>?, 'away_team_raw'=>?]（あればside判定に利用）
 *
 * 戻り値:
 * [
 *   'home' => [
 *     'team_raw' => '日本ハム',
 *     'team_code' => 23,
 *     'innings'  => [1=>0,2=>0,...,9=>null], // 'X' は null
 *     'links'    => [1=>'/npb/game/...score?index=...', ... , 9=>null],
 *     'totals'   => ['R'=>12, 'H'=>12, 'E'=>0],
 *   ],
 *   'away' => [
 *     'team_raw' => '西武',
 *     'team_code' => 24,
 *     'innings'  => [1=>0,2=>0,...,9=>2],
 *     'links'    => [...],
 *     'totals'   => ['R'=>5, 'H'=>11, 'E'=>2],
 *   ],
 *   'innings_count' => 9
 * ]
 *
 * 仕様メモ:
 *   - 1軍: チーム名が <a>、ファーム: <span>（両対応）
 *   - X は数値化せず null とする（ホーム最終攻撃省略の意味合い）
 *   - inning ヘッダは 1..N を動的に検出（延長対応）
 *   - サイド判定は meta があれば一致優先、なければ「上=AWAY / 下=HOME」
 */
final class ScoreboardExtractor
{
    private string $baseSelector;
    public function __construct(string $baseSelector = '#async-inning')
    {
        $this->baseSelector = $baseSelector;
    }

    public function extract(Crawler $root, array $meta = []): array
    {
        $scope = $root->filter($this->baseSelector);
        $table = $scope->filter('#ing_brd');
        if ($table->count() === 0) {
            // フォールバック: 構造が微妙に違う場合（classは同等）
            $table = $scope->filter('.bb-gameScoreTable');
        }
        if ($table->count() === 0) {
            return [
                'home' => ['team_raw' => null, 'team_code' => null, 'innings' => [], 'links' => [], 'totals' => ['R' => null, 'H' => null, 'E' => null]],
                'away' => ['team_raw' => null, 'team_code' => null, 'innings' => [], 'links' => [], 'totals' => ['R' => null, 'H' => null, 'E' => null]],
                'innings_count' => 0,
            ];
        }

        // --- ヘッダからイニング列数を抽出（"1","2",... の <th> を数える）
        $inningIdx = [];
        foreach ($table->filter('thead th') as $th) {
            $txt = $this->norm((new Crawler($th))->text(''));
            if ($txt !== '' && ctype_digit($txt)) {
                $inningIdx[] = (int)$txt; // th のインデックスではなく "順番" として数える用途
            }
        }
        // イニング列数
        $nInnings = count($inningIdx) === 0 ? 9 : count($inningIdx); // 既定で9

        // --- 本文2行（上=ビジター、下=ホーム）
        $rows = $table->filter('tbody tr.bb-gameScoreTable__row');
        if ($rows->count() < 2) {
            // 想定外だが、空で返す
            return [
                'home' => ['team_raw' => null, 'team_code' => null, 'innings' => [], 'links' => [], 'totals' => ['R' => null, 'H' => null, 'E' => null]],
                'away' => ['team_raw' => null, 'team_code' => null, 'innings' => [], 'links' => [], 'totals' => ['R' => null, 'H' => null, 'E' => null]],
                'innings_count' => $nInnings,
            ];
        }

        $topRow  = $rows->eq(0);
        $bottomRow = $rows->eq(1);

        $topData    = $this->parseRow($topRow, $nInnings);
        $bottomData = $this->parseRow($bottomRow, $nInnings);

        $topData['team_code'] = $this->buildTeamCode($root, $topData['team_raw'],  "#async-gameBatterStats");
        $bottomData['team_code'] = $this->buildTeamCode($root, $bottomData['team_raw'],  "#async-gameBatterStats");

        $out = [
            'home'          => $bottomData,
            'away'          => $topData,
            'innings_count' => $nInnings,
        ];

        return $out;
    }

    /** 1行分（チーム）を解析 */
    private function parseRow(Crawler $row, int $nInnings): array
    {
        // チーム名（a/span どちらでも）
        $teamTxt = '';
        $teamCell = $row->filter('.bb-gameScoreTable__data--team');
        if ($teamCell->count() > 0) {
            $nameNode = $teamCell->filter('.bb-gameScoreTable__team');
            if ($nameNode->count() > 0) {
                $teamTxt = $this->norm($nameNode->text(''));
            } else {
                // 念のため a 直下も
                $a = $teamCell->filter('a');
                $teamTxt = $this->norm($a->count() ? $a->first()->text('') : $teamCell->text(''));
            }
        }

        // 各イニングの得点とリンク
        $innings = [];
        $links   = [];
        $cells = $row->filter('td.bb-gameScoreTable__data');

        // 先頭の team セルを除き、次の nInnings セルがイニング（その後が totals）
        // 上のセレクタは team セルも含むため、offset=1
        $offset = 1;

        for ($i = 0; $i < $nInnings; $i++) {
            $idx = $offset + $i;
            if ($cells->count() <= $idx) {
                $innings[$i + 1] = null;
                $links[$i + 1] = null;
                continue;
            }
            $cell = $cells->eq($idx);
            // 値は a または p の中にある
            $a = $cell->filter('a.bb-gameScoreTable__score');
            $p = $cell->filter('p.bb-gameScoreTable__score');

            $txt = '';
            $href = null;
            if ($a->count() > 0) {
                $txt  = $this->norm($a->first()->text(''));
                $href = $a->first()->attr('href');
            } elseif ($p->count() > 0) {
                $txt = $this->norm($p->first()->text(''));
            } else {
                $txt = $this->norm($cell->text(''));
            }

            if ($txt === 'X' || $txt === 'x' || $txt === '') {
                $innings[$i + 1] = null; // X は null で保持
            } elseif (ctype_digit($txt)) {
                $innings[$i + 1] = (int)$txt;
            } else {
                // ハイフン等は null 扱い
                $innings[$i + 1] = null;
            }

            $links[$i + 1] = is_string($href) ? $href : null;
        }

        // 合計（計/安/失）— 行末の .bb-gameScoreTable__total を順に読む
        $totR = $totH = $totE = null;
        $totals = $row->filter('td.bb-gameScoreTable__total');
        if ($totals->count() >= 3) {
            $totR = $this->toIntOrNull($totals->eq(0)->text(''));
            $totH = $this->toIntOrNull($totals->eq(1)->text(''));
            $totE = $this->toIntOrNull($totals->eq(2)->text(''));
        }

        return [
            'team_raw'  => $teamTxt !== '' ? $teamTxt : null,
            'team_code' => null,
            'innings'   => $innings,
            'links'     => $links,
            'totals'    => ['R' => $totR, 'H' => $totH, 'E' => $totE],
        ];
    }

    /**
     * スコア小表からチームコードを返す
     * 
     * チーム名で照合して、チームコードを返す
     * @param Crawler $root ルートノード
     * @param ?string $teamRaw チーム名
     * @param string $baseSelector スコア小表のセレクタ
     * @return ?int チームコード (npbTeam23 の 23 を取り出す(Yahoo!のteam固有のコード))
     */
    private function buildTeamCode(Crawler $root, ?string $teamRaw, string $baseSelector = '#async-gameBatterStats'): ?int
    {
        if (!is_string($teamRaw) || $teamRaw === '') {
            return null;
        }

        $base = $root->filter($baseSelector);
        $boards = $base->filter('.bb-table--resultScoreBoard table.bb-teamScoreTable tr.bb-teamScoreTable__row');
        foreach ($boards as $row) {
            $tr = new Crawler($row);

            // チーム名で照合して、チームコードを返す
            $th = $tr->filter('.bb-teamScoreTable__head--team');
            if ($th->count() === 0) continue;
            $thText = $this->norm($th->text(''));
            if ($thText === $teamRaw) {
                return $this->extractTeamCodeFromClassAttr($th); // npbTeam23 の 23 を取り出す(Yahoo!のteam固有のコード)
            }
        }
        return null;
    }

    /** table クラス名から npbTeamXX の XX を取り出す */
    private function extractTeamCodeFromClassAttr(Crawler $th): ?int
    {
        $cls = (string)($th->attr('class') ?? '');
        return $this->extractTeamCodeFromClassString($cls);
    }

    private function extractTeamCodeFromClassString(string $cls): ?int
    {
        // 例: "bb-statsTable bb-statsTable--npbTeam23"
        if (preg_match('/npbTeam(\d+)/', $cls, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function toIntOrNull(string $raw): ?int
    {
        $t = $this->norm($raw);
        return ctype_digit($t) ? (int)$t : null;
    }
}
