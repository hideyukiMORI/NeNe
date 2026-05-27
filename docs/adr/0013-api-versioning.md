# ADR-0013 — API Versioning Strategy

**Status**: Accepted
**Date**: 2026-05-27

## Context

When breaking changes are needed (field rename, removed endpoint, changed semantics),
clients need time to migrate. We need a clear versioning strategy.

## Decision

**URI prefix versioning** (`/v1/`, `/v2/`): different versions are separate controller classes
with independent routes.

NeNe's explicit routing makes this natural — no framework magic required.

Deprecated versions MUST emit `Deprecation: true` + `Sunset` + `Link` headers (RFC 8594)
via `ApiDeprecation::sendHeaders()` in `preAction()`.

## Alternatives considered

| Option | Why rejected |
|--------|--------------|
| Accept header versioning | Invisible in logs/URLs; complex for clients |
| Query parameter (`?v=2`) | Cache-polluting; easy to forget |

## Consequences

- Breaking changes require a new controller class (low coupling).
- Clients get machine-readable deprecation signals.
- Multiple versions can coexist indefinitely (each is its own route group).
