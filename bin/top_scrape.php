#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Http\DictClient;
use App\Resolver\Resolver;
use App\Resolver\AliasNormalizer;
use App\Resolver\AliasesLoader;
use App\Scraper\Top\GameTopScraper;
use GuzzleHttp\Client;

require_once __DIR__ . '/_bootstrap.php';

// ---- args ----
$opts = getopt('', [
    'url:',        // required
    'page-level:', // required First|Farm
    'timeout::',   // optional int
    'pretty',      // flag
    'out-file::',  // optional path
]);

$url = isset($opts['url']) ? (string)$opts['url'] : '';
if ($url === '') fail(1, '--url is required');
$pageLevel = isset($opts['page-level']) ? (string)$opts['page-level'] : '';

$timeout = isset($opts['timeout'])
    ? max(1, (int)$opts['timeout'])
    : max(1, (int) $_ENV['HTTP_TIMEOUT_SECONDS'] ?? getenv('HTTP_TIMEOUT_SECONDS') ?? '15');
$pretty  = array_key_exists('pretty', $opts);
$outFile = isset($opts['out-file']) ? (string)$opts['out-file'] : null;

// ---- fetch & scrape ----
$http = make_http_client($timeout);

// API 辞書ロード
$apiBase    = $_ENV['APP_API_BASE']    ?? '';
$apiTimeout = (int)($_ENV['APP_API_TIMEOUT'] ?? 10);

try {
    $dict     = new DictClient($apiBase, $apiTimeout);
    $teams    = $dict->teams();
    $stadiums = $dict->stadiums();
    $clubs    = $dict->clubs();
} catch (\Throwable $e) {
    if (!$APP_QUIET) fwrite(STDERR, "[error] dict load failed: {$e->getMessage()}\n");
    exit(3);
}

// 2) エイリアスを使用したリゾルバ
$aliases       = (new AliasesLoader($root))->loadBase();
$resolver      = new Resolver($teams, $stadiums, $clubs, new AliasNormalizer($aliases));

// 3) スクレイプ
try {
    $scraper = new GameTopScraper($http, $resolver, $pageLevel);
    $result  = $scraper->scrape($url);
} catch (\Throwable $e) {
    // エラーログを保存
    $logDir = $root . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    file_put_contents($logDir . '/error.log', sprintf('[%s] url=%s ERROR=%s\n', date('c'), $url, $e->getMessage()), FILE_APPEND);
    if (!$APP_QUIET) fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(3);
}

// 4) 未解決マップを作成 (スクレイパーが提供しない場合のフォールバック)
$unresolvedMap = $result['game_meta']['unresolved_map'] ?? [];

if (empty($unresolvedMap)) {
    $pairs = [
        'stadium'   => [$result['stadium_name']   ?? null, $result['stadium_raw']   ?? null],
        'home_team' => [$result['home_team_name'] ?? null, $result['home_team_raw'] ?? null],
        'away_team' => [$result['away_team_name'] ?? null, $result['away_team_raw'] ?? null],
    ];
    foreach ($pairs as $k => [$id, $raw]) {
        // (IDがnullで、rawが文字列で、空でない場合) 未解決マップに追加
        if ($id === null && is_string($raw) && $raw !== '') $unresolvedMap[$k] = $raw;
    }
}

// 5) 辞書カテゴリにマップ
// チームは First|Farm によって異なる
$catTeam  = ($pageLevel === 'Farm') ? 'teams_farm' : 'teams_first';
// 未解決マップのキーを辞書カテゴリにマップ
$keyToCat = ['stadium' => 'stadiums', 'home_team' => $catTeam, 'away_team' => $catTeam, 'club' => 'clubs'];

// 辞書カテゴリごとに未解決マップを作成 (例: ['stadiums' => ['甲子園' => true], 'teams_first' => ['阪神' => true, '広島' => true]])
$catWise = [];
foreach ($unresolvedMap as $key => $raw) {
    $cat = $keyToCat[$key] ?? null;
    if ($cat === null) continue;
    $catWise[$cat][(string)$raw] = true; // true は重複を避けるためのダミー
}

// 6) ゲート & ロギング (未解決)
if (!empty($catWise)) {
    foreach ($catWise as $cat => $rawSet) {
        $file = $PENDING_DIR . '/' . $cat . '.jsonl';
        foreach (array_keys($rawSet) as $raw) {
            $row = [
                'ts'       => date('c'),
                'event'    => 'game_unresolved',
                'url'      => $url,
                'level'    => $pageLevel,
                'category' => $cat,
                'raw'      => $raw,
            ];
            file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    $eventFile = $PENDING_DIR . '/events.jsonl';
    $gameEvent = [
        'ts'        => date('c'),
        'event'     => 'game_skipped_unresolved',
        'url'       => $url,
        'level'     => $pageLevel,
        'unresolved' => array_map(fn($set) => array_keys($set), $catWise), // 未解決マップのキーを取得
    ];
    file_put_contents($eventFile, json_encode($gameEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

    // ロギング (未解決)
    if (!$APP_QUIET) {
        $summary = [
            'OK',
            'url=' . ($result['url'] ?? $url),
            'level=' . $pageLevel,
            'home=' . ($result['home_team_name'] ?? 'null'),
            'away=' . ($result['away_team_name'] ?? 'null'),
            'stadium=' . ($result['stadium_name'] ?? 'null'),
        ];
        fwrite(STDOUT, implode(PHP_EOL, $summary) . PHP_EOL . PHP_EOL);
        fwrite(STDOUT, 'unresolved=' . json_encode($gameEvent['unresolved'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fwrite(STDOUT, "db_write=skip (unresolved)\n");
    }
    exit(2);
}

// game_key は URL の数列（安定ID用途のみ）。日付ディレクトリは「今年 + $result['date'] の月日」
$gameKey = null;
if (preg_match('#/game/(\d{9,12})/#', $url, $m)) {
    $gameKey = $m[1];
}

$tz  = new \DateTimeZone('Asia/Tokyo');
$now = new \DateTime('now', $tz);
$year = (int)$now->format('Y');

$deriveYmd = function (array $res) use ($year): string {
    // Meta は date と time のみを返す。date 例: "9/7（日）"
    $v = isset($res['date']) && is_string($res['date']) ? trim($res['date']) : '';
    if ($v !== '') {
        // 日本語の曜日などの括弧以降を取り除く（例: 9/7（日）→ 9/7）
        $posParen = mb_strpos($v, '（');
        if ($posParen !== false) {
            $v = mb_substr($v, 0, $posParen);
        }
        $v = trim($v);

        $month = null;
        $day = null;
        // M/D の形式
        if (strpos($v, '/') !== false) {
            [$m, $d] = array_map('trim', explode('/', $v, 2));
            $month = (int)$m;
            $day = (int)$d;
        }

        if ($month !== null && $day !== null && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            return sprintf('%04d%02d%02d', $year, $month, $day);
        }
    }
    // フォールバック：今日（Asia/Tokyo）
    return (new \DateTime('now', new \DateTimeZone('Asia/Tokyo')))->format('Ymd') + 'Fallback(scrape date)';
};

$ymd = $deriveYmd($result);

$outDir  = $root . '/logs/output/' . $ymd;
if (!is_dir($outDir)) mkdir($outDir, 0777, true);
$outFile = $outDir . '/game_participants.ndjson';

$write = function (array $row) use ($outFile) {
    file_put_contents($outFile, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
};

foreach (['home', 'away'] as $side) {
    // starters
    foreach (($participants[$side]['starters'] ?? []) as $p) {
        $write([
            'game_key'        => $gameKey,
            'team_side'       => strtoupper($side), // HOME | AWAY
            'type'            => 'starter',
            'slot'            => $p['slot'] ?? null,     // DHの先発投手補完時はnull
            'position'        => $p['position'] ?? null, // 例: 中, 二, 指, 投
            'player_name_ja'  => $p['name'] ?? null,
            'url'             => $url,
            'scraped_at'      => date('c'),
        ]);
    }
    // bench（存在しない試合もある）
    foreach (($participants[$side]['bench'] ?? []) as $p) {
        $write([
            'game_key'        => $gameKey,
            'team_side'       => strtoupper($side),
            'type'            => 'bench',
            'slot'            => null,
            'position'        => null,
            'player_name_ja'  => $p['name'] ?? null,
            'url'             => $url,
            'scraped_at'      => date('c'),
        ]);
    }
}

// 7) ロギング (正常終了)
if (!$APP_QUIET) {
    $summary = [
        'OK',
        'url=' . ($result['url'] ?? $url),
        'level=' . $pageLevel,
        'home=' . ($result['home_team_name'] ?? 'null'),
        'away=' . ($result['away_team_name'] ?? 'null'),
        'stadium=' . ($result['stadium_name'] ?? 'null'),
    ];
    fwrite(STDOUT, implode(PHP_EOL, $summary) . PHP_EOL . PHP_EOL);
    fwrite(STDOUT, 'unresolved={}' . PHP_EOL);
    fwrite(STDOUT, "db_write=ok\n");
}

exit(0);
