# テーブル定義書

---

## Club

| 項目名     | データ型     | 制約 | 説明                         |
|------------|--------------|------|------------------------------|
| club_id    | INT          | PK   | クラブの一意識別子（球団）     |
| club_name  | VARCHAR(100) |      | 例: 読売ジャイアンツ           |
| created_at | DATETIME     |      | 作成日時                     |
| updated_at | DATETIME     |      | 更新日時                     |

---

## Team

| 項目名     | データ型     | 制約 | 説明                                             |
|------------|--------------|------|--------------------------------------------------|
| team_id    | INT          | PK   | チームの一意識別子                                |
| club_id    | INT          | FK   | 所属クラブID（Club.club_id）                     |
| team_name  | VARCHAR(100) |      | チーム名（例: 読売ジャイアンツ（一軍）など）        |
| league     | VARCHAR(50)  |      | 所属リーグ（例：Central、Eastern など）            |
| level      | VARCHAR(20)  |      | レベル（First, Farm など）                        |
| created_at | DATETIME     |      | レコード作成日時                                  |
| updated_at | DATETIME     |      | 最終更新日時                                      |

---

## Stadium

| 項目名       | データ型     | 制約 | 説明                         |
|--------------|--------------|------|------------------------------|
| stadium_id   | INT          | PK   | 球場の一意識別子             |
| stadium_name | VARCHAR(100) |      | 球場名                       |
| is_dome      | BOOLEAN      |      | ドーム球場の場合 TRUE         |
| created_at   | DATETIME     |      | レコード作成日時             |
| updated_at   | DATETIME     |      | 最終更新日時                 |

---

## GameCategory

| 項目名        | データ型     | 制約 | 説明                                                        |
|---------------|--------------|------|-------------------------------------------------------------|
| category_id   | INT          | PK   | カテゴリの一意識別子                                         |
| category_name | VARCHAR(50)  |      | 例: Official, Farm, Interleague, CS, JapanSeries, Open       |
| created_at    | DATETIME     |      | 作成日時                                                    |
| updated_at    | DATETIME     |      | 更新日時                                                    |

---

## Game

| 項目名           | データ型     | 制約 | 説明                                                |
|------------------|--------------|------|-----------------------------------------------------|
| game_id          | INT          | PK   | 試合の一意識別子                                     |
| season_year      | INT          |      | シーズン年度（2月始まり）                             |
| game_date        | DATE         |      | 試合日                                              |
| stadium_id       | INT          | FK   | 使用球場ID（Stadium.stadium_id）                     |
| home_team_id     | INT          | FK   | ホームチームID（Team.team_id）                       |
| away_team_id     | INT          | FK   | アウェイチームID（Team.team_id）                     |
| final_score_home | INT          |      | ホームチーム最終得点                                  |
| final_score_away | INT          |      | アウェイチーム最終得点                                  |
| status           | VARCHAR(20)  |      | 試合状態（scheduled, completed, cancelled）         |
| is_nighter       | VARCHAR(10)  |      | ナイターかどうか（17時以降開始ならTRUE）              |
| category_id      | INT          | FK   | 試合カテゴリID（GameCategory.category_id）           |
| created_at       | DATETIME     |      | 作成日時                                             |
| updated_at       | DATETIME     |      | 更新日時                                             |

---

## Player

| 項目名         | データ型     | 制約 | 説明                                   |
|----------------|--------------|------|----------------------------------------|
| player_id      | INT          | PK   | 選手の一意識別子                        |
| official_name  | VARCHAR(100) |      | 最新の公式登録名（例: 坂本勇人）         |
| display_name   | VARCHAR(100) |      | 最新の表示名（例: 坂本 or 坂本勇）       |
| english_name   | VARCHAR(100) |      | 最新の英語公式登録名（例: Hayato Sakamoto） |
| date_of_birth  | DATE         |      | 生年月日                                |
| height         | INT          |      | 身長 (cm)                              |
| weight         | INT          |      | 体重 (kg)                              |
| throws_left    | BOOLEAN      |      | 左投げならTRUE                          |
| throws_right   | BOOLEAN      |      | 右投げならTRUE                          |
| bats_left      | BOOLEAN      |      | 左打ちならTRUE                          |
| bats_right     | BOOLEAN      |      | 右打ちならTRUE                          |
| created_at     | DATETIME     |      | 作成日時                                |
| updated_at     | DATETIME     |      | 更新日時                                |

---

## PlayerNameHistory

| 項目名         | データ型     | 制約 | 説明                                                         |
|----------------|--------------|------|--------------------------------------------------------------|
| history_id     | INT          | PK   | 履歴レコードの一意識別子                                      |
| player_id      | INT          | FK   | 対象選手のID（Player.player_id）                              |
| name           | VARCHAR(100) |      | 変更された名前（例: 坂本勇人, 坂本勇）                         |
| name_type      | VARCHAR(20)  |      | 名称の種類 ("official", "display", "english")             |
| effective_date | DATE         |      | この名前が有効になった日                                       |
| end_date       | DATE         |      | この名前の終了日（現状ならNULL）                               |
| created_at     | DATETIME     |      | 履歴作成日時                                                 |
| updated_at     | DATETIME     |      | 履歴更新日時                                                 |

---

## ClubMembership

| 項目名        | データ型 | 制約 | 説明                                      |
|---------------|----------|------|-------------------------------------------|
| membership_id | INT      | PK   | 在籍レコードID                             |
| player_id     | INT      | FK   | 選手ID（Player.player_id）                  |
| club_id       | INT      | FK   | クラブID（Club.club_id）                    |
| start_date    | DATE     |      | 所属開始日                                  |
| end_date      | DATE     |      | 所属終了日（現役ならNULL）                   |
| uniform_number| INT      |      | 背番号（任意、年次で変動可）                 |
| created_at    | DATETIME |      | 作成日時                                    |
| updated_at    | DATETIME |      | 更新日時                                    |
> 注: 期間重複の検知はアプリ/ETL側で行う（DB制約では表現が難しい）。


---

## PlayerGameAppearance

| 項目名          | データ型  | 制約 | 説明                                                                 |
|-----------------|-----------|------|----------------------------------------------------------------------|
| appearance_id   | INT       | PK   | 出場記録の一意識別子                                                   |
| game_id         | INT       | FK   | 試合ID（Game.game_id）                                                |
| player_id       | INT       | FK   | 選手ID（Player.player_id）                                            |
| team_id         | INT       | FK   | その試合で所属するチーム（Team.team_id）                               |
| position        | VARCHAR(20)|     | 出場ポジション（Pitcher, Catcher, Infield, Outfield 等）               |
| start_inning    | INT       |      | 出場開始イニング（例: スタメンなら1）                                   |
| end_inning      | INT       |      | 出場終了イニング（交代があればそのイニング、無ければ最終イニング）        |
| outs_recorded   | INT       |      | **出場アウト数**（※er.pu準拠のカラム名。typoだが er.pu に合わせる）      |
| created_at      | DATETIME  |      | 作成日時                                                               |
| updated_at      | DATETIME  |      | 更新日時                                                               |
> UNIQUE: (game_id, player_id, start_inning)


---

## PlayByPlay

| 項目名                | データ型    | 制約 | 説明                                                                 |
|-----------------------|-------------|------|----------------------------------------------------------------------|
| pbp_id                | INT         | PK   | インプレーの一意識別子                                                |
| game_id               | INT         | FK   | 試合ID（Game.game_id）                                                |
| inning                | INT         |      | イニング番号                                                           |
| top_bottom            | VARCHAR(1)  |      | T: 上, B: 下                                                           |
| pbp_sequence          | INT         |      | イニング内シーケンス番号（1開始）                                       |
| anchor_pitch_sequence | INT         |      | 投球シーケンス番号（0開始：非投球イベントもあるため）                    |
| count_b               | INT         |      | 現在のボール数                                                         |
| count_s               | INT         |      | 現在のストライク数                                                     |
| count_o               | INT         |      | 現在のアウト数                                                         |
| batter_id             | INT         | FK   | 打者の選手ID（Player.player_id）                                       |
| pitcher_id            | INT         | FK   | 投手の選手ID（Player.player_id）                                       |
| runner_first_id       | INT         | FK   | 1塁走者（NULL可）                                                      |
| runner_second_id      | INT         | FK   | 2塁走者（NULL可）                                                      |
| runner_third_id       | INT         | FK   | 3塁走者（NULL可）                                                      |
| event_type            | VARCHAR(50) |      | イベント種別（play, substitution, pitch, steal, error, advancement, wild_pitch, passed_ball, interference） |
| created_at            | DATETIME    |      | 作成日時                                                               |
| updated_at            | DATETIME    |      | 更新日時                                                               |
> UNIQUE: (game_id, inning, top_bottom, pbp_sequence)

---

## PitchEvent

| 項目名                | データ型     | 制約 | 説明                                     |
|-----------------------|--------------|------|------------------------------------------|
| event_id              | INT          | PK   | 投球イベントの一意識別子                  |
| pbp_id                | INT          | FK   | 関連するPlayByPlayのID（PlayByPlay.pbp_id）|
| pitcher_id            | INT          | FK   | 投手の選手ID（Player.player_id）          |
| batter_id             | INT          | FK   | 打者の選手ID（Player.player_id）          |
| pitch_velocity        | INT          |      | 球速 (km/h)                               |
| pitch_type            | VARCHAR(50)  |      | 球種（Fastball, Curve, Slider など）       |
| pitch_location_x      | FLOAT        |      | 投球位置X（正規化: 0～1）                 |
| pitch_location_y      | FLOAT        |      | 投球位置Y（正規化: 0～1）                 |
| swing                 | BOOLEAN      |      | スイング有無                               |
| hit_bases             | INT          |      | ヒットの場合の塁打数（0～4）               |
| contact_made          | BOOLEAN      |      | コンタクト成立有無                         |
| pitcher_hand          | VARCHAR(10)  |      | 投手の利き腕（Left, Right, Both）          |
| batter_hand           | VARCHAR(10)  |      | 打者の利き腕（Left, Right, Both）          |
| pitch_count_in_inning | INT          |      | イニング内投球番号                          |
| pitch_count_in_game   | INT          |      | 試合内通算投球番号                          |
| created_at            | DATETIME     |      | 作成日時                                   |
| updated_at            | DATETIME     |      | 更新日時                                   |
> UNIQUE: (pbp_id) — PBP 1件に最大1

---

## StealEvent

| 項目名        | データ型 | 制約 | 説明                                    |
|---------------|----------|------|-----------------------------------------|
| event_id      | INT      | PK   | 盗塁イベントの一意識別子                 |
| pbp_id        | INT      | FK   | 関連するPlayByPlayのID                   |
| runner_id     | INT      | FK   | 盗塁試行選手のID                         |
| attempted_base| INT      |      | 試行塁（2=二塁, 3=三塁, 4=本塁）          |
| steal_success | BOOLEAN  |      | 盗塁成功ならTRUE                         |
| created_at    | DATETIME |      | 作成日時                                 |
| updated_at    | DATETIME |      | 更新日時                                 |

---

## SubstitutionEvent

| 項目名        | データ型     | 制約 | 説明                                                           |
|---------------|--------------|------|----------------------------------------------------------------|
| event_id      | INT          | PK   | 交代イベントの一意識別子                                         |
| pbp_id        | INT          | FK   | 関連するPlayByPlayのID                                           |
| from_position | VARCHAR(20)  |      | 交代前ポジション（Infield/Outfield/Pitcher/Catcher/Bench）        |
| to_position   | VARCHAR(20)  |      | 交代後ポジション（Infield/Outfield/Pitcher/Catcher/Bench）        |
| player_id     | INT          | FK   | 交代対象の選手ID                                                 |
| appearance_id | INT          | FK   | 関連するPlayerGameAppearance ID                                   |
| created_at    | DATETIME     |      | 作成日時                                                         |
| updated_at    | DATETIME     |      | 更新日時                                                         |
> UNIQUE: (appearance_id) — 途中出場のみ起点

---

## AdvancementEvent

| 項目名   | データ型 | 制約 | 説明                 |
|----------|----------|------|----------------------|
| event_id | INT      | PK   | 進塁イベントの一意識別子 |
| pbp_id   | INT      | FK   | 関連するPlayByPlayのID |
| player_id| INT      | FK   | 進塁対象の選手ID        |
| from_base| INT      |      | 進塁前の塁（1=一塁 等）   |
| to_base  | INT      |      | 進塁後の塁（2=二塁 等）   |
| created_at| DATETIME|      | 作成日時               |
| updated_at| DATETIME|      | 更新日時               |

---

## ErrorEvent

| 項目名             | データ型     | 制約 | 説明                                             |
|--------------------|--------------|------|--------------------------------------------------|
| event_id           | INT          | PK   | エラーイベントの一意識別子                         |
| pbp_id             | INT          | FK   | 関連するPlayByPlayのID                             |
| player_id          | INT          | FK   | エラーを犯した選手のID                             |
| error_context      | VARCHAR(50)  |      | エラー状況（batted_ball, pickoff, throwing_error） |
| defensive_position | VARCHAR(20)  |      | エラー発生時の守備ポジション                        |
| created_at         | DATETIME     |      | 作成日時                                          |
| updated_at         | DATETIME     |      | 更新日時                                          |

---

## OtherEvent

| 項目名        | データ型     | 制約 | 説明                                                             |
|---------------|--------------|------|------------------------------------------------------------------|
| event_id      | INT          | PK   | その他イベントの一意識別子                                         |
| pbp_id        | INT          | FK   | 関連するPlayByPlayのID                                            |
| event_subtype | VARCHAR(50)  |      | サブタイプ（balk, wild_pitch, passed_ball, interference 等）       |
| detail        | VARCHAR(255) |      | 補足情報                                                          |
| created_at    | DATETIME     |      | 作成日時                                                          |
| updated_at    | DATETIME     |      | 更新日時                                                          |

---

## PlayerGameStats

| 項目名        | データ型 | 制約 | 説明                                               |
|---------------|----------|------|----------------------------------------------------|
| stats_id      | INT      | PK   | 統計レコードの一意識別子                             |
| game_id       | INT      | FK   | 試合ID（Game.game_id）                               |
| player_id     | INT      | FK   | 選手ID（Player.player_id）                           |
| AB            | INT      |      | 打席数                                              |
| R             | INT      |      | 得点                                                |
| H             | INT      |      | 安打数                                              |
| doubles       | INT      |      | 二塁打数                                            |
| triples       | INT      |      | 三塁打数                                            |
| HR            | INT      |      | 本塁打数                                            |
| RBI           | INT      |      | 打点                                                |
| SO            | INT      |      | 三振（打者）                                        |
| BB            | INT      |      | 四球                                                |
| HBP           | INT      |      | 死球                                                |
| SacBunt       | INT      |      | 犠打                                                |
| SacFly        | INT      |      | 犠飛                                                |
| SB            | INT      |      | 盗塁                                                |
| E             | INT      |      | 失策                                                |
| IP            | FLOAT    |      | 投球回数（Innings Pitched）                          |
| Pitches       | INT      |      | 投球数                                              |
| BF            | INT      |      | 対戦打者数                                          |
| H_allowed     | INT      |      | 被安打数                                            |
| HR_allowed    | INT      |      | 被本塁打数                                          |
| K             | INT      |      | 奪三振（投手）                                       |
| BB_given      | INT      |      | 与四球数                                            |
| HBP_given     | INT      |      | 与死球数                                            |
| R_allowed     | INT      |      | 失点                                                |
| ER            | INT      |      | 自責点                                              |
| W             | INT      |      | 勝利                                                |
| L             | INT      |      | 敗戦                                                |
| Holds         | INT      |      | ホールド                                             |
| SV            | INT      |      | セーブ                                               |
| outs_recorded | INT      |      | **出場アウト数**（PlayerGameAppearance の集計値）     |
| created_at    | DATETIME |      | 統計レコード作成日時                                  |
| updated_at    | DATETIME |      | 統計レコード更新日時                                  |
> UNIQUE: (game_id, player_id)

---

## 制約・設計メモ（er.pu の note 反映）

- **PlayByPlay**: UNIQUE (game_id, inning, top_bottom, pbp_sequence)
- **PitchEvent**: UNIQUE (pbp_id) — PBP 1件に最大1
- **PlayerGameStats**: UNIQUE (game_id, player_id)
- **PlayerNameHistory**: UNIQUE (player_id, name_type, effective_date)
- **PlayerGameAppearance**: UNIQUE (game_id, player_id, start_inning)
- **SubstitutionEvent**: UNIQUE (appearance_id)
- **ClubMembership**: 期間重複はアプリ/ETLで検知（DBでは困難）

---
