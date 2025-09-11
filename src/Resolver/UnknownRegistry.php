<?php

namespace App\Resolver;

final class UnknownRegistry
{
    /**
     * コンストラクタ
     * 
     * @param string $dir 登録先ディレクトリ(/data/pending_aliases を予定)
     */
    public function __construct(private string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    /**
     * 登録処理
     * 
     * @param string $domain ドメイン(team_name / stadium_name / club_name)
     * @param string $raw
     * @param array{url?: string, level?:string, page_date?:string} $ctx
     */
    public function record(string $domain, string $raw, array $ctx = []): void
    {
        $path = "{$this->dir}/{$domain}.json";
        $list = file_exists($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];

        $key = $this->keyize($raw);
        if (!isset($list[$key])) {
            $list[$key] = [
                'raw'        => $raw,
                'first_seen' => gmdate('c'),
                'seen'       => 1,
                'samples'    => [$ctx],
            ];
        } else {
            $list[$key]['seen'] = (int)($list[$key]['seen'] ?? 0) + 1;
            $samples = $list[$key]['samples'] ?? [];
            $samples[] = $ctx;
            $list[$key]['samples'] = array_slice($samples, -5); // 最新5件だけ保持
        }

        file_put_contents($path, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * キー化
     * 
     * @param string $s
     * @return string
     */
    private function keyize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        return mb_strtolower($s, 'UTF-8');
    }
}
