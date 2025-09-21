#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

// Load environment variables (.env, then .env.local to override)
$projectRoot = dirname(__DIR__);
if (class_exists(Dotenv::class)) {
    $envFiles = [];
    if (file_exists($projectRoot . '/.env')) {
        $envFiles[] = '.env';
    }
    if (file_exists($projectRoot . '/.env.local')) {
        $envFiles[] = '.env.local';
    }
    if (!empty($envFiles)) {
        // shortCircuit=false to load both (later ones override previous)
        Dotenv::createImmutable($projectRoot, $envFiles, false)->safeLoad();
    }
}

// ---- helpers ----
function stderr(string $msg): void
{
    fwrite(STDERR, $msg . PHP_EOL);
}

function fail(int $code, string $msg): never
{
    stderr($msg);
    exit($code);
}

/**
 * JSON 出力ヘルパ
 * - $pretty: 整形出力（JSON_PRETTY_PRINT）
 * - $outFile: パス指定でファイル出力（未指定なら標準出力）
 */
function output_json(array $data, bool $pretty = false, ?string $outFile = null): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        fail(3, 'json_encode failed: ' . json_last_error_msg());
    }

    if ($outFile !== null && $outFile !== '') {
        // Ensure directory exists
        $dir = dirname($outFile);
        if ($dir !== '.' && !is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                fail(3, "failed to create directory: {$dir}");
            }
        }
        if (@file_put_contents($outFile, $json . PHP_EOL) === false) {
            fail(3, "failed to write: {$outFile}");
        }
    } else {
        echo $json, PHP_EOL;
    }
}

/** Guzzle クライアントを作成（最小限のデフォルトを付与） */
function make_http_client(int $timeout = 15): Client
{
    return new Client([
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'allow_redirects' => ['max' => 5, 'strict' => false],
        'headers' => [
            'User-Agent' => $_ENV['SCRAPER_UA_PRODUCT'] ?? getenv('SCRAPER_UA_PRODUCT') .
                $_ENV['SCRAPER_UA_VERSION'] ?? getenv('SCRAPER_UA_VERSION') .
                $_ENV['SCRAPER_UA_PLATFORM'] ?? getenv('USER_AGENT_PLATFORM'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ja,en-US;q=0.7,en;q=0.3',
        ],
        // verify は true のまま（必要なら CA 設定）
        'verify' => true,
    ]);
}

/** HTML を取得（2xx 以外やネットワークエラーで fail） */
function http_get(Client $client, string $url): string
{
    try {
        $res = $client->request('GET', $url);
        $code = $res->getStatusCode();
        if ($code < 200 || $code >= 300) {
            fail(2, "HTTP status {$code}");
        }
        return (string) $res->getBody();
    } catch (GuzzleException $e) {
        fail(2, 'HTTP error: ' . $e->getMessage());
    }
}
