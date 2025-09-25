<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;

/**
 * DakyuResultExtractor
 * --------------------
 * 対象: /score?index=... のフィールド表示 (#field/#base 内)
 * 例:
 *   <div id="base" class="b101">
 *     <div id="dakyu" class="dakyu3"> ... </div>
 *   </div>
 *
 * 仕様（提供情報に準拠）:
 * - class="dakyu??" の ?? を抽出し、打球種別と方向に正規化
 * - 範囲と意味:
 *   1-9   : ヒット方向（direction のみ有意）
 *   28-30 : 本塁打（28=LF,29=CF,30=RF）
 *   40-48 : ゴロ（投,捕,一,二,三,遊,左,中,右）
 *   49-57 : フライ（同上。※ファウルフライは含まれない=表示されないことが多い）
 *   58-66 : ライナー（同上。※ファウルライナーは含まれない=表示されないことが多い）
 *
 * - 打球イベントでない（交代/四球/死球/盗塁/ボーク等）や
 *   ファウルフライ等で描画が無いケースでは dakyu 要素が無く、本抽出は空を返す。
 *
 * 戻り値:
 * [
 *   'present'           => bool,          // dakyu が見つかったか
 *   'dakyu_code'        => ?int,          // dakyu の数値 (例: 42)
 *   'batted_ball_type'  => ?string,       // 'ground'|'fly'|'liner'|'hr'|'hit_dir'|null
 *   'direction_num'     => ?int,          // 1..9 = P,C,1B,2B,3B,SS,LF,CF,RF
 * ]
 */
final class DakyuResultExtractor
{
    public function __construct(
        private string $rootSelector = '#field'
    ) {}

    public function extract(Crawler $root): array
    {
        // #field > #base > #dakyu を基本に、#base 直下にもフォールバック
        $container = $root->filter($this->rootSelector . ' #base, #base')->first();
        if ($container->count() === 0) {
            return $this->empty();
        }

        $dakyu = $container->filter('#dakyu')->first();
        if ($dakyu->count() === 0) {
            return $this->empty();
        }

        $cls = (string)($dakyu->attr('class') ?? '');
        if (!preg_match('/\bdakyu(\d+)\b/', $cls, $m)) {
            return $this->empty();
        }

        $code = (int)$m[1];

        // マッピング
        $type  = null;
        $dir   = null; // 1..9

        if ($code >= 1 && $code <= 9) {
            // ヒット方向（種別は stats 側に委ね、ここでは方向のみ）
            $type = 'hit_dir';
            $dir  = $code; // 1=P,2=C,3=1B,...,9=RF
        } elseif ($code >= 28 && $code <= 30) {
            // 本塁打（方向: 28=LF, 29=CF, 30=RF）
            $type = 'hr';
            $dir  = 7 + ($code - 28); // 28->7, 29->8, 30->9
        } elseif ($code >= 40 && $code <= 48) {
            // ゴロ: 40->P, 41->C, ..., 48->RF
            $type = 'ground';
            $dir  = $code - 39; // 40->1 ... 48->9
        } elseif ($code >= 49 && $code <= 57) {
            // フライ: 49->P, 50->C, ..., 57->RF（※ファウルフライは対象外=通常描画されない）
            $type = 'fly';
            $dir  = $code - 48; // 49->1 ... 57->9
        } elseif ($code >= 58 && $code <= 66) {
            // ライナー: 58->P, 59->C, ..., 66->RF（※ファウルライナーは対象外=通常描画されない）
            $type = 'liner';
            $dir  = $code - 57; // 58->1 ... 66->9
        } else {
            // 未定義/想定外コード
            return $this->empty();
        }

        // ガード（不整合の可能性は極小だが念のため）
        if ($dir !== null && ($dir < 1 || $dir > 9)) {
            return $this->empty();
        }

        return [
            'present'          => true,
            'dakyu_code'       => $code,
            'batted_ball_type' => $type,
            'direction_num'    => $dir,
        ];
    }

    private function empty(): array
    {
        return [
            'present'          => false,
            'dakyu_code'       => null,
            'batted_ball_type' => null,
            'direction_num'    => null,
        ];
    }
}
