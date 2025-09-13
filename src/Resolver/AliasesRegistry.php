<?php

namespace App\Resolver;

use App\Util\Path;
use App\Util\Json;

final class AliasesRegistry
{

    private string $localFile;
    private string $logFile;

    public function __construct(
        private readonly string $projectRoot,
        ?string $localFile = null,
        ?string $logFile   = null,
    ) {
        $this->localFile = Path::resolve($projectRoot, $localFile ?? (getenv('APP_ALIAS_LOCAL_FILE') ?: 'data/aliases.local.php'));
        $this->logFile   = Path::resolve($projectRoot, $logFile   ?? (getenv('APP_ALIAS_REG_LOG')   ?: 'logs/alias_registrations.log'));
    }

    /**
     * 別名の登録（既存と同値なら no-op、異なる値がある場合は $overwrite=false で conflict）
     * 
     * 既に登録されている場合は、上書きするかどうかを指定する。
     * 登録結果はログに記録される。
     * 
     * @param string $category カテゴリ
     * @param string $raw エイリアス
     * @param string $canonical 正規化されたエイリアス
     * @param array<string, mixed> $meta メタデータ
     * @param bool $overwrite 上書きするかどうか
     * @return string 'created'|'updated'|'noop'|'conflict'
     */
    public function register(string $category, string $raw, string $canonical, array $meta = [], bool $overwrite = false): string
    {
        $dict = $this->readPhpArray($this->localFile);
        if (!isset($dict[$category]) || !is_array($dict[$category])) {
            $dict[$category] = [];
        }

        $exists = array_key_exists($raw, $dict[$category]);

        if ($exists && $dict[$category] == $canonical) {
            $result = 'noop';
        } elseif ($exists && !$overwrite) {
            $result = 'conflict';
        } else {
            $dict[$category][$raw] = $canonical;
            ksort($dict[$category], SORT_NATURAL);
            $this->writePhpArray($this->localFile, $dict);
            $result = $exists ? 'updated' : 'created';
        }
        $this->appendLog($this->logFile, [
            'ts' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            'category' => $category,
            'raw' => $raw,
            'canonical' => $canonical,
            'result' => $result,
        ] + $meta);

        return $result;
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
        fwrite($fp, Json::encode($line) . PHP_EOL);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
