# Elephentity.Sqlite

`Elephentity.Sqlite` is the storage adaptor for SQLite. It implements `IStorageAdaptor`, passes the whole `AdaptorConformance`
suite, and is a port of the PHP adaptor (`elephentity/packages/sqlite`): the same tables, the same columns, the same edge
placements, and the same SQL for the same query. A database one language wrote the other reads.

It uses `Microsoft.Data.Sqlite`, which brings its own native SQLite. Nothing here needs a server, so the whole suite runs
everywhere, including CI, with no skips.

## Using it

`eleph-gen-sqlite` writes the manifest; set the target's `language` to `"csharp"` (see
[the builder's README](../../builders/codegen-sqlite/README.md)). Then:

```csharp
var manifest = SqliteStorageManifestFactory.Create();   // generated

await using var connection = new SqliteConnection("Data Source=/var/lib/app/data.sqlite");
await SqliteInstaller.InstallAsync(connection, manifest);   // once, on a fresh database

using var adaptor = new SqliteAdaptor(connection, manifest);
```

`SqliteAdaptor` also takes a connection string, in which case it opens its own connection on first use and disposes it.
**Nothing connects in a constructor.**

## Layout

Every entity has a table named by the manifest (the compiler has already applied `project.storage.tablePrefix`):

| Column | Holds |
|---|---|
| `id` | `INTEGER PRIMARY KEY AUTOINCREMENT`. Ids are made by SQLite, so a row's id is known once it is written, and are positive integers (`EntityId.Of(long)`). |
| one column per field | the field name in snake_case. `TEXT`, `INTEGER` or `REAL`. Booleans are the integers 1 and 0, with a `CHECK`; moments are UTC ISO 8601 text and JSON is text, as the runtime encodes them. |
| a link column | for the edges whose key lives on this table (below). |

Unique and indexed fields produce indexes, and boolean, enum and `maxLength` fields produce `CHECK` constraints. Foreign keys are
`ON DELETE RESTRICT`: the runtime owns cascading, and a database cascade could not be bypassed or authorised.

### Edges

The manifest places each edge by its relation, with no choice offered:

| Relation | Stored as |
|---|---|
| one-to-one, many-to-one | The target's id in `<edge>_id` of the **declaring** table. One-to-one has a unique index on it. |
| one-to-many | The owner's id in `<inverse>_id` of each **target** table. |
| many-to-many | A **join table** `<table>_<edge>` with the two ids, a unique index on the pair, and an index on the second. Linking twice is one row. |

Link columns allow `NULL`, because the unit of work inserts rows before linking them; the runtime enforces required edges. A
batch of parents (`EdgeFilter` with several ids) gets one row per parent, with the parent in `__parent`.

## Queries

Every query is parameterised: **values are always parameters**, and identifiers are checked against the manifest and then quoted.
A field the table has no column for fails with `InvalidOperationException` and runs nothing.

| `Filter` | SQL |
|---|---|
| equal, lt, lte, gt, gte | `=`, `<`, `<=`, `>`, `>=` |
| not equal | `<>` |
| in, not in | `IN (…)`, `NOT IN (…)`; an empty set is decided without SQL (`1 = 0`, `1 = 1`) |
| contains, starts with | `LIKE … ESCAPE '\'`, with `%`, `_` and `\` in the value escaped |
| is null, is not null | `IS NULL`, `IS NOT NULL` |

Three things follow from SQLite, are the same in PHP, and differ from the memory driver and Cosmos:

* `LIKE` ignores the case of ASCII letters, so `Contains("title", "ab")` finds `AB`.
* `<>` is not true for `NULL`, so **`NotEqual` leaves out rows with no value**.
* Text compares byte for byte (the `BINARY` collation); no locale collation is applied.

### Paging

A page is `LIMIT n + 1` (the extra row says whether there is another page and is dropped), capped at `Page<T>.MaxLimit`, and
ends in the id to make an order total. It resumes by **offset**, as in PHP, and returns an `OffsetCursor`. Given a
**`KeysetCursor`** it resumes *strictly after that position* in the criteria's total order and returns another, which is what
reading an entity with read policies relies on. The position is written out as `a > x OR (a = x AND b > y) OR …`, since the terms may
run in different directions and may be null (SQLite sorts `NULL` first), and costs what it scans rather than everything before it.

`Count` ignores paging and ordering. A policy-gated `Count()` still hydrates every row: the database cannot see policies.

## Writes and transactions

SQLite allows one writer, and a connection cannot run two commands at once, so **one lock serialises everything on the adaptor**:
calls from several tasks queue. Use one adaptor per database.

* `WriteAsync` is a transaction (`BEGIN IMMEDIATE … COMMIT`), so a batch is atomic.
* `TransactionAsync` holds the lock for the whole of the work, and commits when it returns or rolls back if it throws. One opened
  inside another is a **savepoint**, which rolls back by itself; reads and writes inside see the transaction's own writes.
  Do not start tasks inside the work that use the adaptor and outlive it.
* Cancelling a call before it reaches SQLite throws and leaves nothing changed; a cancelled transaction is rolled back. (SQLite
  itself cannot be interrupted mid-statement through this driver.)
* Updating a row that does not exist changes nothing and is not an error, as in PHP. Deleting a row another points at fails with the
  `SqliteException` for the foreign key, and the batch is rolled back.
* `Capabilities` is `Transactions` and `ForeignKeys`.

On first use the adaptor sets `foreign_keys = ON`, `busy_timeout = 3000`, `journal_mode = WAL`, `synchronous = FULL` and
`cache_size = -2048`, as the PHP `Database` does, **whether it opened the connection or was handed one**.

## Installing

```csharp
await SqliteInstaller.InstallAsync(connection, manifest, prefix: "");
```

Creates every table and index in one transaction.

* **It fails if a table exists, and changes nothing**: the transaction is rolled back, including the tables it had already made.
  Installing and migrating are different operations; keep reviewed migrations in the application.
* It refuses to run inside a transaction the caller opened, and enables foreign keys before it begins its own.
* An *additional* `prefix` goes in front of every table and index name, and the same prefix goes to `manifest.WithPrefix(prefix)`.
  It must be an identifier (`[A-Za-z_][A-Za-z0-9_]*`), and is checked before anything runs.

## Testing

`dotnet test tests/Elephentity.Sqlite.Tests` runs the conformance suite on an in-memory database per case, and the adaptor,
query compiler, manifest and installer tests. The builder's own tests (`builders/codegen-sqlite/tests/dotnet`) load the
*generated* manifest for every relation shape into this package and run the same suite.
