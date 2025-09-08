<?php
require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

function scrapeGameSummary(string $url): array
{
    $client   = new Client(['timeout' => 15, 'headers' => ['User-Agent' => 'npb-analizo-scraper/1.0']]);
    $html     = (string) $client->get($url)->getBody();
    $crawler  = new Crawler($html);

    // 試合日時・球場名
    // 例: <p id="async-gameCard" class="bb-gameDescription__left"> 9/7（日）<time>18:00</time> 甲子園 </p>
    $card      = $crawler->filter('#async-gameCard')->first();
    $dateText  = trim(preg_replace('/\s+/u', ' ', $card->text('', false)));   // "9/7（日） 18:00 甲子園"
    $dateNode  = $card->filter('time')->first();
    $timeText  = $dateNode->count() ? trim($dateNode->text('', false)) : null; // "18:00"

    // ノードから time を除いた純テキストを再構築（前後の空白も整形）
    $cardTextOnly = trim(
        preg_replace(
            '/\s+/u',
            ' ',
            preg_replace('/<time[^>]*>.*?<\/time>/u', '', $card->html()) // timeタグを除外
        )
    );
    // "9/7（日） 甲子園" → 日付と球場名に分解
    // 末尾の語が球場名である前提（Yahoo!の構造に依存）
    $parts = preg_split('/\s+/u', strip_tags($cardTextOnly));
    $stadium = array_pop($parts) ?? null;                // "甲子園"
    $dateLabel = trim(implode(' ', $parts));             // "9/7（日）"

    // チーム名（ホーム→アウェイの順で2ブロックある想定）
    // 例: div.bb-gameTeam p.bb-gameTeam__name a
    $teamNodes = $crawler->filter('div.bb-gameTeam p.bb-gameTeam__name a');
    $homeTeam  = $teamNodes->eq(0)->count() ? trim($teamNodes->eq(0)->text('', false)) : null; // "阪神"
    $awayTeam  = $teamNodes->eq(1)->count() ? trim($teamNodes->eq(1)->text('', false)) : null; // "広島"

    // スコアと状態
    $homeScore = $crawler->filter('.bb-gameTeam__homeScore')->count()
        ? (int)trim($crawler->filter('.bb-gameTeam__homeScore')->text('', false)) : null;
    $awayScore = $crawler->filter('.bb-gameTeam__awayScore')->count()
        ? (int)trim($crawler->filter('.bb-gameTeam__awayScore')->text('', false)) : null;
    $state     = $crawler->filter('.bb-gameCard__state')->count()
        ? trim($crawler->filter('.bb-gameCard__state')->text('', false)) : null;              // "試合終了" 等

    return [
        'url'         => $url,
        'date_label'  => $dateLabel,  // 例 "9/7（日）"
        'time'        => $timeText,   // 例 "18:00"
        'stadium'     => $stadium,    // 例 "甲子園"
        'home_team'   => $homeTeam,   // 例 "阪神"
        'away_team'   => $awayTeam,   // 例 "広島"
        'home_score'  => $homeScore,  // 例 2
        'away_score'  => $awayScore,  // 例 0
        'state'       => $state,      // 例 "試合終了"
    ];
}

// 使い方（テスト）
$url = 'https://baseball.yahoo.co.jp/npb/game/2021029801/top';
$summary = scrapeGameSummary($url);
print_r($summary);
