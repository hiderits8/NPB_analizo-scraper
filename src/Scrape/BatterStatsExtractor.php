<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * BatterStatsExtractor
 * --------------------
 * 対象: /npb/game/{id}/stats 内の「打撃成績」ブロック (#async-gameBatterStats)
 *
 * 仕様:
 * - チームごとに <table.bb-statsTable--npbTeamXX> が1つずつ（ビジター→ホーム）。
 * - 直近の .bb-table--resultScoreBoard（スコア小表）から
 *   npbTeamXX => {side: away|home, team_raw: "..."} を解決。
 * - 各打者行は .bb-statsTable__row（合計は .bb-statsTable__row--total）。
 * - 先発判定: ポジションセルが「(二)」「（二）」のように括弧あり → starter=true。
 * - イニング列: ヘッダ .bb-statsTable__head--inning の数だけ存在（延長対応）。
 * - イニング詳細セル: <div class="bb-statsTable__dataDetail [--hits] [--point]">表記</div>
 *   - --hits があれば is_hit=true
 *   - --point があれば is_point=true（太字＝打点）
 * - 数値列は int に、打率は文字列のまま保持（".277" 等）。
 *
 * 戻り値:
 * [
 *   'away' => [
 *     'team_raw' => '日本ハム',
 *     'innings_count' => 9,
 *     'players' => [
 *       [
 *         'position_text'   => '(右)',     // 生テキスト（normalize後）
 *         'starter'         => true,       // 括弧つき＝先発
 *         'player_name_raw' => '宮崎 一樹',
 *         'player_href'     => '/npb/player/1950193/top',
 *         'player_id'       => 1950193,    // 取れなければ null
 *         'avg' => '.211',
 *         'ab'  => 4, 'r' => 0, 'h' => 1, 'rbi' => 0, 'so' => 0, 'bb' => 0, 'hbp' => 0,
 *         'sh'  => 0, 'sb' => 0, 'e' => 0, 'hr'  => 0,
 *         'innings' => [
 *           1 => [ ['text'=>'左2',     'is_hit'=>true,  'is_point'=>false] ],
 *           2 => [],
 *           3 => [ ['text'=>'中飛',    'is_hit'=>false, 'is_point'=>false] ],
 *           ...
 *         ],
 *       ],
 *       ...
 *     ],
 *     'totals' => ['AB'=>31,'R'=>0,'H'=>5,'RBI'=>0,'SO'=>2,'BB'=>3,'HBP'=>0,'SH'=>1,'SB'=>0,'E'=>0,'HR'=>0],
 *   ],
 *   'home' => [ ... 同様 ... ],
 * ]
 */
final class BatterStatsExtractor
{
    /** ベースセレクタ（デフォルト: #async-gameBatterStats） */
    private string $baseSelector;
    private array $gameMeta;

    public function __construct(string $baseSelector = '#async-gameBatterStats', array $gameMeta)
    {
        $this->baseSelector = $baseSelector;
        $this->gameMeta = $gameMeta;
    }

    /**
     * 抽出本体
     * @param Crawler $root  ページ全体の Crawler
     * @return array
     * away: [
     *   team_raw: チーム名
     *   innings_count: イニング列数
     *   players: 打者行 (array)
     *   totals: チーム成績 (array)
     * ]
     * home: [ ... ]
     */
    public function extract(Crawler $root): array
    {
        $base = $root->filter($this->baseSelector);
        if ($base->count() === 0) {
            return [
                'away' => ['team_raw' => null, 'innings_count' => 0, 'players' => [], 'totals' => $this->emptyTotals()],
                'home' => ['team_raw' => null, 'innings_count' => 0, 'players' => [], 'totals' => $this->emptyTotals()],
            ];
        }

        // チーム別テーブルを走査
        $chunks = []; // チーム別テーブルを走査した結果を格納する配列
        $tables = $base->filter('.bb-blowResultsTable > table.bb-statsTable');
        foreach ($tables as $tblNode) {
            $table = new Crawler($tblNode);

            // チームコードからチーム名を取得
            $teamCode = $this->extractTeamCodeFromClassAttr($table);
            if ($teamCode !== null && isset($this->gameMeta['away']['team_code'], $this->gameMeta['home']['team_code'])) {
                $side = ($teamCode === $this->gameMeta['away']['team_code']) ? 'away' : 'home';
            } else {
                // フォールバック: 打撃成績テーブルの並び順（1つ目=away, 2つ目=home）
                $side = count($chunks) === 0 ? 'away' : 'home';
            }
            $tname = $this->gameMeta[$side]['team_raw'] ?? null; // '日本ハム' など

            // イニング列数（ヘッダの inning 列を数える）
            $inningsCount = $this->countInningsFromHeader($table);

            // プレイヤー行
            $players = [];
            $rows = $table->filter('tbody > tr.bb-statsTable__row')->reduce(
                fn(Crawler $tr) =>
                !$tr->attr('class') || !str_contains($tr->attr('class'), 'bb-statsTable__row--total') // 合計行を除く
            );

            foreach ($rows as $trNode) {
                $players[] = $this->parsePlayerRow(new Crawler($trNode), $inningsCount); // 打者行を解析
            }

            // 合計行 (チーム成績)
            $totalsRow = $table->filter('tbody > tr.bb-statsTable__row--total');
            $totals = $totalsRow->count() ? $this->parseTotalsRow($totalsRow->first()) : $this->emptyTotals();

            $chunks[] = [
                'side'           => $side,          // 判別できない場合は null
                'team_raw'       => $tname,         // チーム名（例: 日本ハム） string
                'innings_count'  => $inningsCount,  // イニング列数（ヘッダの inning 列を数える） int
                'players'        => $players,       // 打者行 (array)
                'totals'         => $totals,        // チーム成績 (array)
            ];
        }

        // side に基づいて格納
        $out = [
            'away' => ['team_raw' => null, 'innings_count' => 0, 'players' => [], 'totals' => $this->emptyTotals()],
            'home' => ['team_raw' => null, 'innings_count' => 0, 'players' => [], 'totals' => $this->emptyTotals()],
        ];

        // side判定されたものを先に置く
        foreach ($chunks as $ch) {
            if ($ch['side'] === 'away') {
                $out['away'] = [
                    'team_raw'      => $ch['team_raw'],      // チーム名（例: 日本ハム） string
                    'innings_count' => $ch['innings_count'], // イニング列数（ヘッダの inning 列を数える） int
                    'players'       => $ch['players'],       // 打者行 (array)
                    'totals'        => $ch['totals'],        // チーム成績 (array)
                ];
            }
            if ($ch['side'] === 'home') {
                $out['home'] = [
                    'team_raw'      => $ch['team_raw'],      // チーム名（例: 日本ハム） string
                    'innings_count' => $ch['innings_count'], // イニング列数（ヘッダの inning 列を数える） int
                    'players'       => $ch['players'],       // 打者行 (array)
                    'totals'        => $ch['totals'],        // チーム成績 (array)
                ];
            }
        }

        return $out;
    }

    // ----------------- helpers -----------------

    /** class 属性からチームコードを抽出 */
    private function extractTeamCodeFromClassAttr(Crawler $table): ?int
    {
        $cls = (string)($table->attr('class') ?? '');
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

    /** ヘッダからイニング列数を数える */
    private function countInningsFromHeader(Crawler $table): int
    {
        $heads = $table->filter('thead th.bb-statsTable__head--inning');
        $n = $heads->count();
        return $n > 0 ? $n : 9;
    }

    /** 打者行を解析 */
    private function parsePlayerRow(Crawler $tr, int $inningsCount): array
    {
        $tds = $tr->filter('td'); // 打者行のtd

        // 0: 位置, 1: 選手, 2: 打率, 3..: 数値列, 以降: イニング
        $posText = $this->norm($this->safeText($tds, 0)); // 位置（例: 右） string
        $starter = $this->isStarter($posText); // 先発かどうか bool

        $playerName = $this->norm($this->safeText($tds, 1)); // 選手名（例: 宮崎 一樹） string
        $a = $tds->eq(1)->filter('a');
        $href = $a->count() ? (string)$a->first()->attr('href') : null; // 選手ページのURL string
        $playerId = $this->extractPlayerId($href); // 選手ID（例: 1950193） int

        $avg = $this->norm($this->safeText($tds, 2)); // 試合終了後のシーズン打率（例: .277） string

        // 試合内成績 (打数、得点、安打、打点、三振、四球、死球、犠打、盗塁、失策、本塁打)
        $ab  = $this->toIntOrZero($this->safeText($tds, 3)); // 打数（例: 4） int
        $r   = $this->toIntOrZero($this->safeText($tds, 4)); // 得点（例: 0） int
        $h   = $this->toIntOrZero($this->safeText($tds, 5)); // 安打（例: 1） int
        $rbi = $this->toIntOrZero($this->safeText($tds, 6)); // 打点（例: 0） int
        $so  = $this->toIntOrZero($this->safeText($tds, 7)); // 三振（例: 2） int
        $bb  = $this->toIntOrZero($this->safeText($tds, 8)); // 四球（例: 3） int
        $hbp = $this->toIntOrZero($this->safeText($tds, 9)); // 死球（例: 0） int
        $sh  = $this->toIntOrZero($this->safeText($tds, 10)); // 犠打（例: 1） int
        $sb  = $this->toIntOrZero($this->safeText($tds, 11)); // 盗塁（例: 0） int
        $e   = $this->toIntOrZero($this->safeText($tds, 12)); // 失策（例: 0） int
        $hr  = $this->toIntOrZero($this->safeText($tds, 13)); // 本塁打（例: 0） int

        // イニング詳細（14列目以降）
        $innings = [];
        for ($i = 0; $i < $inningsCount; $i++) {
            $tdIndex = 14 + $i;
            $cell = $tds->count() > $tdIndex ? $tds->eq($tdIndex) : null;
            $innings[$i + 1] = $cell ? $this->parseInningCell($cell) : [];
        }

        return [
            'position_text'   => $posText,
            'starter'         => $starter,
            'player_name_raw' => $playerName,
            'player_href'     => $href,
            'player_id'       => $playerId,
            'avg' => $avg,
            'ab'  => $ab,
            'r' => $r,
            'h' => $h,
            'rbi' => $rbi,
            'so' => $so,
            'bb'  => $bb,
            'hbp' => $hbp,
            'sh' => $sh,
            'sb' => $sb,
            'e' => $e,
            'hr' => $hr,
            'innings' => $innings,
        ];
    }

    /** 合計行を解析 */
    private function parseTotalsRow(Crawler $tr): array
    {
        $tds = $tr->filter('td');
        // 0: 打率空, 1: AB, 2: R, 3: H, 4: RBI, 5: SO, 6: BB, 7: HBP, 8: SH, 9: SB, 10: E, 11: HR
        return [
            'AB'  => $this->toIntOrZero($this->safeText($tds, 1)),
            'R'   => $this->toIntOrZero($this->safeText($tds, 2)),
            'H'   => $this->toIntOrZero($this->safeText($tds, 3)),
            'RBI' => $this->toIntOrZero($this->safeText($tds, 4)),
            'SO'  => $this->toIntOrZero($this->safeText($tds, 5)),
            'BB'  => $this->toIntOrZero($this->safeText($tds, 6)),
            'HBP' => $this->toIntOrZero($this->safeText($tds, 7)),
            'SH'  => $this->toIntOrZero($this->safeText($tds, 8)),
            'SB'  => $this->toIntOrZero($this->safeText($tds, 9)),
            'E'   => $this->toIntOrZero($this->safeText($tds, 10)),
            'HR'  => $this->toIntOrZero($this->safeText($tds, 11)),
        ];
    }

    /** イニング詳細セル → [ {text, is_hit, is_point}, ... ] */
    private function parseInningCell(Crawler $td): array
    {
        $out = [];
        $divs = $td->filter('.bb-statsTable__dataDetail');
        if ($divs->count() === 0) {
            // 空欄など
            return [];
        }
        foreach ($divs as $d) {
            $node = new Crawler($d);
            $cls = (string)($node->attr('class') ?? '');
            $text = $this->norm($node->text(''));
            // 表記（例）: 「左２」「三ゴロ」「空三振」「四球」など
            // 注意点: 「右２」などの算用数字は normalize 済。必要なら「右2」に統一。
            $text = $this->normalizeBattedBall($text);

            $out[] = [
                'text'      => $text, // 打球結果表記（例: 左２） string
                'is_hit'    => str_contains($cls, 'bb-statsTable__dataDetail--hits'), // bool
                'is_point'  => str_contains($cls, 'bb-statsTable__dataDetail--point'), // bool
            ];
        }
        return $out;
    }

    /** 「（）」で括られていれば先発 */
    private function isStarter(string $pos): bool
    {
        $p = trim($pos);
        // 全角/半角の括弧両対応
        if ($p === '') return false;
        $first = mb_substr($p, 0, 1);
        $last  = mb_substr($p, -1);
        return ($first === '(' && $last === ')') || ($first === '（' && $last === '）');
    }

    /** 打球結果表記の整形（必要ならここで細かく正規化） */
    private function normalizeBattedBall(string $s): string
    {
        // 例: 「左２」→「左2」などにしたければここで置換
        // 今回は TextNormalizer で全角→半角等は済んでいる前提で、そのまま返す。
        return $s;
    }

    /** 選手ページのURLから選手IDを抽出 */
    private function extractPlayerId(?string $href): ?int
    {
        if (!is_string($href)) return null;
        if (preg_match('#/npb/player/(\d+)/#', $href, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function safeText(Crawler $nodes, int $idx): string
    {
        return $nodes->count() > $idx ? $nodes->eq($idx)->text('') : '';
    }

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function toIntOrZero(string $raw): int
    {
        $t = $this->norm($raw);
        return ctype_digit($t) ? (int)$t : 0;
    }

    private function emptyTotals(): array
    {
        return ['AB' => 0, 'R' => 0, 'H' => 0, 'RBI' => 0, 'SO' => 0, 'BB' => 0, 'HBP' => 0, 'SH' => 0, 'SB' => 0, 'E' => 0, 'HR' => 0];
    }
}
