# Elephentity.Cosmos

`Elephentity.Cosmos` is the storage adaptor for Azure Cosmos DB for NoSQL. It implements `IStorageAdaptor`, passes the
whole `AdaptorConformance` suite on the Cosmos emulator, and is what a small app on the **free tier** runs on, so request
units (RU) are a design input and not an afterthought.

## Layout

One container, partition key `/pk`, **one logical partition per project**. Every document has:

| Property | Holds |
|---|---|
| `id` | The row's id: `Guid.CreateVersion7().ToString("N")`, made by the adaptor. |
| `pk` | The project's partition value (`CosmosStorageManifest.PartitionKey`). |
| `type` | The entity's name, or `__edge:Entity.edge` for a join document. |
| *fields* | One top-level property per field, named by the manifest. |
| `_u0` … `_uN` | The synthetic unique-key slots (below). |

**Why ids are UUIDv7.** The leading bits are a timestamp, so ids sort in the order rows were created. Every page ends in
`id ASC` to make an order total, and rows that tie on their sort value therefore come back oldest first, as they do with
SQLite's integer ids. The adaptor, not Cosmos, makes the id, which is also what lets a unit of work know a row's id
before anything is written.

**Every field is always present.** A row created without a value for a field is stored with an explicit `null`. A Cosmos
`ORDER BY` silently leaves out documents that lack the property, so a missing one would make a row vanish from every
sorted list.

### Edges

The manifest places each edge by its relation, with no choice offered, as the SQL adaptors do:

| Relation | Stored as |
|---|---|
| one-to-one, many-to-one | The target's id in a property of the **declaring** document. |
| one-to-many | The owner's id in a property of each **target** document. |
| many-to-many | A **join document** per link, `type = __edge:Entity.edge`, with `from` and `to`. Its id is derived from the pair, so linking twice is one document and unlinking a pair is a point delete. |

Cosmos cannot join across documents. A query whose key is on the document being queried (`c.postId IN (…)`) is one query;
one whose key is on the other side, or in join documents, is **two**: the first reads the references, the second reads the
rows by id. A batch of parents (`EdgeFilter` with several ids) gets one row per parent, with the parent in `__parent`.

### Uniqueness

A Cosmos unique key is a path, scoped to a logical partition, and it applies to documents of every type. So uniqueness is
carried by synthetic properties: an entity's unique fields take slots 0, 1, …, written as `"Entity|=value"`, and the
container's unique key policy names `/_u0`, `/_u1`, ….

* A document with no value in a slot still needs one (an absent property counts as a value, so two absent ones collide). It gets
  a filler, `"Entity|#id"`, which is unique because the id is. A `null` is no value: any number of rows may have none.
* **Case sensitivity is explicit** in the manifest (`CosmosUniqueKey.CaseSensitive`, default `true`). When it is `false` the
  value is upper-cased invariantly before it is compared, so `Ab` and `ab` collide.
* A conflict (409) is mapped back to the same `CommitRejectedException` that `UniquenessCheck` raises when it sees the value
  first: a `field.unique` violation naming the field. The runtime still checks before it writes; the container is the guarantee.
* **Unique keys cannot be changed once a container exists.** To change them: export, create a new container, import (below).

### Timestamps

Stored by the runtime's encoder as UTC ISO 8601 (`"o"`), so lexical order is chronological.

## Writes and transactions

A `TransactionalBatch` is atomic within one logical partition, and everything here is one partition. So:

* `WriteAsync` outside a transaction is one batch.
* `TransactionAsync` collects the writes made inside it and sends them as **one batch when the work returns**. If the work
  throws, nothing is sent. The runtime's unit of work (rows, then links, then deletions, three separate calls) is therefore
  atomic as a whole. Reads inside a transaction do not see its own staged writes.
* A batch holds **at most 100 operations**. A larger write fails with `CosmosBatchTooLargeException` before anything is
  sent. Staging folds operations: a link to a document created in the same transaction is written into that document, and
  two updates of one document become one, which matters at this limit. A patch holds at most ten changes, so a long update
  is several operations.
* Deleting what is not there, and unlinking what is not linked, are not errors (as deleting no rows is not in SQL). When a batch
  fails on such an operation it is dropped and the batch is sent again, still atomic.
* An update of a row that does not exist fails with `Cannot update X#id: no such row.`

## Queries

Every query is `SELECT … FROM c WHERE c.type = @type AND …` in a single partition (`PartitionKey` is set on the request).
**Values are always parameters.** Property names are resolved against the manifest and written in bracket form.

| `Filter` | Cosmos SQL |
|---|---|
| equal, lt, lte, gt, gte | `=`, `<`, `<=`, `>`, `>=` |
| not equal | `(NOT IS_DEFINED(p) OR p != @v)`: a missing value is "not equal", as in the memory driver |
| in, not in | `ARRAY_CONTAINS(@values, p)`; an empty set is decided without a parameter |
| contains, starts with | `CONTAINS(p, @v, false)`, `STARTSWITH(p, @v, false)` (case-sensitive) |
| is null, is not null | `(IS_NULL(p) OR NOT IS_DEFINED(p))`, `(IS_DEFINED(p) AND NOT IS_NULL(p))` |

`Count` is `SELECT VALUE COUNT(1)`. **A policy-gated `Count()` hydrates every row** (the database cannot see policies), so it
costs a read of the whole set; prefer a page.

### Paging

Keyset, always. A page is `ORDER BY` the sort keys then `c.id`, and it resumes strictly after the cursor's position. Cosmos has
no row-value comparison, so the position is an OR-expanded tuple comparison: `a > x OR (a = x AND b > y) OR (a = x AND b = y
AND id > z)`, with nulls handled for each direction (Cosmos orders null before every other type). The cursor is a
`KeysetCursor`. An `OffsetCursor` is **accepted** (it becomes `OFFSET … LIMIT`, which costs RU in proportion to the offset) and
**never emitted**.

A page of one-row-per-parent rows (a batch of parents with a limit) is cut in memory, because Cosmos cannot produce a row
twice.

**Every ordering must have a composite index.** A Cosmos `ORDER BY` on more than one property fails without one, and every page is
ordered by its sort keys *then* the id. Declare each ordering in the manifest (`CosmosOrdering`); the installer creates the
composite index (sort terms, then `id` ascending) and Cosmos serves the exact inverse from the same index. An ordering that was
not declared fails with a message saying so, not with the service's.

## The client

```csharp
// Managed identity (a Container App): no secret anywhere.
var adaptor = new CosmosAdaptor("https://<account>.documents.azure.com:443/", new DefaultAzureCredential(), "app", manifest);

// Or a client you own. It MUST use CosmosSystemTextJsonSerializer (CosmosClientSettings does it for you).
var client = new CosmosClientSettings(MaxRetryAttemptsOnRateLimitedRequests: 9).CreateClient(endpoint, credential);
var adaptor = new CosmosAdaptor(client, "app", manifest);
```

* **Nothing connects in a constructor.** A `CosmosClient` connects on first use, so a cold-starting app does no work until it serves
  a request.
* **429s are retried by the SDK**, 9 times and up to 30 seconds in total by default (`CosmosClientSettings`), because on a
  shared 400 RU/s a burst is normal and failing the request is worse than waiting.
* **Documents are `System.Text.Json` nodes**, serialized through a source-generated context (`CosmosSystemTextJsonSerializer`).
  Newtonsoft.Json is referenced only because the SDK uses it for its own system types.
* `Capabilities` is `Transactions` only. Full-text search, row locking and foreign keys are not offered.

## Installing

```csharp
await CosmosInstaller.InstallAsync(client, "app", manifest, throughput: 400);
```

Creates the database with **shared** throughput (400 RU/s by default, which leaves room under the free tier's 1000) and the
container with the partition key, the unique keys and the indexing policy.

* **It fails (`ContainerNotEmptyException`) if the container exists and holds any document**, and changes nothing. Installing
  and migrating are different operations. This is the contract an `install` command needs: fail non-zero on a populated container.
* An empty container that already exists, created the way the manifest says, is left alone apart from its indexing policy.
  One created with other unique keys or another partition key is reported, not modified: those cannot change in place.

## Export, import and backups

```csharp
await CosmosBackup.ExportAsync(container, stream);   // JSON Lines, one document per line, system properties removed
await CosmosBackup.ImportAsync(container, stream);   // into an EMPTY container, in batches of one partition
```

This is the migration path for the one thing a container cannot change: export, create a new container with the new manifest,
import. **It is not your backup.** Azure Cosmos DB takes periodic backups of every account, and can take continuous backups with
point-in-time restore; use those for recovery. Import is not atomic as a whole: a failure stops it and leaves the earlier
batches in place (it refuses a populated container, so re-running means starting from an empty one).

## Limits and costs

* **20 GB per logical partition.** One partition per project means a project's data is capped at 20 GB. That is a wide margin for
  a consulting site and a hard wall for something large; past it the answer is a partition key per tenant or per entity, which is a
  different manifest and a migration.
* **Batches: 100 operations.**
* **Free tier:** 1000 RU/s and 25 GB per account, one free account per subscription. A shared 400 RU/s database fits it. Point
  reads cost about 1 RU; queries cost more the more they scan, and the ones that need two steps cost two.

## Testing against the emulator

```bash
# Linux emulator (needs about 3 GB and a minute or two to start)
podman run -d --name cosmos -p 8081:8081 -p 10250-10255:10250-10255 \
  -e AZURE_COSMOS_EMULATOR_PARTITION_COUNT=3 -e AZURE_COSMOS_EMULATOR_ENABLE_DATA_PERSISTENCE=false \
  mcr.microsoft.com/cosmosdb/linux/azure-cosmos-emulator:latest

ELEPH_COSMOS_ENDPOINT=https://localhost:8081 ./tools/dotnet dotnet test tests/Elephentity.Cosmos.Tests
```

Without `ELEPH_COSMOS_ENDPOINT` the tests that need a server are **skipped**, so a plain `dotnet test` stays green. With it, they run
against the emulator (when the key is the emulator's well-known one, the certificate is trusted and the gateway is used) or a real
account (`ELEPH_COSMOS_KEY`). CI runs them on every pull request against the emulator as a service container.

**What the emulator ran:** every conformance case, unique keys (including case-insensitive and composite-with-filler), transactional
batches of exactly 100, patch with a guard predicate, composite indexes, keyset paging across ties, and export/import. **Nothing is
skipped on the emulator.** What no test here has exercised, because it needs a real account: managed identity authentication,
429 throttling under real RU pressure, Direct connection mode, and the service's own composite-index validation (the emulator
accepted a composite index ending in `/id`).
