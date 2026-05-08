# Commit Conventions

Use Conventional Commits.

## Format

```text
type(scope): summary

body
```

The `type` and optional `scope` should be short English identifiers. The summary and body may be Japanese when that is clearer for the project.

## Types

- `feat`: New feature.
- `fix`: Bug fix.
- `docs`: Documentation only.
- `style`: Formatting only, no behavior change.
- `refactor`: Code restructuring without feature or bug fix intent.
- `test`: Tests.
- `chore`: Maintenance, dependency updates, tooling.
- `security`: Security-specific change.

## Examples

```text
docs(project): AI向けプロジェクト資料を追加する
```

```text
chore(deps): 依存パッケージを整理して最新化する
```

```text
fix(router): 存在しないアクションの404処理を安定させる
```

## Guidelines

- One commit should explain one coherent change.
- Mention the Issue or PR in the body when useful.
- Avoid vague messages such as `update` or `fix`.
- Do not use commits to hide unrelated cleanup.
