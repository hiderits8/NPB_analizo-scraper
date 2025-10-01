<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * PitchResultTableExtractor
 * -------------------------
 * 対象: #pitchesDetail セクション内の「投球結果テーブル」（コース図は対象外）
 *
 * 想定HTMLブロック構成:
 * <div id="pitchesDetail" class="bb-splits bb-splits--live">
 *   <section class="bb-splits__item">
 *     <table id="gm_rslt" class="bb-splitsTable target_modules">  ... 打者/投手 ペア ...
 *     <table class="bb-splitsTable"> ... 投球明細（投球数/球種/球速/結果） ...
 *     <!-- ※ 同内容テーブルが重複して現れるケースあり。最初の明細テーブルのみ採用 -->
 *     <ul class="bb-tableNote bb-tableNote--ballresult"> ... 凡例 ...
 *   </section>
 * </div>
 *
 * 返却例:
 * [
 *   'exists'  => true,                 // セクションが存在し投球明細が見つかった
 *   'batter'  => ['name_raw'=>'中野 拓夢','hand'=>'左'],
 *   'pitcher' => ['name_raw'=>'大西 広樹','hand'=>'右'],
 *   'pitches' => [
 *     [
 *       'seq_in_pa'       => 1,        // 打席内の投球順（丸数字内）
 *       'pitcher_np_cum'  => 9,        // その時点の累積投球数（テーブル2列目）
 *       'ball_class'      => 'ball2',  // bb-icon__ballCircle--ball{1..5}
 *       'category'        => 'ball',   // strike_or_foul | ball | out | on_base_no_bb | sacrifice
 *       'pitch_type'      => 'フォーク',
 *       'speed_kmh'       => 136,
 *       'result_label'    => 'ボール', // 角括弧の注記を除いた主要ラベル
 *       'result_notes'    => [],       // 角括弧[...] 内の注記（例: ['詰り','送球間に進塁']）
 *     ],
 *     ...
 *   ],
 * ]
 *
 * セクション（#pitchesDetail）自体が無いケース（継投/回開始/試合終了）は:
 * ['exists'=>false, 'batter'=>null, 'pitcher'=>null, 'pitches'=>[]]
 */
final class PitchResultTableExtractor
{
    public function __construct(private string $rootSelector = '#pitchesDetail') {}

    public function extract(Crawler $root): array
    {
        $section = $root->filter($this->rootSelector)->filter('section')->reduce(function (Crawler $node, $i) {
            return $node->attr("id") !== "gm_mema" && $node->attr("id") !== "gm_memh";
        });
        if ($section->count() === 0) {
            return $this->empty();
        }

        // 打者・投手のペア（#gm_rslt）
        $gm = $section->filter('table#gm_rslt');
        if ($gm->count() === 0) {
            [$batter, $pitcher] = [null, null];
        } else {
            [$batter, $pitcher] = $this->parseBatterPitcher($gm);
        }

        // 投球明細テーブル（最初の bb-splitsTable で id!=gm_rslt）
        $detailTables = $section->filter('table.bb-splitsTable')->reduce(function (Crawler $node, $i) {
            return (string)$node->attr('id') !== 'gm_rslt';
        });

        if ($detailTables->count() === 1) {
            // セクションはあるが、空のコーステーブル1つのみで、投球明細が無い場合（= 継投/回開始/試合終了）ケース
            return [
                'exists'  => false,
                'batter'  => $batter,
                'pitcher' => $pitcher,
                'pitches' => [],
            ];
        }

        // 最初のテーブルはコーステーブルで確定なので、後ろのテーブルだけ読む
        $detail = $detailTables->last();

        $rows = $detail->filter('tbody > tr');
        $pitches = [];
        foreach ($rows as $trEl) {
            $tr = new Crawler($trEl);

            // 1列目: 丸数字 + ballクラス
            $icon = $tr->filter('.bb-icon__ballCircle');
            if ($icon->count() === 0) {
                // 稀に小見出し行などが紛れる安全策
                continue;
            }
            $iconClass = (string)($icon->attr('class') ?? '');
            $ballClass = $this->extractBallClass($iconClass); // ball1..ball5 or null
            $seqInPa   = $this->toInt($icon->text(''));

            // 2列目: 累積投球数
            $npCum = $this->toInt($this->cellText($tr, 1));

            // 3列目: 球種
            $pitchType = $this->nullIfEmpty($this->cellText($tr, 2));

            // 4列目: 球速（km/h）
            $speedText = $this->cellText($tr, 3);
            $speedKmh  = $this->extractSpeed($speedText);

            // 5列目: 結果（<br>や [注記] を含む）
            $resultTd  = $tr->children()->eq(4);
            $resultRaw = $this->norm($resultTd->count() ? $resultTd->text('') : '');
            [$resultLabel, $notes] = $this->splitResultAndNotes($resultRaw);

            $pitches[] = [
                'seq_in_pa'       => $seqInPa,
                'pitcher_np_cum'  => $npCum,
                'ball_class'      => $ballClass,
                'category'        => $this->mapBallClass($ballClass),
                'pitch_type'      => $pitchType,
                'speed_kmh'       => $speedKmh,
                'result_label'    => $resultLabel,
                'result_notes'    => $notes,
            ];
        }

        return [
            'exists'  => count($pitches) > 0,
            'batter'  => $batter,
            'pitcher' => $pitcher,
            'pitches' => $pitches,
        ];
    }

    // ---------------- helpers ----------------

    private function empty(): array
    {
        return [
            'exists'  => false,
            'batter'  => null,
            'pitcher' => null,
            'pitches' => [],
        ];
    }

    /**
     * #gm_rslt から打者/投手情報を抽出（左右の表示順が入れ替わるケースに対応）
     * 返却: [batter|null, pitcher|null]
     */
    private function parseBatterPitcher(Crawler $gm): array
    {
        if ($gm->count() === 0) {
            return [null, null];
        }

        // ヘッダの順序を確認（打者→投手 or 投手→打者）
        $ths = $gm->filter('thead th');
        $headerText = [];
        foreach ($ths as $th) {
            $headerText[] = trim((new Crawler($th))->text(''));
        }
        $isBatterFirst = false;
        foreach ($headerText as $t) {
            if (mb_strpos($t, '打者') !== false) {
                $isBatterFirst = true;
                break;
            }
            if (mb_strpos($t, '投手') !== false) {
                $isBatterFirst = false;
                break;
            }
        }

        $row = $gm->filter('tbody > tr')->first();
        if ($row->count() === 0) {
            return [null, null];
        }
        $tds = $row->filter('td');
        if ($tds->count() < 4) {
            return [null, null];
        }

        // td: 0=name,1=hand,2=name,3=hand をヘッダ順にマップ
        if ($isBatterFirst) {
            $batter = [
                'name_raw' => $this->cellName($tds->eq(0)),
                'hand'     => $this->norm($tds->eq(1)->text('')),
            ];
            $pitcher = [
                'name_raw' => $this->cellName($tds->eq(2)),
                'hand'     => $this->norm($tds->eq(3)->text('')),
            ];
        } else {
            $pitcher = [
                'name_raw' => $this->cellName($tds->eq(0)),
                'hand'     => $this->norm($tds->eq(1)->text('')),
            ];
            $batter = [
                'name_raw' => $this->cellName($tds->eq(2)),
                'hand'     => $this->norm($tds->eq(3)->text('')),
            ];
        }

        // 空文字なら null に
        if ($batter['name_raw'] === '') $batter = null;
        if ($pitcher['name_raw'] === '') $pitcher = null;

        return [$batter, $pitcher];
    }

    private function cellName(Crawler $td): string
    {
        $a = $td->filter('a')->first();
        $t = $a->count() ? $a->text('') : $td->text('');
        return $this->norm($t);
    }

    /** 0始まりindexのtdテキストを正規化して取得 */
    private function cellText(Crawler $tr, int $tdIndex): string
    {
        $td = $tr->children()->eq($tdIndex);
        return $this->norm($td->count() ? $td->text('') : '');
    }

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function nullIfEmpty(string $s): ?string
    {
        $s = trim($s);
        return $s === '' ? null : $s;
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

    private function extractSpeed(?string $text): ?int
    {
        if ($text === null) return null;
        // 例: "143km/h" / "120km/h" / "-" / ""
        if (preg_match('/(\d+)\s*km\/?h/i', $text, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/\d+/', $text, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    /** クラス文字列から ball{1..5} を取り出す */
    private function extractBallClass(string $class): ?string
    {
        if (preg_match('/bb-icon__ballCircle--(ball[1-5])\b/', $class, $m)) {
            return $m[1]; // e.g. "ball2"
        }
        return null;
    }

    /** ballクラス→カテゴリ */
    private function mapBallClass(?string $ballClass): ?string
    {
        if ($ballClass === null) return null;
        return match ($ballClass) {
            'ball1' => 'strike_or_foul', // ストライク・ファウル（三振含む）
            'ball2' => 'ball',           // ボール（四球含む）
            'ball3' => 'out',            // アウト（三振以外）
            'ball4' => 'on_base_no_bb',  // 出塁（四球以外）
            'ball5' => 'sacrifice',      // 犠打・犠飛
            default => null,
        };
    }

    /**
     * 結果セルのテキストから「ラベル」と「[注記]」を分離
     * 例: "左安 [詰り]" -> ['左安', ['詰り']]
     */
    private function splitResultAndNotes(string $raw): array
    {
        // 改行や全角スペースを軽く整形
        $raw = preg_replace("/[ \t　]+/u", ' ', $raw ?? '') ?? '';
        $raw = trim(preg_replace("/\s*\n\s*/u", ' ', $raw));

        $notes = [];
        if (preg_match_all('/\[(.+?)\]/u', $raw, $m)) {
            foreach ($m[1] as $note) {
                $note = trim($note);
                if ($note !== '') $notes[] = $note;
            }
        }
        // [] を除いた本体
        $label = trim(preg_replace('/\[[^\]]*\]/u', '', $raw));
        return [$label, $notes];
    }
}
