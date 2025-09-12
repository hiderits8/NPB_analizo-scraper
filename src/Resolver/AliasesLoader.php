<?php

declare(strict_types=1);

namespace App\Resolver;

final class AliasesLoader
{
    private ?array $cache = null;


    /**
     * @param string $projectRoot プロジェクトルート
     * @param string $baseFile ベースファイル 
     * @param string $loadFile ロードファイル
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $baseFile,
        private readonly string $loadFile,
    ) {}

    /**
     * ロード
     * キャッシュがあればそれを返す
     * なければベースファイルにロードファイルを上書きして辞書を返す（キャッシュに保存）
     * 
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $base = $this->safeRequire($this->path($this->baseFile));
        $local = $this->safeRequire($this->path($this->loadFile));

        return $this->cache = $this->mergeAssocRecursive($base, $local);
    }

    /**
     * 指定カテゴリ（例：'stadiums'、'teams'、'clubs'）に対応する部分辞書をロード
     * 
     * @param string $category カテゴリ
     * @return array<string, mixed>
     */
    public function loadFor(string $category): array
    {
        $all = $this->load();
        $section = $all[$category] ?? [];
        return is_array($section) ? $section : [];
    }

    /**
     * 指定カテゴリで $raw の名称を正規名に解決（未定義ならnull）
     */
    public function resolve(string $category, string $raw): ?string
    {
        $map = $this->loadFor($category);
        return $map[$raw] ?? null;
    }

    /**
     * キャッシュをクリア
     * 
     * 登録直後に再読み込みする時用
     */
    public function cacheClear(): void
    {
        $this->cache = null;
    }

    /**
     * ファイルを安全に読み込む
     * 
     * 存在しない場合は空配列を返す
     * 配列でない場合は例外を投げる
     * 
     * @param string $file ファイルパス
     * @return array<string, mixed>
     */
    private function safeRequire(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Alias file must return array: %s', $file));
        }

        return $data;
    }

    /**
     * 相対パスを絶対パスに変換
     * 
     * @param string $relative 相対パス
     * @return string 絶対パス
     */
    private function path(string $relative): string
    {
        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * 連想配列を再帰的にマージ
     * 
     * @param array<string, mixed> $base ベース配列
     * @param array<string, mixed> $override オーバーライド配列
     * @return array<string, mixed>
     */
    private function mergeAssocRecursive(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (array_key_exists($key, $base) && is_array($base[$key]) && is_array($value)) {
                $result[$key] = $this->mergeAssocRecursive($base[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
