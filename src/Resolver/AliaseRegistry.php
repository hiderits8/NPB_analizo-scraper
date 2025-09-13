<?php

namespace App\Resolver;

final class AliaseRegistry
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $localFile = 'data/aliases.local.php',
        private readonly string $logFile = 'log/aliases_registrations.log',
    ) {}

    /**
     * エイリアスを登録する
     * 
     * 既に登録されている場合は、上書きするかどうかを指定する。
     * 登録結果はログに記録される。
     * 
     * @param string $category カテゴリ
     * @param string $raw エイリアス
     * @param string $canonical 正規化されたエイリアス
     * @param array<string, mixed> $meta メタデータ
     * @param bool $overwrite 上書きするかどうか
     * @return string 登録結果 noop: 既に登録されている、skip: 上書きしない、updated: 更新された、created: 新規登録された
     */
    public function register(string $category, string $raw, string $canonical, array $meta = [], bool $overwrite = false): string
    {
        $localPath = $this->path($this->localFile);
        $logPath = $this->path($this->logFile);

        $dict = $this->readPhpArray($localPath);
        if (!isset($dict[$category]) || !is_array($dict[$category])) {
            $dict[$category] = [];
        }

        $exists = array_key_exists($raw, $dict[$category]);

        if ($exists && $dict[$category] == $canonical) {
            $result = 'noop';
        } elseif ($exists && !$overwrite) {
            $result = 'skip';
        } else {
            $dict[$category][$raw] = $canonical;
            ksort($dict[$category], SORT_NATURAL);
            $this->writePhpArray($localPath, $dict);
            $result = $exists ? 'updated' : 'created';
        }
        $this->appendLog($logPath, [
            'ts' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'category' => $category,
            'raw' => $raw,
            'canonical' => $canonical,
            'result' => $result,
        ] + $meta);

        return $result;
    }

    /** 
     * ファイルパスを返す
     * 
     * @param string $relative 相対パス
     * @return string ファイルパス
     */
    private function path(string $relative): string
    {
        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * PHP配列を読み込む
     * 
     * @param string $file ファイルパス
     * @return array<string, mixed> PHP配列
     */
    private function readPhpArray(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /**
     * PHP配列を書き込む
     * 
     * @param string $file ファイルパス
     * @param array<string, mixed> $data データ 
     */
    private function writePhpArray(string $file, array $data): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $tmp = $file . '.tmp';
        $code = "<?php\nreturn " . var_export($data, true) . ";\n";
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open temp file: {$tmp}");
        }
        fwrite($fp, $code);
        fclose($fp);
        rename($tmp, $file);
    }

    /**
     * ログを追加する
     * 
     * @param string $file ファイルパス
     * @param array<string, mixed> $line ログ
     */
    private function appendLog(string $file, array $line): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $fp = fopen($file, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open log file: {$file}");
        }
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
