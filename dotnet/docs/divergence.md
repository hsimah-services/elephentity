# Where the C# runtime differs from the PHP one

`Elephentity.Runtime` is a port of `elephentity/packages/runtime`, type by type and test by
test. The generated code and the builders name these types as strings, so a type keeps its PHP
name unless a rule below says otherwise. This file lists **every** intended difference. Anything
not listed here is a bug in one of the two runtimes.

## The intended semantic difference

**Async-first.** Every operation that can reach storage returns a `Task<T>` and takes a
`CancellationToken`: the storage port (`IStorageAdaptor`), and later the gateway, the unit of
work and queries. The Cosmos SDK is asynchronous, and a synchronous port would block a thread
per request.

**Policies are async too, as `ValueTask`.** A policy can need to consult something ("the viewer
is a member of this ticket's project" is a lookup, and on Cosmos an asynchronous one), and the
application implements the policy interfaces that step 14 generates, so the signature has to
allow it from the start: `IEntityReadPolicies.DecideAsync`, `IEntityWritePolicies.DecideAsync`,
and on the gates `ReadGate.PermitAsync`/`RetainAsync` and `WriteGate.PermitAsync`. A policy that
only reads fields of the entity completes synchronously and allocates nothing. Pure value types
stay synchronous, and so does the viewer: `IViewer` is request data already in memory.

## Names

The mapping is mechanical so generated code can compute it.

| PHP | C# | Why |
|---|---|---|
| an interface, `StorageAdaptor` | `IStorageAdaptor` | .NET convention; the plan fixes the `I` prefix |
| a method that reaches storage or a policy, `get()` or `decide()` | `GetAsync()`, `DecideAsync()` | .NET convention for `Task`- and `ValueTask`-returning methods |
| an exception, `AccessDenied`, `UnsupportedCapability` | `AccessDeniedException`, `UnsupportedCapabilityException` | .NET convention; analyzers enforce it |
| `Filter::equals`, `Filter::notEquals`, `Comparison::Equals`, `Comparison::NotEquals` | `Filter.Equal`, `Filter.NotEqual`, `Comparison.Equal`, `Comparison.NotEqual` | a member called `Equals` collides with `object.Equals` |
| `Criteria->order` | `Criteria.Orders` | a property named `Order` would hide the `Order` type inside `Criteria` |
| `IDeletionRules::for()` | `IDeletionRules.RulesFor()` | `For` is a reserved word in Visual Basic (CA1716) |
| `Page::empty()` | `Page.Empty<T>()` | a static member on a generic type is an analyzer error (CA1000) |
| `Cursor` | `ICursor`, with `Cursor`, `OffsetCursor` and `KeysetCursor` | the cursor is opaque; one interface, a token a client sent back, and the two kinds a runtime issues |
| `Offset` | `OffsetCursor` | it is a cursor, and `Offset.fromCursor()` becomes `OffsetCursor.From()` |
| a string-backed PHP enum | a C# enum plus `ToWire()` and `FromWire()` in `<Enum>Extensions` | never rely on `ToString()` |
| a PHP closure parameter | a `Func<...>` | |
| `OutOfBoundsException` | `KeyNotFoundException` | the nearest .NET exception |
| `RuntimeException` from `NullProcessorRegistry` | `InvalidOperationException` | likewise |

A type that shares its name with a sibling namespace (`Capability`, `Verification`) is reached
as `Capability.Capability` or `Verification.Verification` from inside `Elephentity.Runtime.*`,
because C# looks for a namespace before it looks at a `using`. Application code outside
`Elephentity.Runtime` is not affected. In a test project, `Xunit.Record` hides
`Elephentity.Runtime.Storage.Record`; alias it.

## Types

- **`EntityId`** holds a `long` or a `string`, never a number in a string. `EntityId.Of(7)` and
  `EntityId.Of("7")` are different ids, as `7 === "7"` is false in PHP. It is a sealed record, so
  equality is by value and `default` cannot exist.
- **Processors are untyped at the registry**, as in PHP: `IReadProcessor.Read(object)` and
  `IWriteProcessor.Write(object)`. A generated processor casts to the types it was written for.
- **`Filter` values** are `object` checked at construction: a string, a number or a bool
  (`int`, `short` and the like widen to `long`, `float` to `double`), or a list of those for `In`
  and `NotIn`. PHP spells this `string|int|float|bool|array|null`.
- **`PendingId`** counts with `Interlocked.Increment`, so ids made on several threads stay unique.
- **`WriteResult`** keys by reference identity (`ReferenceEqualityComparer`), the equivalent of
  `SplObjectStorage`.

## Additions

- **`KeysetCursor`** has no PHP counterpart. It is the position an entity with read policies is
  paged by (step 11); see its documentation for the token format.
- **A whole-valued float in a keyset cursor reads back as an integer.** JSON writes `2.0` as `2`.
  The two compare equal, which is all a resumed sort needs.

## Step 11: mutations, unit of work, gateway, queries

- **Async all the way down.** The unit of work, `DeletionPlanner`, `UniquenessCheck`, the gateway
  and the queries are `async` and take a `CancellationToken`. Side-effect handlers are
  `Func<CancellationToken, ValueTask>` and field verifiers are `IEntityVerifiers.VerifyAsync`,
  because both may consult storage. Read and write *processors* stay synchronous: they map one
  value and touch nothing.
- **No reflection.** PHP finds `ItemFinder::byBarcode()`, `ItemMutator::rename()` and `getName()`
  by `method_exists` and a string. Here the generated catalogue does the call itself:
  `RunQueryAsync(entity, query, args)`, `RunActionAsync(entity, mutation, call)` and
  `ValuesOf(entity, instance)`. `finder()` and `mutatorFor()` do not exist.
- **Edge mutation is `Add`, `Remove`, `Replace`.** `Set` is a reserved word in other .NET languages
  (CA1716), so `Mutation.Set` is `Assign` and `Edge(..).set` is `Replace`.
- **Moments are stored as UTC ISO 8601** (`o`), which sorts as the moments do, not as
  `Y-m-d H:i:s`. `ValueDecoder.DateTime` still reads the old form, as UTC.
- **A C# `enum` with no write processor is refused** by `ValueEncoder` with a message naming the
  field. How an enum is stored is the .NET builder's decision (step 14); `ValueDecoder.Enum<T>`
  takes the generated `FromWire`.
- **`ValueDecoder` is a static class** and names the rest `Text`, `Whole` and `Number`, because
  `String`, `Int` and `Float` trip CA1720.
- **Post-commit side-effect failures** are logged through a source-generated `[LoggerMessage]`
  and swallowed, as PHP's are, except `OperationCanceledException`, which propagates.
- **`DependencySorter` is static**: it holds no state.
- **Paging an entity with read policies resumes by keyset.** PHP re-reads every earlier row on
  every page (`CHUNK` = 100). `LazyEntityQuery` reads chunks *after* the `KeysetCursor` in the
  criteria's total order (sort keys, then id), keeps what the viewer may see, and stops once the
  page is full and one more visible row proves there is a next page. The cursor it returns names
  the last visible row, so hidden rows leave no trace in it and page *n* costs what it scans, not
  everything before it. A cursor that is not a keyset position (an offset, or garbage) restarts
  the list and is logged at Debug. `Count()` of a gated entity still hydrates and filters.
  An ungated entity hands the cursor to storage untouched.
- **`BootCheck`** resolves each catalogue contract from the `IServiceProvider` and reports every
  missing one in one `MissingImplementationsException`.

## Step 12: the memory driver, conformance and the contract check

- **`MemoryAdaptor` is `IDisposable`** (it owns a `SemaphoreSlim`) and thread-safe: one lock per
  operation, and a second held for the whole of a transaction so two never interleave. A write
  outside a transaction while one is open is part of the state a rollback restores; share an
  adaptor between threads only if that is acceptable. Separate adaptors per test, which is what the
  conformance base class does, need none of this.
- **It honours the keyset contract** on `IStorageAdaptor.QueryAsync`: a `KeysetCursor` resumes
  strictly after `(sort values, id)` in the criteria's total order and the page's `Next` is another
  keyset cursor; any other cursor is an offset, as in PHP. A keyset cursor carrying a different
  number of sort values than the criteria order by is refused with an `ArgumentException`.
- **Filter values compare strictly by type**, as `===` does: `1` is not `"1"`. Ordering is total (a
  missing value sorts first, numbers as numbers, different kinds by kind), so ordering a mixed
  column never throws.
- **`AdaptorConformance` is a list of named cases, not one `check()`.** `Cases(names)` gives
  `ConformanceCase(Name, Func<IStorageAdaptor, CancellationToken, Task>)`; `CaseNames` is
  adaptor-independent so a framework can enumerate them; `CheckAsync(create)` runs every case on a
  fresh adaptor and returns the failures, as PHP's `check()` did. Each case seeds what it needs and
  removes it, so cases also run in sequence against one adaptor (a live database). A case reports
  every problem it finds. PHP's five checks are 22 cases, plus
  `paging.keyset-cursor-resumes-strictly-after-its-position`, which PHP has no use for.
- **`Elephentity.Runtime.Testing.Xunit`** is the separate package that makes the cases a `[Theory]`.
  Its namespace is `Elephentity.Runtime.Testing.Xunit`, so inside it `Xunit` must be written
  `global::Xunit`.
- **`ContractCheck`** replaces the PHP `CheckCommand`'s walk of `*/verify.php`. It resolves every
  `Contracts()` type from the application's `IServiceProvider` (a missing one is an error naming the
  interface), then runs every `IVerifier` registered as `IEnumerable<IVerifier>`. It is an assertion
  (`AssertConforms`) and a console entry point (`TryRunConsole`, argument `eleph-check`) that prints
  the `{"severity","message"}` lines `eleph check` reads.
- **`StubEntityCatalogue`** is public in `Elephentity.Runtime.Testing`: every collection empty,
  every policy set `NoPolicies`, and anything that would run generated code throws until a test
  overrides it. The PHP tests mocked the catalogue interface; there is no mock library here.
- **`MutationLifecycleTest`** is ported in full (`MutationLifecycleTests`) and run against the real
  `MemoryAdaptor` rather than a mock of storage, so it is also the first end-to-end proof that step 11's
  runtime, unit of work and gates work through a real adaptor.

## Step 15: Cosmos

- **`paging.oversized-pages-cap-with-an-effective-size-cursor` accepts a keyset cursor.** PHP's case required an offset cursor standing at
  `Page::MAX_LIMIT`. An adaptor that resumes by keyset has no number to check, so the case accepts either: an offset cursor must still
  stand at the effective size, a keyset cursor must be returned, and in both the walk that follows must resume without gaps or
  repeats, which is the property that matters. The case keeps its name.
- **Cosmos never emits an `OffsetCursor`** (it accepts one). See `docs/cosmos.md`.
- **`TransactionAsync` stages and flushes.** Cosmos has no multi-request transaction, so the writes made inside one are collected
  and sent as a single `TransactionalBatch` when the work returns. Reads inside do not see staged writes; the memory driver's do.
- **Ids are made by the adaptor** (UUIDv7 strings), not by the store, so a row's id is known before it is written.
- **A missing property is "not equal"** in `Filter.NotEqual`, as in the memory driver; Cosmos alone would leave the row out.
- **Documents are `JsonObject`s** and the adaptor is `IsAotCompatible` (it builds clean with the analyzers on), but the Cosmos SDK
  itself is not trim-safe; an application that publishes with Native AOT should check the SDK's own status.

## Step 17: GraphQL

`Elephentity.GraphQL` ports `elephentity-graphql-php`. The schema it builds is meant to be indistinguishable from the PHP one for the
same spec: `tests/fixtures/graphql/book-author.graphql` is what the PHP package prints for a small model (normalised as
`tsquid-schema fetch` does), and a test requires the C# schema to print the same bytes. Where the C# differs:

- **Delegates, not accessor names.** PHP's `FieldEntry` and `ConnectionEntry` name a method and call it; C# carries the accessor
  (`Func<object, object?>`, `Func<object, IEntityQuery<object>>`) and `ObjectTypeEntry` an `IsInstance` delegate, so nothing uses
  reflection and the compiler checks the generated manifest against the read models.
- **`ReadAsync`.** A to-one edge costs a query (`GetAuthorAsync` on the read model), so `FieldEntry` can carry an async reader instead of `Read`.
- **No `BackedEnum` encoding.** The generated `<Enum>Wire` class turns a C# enum into its stored value, so the field's delegate
  returns the stored value and the encoding is `Value`.
- **`GraphQLManifest`, `MutationKind`** replace `Manifest` and the string constants.
- **The package finishes what PHP left to the application** (`docs/extraction.md` there): `Node`, `PageInfo` and `node(id:)` are
  registered; a wrong-type global id is an error; a malformed cursor is an error; backward paging is refused; `first: 0` is an empty
  page; errors map to `extensions.code` (`FORBIDDEN`, `BAD_USER_INPUT` with `violations`, `NOT_FOUND`, masked `INTERNAL`).
- **Cursors are the runtime's keyset cursors.** A page knows where it ends, not where each row is, so only the last edge of a page
  carries a cursor (also `pageInfo.endCursor`). PHP's positional cursors gave every edge one. So `pageInfo.startCursor` is null unless the
  page holds a single row, and `endCursor` is null on the last page; in PHP they always named the first and last edge.
- **A published collection query with no arguments has no `where`.** PHP registers an input object with no fields for it, which GraphQL
  refuses (webonyx `assertValid` fails the same way), so the PHP stack cannot publish such a query; here it is a connection named by the
  field, with no `where` argument and no `WhereArgs` type.
- **`totalCount` is counted only when selected.**
- **Page sizes are options** (`DefaultPageSize` 10, `MaxPageSize` 100). The reference application, hblake, configures 20.
- **Edge connections page the lazy query**, as PHP does. Batching a to-many edge across parents through a DataLoader is deferred:
  `CachingEdgeLoader.PreloadAsync` has no per-parent limit or order, so it would ignore `first`/`after`. To-one fields are not
  batched either: a to-one edge is a `FieldEntry` with an opaque `ReadAsync`, so the schema cannot tell which edge to preload without
  a manifest change, and each costs a query per parent (cached per request). See the follow-up,
  #15.
- **Runtime additions.** `EntityQueries.AsObjects` (the inverse of `Cast`, for the untyped gateway surface) and
  `EntityNotFoundException` (an `InvalidOperationException` subclass, so nothing that caught the old exception stops catching it).
- **Declared collection queries must come back as `IEntityQuery<object>`** from `IEntityCatalogue.RunQueryAsync`; the C# builder's
  catalogue returns a typed query today, so it needs `.AsObjects()` there.

## Step 19: Tsquid

`Elephentity.Tsquid` ports `elephentity-codegen-tsquid` (`Manifest`, `InitialData`, `HtmlResponse`) and follows the tsquid server-integration
SKILL, whose `vectors.json` runs in the tests. Where the C# differs from the PHP:

- **The executor is passed per render, not at construction.** `InitialData.RenderAsync(url, html, execute, ct)` takes the delegate, because in
  ASP.NET Core the viewer lives in the request's services. It returns the response body as JSON text (null on a transport failure), so this
  package knows no GraphQL type; the middleware adapts `ElephentityGraphQLExecutor`.
- **Async,** like everything that can reach storage.
- **A timeout.** The SKILL says not to block the HTML on a slow query. PHP cannot interrupt a synchronous executor, so it leaves that to the
  callback; the middleware owns the execution here, so `UseElephentityTsquid` takes a `timeout` (one second by default).
- **A failed query is logged** (an optional `ILogger` on `InitialData`), not swallowed silently.
- **`RouteManifest` and `ManifestJson`.** The manifest is data (`RouteManifest`) with a JSON reader for apps that load the committed
  `routes.manifest.json` at deploy time and for the conformance vectors; the C# builder (step 20) emits the same data as code.
- **Escaping.** `<` is escaped to `\u003c` (as the SKILL says) and not `>` (PHP's `JSON_HEX_TAG` escapes both), and U+2028/U+2029 are escaped.
- **`FieldKind` is `Text`, `Whole`, `Flag`** (a .NET analyzer refuses `String` and `Int`).

## SQLite

`Elephentity.Sqlite` ports `packages/sqlite` (`SQLiteAdaptor`, `Database`, `QueryCompiler`, `FieldMap` and the manifest types). It
writes the same tables and the same SQL for the same query; see [sqlite.md](sqlite.md). Where it differs:

- **Names.** The manifest types carry the package's prefix, as Cosmos's do: `Column` is `SqliteColumn`, `Index` is `SqliteIndex`,
  `TableSchema` is `SqliteTable`, `EdgePlacement` is `SqliteEdgePlacement` and `StorageManifest` is `SqliteStorageManifest`
  (`Manifest` is a namespace, and `Index` would collide with `System.Index`). `FieldMap` is not a type: the manifest answers
  `Column(entity, field)` and `Field(entity, column)` itself. `Database` is not a type either; the adaptor owns its connection.
- **One lock.** PDO runs one statement at a time because PHP is single-threaded. Here an adaptor serialises every call with a lock a
  transaction holds, which is the same guarantee for code that is not.
- **The installer is a library call.** PHP generates an `install.php` closure around the statements. C# generates the statements
  (`SqliteStorageManifest.Install`, with table and index names left open for a prefix) and `SqliteInstaller.InstallAsync` runs them
  with the same rules: foreign keys on, one transaction, an existing table fails and rolls back, a surrounding transaction is
  refused, a prefix must be an identifier. The PDO driver check has no equivalent; the type is the check.
- **`KeysetCursor` is honoured.** PHP has no keyset cursor. A `KeysetCursor` passed in resumes strictly after its position, and the
  page's `Next` is another; any other cursor is an offset, as in PHP.
- **Values are bound as their type.** PDO binds a float as text, which a `REAL` column then converts; a C# `double` is bound as a
  `REAL`. A `bool` is bound as the integer 1 or 0 in both. A value that is not a string, a number, a bool or null is refused with
  an `ArgumentException` naming the field, before any SQL runs.
- **The `CLOG_NOCASE` collation is not registered.** PHP's `Database` creates it for Clog; no schema this builder writes uses it.
- **A failed rollback does not replace the failure that caused it.** SQLite may already have undone the transaction (a disk error does);
  the original exception is the one that propagates.
- **A link query binds ids as the numbers they are.** PHP binds them as text, which SQLite compares as the same number.

What is the same as PHP, and so differs from the memory driver and from Cosmos (listed in [sqlite.md](sqlite.md)): `LIKE` ignores
the case of ASCII letters, `NotEqual` leaves out rows with no value, and updating a row that does not exist is not an error.
