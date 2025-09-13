<?php

namespace App\Resolver;

use App\Util\Path;
use App\Util\Json;

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
        $this->baseDir = Path::resolve($projectRoot, $rel);
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
        fwrite($fp, Json::encode($line) . PHP_EOL);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * ファイル名を生成
     * @param string $category
     * @param array $context
     * @return string
     */
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
}
