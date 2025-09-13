<?php

declare(strict_types=1);

namespace App\Resolver;

use App\Util\Path;

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
        $this->baseFile  = Path::resolve($projectRoot, $baseFile  ?? (getenv('APP_ALIAS_BASE_FILE')  ?: 'data/aliases.php'));
        $this->localFile = Path::resolve($projectRoot, $localFile ?? (getenv('APP_ALIAS_LOCAL_FILE') ?: 'data/aliases.local.php'));
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

        $base = $this->safeRequire($this->baseFile);
        $local = $this->safeRequire($this->localFile);

        return $this->cache = $this->mergeAssocRecursive($base, $local);
    }


    /**
     * 基本辞書（aliases.php）のみを読み込む（ランタイム推奨）
     * @return array<string,mixed>
     */
    public function loadBase(): array
    {
        return $this->safeRequire($this->baseFile);
    }

    /**
     * ローカル辞書（aliases.local.php）のみを読み込む（レビュー用）
     * @return array<string,mixed>
     */
    public function loadLocal(): array
    {
        return $this->safeRequire($this->localFile);
    }

    /**
     * 上位優先でマージ（local が base を上書き）。管理系でのみ使用。
     * @return array<string,mixed>
     */
    public function loadMerged(): array
    {
        return $this->mergeAssocRecursive($this->loadBase(), $this->loadLocal());
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
