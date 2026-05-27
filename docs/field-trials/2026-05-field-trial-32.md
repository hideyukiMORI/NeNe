# Field Trial 32 — パスワードリセット

**Date:** 2026-05-27
**Theme:** `PasswordResetToken` — パスワードリセットフロー用暗号ヘルパー
**Source:** NENE2 FT126 (resetlog)

---

## What was done

- `class/xion/PasswordResetToken.php` — `generate()`, `hash()`, `verify()`, `isExpired()`, `expiresAt()`
- `config/error_codes.php` — `TOKEN-EXPIRED` (410), `TOKEN-ALREADY-USED` (409) 追加
- `tests/Unit/Xion/PasswordResetTokenTest.php` — 13 テスト
- `docs/development/password-reset.md` — howto doc（セキュリティガイド含む）

---

## Findings

### F-1 — ランダムトークンの SHA-256 ハッシュは bcrypt 不要（設計確認）

bcrypt/Argon2 はユーザーが選んだ低エントロピーパスワード用。`bin2hex(random_bytes(32))` は256ビットランダムなので SHA-256 で十分（NENE2 FT126 と同じ判断）。

### F-2 — `isExpired` の境界: `current >= expires_at`（細部確認）

同時刻（`current == expires_at`）は期限切れ扱い。NENE2 FT126 と同じ: "expired at that moment" が正しい解釈。

### F-3 — ユーザー列挙防止のため常に 202

メールが登録済みか否かに関わらず 202 を返す。テスト環境では token をレスポンスに含めることもあるが本番では不要。

### F-4 — 古いトークン無効化（VULN-F 対策）

新しいリセットリクエスト時に `UPDATE … SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL` で前回トークンを無効化する。

---

## Security notes from NENE2 FT126 vulnerability assessment

| VULN | Finding | Status |
|------|---------|--------|
| A | `user_id` in response | Handled: howto doc に明記 |
| B | Token stored plaintext | Not present: `hash()` パターン |
| C | User enumeration | Not present: 常に 202 |
| D | Expiry bypass | Not present: `isExpired()` |
| E | Token reuse | Not present: `used_at` チェック |
| F | Old tokens not invalidated | Not present: howto に invalidate パターン |
| G | `token_hash` in response | Not present: howto に明記 |
