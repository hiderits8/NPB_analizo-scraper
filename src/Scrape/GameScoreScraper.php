<?php

declare(strict_types=1);

namespace App\Scrape;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

final class GameScoreScraper
{
    public function __construct(
        private ClientInterface $http,
        private string $fieldBsoSelector = '#async-fieldBso',
    ) {}

    /**
     * @param string $url 例: https://baseball.yahoo.co.jp/npb/game/2021029839/score?index=0110100
     * @param array  $gameMeta 任意: ['away'=>['team_code'=>..,'team_raw'=>..], 'home'=>[...]]
     */
    public function scrape(string $url, array $gameMeta = []): array
    {
        $res   = $this->http->request('GET', $url);
        $html  = (string) $res->getBody();
        $root  = new Crawler($html);

        // 1.1: BSO/スコア小表/見出し（回・表裏 or 試合終了）
        $fieldBso = (new FieldBsoExtractor($this->fieldBsoSelector))->extract($root);

        // 今後 1.2〜1.5, 3.1〜3.3 を順次追加していく想定
        return [
            'url'       => $url,
            'game_meta' => $gameMeta,
            'field_bso' => $fieldBso,
        ];
    }
}
