<?php

declare(strict_types=1);

namespace App\Scraper\Score\Extractor;

use Symfony\Component\DomCrawler\Crawler;

/**
 * PitchCourseChartExtractor
 * -------------------------
 * 対象: /score?index=... の「詳しい投球内容」テーブル（コース図）
 *
 * 構造例:
 * <table class="bb-splitsTable">
 *   <tbody>
 *     <tr>
 *       <td class="bb-splitsTable__data bb-splitsTable__allocationChartBg--rightBatter">
 *         <div class="bb-allocationChart">
 *           <span style="top:146px; left:22px;" class="bb-icon__ballCircle bb-icon__ballCircle--ball1">
 *             <span class="bb-icon__number">1</span>
 *           </span>
 *           ...
 *         </div>
 *       </td>
 *     </tr>
 *   </tbody>
 * </table>
 *
 * 取得方針:
 * - #pitchesDetail セクション内の「詳しい投球内容」テーブルから、
 *   .bb-allocationChart 内の span.bb-icon__ballCircle を列挙。
 * - 各 span から:
 *   - seq_in_pa : 内部の .bb-icon__number の数値
 *   - bucket    : クラス 'bb-icon__ballCircle--ballN' の N(1..5)
 *                 1=スト/ファウル, 2=ボール, 3=アウト(非三振), 4=出塁(四球以外), 5=犠打/犠飛
 *   - top_px/left_px : style="top:..., left:..." の px 数値（float）
 * - 打者の左右は、親 <td> のクラス:
 *   '...allocationChartBg--leftBatter' / '...--rightBatter' から判定
 *
 * 返り値:
 * [
 *   'exists'           => bool,   // テーブルが存在し .bb-allocationChart に1球以上あれば true
 *   'batter_box_side'  => 'left'|'right'|null, // 図の背景クラスから判定。なければ null
 *   'pitches' => [
 *     [
 *       'seq_in_pa'   => int,     // 打席内球順
 *       'bucket'      => int,     // 1..5
 *       'bucket_label'=> string,  // 説明文字（固定マップ）
 *       'top_px'      => float,   // px
 *       'left_px'     => float,   // px
 *     ],
 *     ...
 *   ],
 * ]
 *
 * 備考:
 * - 継投/試合終了などで .bb-allocationChart が空の場合は exists=false / pitches=[] を返す。
 * - 同一打席でページが複数ある（重複）ケースは上位で統合してください
 *   （seq_in_pa 昇順ソートは本クラス内で実施します）。
 */
final class PitchCourseChartExtractor
{
    public function __construct(private string $rootSelector = '#pitchesDetail') {}

    public function extract(Crawler $root): array
    {
        $empty = [
            'exists'          => false,
            'batter_box_side' => null,
            'pitches'         => [],
        ];

        $sec = $root->filter($this->rootSelector);
        if ($sec->count() === 0) {
            return $empty;
        }

        // セクション内の「詳しい投球内容」テーブルにある .bb-allocationChart を取得
        $chart = $sec->filter('.bb-allocationChart')->first();
        if ($chart->count() === 0) {
            return $empty;
        }

        // 親 <td> の class から左右打者背景を判定
        $td = $chart->ancestors()->filter('td')->first();
        $side = null;
        if ($td->count()) {
            $tdCls = (string)($td->attr('class') ?? '');
            if (str_contains($tdCls, 'allocationChartBg--leftBatter')) {
                $side = 'left';
            } elseif (str_contains($tdCls, 'allocationChartBg--rightBatter')) {
                $side = 'right';
            }
        }

        $rows = [];
        $chart->filter('span.bb-icon__ballCircle')->each(function (Crawler $node) use (&$rows) {
            $cls = (string)($node->attr('class') ?? '');
            $style = (string)($node->attr('style') ?? '');

            $bucket = $this->detectBucket($cls);      // 1..5 or null
            $left   = $this->extractPx($style, 'left');
            $top    = $this->extractPx($style, 'top');

            // 球番号（打席内順）
            $seqNode = $node->filter('.bb-icon__number')->first();
            $seqText = $seqNode->count() ? trim($seqNode->text('')) : '';
            $seq     = ctype_digit($seqText) ? (int)$seqText : null;

            if ($seq === null || $bucket === null) {
                // 必須情報が欠ける場合はスキップ
                return;
            }

            $rows[] = [
                'seq_in_pa'    => $seq,
                'bucket'       => $bucket,
                'bucket_label' => self::BUCKET_LABELS[$bucket] ?? 'unknown',
                'top_px'       => $top,
                'left_px'      => $left,
            ];
        });

        if (!$rows) {
            return $empty;
        }

        // seq_in_pa 昇順に整列（重複ページ統合は上位層で）
        usort($rows, fn($a, $b) => $a['seq_in_pa'] <=> $b['seq_in_pa']);

        return [
            'exists'          => true,
            'batter_box_side' => $side,
            'pitches'         => $rows,
        ];
    }

    // ---------------- helpers ----------------

    private const BUCKET_LABELS = [
        1 => 'strike_or_foul',        // ストライク・ファウル（三振含む）
        2 => 'ball',                  // ボール（四球含む）
        3 => 'out_non_k',             // アウト（三振以外）
        4 => 'on_base_non_bb',        // 出塁（四球以外）
        5 => 'sac_bunt_or_fly',       // 犠打、犠飛
    ];

    /** class="... bb-icon__ballCircle--ballN ..." の N を抽出 */
    private function detectBucket(string $class): ?int
    {
        if (preg_match('/bb-icon__ballCircle--ball([1-5])/u', $class, $m)) {
            return (int)$m[1];
        }
        return null;
        // 将来: ball6 などが増えたらマップ拡張
    }

    /** style="top:xxpx; left:yypx;" から px 数値を抽出（float） */
    private function extractPx(string $style, string $prop): float
    {
        // 例: "top:43.199999999999996px; left:33.12px;"
        $re = sprintf('/%s\s*:\s*([0-9.]+)px/u', preg_quote($prop, '/'));
        if (preg_match($re, $style, $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }
}
