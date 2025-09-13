<?php

namespace App\Resolver;

/**
 * 未解決エイリアスのキューを JSON Lines で蓄積する。
 * 出力先は APP_PENDING_DIR（既定: ./logs/pending_aliases）
 */
final class UnknownRegistry
{
    private string $baseDir;

    public function __construct(
        private readonly string $projectRoot,
        ?string $baseDir = null,
    ) {
        $rel = $baseDir ?? (getenv('APP_PENDING_DIR') ?: 'logs/pending_aliases');
        $this->baseDir = $this->resolvePath($rel);
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
        }
    }

    /**
     * 1件記録
     * @param string $category 例: 'stadium','club','team' など
     * @param string $raw      生テキスト
     * @param array  $context  例: ['url'=>..., 'page_hint'=>..., 'level'=>'First'|'Farm']
     */
    public function record(string $category, string $raw, array $context = []): void
    {
        $line = [
            'ts' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'category' => $category,
            'raw' => $raw,
            'context' => $context,
        ];

        $file = $this->baseDir . DIRECTORY_SEPARATOR . $this->fileNameFor($category, $context);
        $fp = fopen($file, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open pending file: {$file}");
        }
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function fileNameFor(string $category, array $context): string
    {
        // 規約: stadiums.jsonl / teams_first.jsonl / teams_farm.jsonl / clubs.jsonl / その他は <category>.jsonl
        return match ($category) {
            'stadiums' => 'stadiums.jsonl',
            'club'    => 'clubs.jsonl',
            'team'    => 'teams_' . (strtolower((string)($context['level'] ?? ''))) . '.jsonl',
            default => $category . '.jsonl',
        };
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
}
