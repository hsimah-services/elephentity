# Mutation lifecycle

A gateway mutation operates on one entity. `runActions()` accepts an ordered list of
`ActionCall` values; every call is authorized against the original entity before any
action executes. The policy context exposes the complete list through `actions()`;
`action()` and `arguments()` identify the particular call being authorized. Actions
share one pending buffer and one commit. An empty action list is rejected.

1. **Write policies:** authorize the requested operation with original state and
   arguments, while the change buffer is empty. Create and update input is applied
   only after authorization. Named-action arguments are decoded before authorization.
2. **Actions:** execute in request order. An exception discards the pending mutation.
3. **Pre-commit side effects:** execute in composed spec declaration order, including
   pattern contributions. The generated `EntityPreCommitContext` exposes typed
   original/pending reads, setters, and relationship add/remove/set operations.
   Each handler observes changes from earlier handlers. An exception prevents writes.
4. **Preparation:** finalize managed fields, verify the resulting state, check
   uniqueness, order dependencies, and encode domain values. This includes values
   supplied or changed by pre-commit side effects.
5. **Storage transaction:** write rows, resolve pending IDs, update relationships,
   and apply deletion rules. No application side-effect handler or value processor
   runs inside this transaction. Storage failure rolls back the transaction.
6. **Post-commit side effects:** receive the read-only generated mutation context.
   Each handler failure is logged independently; remaining handlers continue. A new
   mutation initiated here has its own lifecycle and transaction.
7. **Result visibility:** reload the entity and evaluate read policies. Return a
   `MutationResult` with its ID and visible entity, or a null entity when visibility
   is denied. The mutation remains successful. Ordinary `find()` still throws on
   access denial; mutation result handling does not change query semantics.

`actions()` and `originalEntity()` remain available on the mutation context through
verification and both side-effect phases. Create has no original entity. A new entity
has a `PendingId` before storage and an `EntityId` afterward. Pending identifiers may
be used in buffered relationships; pre-commit code must not assume that a new row can
already be queried from storage. Existing `pendingEdge()` accessors describe pending
attachments, not a materialized view of all persisted relationships.

The lower-level unit of work retains multi-row storage support, but the gateway does
not expose a cross-entity action transaction. Deletions notify every planned removal,
including cascades, before persistence. The plan is checked again in the transaction;
a changed deletion graph aborts instead of deleting a row whose pre-commit side
effects did not run. Deletion contexts carry identity; changes they buffer are verified
and persisted in the same transaction before removal.

## Migration (IR 1.2)

- Rename spec `triggers:` to `sideEffects:`. Keep `events`, `phase`, and `handler`.
- Regenerate using the compiler, orchestrator, and all builders supporting IR 1.2.
  IR 1.1 is rejected rather than silently dropping side-effect declarations.
- Replace generated `*Trigger`/`*Triggers` references with `*SideEffect`/`*SideEffects`.
- Pre-commit implementations accept `EntityPreCommitContext`; post-commit
  implementations retain `EntityMutationContext`.
- Runtime assembly uses `EntitySideEffects`, `SideEffectDispatcher`, and the
  `SideEffect` namespace. Generated collections return individual handler callables
  so failures can be isolated per post-commit handler.
- `create()`, `update()`, `runAction()`, and `runActions()` return `MutationResult`.
  Use `->id` for the persisted ID and `->entity` for the authorized result.
  GraphQL returns that result without performing another policy-throwing lookup.

Regenerate consuming projects and migrate application bindings together with the
runtime upgrade. The runtime mirror is populated by the existing source sync workflow.
