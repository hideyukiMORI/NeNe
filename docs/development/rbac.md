# Role-Based Access Control (RBAC)

NeNe provides `RoleGuard` for controller-level role checks based on JWT claims.

## Setup

1. Add a `role` field to the JWT claims when issuing tokens:

```php
$token = $jwt->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role,   // e.g. 'admin', 'user', 'moderator'
]);
```

2. Verify JWT and store claims in `preAction()`:

```php
protected function preAction(): void
{
    $this->claims = (new JwtCodec((string)getenv('NENE_JWT_SECRET')))->require();
}
```

3. Guard specific methods:

```php
// Single role
public function deletePostRest(): array
{
    RoleGuard::require('admin', $this->claims);
    // ...
}

// Multiple acceptable roles
public function updatePostRest(): array
{
    RoleGuard::requireAny(['editor', 'admin'], $this->claims);
    // ...
}

// Non-throwing check
if (RoleGuard::has('admin', $this->claims)) {
    // show admin-only fields
}
```

## 401 vs 403

| Situation | HTTP Status |
|-----------|-------------|
| No token / expired / invalid signature | **401 Unauthorized** (JwtCodec::require) |
| Authenticated but wrong role | **403 Forbidden** (RoleGuard::require) |
| Resource not found | **404 Not Found** |

Never return 401 to an authenticated user who lacks the required role — use 403.

## Role in JWT claims

Role changes take effect only after the user re-authenticates (new token).
For high-security use cases, combine with a DB lookup to verify the current role on each request.
