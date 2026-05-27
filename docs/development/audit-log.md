# Audit Log (Audit Trail)

How to record every state-changing operation in NeNe using `AuditLogger`.

## Framework class: `AuditLogger`

`Nene\Xion\AuditLogger` writes append-only records to an `audit_log` table.

```php
$audit = new AuditLogger();
$audit->record($actorId, 'created', 'task', $task->id, ['title' => $task->title]);
```

## Schema

Add to `class/xion/SchemaDefinition.php`:

```php
'audit_log' => [
    'columns' => [
        'id'            => ['type' => 'pk-bigint'],
        'created_at'    => ['type' => 'datetime-now'],
        'actor_id'      => ['type' => 'bigint'],
        'action'        => ['type' => 'varchar:64'],   // created | updated | deleted | custom
        'resource_type' => ['type' => 'varchar:64'],   // e.g. 'task', 'user'
        'resource_id'   => ['type' => 'bigint'],
        'payload'       => ['type' => 'text'],         // JSON snapshot / before+after
    ],
    'indexes' => [
        'audit_log_actor_id_index'            => ['actor_id'],
        'audit_log_resource_type_id_index'    => ['resource_type', 'resource_id'],
    ],
],
```

## Usage patterns

### Create

```php
$task = $this->taskMapper->create($title, $body, $actorId);
$audit->record($actorId, 'created', 'task', $task->id, [
    'title' => $task->title,
    'body'  => $task->body,
]);
```

### Update (before/after)

```php
$before = $this->taskMapper->find($id);
$after  = $this->taskMapper->update($id, $newTitle, $newBody);
$audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body],
    'after'  => ['title' => $after->title,  'body' => $after->body],
]);
```

### Delete

```php
$task = $this->taskMapper->find($id);
$this->taskMapper->delete($task);
$audit->record($actorId, 'deleted', 'task', $task->id, [
    'title' => $task->title,
]);
```

## Immutability

Never expose write endpoints (`POST /audit`, `DELETE /audit/{id}`) for audit records.
`AuditLogger` only inserts — no `update()` or `delete()` methods by design.

## Security notes

- **Do not log secrets**: never include passwords, tokens, or API keys in payload.
- **Actor from JWT claims**: always use `$claims['sub']` as `$actorId`, never a user-supplied value.
- **Audit failures must not break primary operations**: `AuditLogger::record()` catches `PDOException` internally and logs the error via Monolog — the main operation continues.
