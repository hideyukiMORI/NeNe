---
hide:
  - navigation
  - toc
---

# NeNe Documentation

**NeNe** is a tiny renovated legacy-style PHP framework for *reviewable* small
services. It keeps visible URL routing, controller actions, Smarty pages,
lightweight mappers, REST endpoints, OpenAPI, Docker, tests, and explicit
security boundaries — small enough to read end-to-end.

This site collects the **how-to guides**, the **getting-started tutorial**, and
a **project overview**. Source and issues live on
[GitHub](https://github.com/hideyukiMORI/NeNe); a live instance runs at
[nene-php.com](https://nene-php.com/).

<div class="grid cards" markdown>

-   :material-rocket-launch: **Getting Started**

    ---

    Build a small NeNe service end-to-end: pages, REST endpoints, a
    database-backed feature, OpenAPI, and tests.

    [:octicons-arrow-right-24: Building a service](tutorials/building-a-service.md)

-   :material-book-open-variant: **How-to Guides**

    ---

    ~80 focused, single-topic guides — rate limiting, money handling, RBAC,
    state machines, signed URLs, pagination, full-text search, and more.

    [:octicons-arrow-right-24: Browse the guides](development/coding-standards.md)

-   :material-map: **Overview**

    ---

    What NeNe is (and is not), the routing convention, and the
    `Nene\Xion` core vs. `Nene\Kit` helper-catalogue layout (ADR-0014).

    [:octicons-arrow-right-24: Project overview](project.md)

-   :material-github: **Source & Releases**

    ---

    Clone, read the conventions, and follow the release notes.

    [:octicons-arrow-right-24: GitHub repository](https://github.com/hideyukiMORI/NeNe)

</div>

---

NeNe is intentionally much smaller than full-stack frameworks. Its visible
conventions stay readable by both humans and AI agents, so code generated or
edited with AI assistance follows the same shape a developer would normally
review. The goal is to lower review cost for small services by keeping HTTP
input, controller actions, service rules, mapper SQL, OpenAPI, and tests in
predictable places.
