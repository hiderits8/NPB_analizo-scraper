<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\DictClient;
use App\Resolver\Resolver;
use App\Resolver\AliasNormalizer;
use App\Scrape\GameMetaScraper;
use App\Resolver\UnknownRegistry;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeload();

$apiBase = $_ENV['APP_API_BASE'];
$apiTimeout = (int)$_ENV['APP_API_TIMEOUT'];

$url = $argv[1] ?? null;
$pageLevel = $argv[2] ?? null;
if (!$url || !in_array($pageLevel, ['First', 'Farm'], true)) {
    fwrite(STDERR, "Usage: php bin/scrape_one.php <game-url> <First|Farm>\n");
    exit(2);
}

// 1) API 辞書ロード
$dict = new DictClient($apiBase, $apiTimeout);
$teams = $dict->teams();
$stadiums = $dict->stadiums();
$clubs = $dict->clubs();

// 2) Resolver 準備（同義語辞書）
$aliases = require dirname(__DIR__) . '/data/aliases.php';
$resolver = new Resolver($teams, $stadiums, $clubs, new AliasNormalizer($aliases));

// 3) 実ページをスクレイプ
try {
    $http = new Client(['timeout' => 15]);
    $scraper = new GameMetaScraper($http, $resolver);
    $result = $scraper->scrape($url, $pageLevel);
} catch (Throwable $e) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    $line = sprintf("[%s] url=%s ERROR=%s\n", date('c'), $url, $e->getMessage());
    file_put_contents($logDir . '/error.log', $line, FILE_APPEND);
    throw $e;
    // ここで終了（継続したければ return に変更）
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

// 4) 未解決をリストに保存
$reg = new UnknownRegistry(dirname(__DIR__) . '/data/pending_aliases');

if (($result['home_team_id'] ?? null) === null && !empty($result['home_team_raw'])) {
    $reg->record($pageLevel === 'First' ? 'teams_first' : 'teams_farm', $result['home_team_raw'], ['url' => $url, 'level' => $pageLevel, 'page_date' => $result['date_label']]);
}
if (($result['away_team_id'] ?? null) === null && !empty($result['away_team_raw'])) {
    $reg->record($pageLevel === 'First' ? 'teams_first' : 'teams_farm', $result['away_team_raw'], ['url' => $url, 'level' => $pageLevel, 'page_date' => $result['date_label']]);
}
if (($result['stadium_id'] ?? null) === null && !empty($result['stadium_raw'])) {
    $reg->record('stadiums', $result['stadium_raw'], ['url' => $url, 'page_date' => $result['date_label']]);
}

// 5) 出力と未解決ログ
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// $result が配列か最低限のキーを持つかガード
if (!is_array($result)) {
    file_put_contents(
        $logDir . '/error.log',
        sprintf("[%s] url=%s ERROR=Result is not array\n", date('c'), $url),
        FILE_APPEND
    );
    fwrite(STDERR, "ERROR: result is not array\n");
    exit(1);
}

// 未解決推定（キーが無い or null を未解決とみなす）
$unresolved = $result['unresolved'] ?? [];
foreach (['home_team_id', 'away_team_id', 'stadium_id'] as $k) {
    if (!array_key_exists($k, $result) || $result[$k] === null) {
        $unresolved[] = $k;
    }
}
$unresolved = array_values(array_unique($unresolved));

// 常にサマリを標準出力
fwrite(STDOUT, sprintf(
    "OK url=%s level=%s home=%s away=%s stadium=%s unresolved=%d\n",
    $result['url'] ?? $url,
    $pageLevel,
    $result['home_team_id'] ?? 'null',
    $result['away_team_id'] ?? 'null',
    $result['stadium_id']   ?? 'null',
    count($unresolved)
));

// 未解決があればログへ追記
if (!empty($unresolved)) {
    $line = sprintf(
        "[%s] url=%s unresolved=%s\n",
        date('c'),
        $result['url'] ?? $url,
        json_encode($unresolved, JSON_UNESCAPED_UNICODE)
    );
    file_put_contents($logDir . '/unresolved.txt', $line, FILE_APPEND);
}
