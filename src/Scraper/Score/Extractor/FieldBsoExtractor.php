<?php

declare(strict_types=1);

namespace App\Scraper\Score\Extractor;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * FieldBsoExtractor
 * -----------------
 * 対象: /npb/game/{id}/score 内の「イニング/得点/BSO」を表示するブロック (#async-fieldBso)
 *
 * 例のDOM:
 * <div id="async-fieldBso">
 *   <div id="sbo">
 *     <h4 class="live"><em>7回表</em> ...</h4>
 *     <div class="score">
 *       <table> 2行 (上=片方のチーム, 下=もう片方) </table>
 *       <div class="sbo">
 *         <p class="b"><em>B</em><b>●●</b></p>
 *         <p class="s"><em>S</em><b>●●●</b></p>
 *         <p class="o"><em>O</em><b>●●●</b></p>
 *       </div>
 *     </div>
 *   </div>
 * </div>
 *
 * 戻り値:
 * [
 *   'inning' => 7, // イニング (試合終了時は0)
 *   'half'   => 'top' | 'bottom' | null,   // 「表/裏」を英語化
 *   'bso'    => ['b'=>2, 's'=>3, 'o'=>3],
 *   'score_rows' => [
 *     ['abbr'=>'西','score'=>3,'active'=>true],   // td.nm のテキストと得点。activeは .nm.act の有無
 *     ['abbr'=>'日','score'=>9,'active'=>false],
 *   ],
 * ]
 *
 * 備考:
 * - チームの「ホーム/ビジター」や team_code への割当はここでは行わず、呼び出し側で game_meta 等と突合してください。
 * - B/S/O は丸印「●」の個数でカウント（<b> が1個で中身が "●●" のケースに対応）。
 */
final class FieldBsoExtractor
{
    public function __construct(private string $baseSelector = '#async-fieldBso') {}

    public function extract(Crawler $root): array
    {
        $base = $root->filter($this->baseSelector);
        if ($base->count() === 0) {
            return $this->empty();
        }

        // 見出しテキスト（例: "7回表" / "試合終了"）
        $sbo = $base->filter('#sbo');
        $label = $sbo->filter('h4 em')->count()
            ? trim($sbo->filter('h4 em')->first()->text(''))
            : '';
        // 既定値（未知表記や終了時は inning=0, half=null で扱う）
        $inning = 0;
        $half   = null; // 'top' | 'bottom' | null

        if (preg_match('/(\d+)\s*回\s*(表|裏)/u', $label, $m)) {
            $inning = (int) $m[1];
            $half   = ($m[2] === '表') ? 'top' : 'bottom';
        } elseif (mb_strpos($label, '試合終了') !== false) {
            // 終了時は「回・表裏」自体が出ないのでそのまま（inning=0, half=null）
            // B/S/O の <b> は空になる想定（集計には影響なし）
        } else {
            // リプレイ等の想定外表記は既定値のまま
        }

        // もし返却ペイロードに見出しそのものを残したい場合は、必要に応じて:
        // $out['label'] = $label;

        // スコア（2行）
        $scoreRows = [];
        $rows = $base->filter('.score table tbody tr');
        foreach ($rows as $trNode) {
            $tr = new Crawler($trNode);
            $abbrNode = $tr->filter('td.nm');
            $scoreNode = $tr->filter('td')->reduce(fn(Crawler $td, $i) => $i === 1);

            $abbrRaw = $abbrNode->count() ? $this->norm($abbrNode->first()->text('')) : '';
            $score   = $scoreNode->count() ? $this->toIntOrZero($scoreNode->first()->text('')) : null;
            $active  = $abbrNode->count() && str_contains((string)$abbrNode->first()->attr('class'), 'act');

            $scoreRows[] = [
                'abbr'   => $abbrRaw,  // 例: "西", "日"
                'score'  => $score,    // int|null
                'active' => $active,   // 攻撃側に .act が付く
            ];
        }

        // B/S/O カウント 
        $b = $this->countDots($base->filter('.sbo p.b'));
        $s = $this->countDots($base->filter('.sbo p.s'));
        $o = $this->countDots($base->filter('.sbo p.o'));

        return [
            'inning'      => $inning ?? 0,
            'half'        => $half ?? null,
            'bso'         => ['b' => $b, 's' => $s, 'o' => $o],
            'score_rows'  => $scoreRows,
        ];
    }

    // -------------- helpers --------------

    /** 丸印 "●" の個数をカウント（<b>●●</b> のような表記に対応） */
    private function countDots(Crawler $p): int
    {
        if ($p->count() === 0) return 0;
        $txt = $p->first()->text('');
        // 「●」の数を数える（全角マル以外は無視）
        return mb_substr_count($txt, '●');
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

    private function empty(): array
    {
        return [
            'inning'     => 0,
            'half'       => null,
            'bso'        => ['b' => 0, 's' => 0, 'o' => 0],
            'score_rows' => [],
        ];
    }
}
