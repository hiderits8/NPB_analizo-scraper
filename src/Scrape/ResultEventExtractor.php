<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * ResultEventExtractor
 * --------------------
 * 対象: /npb game /score?index=... の「結果」ブロック: <div id="result"> 内
 *
 * 例:
 * <div id="result">
 *   <span class="red">中安打 ＋1点</span>
 *   <em>140km/h カットボール、ランナー1,2塁</em>
 * </div>
 *
 * <div id="result">
 *   <span>【代走】外崎→長谷川</span>
 *   <em>【盗塁成功率】.667(12-8)</em>
 * </div>
 *
 * <div id="result">
 *   <span>【守備】（中）五十幡、淺間：（中）→（左）、矢澤：（走）→（右）</span>
 * </div>
 *
 * <div id="result">
 *   <span>試合終了</span>
 *   <em>17回戦：西武 9勝8敗0分</em>
 * </div>
 *
 * - pbp の「イベント種別」を抽出する
 * - 投球情報（球速/球種など）や、ランナー状況はここでは解析しない（別セクションで取得）。
 *   -> raw テキストは保持（後工程で必要なら解析できるように）。
 *
 * 戻り値（例）:
 * [
 *   'event_types'   => ['play', 'sub_runner', ...],  // 複数対応
 *   'primary_raw'   => '中安打 ＋1点',
 *   'detail_raw'    => '140km/h カットボール、ランナー1,2塁',  // あれば
 *   'seq_in_pa'    => 3,               // その打席内での通算イベント番号（0から始まる連番） 0=>打席開始前, 1=>1球目, 2=>2球目, ...
 *   'is_hit'       => true,            // <span class="red"> なら true
 *   'runs_add'     => 1,               // 表記に「＋n点」を含めば数値、なければ 0
 *   'substitutions' => [               // 複数対応
 *       [
 *         'kind'  => 'runner'|'batter'|'pitcher'|'defense',
 *         'items' => [
 *            // 代走/代打/投手交代: A→B
 *            ['from' => '外崎', 'to' => '長谷川'],
 *            // 守備: 出場 or ポジション変更
 *            //   type: 'enter' | 'move'
 *            //   enter: （中）五十幡
 *            //   move : 淺間：（中）→（左）
 *            ['type'=>'enter','name'=>'五十幡','to_pos'=>'中'],
 *            ['type'=>'move','name'=>'淺間','from_pos'=>'中','to_pos'=>'左'],
 *         ],
 *       ],
 *   ],
 *   'steals'        => [               // 複数対応
 *       [
 *         'type'  => 'success'|'failure',
 *         'runner' => '外崎',
 *       ],
 *   ],
 * ]
 */
final class ResultEventExtractor
{
    public function __construct(private string $rootSelector = '#result') {}

    /**
     * @param Crawler $root
     * @return array<string, mixed>
     */
    public function extract(Crawler $root): array
    {
        $box = $root->filter($this->rootSelector);
        if ($box->count() === 0) {
            return $this->empty();
        }

        // primary (<span>) と detail (<em>)
        $span = $box->filter('span')->first();
        $em   = $box->filter('em')->first();

        $spanText = $this->norm($span->count() ? $span->text('') : '');
        $spanCls  = $span->count() ? (string)($span->attr('class') ?? '') : '';
        $emText   = $this->norm($em->count() ? $em->text('') : '');

        // 複数イベント種別に対応
        $eventTypes = $this->detectEventTypes($spanText, $emText);

        // その打席内での通算イベント番号（0から始まる連番）を検出
        $seqInPa = $this->detectSeqInPa($root);

        // is_hit は <span class="red"> の有無のみで判定
        $isHit = str_contains($spanCls, 'red');

        // 「＋n点」表記から加点数
        $runsAdd = $this->extractRunsAdd($spanText);

        // 交代/守備の詳細（複数可）
        $subs = [];
        if (in_array('sub_runner', $eventTypes, true)) {
            $content = $this->extractGroupContent($spanText, '代走');
            $items   = $this->parseArrowPairs($content ?? '');
            if ($items) {
                $subs[] = ['kind' => 'runner', 'items' => $items];
            }
        }
        if (in_array('sub_batter', $eventTypes, true)) {
            $content = $this->extractGroupContent($spanText, '代打');
            $items   = $this->parseArrowPairs($content ?? '');
            if ($items) {
                $subs[] = ['kind' => 'batter', 'items' => $items];
            }
        }
        if (in_array('sub_pitcher', $eventTypes, true)) {
            $content = $this->extractGroupContent($spanText, '継投');
            $items   = $this->parseArrowPairs($content ?? '');
            if ($items) {
                $subs[] = ['kind' => 'pitcher', 'items' => $items];
            }
        }
        if (in_array('defense', $eventTypes, true)) {
            $content = $this->extractGroupContent($spanText, '守備');
            $items   = $this->parseDefenseItems($content ?? '');
            if ($items) {
                $subs[] = ['kind' => 'defense', 'items' => $items];
            }
        }

        $steals = [];
        if (in_array('steal_success', $eventTypes, true)) {
            $content = preg_match('/盗塁成功（(.+?)）/', $emText, $m);
            if ($m2 = preg_split('/、/u', $m[1])) {
                foreach ($m2 as $m3) {
                    $steals[] = ['type' => 'success', 'runner' => $m3];
                }
            } else {
                $steals[] = ['type' => 'success', 'runner' => $m[1]];
            }
        }
        if (in_array('steal_failure', $eventTypes, true)) {
            $content = preg_match('/盗塁失敗（(.+?)）/', $emText, $m);
            if ($m2 = preg_split('/、/u', $m[1])) {
                foreach ($m2 as $m3) {
                    $steals[] = ['type' => 'failure', 'runner' => $m3];
                }
            } else {
                $steals[] = ['type' => 'failure', 'runner' => $m[1]];
            }
        }

        return [
            'event_types'     => $eventTypes,
            'primary_raw'     => $spanText !== '' ? $spanText : null,
            'detail_raw'      => $emText   !== '' ? $emText   : null,
            'seq_in_pa'       => $seqInPa,
            'is_hit'          => $isHit,
            'runs_add'        => $runsAdd,
            'substitutions'   => $subs ?: null,
            'steals'          => $steals ?: null,
        ];
    }

    // ---------------- helpers ----------------

    private function empty(): array
    {
        return [
            'event_types'   => ['announcement'],
            'primary_raw'   => null,
            'detail_raw'    => null,
            'seq_in_pa'     => 0,
            'is_hit'        => false,
            'runs_add'      => 0,
            'substitutions' => null,
            'steals'        => null,
        ];
    }

    /**
     * イベント種別判定（複数）
     * 例: "【継投】…、【守備】…" -> ['sub_pitcher','defense']
     */
    private function detectEventTypes(string $primary, string $emText): array
    {
        $primary = $this->norm($primary);
        if ($primary === '') {
            return ['announcement'];
        }

        // 明示ワード優先（単独扱い）
        if (mb_strpos($primary, '試合終了') !== false) {
            return ['game_end'];
        }

        $types = [];
        if (mb_strpos($primary, '【代走】') !== false) {
            $types[] = 'sub_runner';
        }
        if (mb_strpos($primary, '【代打】') !== false) {
            $types[] = 'sub_batter';
        }
        if (mb_strpos($primary, '【継投】') !== false) {
            $types[] = 'sub_pitcher';
        }
        if (mb_strpos($primary, '【守備】') !== false) {
            $types[] = 'defense';
        }
        if (mb_strpos($primary, '敬遠（申告敬遠）') !== false) {
            $types[] = 'intentional_base_on_balls';
        }

        $ems = preg_split('/、/u', $emText);
        foreach ($ems as $em) {
            if ($this->StealSuccess($em)) {
                $types[] = 'steal_success';
            } elseif ($this->StealFailure($em)) {
                $types[] = 'steal_failure';
            }
        }

        // 何も該当しなければ通常プレイ
        if ($types === []) {
            return ['play'];
        }
        return $types;
    }

    private function StealSuccess(string $emText): bool
    {
        return preg_match('/盗塁成功/u', $emText) === 1;
    }

    private function StealFailure(string $emText): bool
    {
        return preg_match('/盗塁失敗/u', $emText) === 1;
    }

    /**
     * "【ラベル】..." から当該ラベルの内容部分だけを切り出す（次の "【" 直前まで）
     */
    private function extractGroupContent(string $str, string $label): ?string
    {
        $pattern = '/【' . preg_quote($label, '/') . '】([^【]+)/u';
        if (preg_match($pattern, $str, $m)) {
            $content = rtrim($m[1], "、, 　\t\n\r");
            return $this->norm($content);
        }
        return null;
    }

    /** 「＋n点」抽出（なければ 0） */
    private function extractRunsAdd(string $primary): int
    {
        if (preg_match('/＋\s*([0-9０-９]+)\s*点/u', $primary, $m)) {
            $n = $this->toAsciiDigits($m[1]);
            return ctype_digit($n) ? (int)$n : 0;
        }
        return 0;
    }

    private function parseArrowPairs(string $primary): array
    {
        $s = preg_replace('/^【.*?】/u', '', $primary) ?? $primary;
        $s = $this->norm($s);

        $items = [];
        $chunks = preg_split('/、/u', $s);
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            if (preg_match('/(.+?)\s*→\s*(.+)/u', $chunk, $m)) {
                $items[] = ['from' => trim($m[1]), 'to' => trim($m[2])];
            }
        }
        return $items;
    }

    /**
     * 守備変更の内訳を抽出
     *
     * 形式は混在しうる:
     *  - 出場:      （中）五十幡
     *  - 位置変更:  淺間：（中）→（左）
     *  - ラン用:    矢澤：（走）→（右）
     */
    private function parseDefenseItems(string $primary): array
    {
        $s = $this->norm($primary);

        $items = [];
        foreach (preg_split('/、/u', $s) as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;

            // パターン1: "（中）五十幡" -> enter
            if (preg_match('/^（(.+?)）\s*(.+)$/u', $seg, $m)) {
                $items[] = [
                    'type'   => 'enter',
                    'name'   => trim($m[2]),
                    'to_pos' => trim($m[1]),
                ];
                continue;
            }

            // パターン2: "淺間：（中）→（左）" -> move
            if (preg_match('/^(.+?)\s*[：:]\s*（(.+?)）\s*→\s*（(.+?)）$/u', $seg, $m)) {
                $items[] = [
                    'type'     => 'move',
                    'name'     => trim($m[1]),
                    'from_pos' => trim($m[2]),
                    'to_pos'   => trim($m[3]),
                ];
                continue;
            }

            // それ以外は raw を保持（将来拡張）
            $items[] = ['type' => 'raw', 'text' => $seg];
        }

        return $items;
    }

    /**
     * その打席内での通算イベント番号（0から始まる連番）を検出
     * 例: 0=>打席開始前, 1=>1球目, 2=>2球目, ...
     */
    private function detectSeqInPa(Crawler $root): int
    {
        $section = $root->filter('#pitchesDetail')->filter('section')->reduce(function (Crawler $node, $i) {
            return $node->attr("id") !== "gm_mema" && $node->attr("id") !== "gm_memh";
        });
        // id=gm_rsltは投手・打者ペアテーブルで確定なので、以降はそれ以外のテーブルを読む
        $detailTables = $section->filter('table.bb-splitsTable')->reduce(function (Crawler $node, $i) {
            return (string)$node->attr('id') !== 'gm_rslt';
        });

        if ($detailTables->count() === 1) {
            // セクションはあるが、空のコーステーブル1つのみで、投球明細が無い場合（= 継投/回開始/試合終了）ケース
            return 0;
        }

        $detail     = $detailTables->last();
        $lastRow    = $detail->filter('tbody > tr')->last();
        $icon       = $lastRow->filter('.bb-icon__ballCircle');
        $seqInPa    = $this->toInt($icon->text(''));
        return $seqInPa;
    }

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function toInt(string $s): int
    {
        $s = trim($s);
        if ($s === '') return 0;
        // 全角→半角
        $s = strtr($s, ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);
        // 数字抽出
        if (preg_match('/\d+/', $s, $m)) return (int)$m[0];
        return 0;
    }

    private function toAsciiDigits(string $s): string
    {
        // 全角数字→半角
        $map = [
            '０' => '0',
            '１' => '1',
            '２' => '2',
            '３' => '3',
            '４' => '4',
            '５' => '5',
            '６' => '6',
            '７' => '7',
            '８' => '8',
            '９' => '9'
        ];
        return strtr($s, $map);
    }
}
