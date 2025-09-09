<?php

require_once(dirname(__DIR__) . '/vendor/autoload.php');

use App\Http\DictClient;
use App\Resolver\Resolver;
use App\Resolver\AliasNormalizer;
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeload();

$base = $_ENV['APP_API_BASE'];
$timeout = $_ENV['APP_API_TIMEOUT'];

$dict = new DictClient($base, $timeout);
$alias = new AliasNormalizer(require dirname(__DIR__) . '/data/aliases.php');
$res   = new Resolver($dict->teams(), $dict->stadiums(), $dict->clubs(), $alias);

// 簡易辞書作り（ID→名前）: 確認出力用
$teams = $dict->teams();
$tById = [];
foreach ($teams as $t) {
    $tById[(int)$t['team_id']] = $t['team_name'];
}

echo "=== First League ===\n";
$pageLevel = 'First';
$homeRaw = '阪神';
$awayRaw = '巨人';
$stRaw   = '甲子園';
$clubRaw = '読売';

$homeId = $res->resolveTeamIdFuzzy($homeRaw, $pageLevel);
$awayId = $res->resolveTeamIdFuzzy($awayRaw, $pageLevel);
$stId   = $res->resolveStadiumIdFuzzy($stRaw);
$clubId = $res->resolveClubIdFuzzy($clubRaw);

printf("home: raw=%s => id=%s (%s)\n", $homeRaw, var_export($homeId, true), $homeId ? $tById[$homeId] : 'N/A');
printf("away: raw=%s => id=%s (%s)\n", $awayRaw, var_export($awayId, true), $awayId ? $tById[$awayId] : 'N/A');
printf("stadium: raw=%s => id=%s\n", $stRaw, var_export($stId, true));
printf("club: raw=%s => id=%s\n", $clubRaw, var_export($clubId, true));

echo "\n=== Farm League ===\n";
$pageLevel = 'Farm';
$homeRaw = '阪神';
$awayRaw = '読売';
$stRaw   = 'マツダスタジアム'; // 例: スタ球場の別名
$clubRaw = 'ジャイアンツ';

$homeId = $res->resolveTeamIdFuzzy($homeRaw, $pageLevel);
$awayId = $res->resolveTeamIdFuzzy($awayRaw, $pageLevel);
$stId   = $res->resolveStadiumIdFuzzy($stRaw);
$clubId = $res->resolveClubIdFuzzy($clubRaw);

printf("home: raw=%s => id=%s (%s)\n", $homeRaw, var_export($homeId, true), $homeId ? $tById[$homeId] : 'N/A');
printf("away: raw=%s => id=%s (%s)\n", $awayRaw, var_export($awayId, true), $awayId ? $tById[$awayId] : 'N/A');
printf("stadium: raw=%s => id=%s\n", $stRaw, var_export($stId, true));
printf("club: raw=%s => id=%s\n", $clubRaw, var_export($clubId, true));

echo "\nOK\n";
