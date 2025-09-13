<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\DictClient;
use App\Resolver\Resolver;
use App\Resolver\AliasNormalizer;
use App\Scrape\GameMetaScraper;
use App\Resolver\AliasesLoader;
use App\Resolver\UnknownRegistry;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

$root = \dirname(__DIR__);

// .env 読み込み（存在すれば）
if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

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
$aliasesLoader = new AliasesLoader($root);
$aliases = $aliasesLoader->load();
$resolver = new Resolver($teams, $stadiums, $clubs, new AliasNormalizer($aliases));

// 3) 実ページをスクレイプ
try {
    $http = new Client(['timeout' => 15]);
    $scraper = new GameMetaScraper($http, $resolver);
    $result = $scraper->scrape($url, $pageLevel);
} catch (Throwable $e) {
    $logDir = $root . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    $line = sprintf("[%s] url=%s ERROR=%s\n", date('c'), $url, $e->getMessage());
    file_put_contents($logDir . '/error.log', $line, FILE_APPEND);
    throw $e;
    // ここで終了（継続したければ return に変更）
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

// 4) 未解決をリストに保存
$unknown = new UnknownRegistry($root);

if (($result['home_team_id'] ?? null) === null && !empty($result['home_team_raw'])) {
    $unknown->record($pageLevel === 'First' ? 'teams_first' : 'teams_farm', $result['home_team_raw'], ['url' => $url, 'level' => $pageLevel, 'page_date' => $result['date']]);
}
if (($result['away_team_id'] ?? null) === null && !empty($result['away_team_raw'])) {
    $unknown->record($pageLevel === 'First' ? 'teams_first' : 'teams_farm', $result['away_team_raw'], ['url' => $url, 'level' => $pageLevel, 'page_date' => $result['date']]);
}
if (($result['stadium_id'] ?? null) === null && !empty($result['stadium_raw'])) {
    $unknown->record('stadiums', $result['stadium_raw'], ['url' => $url, 'page_date' => $result['date']]);
}

// 5) 出力と未解決ログ
$logDir = $root . '/logs';
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

// --- 未解決の標準化（表示用） ----------------------------
// 1) unresolved_keys: scraper の raw 値配列 + 欠落ID名（重複排除）
$unresolvedKeys = $result['unresolved'] ?? []; // ← scraper 由来（raw 値のみ）
foreach (['home_team_id', 'away_team_id', 'stadium_id'] as $k) {
    if (!array_key_exists($k, $result) || $result[$k] === null) {
        $unresolvedKeys[] = $k;
    }
}
$unresolvedKeys = array_values(array_unique(array_filter($unresolvedKeys, fn($v) => $v !== null && $v !== '')));

// 2) unresolved(JSON): key→raw の連想
$unresolvedMap = $result['unresolved_map'] ?? [];
if (empty($unresolvedMap)) {
    $tmp = [
        'stadium'   => [$result['stadium_id']   ?? null, $result['stadium_raw']    ?? null],
        'home_team' => [$result['home_team_id'] ?? null, $result['home_team_raw']  ?? null],
        'away_team' => [$result['away_team_id'] ?? null, $result['away_team_raw']  ?? null],
    ];
    foreach ($tmp as $k => [$id, $raw]) {
        if ($id === null && !empty($raw)) {
            $unresolvedMap[$k] = $raw;
        }
    }
}

$summary = [
    'OK',
    'url=' . ($result['url'] ?? $url),
    'level=' . $pageLevel,
    'home=' . ($result['home_team_id'] ?? 'null'),
    'away=' . ($result['away_team_id'] ?? 'null'),
    'stadium=' . ($result['stadium_id'] ?? 'null'),
];
fwrite(STDOUT, implode(PHP_EOL, $summary) . PHP_EOL . PHP_EOL);

// 未解決の内訳
if (!empty($unresolvedMap)) {
    fwrite(STDOUT, 'unresolved=' . json_encode($unresolvedMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
