<?php

require_once 'vendor/autoload.php';

use App\Http\DictClient;
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeload();

$base = $_ENV['APP_API_BASE'];
$timeout = $_ENV['APP_API_TIMEOUT'];

$dict = new DictClient($base, $timeout);

$teams = $dict->teams();
$stadiums = $dict->stadiums();
$clubs = $dict->clubs();

echo "teams: " . count($teams) . PHP_EOL;
echo "stadiums: " . count($stadiums) . PHP_EOL;
echo "clubs: " . count($clubs) . PHP_EOL;

echo "teams: first 3" . PHP_EOL;
echo json_encode(array_slice($teams, 0, 3), JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "stadiums: first 3" . PHP_EOL;
echo json_encode(array_slice($stadiums, 0, 3), JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "clubs: first 3" . PHP_EOL;
echo json_encode(array_slice($clubs, 0, 3), JSON_UNESCAPED_UNICODE) . PHP_EOL;
