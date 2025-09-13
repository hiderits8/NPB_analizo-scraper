<?php

declare(strict_types=1);

namespace App\Resolver;

final class AliasesLoader
{
    private ?array $cache = null;

    private string $baseFile;
    private string $localFile;

    /**
     * @param string $projectRoot プロジェクトルート
     * @param string $baseFile ベースファイル 
     * @param string $loadFile ロードファイル
     */
    public function __construct(
        private readonly string $projectRoot,
        ?string $baseFile  = null,
        ?string $localFile = null,
    ) {
        $this->baseFile  = $this->resolvePath($baseFile  ?? (getenv('APP_ALIAS_BASE_FILE')  ?: 'data/aliases.php'));
        $this->localFile = $this->resolvePath($localFile ?? (getenv('APP_ALIAS_LOCAL_FILE') ?: 'data/aliases.local.php'));
    }

    /**
     * キャッシュがあればそれを返す
     * なければ上位優先（local が base を上書き）で再帰マージした辞書を返す
     * 
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $base = $this->safeRequire($this->resolvePath($this->baseFile));
        $local = $this->safeRequire($this->resolvePath($this->localFile));

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
     * キャッシュクリア（登録直後に再読み込みしたい場合）
     */
    public function cacheClear(): void
    {
        $this->cache = null;
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }
        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, 'phar://');
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
