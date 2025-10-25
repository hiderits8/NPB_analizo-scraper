# NPB Analizo Scraper (Nightly Job)

このリポジトリは、NPB-analizo-app のために AWS 上で深夜帯に定期実行されるスクレイパーツールを構築することを目的としています。最終的には、Yahoo! JAPAN のプロ野球ページやその他の公開ソースから 1 日分の試合情報をまとめて取得し、翌日のアプリ配信に必要なデータを確定させるバッチジョブになります。
（現状は CLI 中心の実装段階）

- **深夜バッチ**: EventBridge/CloudWatch で日次（例: 01:30 JST）にスケジュールされた ECS Fargate を用い、当日分の `/top` `/score` `/stats` ページを順次クロール。
- **API 供給用データ生成**: 抽出した結果を NPB Analizo の内部 API と互換性のある JSON/NDJSON 形式に整形する。
- **辞書ベースの正規化**: `DictClient` で取得した公式辞書と `AliasNormalizer` により、チーム名・球場名・クラブ名をアプリ内 ID へマッピング。未解決テキストは別キューに溜め、運用者が補完できるようにします。
- **ロギングと監視**: 失敗ログ/未解決ログをアラーム発火で再実行やエイリアス登録フローに繋げる計画です。

## 想定アーキテクチャ (日次実行)
1. **URL 供給レイヤー**  
   - 当日中に予定された試合一覧ページから URL リストを生成。  
2. **スクレイパーワーカー**  
   - `ScoreWalker` + `GameScoreScraper` は `/score` の「前へ/次へ」を辿り、1 試合の全打席を取得。
    - `bin/top_scrape.php` / `bin/stats_scrape.php` はテスト用の 1 ページタスク。
3. **成果物保管**  
   - メタデータ（試合 ID、処理ステータス、リトライ回数）は Amazon RDS に記録。
4. **未解決名フロー**  
   - `logs/pending_aliases/*.jsonl` を集約し、1 日 1 回 Slack/メールでサマリ通知予定。  
   - 運用者が `alias.php` を使って別名辞書へ登録 → 次回のワーカー起動時に反映。

## 現在のコンポーネント

| コンポーネント                                 | 役割                                                       |
| ---------------------------------------------- | ---------------------------------------------------------- |
| `GameTopScraper` + Extractors                  | `/top` ページから試合メタ・スタメン・ベンチ情報を抽出      |
| `GameScoreScraper` + Extractors                | `/score?index=` ページの BSO, 結果イベント, 投球詳細を抽出 |
| `GameStatsScraper`                             | `/stats` ページのイニング別スコア・打撃/投手成績を抽出     |
| `Resolver`, `AliasNormalizer`, `AliasesLoader` | API 辞書とローカル別名リストを組み合わせて ID 解決         |
| `bin/alias.php`                                | 未解決名を運用者が手動登録する CLI                         |
| `src/Orchestrator/ScoreWalker.php`             | `/score` ページを「前へ」「次へ」で巡回し、重複なく収集    |

## ディレクトリ構成（現状）

| Path                                     | 役割                                                      |
| ---------------------------------------- | --------------------------------------------------------- |
| `bin/`                                   | CLI エントリーポイント。AWS ワーカー化のベース。          |
| `src/Scraper/Top` / `Score` / `Stats`    | ページ種別ごとのスクレイパー + 抽出器。                   |
| `src/Orchestrator`                       | ページ遷移を制御するユーティリティ (`ScoreWalker` など)。 |
| `src/Resolver` / `src/Http` / `src/Util` | 辞書解決、API クライアント、共通ヘルパー。                |
| `data/`                                  | ベース/ローカル別名辞書。                                 |
| `logs/`                                  | ローカル実行時の成果物・未解決名。                        |
| `test/`                                  | 依存 API と Resolver のスモークテスト。                   |

## 必要環境（開発フェーズ）
- PHP **8.4** (`mbstring`, `dom`, `json`, `curl`, `iconv`)
- Composer 2.x
