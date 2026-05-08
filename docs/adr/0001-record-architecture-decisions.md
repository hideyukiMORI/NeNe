# ADR 0001: Record Architecture Decisions

## Status

Accepted

## Context

NeNe is a legacy, lightweight PHP framework. The project is being prepared for AI-assisted and Issue-driven maintenance.

Future changes may affect PHP compatibility, routing, dependency policy, security, OpenAPI contracts, and framework architecture. Those decisions should be visible and traceable.

## Decision

Use Architecture Decision Records in `docs/adr/`.

Each ADR should describe:

- Status.
- Context.
- Decision.
- Consequences.

## Consequences

- Broad technical decisions become easier to review later.
- AI agents have durable context for project direction.
- Significant changes should include a new or updated ADR instead of relying only on PR comments.
