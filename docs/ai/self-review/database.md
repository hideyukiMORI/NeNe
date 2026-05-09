# Database Self-Review

Use this checklist when a change touches mappers, models, SQL, schema setup, or transactions.

## Mapper and Model Shape

- Each mapper extends `Nene\Xion\DataMapperBase`.
- Each mapper defines `MODEL_CLASS`, `TARGET_TABLE`, and `KEY_SID` when it uses the base mapper conventions.
- `MODEL_CLASS` points to the model class that owns schema metadata.
- Each model extends `Nene\Xion\DataModelBase` when it uses NeNe schema validation.
- Mapper methods use typed parameters and return values where practical.
- Mapper method names describe database intent, such as `findRowById()`, `findRowsByUserId()`, or `createForUser()`.

## SQL Boundary

- SQL lives in mapper methods, not controllers or templates.
- Bound parameters are used for request-derived values.
- Reads return predictable shapes, such as `array`, `?array`, `int`, or `bool`.
- Writes return a useful result or throw when the write cannot be verified.
- MySQL and SQLite setup stay aligned when a feature adds or changes tables.

## Transactions

- `DataMapperBase::execute()` remains a single-statement boundary.
- Do not start and commit a transaction inside every mapper method.
- Use `Nene\Xion\TransactionManager` at the service/use-case boundary for multi-step writes.
- Use one transaction when one logical operation needs multiple SQL statements or multiple mappers.
- Let exceptions roll back the transaction; do not hide failed writes behind partial success payloads.

## Static Analysis

- Avoid new `mixed` return types unless the value is genuinely open-ended and documented.
- Do not rely on mapper-name string replacement to resolve a model class.
- Reduce Phan baseline entries when a focused mapper cleanup makes that safe.
