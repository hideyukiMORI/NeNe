# Personal Access Token

`Nene\Kit\PersonalAccessToken` — user-managed Personal Access Tokens (PATs) with ability-based authorization and last-used tracking.

PATs are long-lived credentials managed by users through a dashboard — analogous to GitHub's Personal Access Tokens. Each token carries a named set of abilities, and every successful authentication records a `last_used_at` timestamp.

## Schema

```sql
CREATE TABLE personal_access_tokens (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    prefix       VARCHAR(16)  NOT NULL,
    token_hash   VARCHAR(64)  NOT NULL UNIQUE,
    user_id      VARCHAR(255) NOT NULL,
    name         VARCHAR(255) NOT NULL DEFAULT '',
    abilities    TEXT         NOT NULL DEFAULT '*',
    expires_at   DATETIME     DEFAULT NULL,
    last_used_at DATETIME     DEFAULT NULL,
    revoked_at   DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_personal_access_tokens_prefix ON personal_access_tokens (prefix);
```

## API

| Method | Description |
| --- | --- |
| `create(string $userId, string $name = '', array\|string $abilities = '*', ?int $expiresIn = null): array{rawToken: string, id: int}` | Create a token. Returns raw token once. |
| `authenticate(string $rawToken, string $requiredAbility = '*'): ?array` | Authenticate and record last used. Returns record or null. |
| `list(string $userId): array` | Active tokens for a user (no token_hash). |
| `revoke(int $tokenId, string $userId): bool` | Revoke by owner. Returns true if revoked. |

## Usage

```php
$pat = new PersonalAccessToken($pdo);

// Create a wildcard token
['rawToken' => $raw, 'id' => $id] = $pat->create('user:42', 'My token');

// Create an ability-scoped token
['rawToken' => $raw2] = $pat->create('user:42', 'CI Deploy', ['read', 'deploy']);

// Authenticate (updates last_used_at on success)
$token = $pat->authenticate($raw, 'read');
if ($token === null) {
    http_response_code(401); exit;
}
echo $token['user_id'];        // 'user:42'
echo $token['last_used_at'];   // '2026-05-27 12:34:56'

// List (token_hash never exposed)
$tokens = $pat->list('user:42');
// [['id' => 1, 'name' => 'My token', 'abilities' => '*', 'last_used_at' => '...', ...]]

// Revoke
$pat->revoke($id, 'user:42');  // true
$pat->revoke($id, 'user:42');  // false — already revoked
```

## Abilities

Abilities are stored as JSON array text or the literal `'*'` wildcard:

| Stored | Meaning |
| --- | --- |
| `*` | All abilities granted |
| `["read","write"]` | Only `read` and `write` |
| `["*"]` | All abilities (wildcard in array) |

`authenticate($token, 'deploy')` succeeds if:
- `abilities === '*'`
- `requiredAbility === '*'`
- abilities array contains `'*'` or `'deploy'`

## vs ApiKey (FT56)

| Feature | `ApiKey` | `PersonalAccessToken` |
| --- | --- | --- |
| Token prefix | `nk_` | `pat_` |
| Authorization model | Scope hierarchy (`read⊃write⊃admin`) | Ability list / wildcard |
| Last-used tracking | No | Yes — updated on every authenticate |
| Target use case | Server-to-server API access | User-managed dashboard tokens |

## Key design points

- **Hash storage**: SHA-256 of raw token; raw token shown once at creation.
- **Prefix lookup**: first 16 chars stored for O(1) indexed lookup before hash comparison.
- **last_used_at**: updated within the same `authenticate()` call, not a separate explicit call.
- **token_hash never exposed**: neither `authenticate()` nor `list()` include `token_hash` in results.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE personal_access_tokens (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    prefix       VARCHAR(16)  NOT NULL,
    token_hash   VARCHAR(64)  NOT NULL UNIQUE,
    user_id      VARCHAR(255) NOT NULL,
    name         VARCHAR(255) NOT NULL DEFAULT \'\',
    abilities    TEXT         NOT NULL DEFAULT \'*\',
    expires_at   DATETIME     DEFAULT NULL,
    last_used_at DATETIME     DEFAULT NULL,
    revoked_at   DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pat = new PersonalAccessToken($db);
```
