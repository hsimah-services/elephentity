# What you write by hand

PheFr generates everything it can infer. What remains is code no generator could
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

## The five kinds

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

### 3. Trigger handlers — `{Entity}{Trigger}Trigger`

```php
interface PostAuditTrigger
{
    public function handle(PostMutationContext $context): void;
}
```

**Decide what a failure means and act accordingly.** The framework does not classify
triggers as critical: throw to abort, catch to continue. In `preCommit` a throw rolls
the whole commit back; in `postCommit` there is nothing left to roll back, so it is
logged and the remaining triggers still run.

`preCommit` may not mutate. `postCommit` may, as a separate unit of work.

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
