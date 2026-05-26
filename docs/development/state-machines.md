# State Machine Workflows

A state machine formalizes multi-step business processes: order approval, content moderation, support tickets, delivery tracking. Instead of ad-hoc `status` column updates, a state machine enforces valid transitions, records history, and exposes "what's allowed next" to clients.

## Core design

- **`WorkflowDefinition`** — static class with the allowed-transitions map. Not DB-driven; one class per workflow type.
- **`instances` table** — stores current state, arbitrary JSON context, and workflow name.
- **`transitions` table** — append-only history; never update or delete.
- **`allowed_next`** — derived at serialization time from the definition; clients always know what they can do.

## Schema

```php
// class/xion/SchemaDefinition.php

'workflow_instances' => [
    'columns' => [
        'id'            => ['type' => 'pk-bigint'],
        'created_at'    => ['type' => 'datetime-now'],
        'updated_at'    => ['type' => 'datetime-touch'],
        'workflow'      => ['type' => 'varchar:64'],
        'current_state' => ['type' => 'varchar:64'],
        'context'       => ['type' => 'text'],          // arbitrary JSON blob
    ],
],
'workflow_transitions' => [
    'columns' => [
        'id'          => ['type' => 'pk-bigint'],
        'created_at'  => ['type' => 'datetime-now'],
        'instance_id' => ['type' => 'bigint'],
        'from_state'  => ['type' => 'varchar:64'],
        'to_state'    => ['type' => 'varchar:64'],
        'actor'       => ['type' => 'varchar:255'],
        'note'        => ['type' => 'text', 'nullable' => true],
    ],
    'indexes' => [
        'workflow_transitions_instance_id_index' => ['instance_id'],
    ],
    'foreign_keys' => [
        'workflow_transitions_instance_id_foreign' => [
            'columns'    => ['instance_id'],
            'references' => ['workflow_instances', ['id']],
            'on_delete'  => 'CASCADE',
        ],
    ],
],
```

## WorkflowDefinition

```php
// class/func/WorkflowDefinition.php

final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> Workflow name → from-state → allowed to-states */
    private const DEFINITIONS = [
        'order' => [
            'draft'     => ['submitted'],
            'submitted' => ['approved', 'rejected'],
            'approved'  => ['fulfilled', 'cancelled'],
            'rejected'  => [],       // terminal
            'fulfilled' => [],       // terminal
            'cancelled' => [],       // terminal
        ],
    ];

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::DEFINITIONS[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return array_key_first(self::DEFINITIONS[$workflow]) ?? 'draft';
    }

    /**
     * @return list<string> Valid next states from the current state.
     */
    public static function allowedNext(string $workflow, string $currentState): array
    {
        return self::DEFINITIONS[$workflow][$currentState] ?? [];
    }

    public static function isValidTransition(string $workflow, string $from, string $to): bool
    {
        return in_array($to, self::allowedNext($workflow, $from), true);
    }
}
```

## Mapper

```php
// class/db/WorkflowInstanceMapper.php

public function create(string $workflow, array $context = []): array
{
    $initialState = WorkflowDefinition::initialState($workflow);
    $stmt = $this->DB->prepare('
        INSERT INTO ' . static::TARGET_TABLE . ' (workflow, current_state, context)
        VALUES (:workflow, :state, :context)
    ');
    $stmt->bindValue(':workflow', $workflow,                           PDO::PARAM_STR);
    $stmt->bindValue(':state',    $initialState,                      PDO::PARAM_STR);
    $stmt->bindValue(':context',  json_encode($context, JSON_THROW_ON_ERROR), PDO::PARAM_STR);
    $this->execute($stmt);
    return $this->findRowById((int)$this->DB->lastInsertId());
}

/**
 * Apply a transition — returns updated instance or null if invalid.
 */
public function transition(
    int $id,
    string $toState,
    string $actor,
    ?string $note,
    WorkflowTransitionMapper $historyMapper
): ?array {
    $instance = $this->findRowById($id);
    if ($instance === null) {
        return null;
    }
    $workflow = (string)$instance['workflow'];
    $from     = (string)$instance['current_state'];

    if (!WorkflowDefinition::isValidTransition($workflow, $from, $toState)) {
        return null; // caller maps to 409 Conflict
    }

    $transaction = new \Nene\Xion\TransactionManager();
    return $transaction->run(function () use ($id, $from, $toState, $actor, $note, $historyMapper): array {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . ' SET current_state = :state WHERE id = :id
        ');
        $stmt->bindValue(':state', $toState, PDO::PARAM_STR);
        $stmt->bindValue(':id',    $id,      PDO::PARAM_INT);
        $this->execute($stmt);

        $historyMapper->record($id, $from, $toState, $actor, $note);

        return $this->findRowById($id);
    });
}
```

## Controller response with `allowed_next`

```php
private function normalizeInstance(array $row): array
{
    $workflow     = (string)$row['workflow'];
    $currentState = (string)$row['current_state'];
    return [
        'id'            => (int)$row['id'],
        'workflow'      => $workflow,
        'current_state' => $currentState,
        'allowed_next'  => WorkflowDefinition::allowedNext($workflow, $currentState),
        'context'       => json_decode((string)$row['context'], true) ?? [],
        'created_at'    => (string)$row['created_at'],
        'updated_at'    => (string)$row['updated_at'],
    ];
}

public function transitionPostRest(): array
{
    $id      = $this->getInstanceId();
    $toState = trim((string)($this->REQUEST_JSON['to_state'] ?? ''));
    $actor   = trim((string)($this->REQUEST_JSON['actor']    ?? ''));
    $note    = trim((string)($this->REQUEST_JSON['note']     ?? '')) ?: null;

    $instance = (new Database\WorkflowInstanceMapper())
        ->transition($id, $toState, $actor, $note, new Database\WorkflowTransitionMapper());

    if ($instance === null) {
        return $this->API_RESPONSE->failure('WORKFLOW-TRANSITION-INVALID'); // 409
    }

    return $this->API_RESPONSE->success(['instance' => $this->normalizeInstance($instance)]);
}
```

## Key design decisions

| Decision | Recommendation |
|---|---|
| Workflow definition | Static class — not DB-driven. Changing a workflow is a code deploy, not a DB edit. |
| Terminal states | Empty `allowed_next` — the general invalid-transition guard handles them automatically. |
| Invalid transition response | `409 Conflict` — the resource exists and the request is understood but cannot be applied. |
| History table | Append-only. Actor and note on each row support auditing. |
| `context` field | Arbitrary JSON blob stored as TEXT. Decoded on hydration. Avoid over-normalizing domain data into the generic workflow table. |

## Related

- `docs/development/ledger-systems.md` — append-only history pattern
- `docs/tutorials/building-a-service.md` — TransactionManager, mapper patterns
