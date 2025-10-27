#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Orchestrator\ScoreWalker;

require_once __DIR__ . '/_bootstrap.php';

// ========== CLI 引数 ==========
$opts = getopt('', ['url:', 'out-file::', 'pretty::']);
$url      = isset($opts['url']) ? (string)$opts['url'] : '';
$outFile = isset($opts['out-file']) ? (string)$opts['out-file'] : null;
$pretty  = array_key_exists('pretty', $opts);
$timeout = isset($opts['timeout'])
    ? max(1, (int)$opts['timeout'])
    : max(1, (int) $_ENV['HTTP_TIMEOUT_SECONDS'] ?? getenv('HTTP_TIMEOUT_SECONDS') ?? '15');

$http = make_http_client($timeout);

// ========== ScoreWalker ==========

$walker = new ScoreWalker($http);
$result = $walker->walk($url);

// ---- 出力 ----
output_json($result, $pretty, $outFile);
