# HasTimestamps

Ships the YAML spec and an application-owned PHP trigger together. Before storage,
`HasTimestampsTrigger` sets both `createdAt` and `updatedAt` to the same instant on
creation. On update it sets only `updatedAt`; attempting to change `createdAt`
rejects the mutation. The spec dispatches only on create and update, never delete
(including cascade deletes).

## Install and wire

```sh
python3 tools/catalog.py copy HasTimestamps --into /path/to/application
```

This copies the spec to `spec/patterns/HasTimestamps.yml`, the trigger to
`src/Patterns/HasTimestamps/HasTimestampsTrigger.php`, and these instructions and
the MIT license to `docs/patterns/`. Manual copying of the same files works too.
Requires PHP 8.3+, `elephentity/runtime`, and the PHP generator. The spec is
compatible with Elephentity 0.11.

1. Add `use: [HasTimestamps]` to your entities and regenerate.
2. The copied class uses `App\Patterns\HasTimestamps`; with Composer's usual
   `"App\\": "src/"` PSR-4 mapping it autoloads directly. Adjust the namespace to
   your application if needed.
3. Add an adapter implementing the generated `timestamps` handler interface for
   each consuming entity. One adapter can implement multiple entity interfaces:

```php
namespace App\Contract;

use App\Entity\Article\Contract\ArticleTimestampsSideEffect;
use App\Entity\Comment\Contract\CommentTimestampsSideEffect;
use App\Patterns\HasTimestamps\HasTimestampsTrigger;

final class Timestamps extends HasTimestampsTrigger implements
    ArticleTimestampsSideEffect,
    CommentTimestampsSideEffect
{
}
```

The example assumes the PHP generator namespace is `App\Entity`. Use your actual
namespace and entities. The inherited `handle(MutableMutationContext)` accepts
both generated pre-commit contexts; no duplicated timestamp logic is needed.
Register an instance of `Timestamps` under each generated handler interface in
your application's container before boot checks. Alternatively, pass it to each
generated side-effects class, e.g. `new ArticleSideEffects($timestamps)` when this
is the entity's only side effect. The copy helper does not edit your container.

The catalog labels this ownership model `extendable`: the shipped base supplies
the implementation, and your adapter satisfies the generated contracts. This is
manual PHP wiring using existing side effects, not the proposed YAML `contract`
syntax or automatic pattern-level handler wiring described in `docs/CONTRACTS.md`.

The default clock uses UTC. For a controlled clock, pass a closure returning a
`DateTimeImmutable`, e.g. `new Timestamps(fn () => $clock->now())`. Use UTC for an
injected clock too. Each invocation reads the clock once.

## Behavior and ownership

The spec deliberately has no `managed` flags: the trigger owns these values, so
runtime stamping must not overwrite them. Generated creation input may expose
the fields, but the trigger replaces supplied timestamps on create and replaces
`updatedAt` on update. `createdAt` is immutable in the generated mutator; the
trigger also rejects pending writes to it on update. Other custom pre-commit
handlers must not rewrite timestamps after this trigger.

Every dispatched update refreshes `updatedAt`, including an otherwise empty
update and a relationship-only update. This differs from automatic managed
stamping, which skips empty updates. Merely reading an entity does not dispatch
an update. Delete exclusion is enforced by the spec, not by calling `handle()`
directly: the runtime context does not distinguish updates from deletions.

Files are yours to edit after copying. Keep custom code outside generated output
so regeneration preserves it. This pattern records framework writes, not direct
SQL changes, and existing rows need an application migration. When replacing the
old managed-field version, copy the trigger, replace the spec, regenerate, and
wire the handler together; the copy helper refuses to overwrite an existing copy.
