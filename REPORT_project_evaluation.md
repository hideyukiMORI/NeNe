# NeNe プロジェクト 評価レポート

評価日: 2026-05-23

---

## 1. アーキテクチャ設計 — 88 / 100

### 強み
- `URL → Dispatcher → Controller::method()` の流れが誰でも5分以内に追える。"visible routing" の約束を完全に守っている
- `HttpTermination` 例外によるレスポンス終了パターンが一貫しており、`header()`/`exit()` の乱用を排除できている
- `HttpResponse` が不変値オブジェクト（`readonly`）— テスト容易性が高い
- `CsrfProtectionPolicy` の抽出（ADR-0003経緯）と `SchemaCompiler` の導入（ADR-0005）は設計判断として正しい
- `class/xion/` がフレームワーク核、`class/controller/` 等がアプリ層という**ドキュメント上の**境界は明確

### 懸念
- `AuthSession`/`ErrorCode`/`RouteContext` などシングルトンが多用されており、constructor injection が全くない。テストで状態をリセットしにくい局面が将来出うる
- `ControllerBase::run()` 内の `if ($controller != 'debug')` — ハードコード定数に見えるが実はコントローラ名文字列比較。この "debug" はフレームワーク特権コントローラとして正式に文書化されていない
- `DataModelBase` の magic `__get`/`__set` が Phan baseline に5件残存。旧来パターンとして意図保存だが、将来の型安全化の足かせになりうる

---

## 2. コード品質 — 84 / 100

### 強み
- 全ファイル `declare(strict_types=1)` 徹底
- PHP 8.4 typed properties、`readonly` constructor promotion を適切に使用
- Phan baseline が僅か **6件**（うち5件が DataModelBase の legacy magic patterns、1件が PdoConnection の定数）
- `final` / `never` / `?self` の型宣言が整備済み
- PHP CS Fixer 設定済み

### 懸念
- `$REQUEST_JSON` の型が `array`（非parameterized）。`array<string, mixed>` とすれば Phan がより深く追える
- `ControllerBase` の protected プロパティが大文字 (`$VIEW`, `$LOGGER` 等) — コーディング標準に "legacy surface のみ保存" と記載されているが、新規サブクラス作成者が混乱するケースがある
- `ApiResponse::success()` と `ApiResponse::failure()` が返す配列形状が異なる（success は `status`+データ混在、failure は固定3キー）。型情報上は両方 `array<string,mixed>` で区別不能

---

## 3. テスト — 87 / 100

### 強み
- Unit (57テスト) + HTTP smoke (23テスト) + OpenAPI contract runtime test の3層構成
- `OpenApiRuntimeContractTest` が `openapi.yaml` を読んで**自動的に全エンドポイントを網羅**する仕組みは秀逸
- `SchemaCompilerTest::testDockerInitSqlMatchesCompiledOutput()` — drift gate として ADR と連動する設計が美しい
- error-code/docs-sync テスト（FT10 F-5 由来）が `config/error_codes.php` と `error-codes.md` の乖離を自動検出
- フィールドトライアル方法論（ADR-0002）が継続的な外部からの usability 検証として機能している

### 懸念
- ソースコード比: 本体 **5363行** vs テスト **2175行** — 比率として薄め。特に `DataModelBase`/`DataMapperBase` のロジックに対する unit test がほぼなし
- `TransactionManager` の unit test はあるが Happy Path のみ。nested transaction（例外中の rollback）のパスは未カバー
- CI にコードカバレッジレポートなし
- HTTP テストは `NENE_HTTP_BASE_URL` 環境変数依存で、Docker 起動なしでは全スキップ

---

## 4. セキュリティ — 88 / 100

### 強み
- CSRF: `hash_equals()` でタイミング攻撃耐性あり、REST 側は自動適用、HTML 側は `requireCsrfFromPost()` を明示呼び出し
- セッション: ログイン時に `session_regenerate_id(true)` で固定セッション攻撃対策
- パスワード: サンプルで bcrypt ハッシュ保存、プレーンテキストなし
- `UploadedFile::mime()` が `finfo` でサーバ側判定（クライアント提供の `type` を無視）
- `Content-Disposition` ファイル名のサニタイズ（`"` `\r` `\n` を除去）
- 本番では `NENE_APP_DEBUG=0` でスタックトレース非公開
- PDO プリペアドステートメント使用（SQL インジェクション対策）

### 懸念
- `Request::getFile()` は `$_FILES` エントリが存在すれば常に `UploadedFile` を生成するため、`tmp_name` が空文字でも `new UploadedFile(...)` が作られてしまう（`isValid()` で弾けるが少し脆弱なパス）
- `Request::getQuery()` / `getPost()` に XSS 対策なし（Smarty 側の `setEscapeHtml(true)` に依存。フレームワーク層は素通し）
- レートリミットなし（小規模サービス向けという割り切りは理解できる）
- `location()` で外部 URL（`$flag = false`）を渡す場合のオープンリダイレクト対策なし

---

## 5. ドキュメント — 96 / 100

**このプロジェクト最大の強み**

### 強み
- `README.md` → `AGENTS.md` → `docs/project.md` → チュートリアルの情報アーキテクチャが整然としている
- **5本の ADR** — 範囲を絞り、決定理由と却下した選択肢を明記
- **12本のフィールドトライアルレポート** — "外部からクローンした人が感じる摩擦" を具体的な findings で記録。OSS としてかなり珍しい取り組み
- `docs/review/` の自己レビューチェックリスト (7面) が AI agent にも人間にも同じ審査基準を提供
- `docs/field-trials/follow-ups.md` で deferred findings のトラッキングを一元管理
- コミット規約、ワークフロー、コーディング標準が揃っている

### 懸念
- ADR の README が 0001/0002 しかインデックスしておらず、0003〜0005 が未記載
- `docs/milestones/README.md` はロードマップとやや情報が重複している

---

## 6. DevOps / CI — 82 / 100

### 強み
- unit + Docker runtime smoke の2ジョブ CI
- `/health` エンドポイントが `healthStatus=ok` を要求する厳格なウォームアップ待機
- `compose.prod.yaml` overlay で本番設定分離
- `NENE_UPLOAD_MAX_FILESIZE` / `NENE_POST_MAX_SIZE` 環境変数で PHP ini を動的オーバーライド

### 懸念
- Phan (`composer analyze`) と format:check が CI に含まれていない — `composer check` が定義済みなのに CI で呼ばれていない
- `actions/checkout@v6` — 2026年5月時点で v6 は存在しない（v4 が最新）。yml 表記が実態と乖離している
- Docker build キャッシュが CI で利用されていない（毎回フルビルド）

---

## 7. プロジェクト管理 — 92 / 100

### 強み
- Issue 駆動開発が徹底されており、PR と Issue が 1:1 対応
- field trial → finding → Issue → PR → close のサイクルが12回回っており、機能している
- ロードマップ・マイルストーン・TODO が整備されて陳腐化していない
- `docs/field-trials/follow-ups.md` でキャリーオーバー管理

### 懸念
- 現在アクティブな Issue は #178 のみ（Qiita 記事）。バックログ候補（FT7+ テーマ）が Issue 化されていない

---

## 総合スコア

| カテゴリ | スコア |
|---|---|
| アーキテクチャ設計 | 88 |
| コード品質 | 84 |
| テスト | 87 |
| セキュリティ | 88 |
| ドキュメント | 96 |
| DevOps / CI | 82 |
| プロジェクト管理 | 92 |
| **総合** | **88 / 100** |

---

## 総括

NeNe は「レガシーフレームワーク慣れ層向けの renovation」という哲学を**一貫して守り抜いている**プロジェクトです。哲学が明文化され、設計判断が ADR に残り、フィールドトライアルで外部視点の摩擦が継続的に記録されている。この組み合わせはこの規模の OSS では珍しいレベルの品質管理です。

**次に投資すべき2点**を挙げるとすれば：

1. **Phan と format:check を CI に追加する** — `composer check` がすでに定義済みなのに CI で呼ばれていないのは勿体ない
2. **DataModelBase のテスト補強** — magic patterns が baseline に残っている唯一の面であり、将来の型安全化リファクタリング時に安全網がないと危険
