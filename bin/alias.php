#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = \dirname(__DIR__);
if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

// ===== Minimal helpers (keep surface small) =====
/**
 * 標準入力から1行読み込む
 * @return string
 */
function rl(): string
{
    $l = fgets(STDIN);
    return $l === false ? '' : rtrim($l, "\r\n");
}

/**
 * 現在の日時を ISO 8601 形式で返す
 * @return string
 */
function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format(DATE_ATOM);
}

/**
 * PHP 配列をファイルから読み込む
 * @param string $file
 * @return array<string, mixed>
 */
function load_php_array(string $file): array
{
    return is_file($file) ? (require $file) : [];
}

/**
 * PHP 配列をファイルに保存する
 * @param string $file
 * @param array<string, mixed> $arr
 */
function save_php_array_atomic(string $file, array $arr): void
{
    $code = "<?php\nreturn " . var_export($arr, true) . ";\n";
    $tmp  = $file . '.tmp';
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($tmp, $code);
    if (!@rename($tmp, $file)) {
        copy($tmp, $file);
        unlink($tmp);
    }
}

/**
 * JSONL ファイルに1行を追加する
 * @param string $file
 * @param array<string, mixed> $row
 */
function append_jsonl(string $file, array $row): void
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * JSONL ファイルを読み込む
 * @param string $file
 * @return Generator<array<string, mixed>>
 */
function read_jsonl(string $file): Generator
{
    if (!is_file($file)) return;
    $fh = fopen($file, 'rb');
    if (!$fh) return;
    while (!feof($fh)) {
        $l = fgets($fh);
        if ($l === false) break;
        $l = trim($l);
        if ($l === '') continue;
        $o = json_decode($l, true);
        if (is_array($o)) yield $o;
    }
    fclose($fh);
}

// ===== Paths / constants =====
$ALIAS_BASE  = $root . '/' . (getenv('APP_ALIAS_BASE_FILE')  ?: 'data/aliases.php');
$ALIAS_LOCAL = $root . '/' . (getenv('APP_ALIAS_LOCAL_FILE') ?: 'data/aliases.local.php');
$PENDING_DIR = $root . '/' . (getenv('APP_PENDING_DIR')      ?: 'logs/pending_aliases');
$REG_LOG     = $root . '/' . (getenv('APP_ALIAS_REG_LOG')    ?: 'logs/alias_registrations.log');
@mkdir($PENDING_DIR, 0777, true);
@mkdir($PENDING_DIR . '/_resolved', 0777, true);

$CATEGORIES = ['teams_first', 'teams_farm', 'stadiums', 'clubs'];

/**
 * - 未解決のエントリーを取得
 * - 解決済みのエントリーを除外
 * - 未解決のエントリーを返す
 * - 未解決のエントリーは、解決済みのエントリーを除外したもの
 * 
 * @param string $pendingDir
 * @param string $category
 * @return array<string, array<string, mixed>>
 */
function unresolved_set(string $pendingDir, string $category): array
{
    // 未解決のエントリーを取得
    $pending = $pendingDir . "/{$category}.jsonl";
    // 解決済みのエントリーを取得
    $resolved = $pendingDir . "/_resolved/{$category}.jsonl";
    // 未解決のエントリーを格納する配列
    $seen = [];

    // 未解決のエントリーを処理
    foreach (read_jsonl($pending) as $row) {
        $raw = (string)($row['raw'] ?? '');
        if ($raw === '') continue;
        $url = (string)($row['url'] ?? '');
        $ts = (string)($row['ts'] ?? '');

        $r = &$seen[$raw];
        if (!isset($r)) $r = ['count' => 0, 'examples' => [], 'last_ts' => ''];
        $r['count']++;

        if ($url !== '' && count($r['examples']) < 3) $r['examples'][] = $url;
        if ($ts > $r['last_ts']) $r['last_ts'] = $ts;
    }

    // 解決済みのエントリーを除外
    $done = [];
    foreach (read_jsonl($resolved) as $row) {
        $raw = (string)($row['raw'] ?? '');
        if ($raw !== '') $done[$raw] = true;
    }
    foreach ($done as $raw => $_) {
        unset($seen[$raw]);
    }

    return $seen;
}

$cmd = $argv[1] ?? 'help';

switch ($cmd) {
    case 'review': {
            // カテゴリを選択
            fwrite(STDOUT, "Alias review mode\nCategories: " . implode(', ', $CATEGORIES) . "\nChoose category (enter to cancel): ");
            $category = trim(rl());
            if ($category === '' || !in_array($category, $CATEGORIES, true)) {
                fwrite(STDOUT, "Canceled.\n");
                exit(0);
            }

            // 未解決のエントリーを取得
            $set = unresolved_set($PENDING_DIR, $category);
            if (empty($set)) {
                fwrite(STDOUT, "No unresolved in '{$category}'.\n");
                exit(0);
            }

            // aliases.local.php を読み込む
            $local = load_php_array($ALIAS_LOCAL);
            if (!is_array($local)) $local = [];
            if (!isset($local[$category]) || !is_array($local[$category])) $local[$category] = [];
            if (!is_file($ALIAS_LOCAL)) save_php_array_atomic($ALIAS_LOCAL, $local);

            // 最後の更新日時でソート
            uasort($set, fn($a, $b) => ($a['last_ts'] <=> $b['last_ts']) ?: 0);
            // 未解決のエントリーを1件ずつ対話で aliases.local.php に登録し、_resolved に解消印を残す
            foreach ($set as $raw => $info) {
                // 未解決のエントリーを表示
                fwrite(STDOUT, str_repeat('-', 60) . "\nraw       : {$raw}\ncount     : {$info['count']}\n");
                if (!empty($info['examples'])) fwrite(STDOUT, "examples  : " . implode(' ', $info['examples']) . "\n");
                // 正式名称を入力
                fwrite(STDOUT, "Enter canonical (正式名称). blank=skip, q=quit: ");
                $canonical = rl();
                // 終了
                if ($canonical === 'q') {
                    fwrite(STDOUT, "Quit.\n");
                    break;
                }
                // スキップ
                if ($canonical === '') {
                    fwrite(STDOUT, "Skipped.\n");
                    continue;
                }
                // 登録確認
                fwrite(STDOUT, "Confirm register: {$category} '{$raw}' => '{$canonical}' ? [y/N]: ");
                $ans = strtolower(rl());
                if ($ans !== 'y') {
                    fwrite(STDOUT, "Canceled.\n");
                    continue;
                }
                // 登録
                $local[$category][$raw] = $canonical; // overwrite by design
                // 保存
                save_php_array_atomic($ALIAS_LOCAL, $local);
                // 解消印を残す
                append_jsonl($PENDING_DIR . "/_resolved/{$category}.jsonl", ['ts' => now_iso(), 'raw' => $raw, 'by' => get_current_user() ?: 'cli']);
                // 監査ログを残す
                file_put_contents($REG_LOG, sprintf("%s\tREVIEW\t%s\t%s\t%s\n", now_iso(), $category, $raw, $canonical), FILE_APPEND | LOCK_EX);
                // 登録完了
                fwrite(STDOUT, "Registered (local): {$raw} => {$canonical}\n");
            }
            fwrite(STDOUT, "Done.\n");
            exit(0);
        }

    case 'promote': {
            // aliases.php を読み込む
            $base  = load_php_array($ALIAS_BASE);
            // aliases.local.php を読み込む
            if (!is_array($base))  $base = [];
            // aliases.local.php を読み込む
            $local = load_php_array($ALIAS_LOCAL);
            if (!is_array($local)) $local = [];
            if (empty($local)) {
                fwrite(STDOUT, "Nothing to promote.\n");
                exit(0);
            }

            // カテゴリごとに処理
            foreach ($CATEGORIES as $cat) {
                if (!isset($local[$cat]) || !is_array($local[$cat])) continue;
                if (!isset($base[$cat])  || !is_array($base[$cat]))  $base[$cat] = [];
                foreach ($local[$cat] as $raw => $canonical) {
                    $prev = $base[$cat][$raw] ?? null;
                    $base[$cat][$raw] = $canonical; // overwrite
                    file_put_contents($REG_LOG, sprintf("%s\tPROMOTE\t%s\t%s\t%s\t(prev:%s)\n", now_iso(), $cat, $raw, $canonical, $prev === null ? '-' : $prev), FILE_APPEND | LOCK_EX);
                }
            }
            // aliases.php を保存
            save_php_array_atomic($ALIAS_BASE, $base);
            // aliases.local.php を空にする
            save_php_array_atomic($ALIAS_LOCAL, []);
            // 完了
            fwrite(STDOUT, "Promoted and local cleared.\n");
            exit(0);
        }
}

// 使い方を表示
echo <<<TXT
Alias helper (review/promote)

  review   # 未解決(raw)を1件ずつ対話で aliases.local.php に登録し、_resolved に解消印を残す
  promote  # aliases.local.php の差分を aliases.php に反映し、その後 local を空に戻す

Notes:
- 本番スクレイプは aliases.php のみ参照（ランタイム合成なし）
- 未解決ログ: logs/pending_aliases/<category>.jsonl
- 解消印:     logs/pending_aliases/_resolved/<category>.jsonl
- 監査ログ:   logs/alias_registrations.log
TXT;

exit(0);
