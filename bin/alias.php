#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Resolver\AliasesRegistry;
use App\Resolver\AliasesLoader;
use Dotenv\Dotenv;

$root = \dirname(__DIR__);

// .env 読み込み（存在すれば）
if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

$cmd = $argv[1] ?? null;

$registry = new AliasesRegistry($root);
$loader = new AliasesLoader($root);

switch ($cmd) {
    case 'register':
        // usage: register <category> <raw> <canonical> [--source=URL] [--note=TEXT] [--overwrite]
        $category = $argv[2] ?? null;
        $raw = $argv[3] ?? null;
        $canonical = $argv[4] ?? null;
        $opts = getopt('', ['source::', 'note::', 'overwrite']);
        if (!$category || !$raw || !$canonical) {
            fwrite(STDERR, "Usage: php bin/alias.php register <category> <raw> <canonical> [--source=URL] [--note=TEXT] [--overwrite]\n");
            exit(1);
        }
        $meta = [];
        if (!empty($opts['source'])) $meta['source'] = $opts['source'];
        if (!empty($opts['note'])) $meta['note'] = $opts['note'];

        $result = $registry->register($category, $raw, $canonical, $meta, isset($opts['overwrite']));
        $loader->cacheClear();
        echo "[{$result}] $category: {$raw} => {$canonical}" . PHP_EOL;
        exit(0);

    case 'resolve':
        // usage: resolve <category> <raw>
        $category = $argv[2] ?? null;
        $raw = $argv[3] ?? null;
        if (!$category || !$raw) {
            fwrite(STDERR, "Usage: php bin/alias.php resolve <category> <raw>\n");
            exit(1);
        }
        $resolved = $loader->resolve($category, $raw);
        echo $resolved !== null ? $resolved . PHP_EOL : "(not found)\n";
        exit(0);

    case 'help':
        echo <<<TXT
Alias helper

  register <category> <raw> <canonical> [--source=URL] [--note=TEXT] [--overwrite]
  resolve  <category> <raw>

Examples:
  php bin/alias.php register stadium '甲子園' '阪神甲子園球場' --source='https://...' --note='略称->正式'
  php bin/alias.php resolve stadium '甲子園'

TXT;
        exit(0);
}
