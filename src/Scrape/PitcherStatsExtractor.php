<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;

/**
 * PitcherStatsExtractor
 * ---------------------
 * /npb/game/{id}/stats 内の「投手成績」テーブル(#async-gamePitcherStats)を抽出。
 *
 * 戻り値の例:
 * [
 *   'away' => [
 *     'team_raw' => '広島東洋カープ',
 *     'team_code' => 6,
 *     'players' => [
 *       [
 *         'state' => '敗',                // 勝, 敗, H, S など。無ければ ''。
 *         'player_name_raw' => '森 翔平',
 *         'player_href' => '/npb/player/2103788/top',
 *         'player_id' => 2103788,         // 数字が取れなければ null
 *         'era' => '3.47',                // 文字列のまま保持（DBでは使わないならETL側で捨ててOK）
 *         'ip_text' => '5',               // 表示そのまま
 *         'ip_outs' => 15,                // 投球回をアウト数に変換（5.2 => 17 等）
 *         'np' => 79,                     // 投球数
 *         'bf' => 21,                     // 打者
 *         'h' => 5, 'hr' => 1, 'so' => 4, 'bb' => 0, 'hbp' => 1, 'bk' => 0, 'r' => 3, 'er' => 3,
 *       ],
 *       ...
 *     ],
 *   ],
 *   'home' => [ ... ],
 * ]
 */
final class PitcherStatsExtractor
{
    private string $rootSelector;
    private array $gameMeta;
    public function __construct(
        string $rootSelector = '#async-gamePitcherStats',
        array $gameMeta
    ) {
        $this->rootSelector = $rootSelector;
        $this->gameMeta = $gameMeta;
    }

    /**
     * @param Crawler $root ページ全体の Crawler を渡す（セクション探索は内部で行う）
     * @param array   $meta  互換用（未使用）
     * @return array
     * away: [
     *   team_raw: チーム名
     *   team_code: チームコード (Yahoo!のteam固有のコード)
     *   players: 投手行 (array)
     *     state: 勝/敗/H/S
     *     player_name_raw: 選手名
     *     player_href: 選手ページのURL
     *     player_id: 選手ID (Yahoo!の選手固有のコード)
     *     era: 防御率
     *     ip_text: 投球回
     *     ip_outs: 投球回をアウト数に変換
     *     np: 投球数
     *     bf: 打者数
     *     h: 被安打数
     *     hr: 被本塁打球数
     *     so: 奪三振数
     *     bb: 与四球数
     *     hbp: 与死球数
     *     bk: ボーク数
     *     r: 失点数
     *     er: 自責点数
     * ]
     * home: [ ... ]
     */
    public function extract(Crawler $root, array $meta = []): array
    {
        $container = $root->filter($this->rootSelector);
        if ($container->count() === 0) {
            return $this->emptyResult();
        }

        // 投手成績テーブルを抽出
        $sections = $container->filter('.bb-modCommon03');
        if ($sections->count() === 0) {
            return $this->emptyResult();
        }

        // Yahoo!の並びは概ね「ビジター→ホーム」だが、確実にするため teamMeta で side を解決。
        $buckets = [
            'away' => ['team_raw' => null, 'team_code' => null, 'players' => []],
            'home' => ['team_raw' => null, 'team_code' => null, 'players' => []],
        ];

        // 投手成績テーブルを抽出
        $sections->each(function (Crawler $sec) use (&$buckets) {
            $teamName = trim($sec->filter('header .bb-head02__title')->text(''));
            $table = $sec->filter('table.bb-scoreTable');
            if ($table->count() === 0) {
                return;
            }
            $teamCode = $this->extractTeamCodeFromClassAttr($table->attr('class') ?? '');

            // side 決定（teamCode 優先、なければ teamName 照合、最後に順序フォールバック）
            $side = $this->resolveSide($teamCode, $teamName);

            // 選手行を抽出
            $players = [];
            $table->filter('tbody tr.bb-scoreTable__row')->each(function (Crawler $tr) use (&$players) {
                $players[] = $this->parsePitcherRow($tr);
            });

            // 既に side が埋まっている場合は後勝ちしないよう初回のみ採用
            if ($buckets[$side]['team_raw'] === null) {
                $buckets[$side]['team_raw'] = $teamName ?: null;
                $buckets[$side]['team_code'] = $teamCode;
            }
            $buckets[$side]['players'] = array_merge($buckets[$side]['players'], $players);
        });

        return $buckets;
    }

    private function emptyResult(): array
    {
        return [
            'away' => ['team_raw' => null, 'team_code' => null, 'players' => []],
            'home' => ['team_raw' => null, 'team_code' => null, 'players' => []],
        ];
    }

    /**
     * 投手行を解析
     * 
     * @param Crawler $tr 投手行の Crawler を渡す
     * @return array
     * state: 勝/敗/H/S
     * player_name_raw: 選手名
     * player_href: 選手ページのURL
     * player_id: 選手ID (Yahoo!の選手固有のコード)
     * era: 防御率
     * ip_text: 投球回
     * ip_outs: 投球回をアウト数に変換
     * np: 投球数
     * bf: 打者数
     * h: 被安打数
     * hr: 被本塁打球数
     * so: 奪三振数
     * bb: 与四球数
     * hbp: 与死球数
     * bk: ボーク数
     * r: 失点数
     * er: 自責点数
     */
    private function parsePitcherRow(Crawler $tr): array
    {
        // state（勝/敗/H/S）は最左セル
        $state = trim($tr->filter('.bb-scoreTable__data--state')->text(''));

        // 選手名 & href
        $playerCell = $tr->filter('.bb-scoreTable__data--player a');
        $playerName = trim($playerCell->text(''));
        $href = $playerCell->attr('href') ?? null;
        $playerId = $this->extractPlayerId($href);

        // 数値セルは .bb-scoreTable__data--score > p.bb-scoreTable__dataLabel に入っている
        $nums = [];
        $tr->filter('.bb-scoreTable__data--score > .bb-scoreTable__dataLabel')->each(function (Crawler $p) use (&$nums) {
            $nums[] = trim($p->text(''));
        });

        // 列順: 防御率, 投球回, 投球数, 打者, 被安打, 被本塁打, 奪三振, 与四球, 与死球, ボーク, 失点, 自責点
        // 不足時は null 補完
        $nums = array_pad($nums, 12, null);

        [$era, $ipText, $np, $bf, $h, $hr, $so, $bb, $hbp, $bk, $r, $er] = $nums;

        return [
            'state'           => $state,                  // 勝/敗/H/S
            'player_name_raw' => $playerName,             // 選手名
            'player_href'     => $href,                   // 選手ページのURL
            'player_id'       => $playerId,               // 選手ID (Yahoo!の選手固有のコード)

            'era'             => $this->nz($era),         // 防御率
            'ip_text'         => $this->nz($ipText),      // 投球回
            'ip_outs'         => $this->toOuts($ipText),  // 投球回をアウト数に変換

            'np'              => $this->toInt($np),       // 投球数
            'bf'              => $this->toInt($bf),       // 打者数
            'h'               => $this->toInt($h),        // 被安打数
            'hr'              => $this->toInt($hr),       // 被本塁打球数
            'so'              => $this->toInt($so),       // 奪三振数
            'bb'              => $this->toInt($bb),       // 与四球数
            'hbp'             => $this->toInt($hbp),      // 与死球数
            'bk'              => $this->toInt($bk),       // ボーク数
            'r'               => $this->toInt($r),        // 失点数
            'er'              => $this->toInt($er),       // 自責点数
        ];
    }

    /**
     * 文字列が null または空文字列の場合は null を返す
     */
    private function nz(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);
        if ($s === '' || $s === '-' || $s === '－') return null;
        return $s;
    }

    private function toInt(?string $s): ?int
    {
        $s = $this->nz($s);
        if ($s === null) return null;
        // 余分な記号除去
        $s = preg_replace('/[^\d\-]/u', '', $s) ?? $s;
        if ($s === '' || $s === '-') return null;
        return (int)$s;
    }

    /**
     * 投球回 "5", "5.1", "0.2" をアウト数に変換。
     * 例: 5   -> 15outs, 5.1 -> 16outs, 5.2 -> 17outs
     */
    private function toOuts(?string $ip): ?int
    {
        $ip = $this->nz($ip);
        if ($ip === null) return null;

        // "5.2" のような表現に対応
        if (preg_match('/^(\d+)(?:\.(\d))?$/', $ip, $m)) {
            $whole = (int)$m[1];
            $frac  = isset($m[2]) ? (int)$m[2] : 0;
            $add = ($frac === 1) ? 1 : (($frac === 2) ? 2 : 0);
            return $whole * 3 + $add;
        }
        // 念のため "5 1/3" のような表現も許容
        if (preg_match('/^(\d+)\s*(?:1\/3|2\/3)$/', $ip)) {
            [$w, $f] = explode(' ', str_replace(['1/3', '2/3'], ['.1', '.2'], $ip));
            return $this->toOuts(trim($w . $f));
        }
        // それ以外は不明
        return null;
    }

    private function extractPlayerId(?string $href): ?int
    {
        if (!$href) return null;
        if (preg_match('#/npb/player/(\d+)/top#', $href, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * .bb-scoreTable--npbTeam6 のような class から 6 を取り出す
     */
    private function extractTeamCodeFromClassAttr(string $classAttr): ?int
    {
        if (preg_match('/npbTeam(\d+)/', $classAttr, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * サイド解決:
     * 1) npbTeamCode が scoreBoard 由来の map に居ればそれを採用
     * 2) teamName が map に居れば採用
     * 3) どちらも無ければ away→home の順に埋めるフォールバック
     *
     * ここでは 3) は呼び出し側（ループ順）に依存するので、
     * teamName が既に buckets に存在しているかで簡易制御するのも可。
     */
    private function resolveSide(?int $npbTeamCode, string $teamName): string
    {
        if ($npbTeamCode !== null) {
            return $npbTeamCode === $this->gameMeta['away']['team_code'] ? 'away' : 'home';
        }
        if ($teamName !== '') {
            return $teamName === $this->gameMeta['away']['team_raw'] ? 'away' : 'home';
        }
        // フォールバック: 未確定のときは away 優先、2回目以降は home
        // ただしここではコンテキストが無いのでデフォルトは away にする。
        return 'away';
    }
}
