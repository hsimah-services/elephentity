# What you write by hand

Elephentity generates everything it can infer. What remains is code no generator could
invent, and it arrives as interfaces with exact types.

## Finding the list

```bash
ls generated/*/Contract/
```

Every file is an interface with no implementation. **The application will not boot
until each has one** — `MissingImplementations` is thrown at startup and names all of
them at once, rather than one per restart.

Boot time is deliberate. Later than generate time, so codegen stays a pure function of
the spec and never scans your source; earlier than call time, so a missing handler
cannot lurk in production until the wrong request arrives.

## The seven kinds (PHP names; C# is in the last section)

### 1. Query handlers — `{Entity}{Query}Query`

Backs a finder method.

```php
interface PostPublishedQuery
{
    /** @return EntityQuery<Post> */
    public function find(?int $limit = null): EntityQuery;
}
```

Return the lazy query, not an array. Building a `Criteria` and handing it to the storage
adaptor is the usual implementation; hydrating everything and filtering in PHP is not.

### 2. Action handlers — `{Entity}{Action}Action`

```php
interface PostPublishAction
{
    public function handle(PostPublishContext $context, ?DateTimeImmutable $at = null): void;
}
```

The context exposes only what the action declared it `writes:`. If you need a member
that is not there, add it to the spec's `writes:` block and regenerate — do not reach
around the context.

### 3. SideEffect handlers — `{Entity}{SideEffect}SideEffect`

```php
interface PostAuditSideEffect
{
    public function handle(PostMutationContext $context): void;
}
```

**Decide what a failure means and act accordingly.** The framework does not classify
sideEffects as critical: throw to abort, catch to continue. In `preCommit` a throw rolls
the whole commit back; in `postCommit` there is nothing left to roll back, so it is
logged and the remaining sideEffects still run.

`preCommit` receives the writable `EntityPreCommitContext` before verification and storage.
`postCommit` receives `EntityMutationContext`; new writes use a separate unit of work.

### 4. Field verifiers — `{Entity}{Field}Verifier`

```php
interface PostPriceVerifier
{
    public function verify(Money $value, PostMutationContext $context): Verification;
}
```

**Return violations; never throw.** Throwing aborts on the first bad field, so a caller
submitting fifteen fields fixes one error per round trip. Returning lets the unit of
work run every verifier and reject the commit with the complete list.

```php
public function verify(Money $value, PostMutationContext $context): Verification
{
    if ($value->isNegative()) {
        return Verification::failed(new Violation('post.price.negative', 'Must not be negative.'));
    }

    return Verification::ok();
}
```

The context is where cross-field rules live — `$context->pendingStatus()`,
`$context->originalPrice()` — exactly typed, because the generator knew both the entity
and the field.

A violation carries a code and a message and **no field name**: the unit of work
attaches the path, because a shared processor does not know which field it is on.

### 5. Type processors — `{Type}ReadProcessor` / `{Type}WriteProcessor`

```php
interface MoneyReadProcessor extends ReadProcessor
{
    public function read(mixed $value): Money;
}

interface MoneyWriteProcessor extends WriteProcessor
{
    public function write(mixed $value): int;
}
```

`read` turns the stored primitive into the domain value; `verify` then `write` go the
other way. **Null never reaches any of them** — a nullable field holding null
short-circuits, so no processor needs to open with the same null check.

### 6. Read policy handlers — `{Entity}{Policy}ReadPolicy`

Read policy contracts decide whether an entity may be exposed to the current viewer.
Pattern policies live under `Pattern/{P}/Contract/` and are typed to the pattern shape,
so one implementation can serve every entity using that pattern.

```php
interface PostOwnerReadPolicy
{
    public function decide(Post $entity, Viewer $viewer): PolicyDecision;
}
```

### 7. Write policy handlers — `{Entity}{Policy}WritePolicy`

Write policy contracts decide whether a mutation may proceed. Entity policies receive the
generated write context; pattern policies receive the shared runtime `WriteContext`.

```php
interface PostStaffWritePolicy
{
    public function decide(?Post $entity, PostWriteContext $context, Viewer $viewer): PolicyDecision;
}
```

## Value classes

A type declared with `processors: true` names a class the generator references but
never emits:

```yaml
type: Money
primitive: int
processors: true
```

→ `App\Type\Money`, written by you, plain unconstrained PHP. A value object has
behaviour — `add()`, `allocate()`, `format()` — that no generator can invent. A missing
one fails at boot by name, and PHPStan catches it earlier still.

## Wiring

Generated classes have a private constructor and a static `of()`:

```php
$mutator = PostMutator::of($buffer, $publishAction);
```

Your container supplies the handlers. Nothing is auto-discovered — if a handler is not
wired, boot fails and says which.

**Copy the assembly from a working application, not from this page.** The order is easy to
get subtly wrong:

- [hsimah/clog](https://github.com/hsimah/clog) is a real application on the PHP and SQLite
  stack, with `server/standalone/` as the wiring and its generated tree committed.
- [elephentity-examples](https://github.com/hsimah-services/elephentity-examples) holds
  smaller ones. Its `clog/src/Bootstrap.php` shows the shape of the assembly; its adaptor
  (WordPress) is not supported, so substitute `SQLiteAdaptor` or `MemoryAdaptor`.

The order, in PHP:

1. The storage adaptor: `MemoryAdaptor`, or `Database` + `SQLiteAdaptor` with the
   generated `generated/sqlite/storage-manifest.php` (load it with
   `StorageManifest::withPrefix()` if the project sets a table prefix).
2. A container holding the generated classes and your contract implementations.
3. `new Catalogue($container)` — generated; it resolves everything through the
   container, so adding an entity never widens your wiring's signature.
4. `new Runtime($storage, $catalogue, new UnitOfWorkFactory(...))`.
5. `(new BootCheck($catalogue, $container))->run()`, before serving anything.

Three things are easy to get wrong on the first attempt:

- **The catalogue needs a PSR-11 container**, not a list of services.
- **Generated hydrators and input appliers take a `ValueDecoder`.** One instance,
  shared; it holds no state.
- **A hand-written query handler needs a `Queries`**, from `$runtime->queries()`.
  Pass it the entity's generated hydrator and the return type stays exact.

## Creating the tables

Nothing at build time can migrate a database it cannot reach, so this is a runtime
call. With SQLite, `generated/sqlite/install.php` returns a closure that creates the
initial schema atomically:

```php
$install = require 'generated/sqlite/install.php';
$install($database->pdo, 'app_'); // optional extra table prefix; use the same one for the manifest
```

It is an explicit initial installation: existing tables make it fail and roll back.
Evolving a populated database is a migration the application writes and reviews, because
the build cannot see a live database. The `memory` driver has no schema at all.

With C#, the SQLite target (`"language": "csharp"`) writes `SqliteStorageManifest.cs` instead, and the statements travel inside the
manifest: `await SqliteInstaller.InstallAsync(connection, SqliteStorageManifestFactory.Create())`, with the same rules.

## C#

The same seven kinds exist, as interfaces in the `{namespace}.Contract` namespace:
`I<Entity><Name>Query`, `…Action`, `…SideEffect`, `…Verifier`, `…ReadPolicy` and
`…WritePolicy`, plus `I<Type>ReadProcessor` / `I<Type>WriteProcessor` in `{namespace}.Types`.
What differs from PHP:

- **Async-first.** Storage, queries and handlers take a `CancellationToken` and return
  `Task`/`ValueTask`. Match the generated signature exactly; do not guess it.
- **Wiring is dependency injection.** `Add<Project>Entities(this IServiceCollection)`, in
  the generated `Wiring.cs`, registers everything generated and leaves the contracts to
  you. Register your implementations after it. Add the runtime and the adaptor the project
  uses (`Elephentity.Runtime`, `Elephentity.Runtime.Memory`, `Elephentity.Sqlite` or `Elephentity.Cosmos`).
- **Declared types are yours.** A type with `processors: true` is a class you put in
  `typeNamespace`; the builder assumes a reference type. Spec `int` is `long`, `datetime` is
  `DateTimeOffset` and `json` is `JsonElement`.
- **No reflection.** The libraries are AOT-compatible, so register concrete types rather
  than scanning assemblies.
- **Checking** is `eleph check`, which runs the target's `check` command (`ContractCheck`
  in a test project) and names every contract that has no registered implementation.

Every difference from the PHP runtime is listed in `dotnet/docs/divergence.md` in the
framework repository.
