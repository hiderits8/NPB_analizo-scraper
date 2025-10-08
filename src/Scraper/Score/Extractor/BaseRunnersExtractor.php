<?php

declare(strict_types=1);

namespace App\Scraper\Score\Extractor;

use Symfony\Component\DomCrawler\Crawler;
use App\Util\TextNormalizer;

/**
 * BaseRunnersExtractor
 * --------------------
 * 対象: /score?index=... のフィールド表示
 *   <div id="field">
 *     <div id="base" class="b101">
 *       <div id="dakyu" class="dakyu3">...</div>
 *       <div id="base1"><span>27 中村悠</span></div>
 *       <div id="base2"><span>38 小幡</span></div>
 *       <div id="base3"><span>1 山田</span></div>
 *     </div>
 *   </div>
 *
 * 仕様:
 * - #base の class="bXYZ" をビット列として解釈 (X=一塁, Y=二塁, Z=三塁; 1=占有,0=空)
 * - 各 base{1,2,3} の <span> から「背番号 + 名前」を抽出
 *
 * 戻り値例:
 * [
 *   'base_class'   => 'b101',
 *   'occupied'     => ['1'=>true,'2'=>false,'3'=>true],
 *   'runners'      => [
 *       '1' => ['uniform_no'=>27, 'name_raw'=>'中村悠'],
 *       '2' => ['uniform_no'=>38, 'name_raw'=>'小幡'],
 *       '3' => ['uniform_no'=>1,  'name_raw'=>'山田'],
 *   ],
 * ]
 */
final class BaseRunnersExtractor
{
    public function __construct(private string $rootSelector = '#field') {}

    public function extract(Crawler $root): array
    {
        // #field の下、またはドキュメント直下にある #base を拾う
        $base = $root->filter($this->rootSelector . ' #base');
        if ($base->count() === 0) {
            $base = $root->filter('#base');
        }
        if ($base->count() === 0) {
            return $this->empty();
        }
        $base = $base->first();

        $baseClass  = (string)($base->attr('class') ?? '');

        // bXYZ を occupancy に変換
        $occupied = $this->occupiedFromClass($baseClass);

        // 各塁のランナー名・背番号
        $runners = [];
        foreach ([1, 2, 3] as $n) {
            $span = $base->filter("#base{$n} span")->first();
            if ($span->count() === 0) {
                // span が無い場合はエントリ自体を作らない（occupied が true でも無いことはある）
                continue;
            }
            [$no, $name] = $this->parseUniformAndName($this->norm($span->text('')));
            $runners[(string)$n] = [
                'uniform_no' => $no,
                'name_raw'   => $name !== '' ? $name : null,
            ];
        }

        return [
            'base_class'  => $baseClass !== '' ? $baseClass : null,
            'occupied'    => $occupied,
            'runners'     => $runners,
        ];
    }

    // ---------------- helpers ----------------

    private function empty(): array
    {
        return [
            'base_class'  => null,
            'occupied'    => ['1' => false, '2' => false, '3' => false],
            'runners'     => [],
        ];
    }

    /**
     * class="bXYZ" を ['1'=>bool,'2'=>bool,'3'=>bool] に変換
     * X=一塁, Y=二塁, Z=三塁
     */
    private function occupiedFromClass(string $class): array
    {
        $x = ['1' => false, '2' => false, '3' => false];

        if (preg_match('/\bb([01]{3})\b/', $class, $m)) {
            $bits = $m[1]; // 例: "101"
            // 先頭=一塁, 中央=二塁, 末尾=三塁
            $x['1'] = $bits[0] === '1';
            $x['2'] = $bits[1] === '1';
            $x['3'] = $bits[2] === '1';
        }
        return $x;
    }

    /**
     * 「背番号(全角半角可) + 空白 + 名前」を抽出
     * 例: "27 中村悠" -> [27, "中村悠"]
     *     "8 佐藤輝"  -> [8,  "佐藤輝"]
     *     "小幡"      -> [null, "小幡"]  // 背番号が無い場合
     */
    private function parseUniformAndName(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^\s*([0-9０-９]+)\s+(.+?)\s*$/u', $text, $m)) {
            $no = $this->toAsciiDigits($m[1]);
            return [ctype_digit($no) ? (int)$no : null, trim($m[2])];
        }

        // 数字が先頭に無ければ名前だけとみなす
        return [null, $text];
    }

    private function norm(string $s): string
    {
        return TextNormalizer::normalizeJaName($s);
    }

    private function toAsciiDigits(string $s): string
    {
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
