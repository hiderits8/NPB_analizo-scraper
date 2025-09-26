<?php

declare(strict_types=1);

namespace App\Scrape;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * PitchBatterPanelExtractor
 * -------------------------
 * 対象: /score?index=... の「投手パネル」「打者パネル」「次打者」情報
 *
 * 取得項目:
 * - pitcher: 名前, 背番号, 左右投, 打者数（BF）
 * - batter : 名前, 背番号, 左右打
 * - next_batter（任意）: 名前
 *
 * 仕様メモ:
 * - 継投直後などで打者パネルが空（.teamnull）になる場合がある → batter は null を返す
 * - 試合終了の index では .bottom ブロック自体が無いことがある → pitcher/batter/next_batter すべて null
 * - 個人ページ URL や通算レート（防御率/打率等）は取得しない
 * 
 * 返り値
 * {
 *   pitcher: null|array{name_raw:string, number:int|null, hand_text:string|null, bf:int|null},
 *   batter:  null|array{name_raw:string, number:int|null, hand_text:string|null},
 *   next_batter: null|string
 * }
 */
final class PitchBatterPanelExtractor
{
    public function __construct(
        private string $pitcherRoot     = '#pit',
        private string $pitcherCardPath = '.card table.ct',
        private string $batterRoot      = '#batter',
        private string $batterTableSel  = '#batt, table.ct', // どちらかが使われる
        private string $nextBatterRoot  = '#nxt_batt'
    ) {}

    /**
     * @return array{
     *   pitcher: null|array{name_raw:string, number:int|null, hand_text:string|null, bf:int|null},
     *   batter:  null|array{name_raw:string, number:int|null, hand_text:string|null},
     *   next_batter: null|string
     * }
     */
    public function extract(Crawler $root): array
    {
        return [
            'pitcher'     => $this->extractPitcher($root),
            'batter'      => $this->extractBatter($root),
            'next_batter' => $this->extractNextBatter($root),
        ];
    }

    // ---------------- Pitcher ----------------

    /** @return null|array{name_raw:string, number:int|null, hand_text:string|null, bf:int|null} */
    private function extractPitcher(Crawler $root): ?array
    {
        $tbl = $root->filter($this->pitcherRoot . ' ' . $this->pitcherCardPath)->first();
        if ($tbl->count() === 0) {
            return null;
        }

        // 名前
        $nameNode = $tbl->filter('.nm_box .nm a')->first();
        if ($nameNode->count() === 0) {
            // anchor が無いケースに備えてテキスト全体から拾う
            $nameNode = $tbl->filter('.nm_box .nm')->first();
        }
        $name = $this->norm($nameNode->count() ? $nameNode->text('') : '');
        if ($name === '') {
            // 何らかの理由で名前がない場合はパネル無効扱い
            return null;
        }

        // 背番号
        $numText = $tbl->filter('.nm_box .playerNo')->first()->text('');
        $number  = $this->parseNumber($numText);

        // 右投/左投
        $hand = $this->norm($tbl->filter('.nm_box .dominantHand')->first()->text('')) ?: null;

        // 打者数（見出し行から列位置を特定して値を取る）
        $bf = $this->extractByHeader($tbl, '打者数');

        return [
            'name_raw'  => $name,
            'number'    => $number,
            'hand_text' => $hand,     // 例: "右投" / "左投"
            'bf'        => $bf,       // 例: 26
        ];
    }

    // ---------------- Batter ----------------

    /** @return null|array{name_raw:string, number:int|null, hand_text:string|null} */
    private function extractBatter(Crawler $root): ?array
    {
        $card = $root->filter($this->batterRoot)->first();
        if ($card->count() === 0) {
            return null;
        }

        // 継投直後などで .teamnull になると実体が空
        $isTeamNull = str_contains((string)($card->attr('class') ?? ''), 'teamnull');
        // table を拾う（id="batt" 優先、なければ汎用 .ct）
        $tbl = $card->filter($this->batterTableSel)->first();

        // 名前
        $nameNode = $tbl->filter('.nm_box .nm a')->first();
        if ($nameNode->count() === 0) {
            $nameNode = $tbl->filter('.nm_box .nm')->first();
        }
        $name = $this->norm($nameNode->count() ? $nameNode->text('') : '');

        // 空カードや名前が拾えない場合は null
        if ($isTeamNull || $name === '') {
            return null;
        }

        // 背番号
        $numText = $tbl->filter('.nm_box .playerNo')->first()->text('');
        $number  = $this->parseNumber($numText);

        // 左右打
        $hand = $this->norm($tbl->filter('.nm_box .dominantHand')->first()->text('')) ?: null;

        return [
            'name_raw'  => $name,
            'number'    => $number,
            'hand_text' => $hand,    // 例: "右打" / "左打"
        ];
    }

    // ---------------- Next Batter (optional) ----------------

    private function extractNextBatter(Crawler $root): ?string
    {
        $box = $root->filter($this->nextBatterRoot . ' .nextBatter dd')->first();
        if ($box->count() === 0) {
            return null;
        }

        // 通常は <dd><a><p>氏名</p></a></dd>
        $nameNode = $box->filter('a p')->first();
        if ($nameNode->count() === 0) {
            $nameNode = $box->filter('p')->first();
        }
        $name = $this->norm($nameNode->count() ? $nameNode->text('') : '');
        return $name !== '' ? $name : null;
    }

    // ---------------- helpers ----------------

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function parseNumber(string $raw): ?int
    {
        // 例: "#11" → 11
        if (preg_match('/\d+/', $raw, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    /**
     * 見出し行（<th>）の順序を見て、次行（<td>群）から該当列を数値で返す
     * 見つからない場合は null
     */
    private function extractByHeader(Crawler $table, string $headerJa): ?int
    {
        $rows = $table->filter('tr');
        if ($rows->count() < 2) return null;

        $headerIdx = null;
        foreach ($rows as $i => $tr) {
            $cr = new Crawler($tr);
            // 見出し候補
            $ths = $cr->filter('th');
            if ($ths->count() > 0) {
                foreach ($ths as $j => $th) {
                    $text = TextNormalizer::normalizeJaName((new Crawler($th))->text(''));
                    if ($text === $headerJa) {
                        $headerIdx = $j + 1; // nth-child は 1 始まり
                        break 2;
                    }
                }
            }
        }
        if ($headerIdx === null) return null;

        // 見出し行の直後にスコア行（<td>群）がある想定
        // よくある構造: <tr><th>投球数</th><th>打者数</th>...</tr><tr class="score"><td>85</td><td>26</td>...</tr>
        $scoreRow = null;
        for ($k = 0; $k < $rows->count(); $k++) {
            $cr = $rows->eq($k);
            if ($cr->filter('th')->count() > 0) {
                // 次の tr をスコア行候補に
                if ($k + 1 < $rows->count()) {
                    $scoreRow = $rows->eq($k + 1);
                }
                break;
            }
        }
        if ($scoreRow === null) return null;

        $td = $scoreRow->filter("td:nth-child({$headerIdx})")->first();
        if ($td->count() === 0) return null;

        $val = trim($td->text(''));
        $val = preg_replace('/[^\d]/u', '', $val) ?? '';
        return $val !== '' ? (int)$val : null;
    }
}
