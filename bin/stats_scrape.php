#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Scraper\Stats\GameStatsScraper;

require_once __DIR__ . '/_bootstrap.php';

// ---- args ----
$opts = getopt('', [
    'url:',        // required
    'timeout::',   // optional int
    'pretty',      // flag
    'out-file::',  // optional path
]);

$url = isset($opts['url']) ? (string)$opts['url'] : '';
if ($url === '') fail(1, '--url is required');

$timeout = isset($opts['timeout'])
    ? max(1, (int)$opts['timeout'])
    : max(1, (int) $_ENV['HTTP_TIMEOUT_SECONDS'] ?? getenv('HTTP_TIMEOUT_SECONDS') ?? '15');
$pretty  = array_key_exists('pretty', $opts);
$outFile = isset($opts['out-file']) ? (string)$opts['out-file'] : null;

// ---- fetch & scrape ----
$http = make_http_client($timeout);

// GameStatsScraper は既存実装を想定（$crawler と URL を渡す）
$scraper = new GameStatsScraper($http);
$result  = $scraper->scrape($url);

// ---- 出力 ----
output_json($result, $pretty, $outFile);
