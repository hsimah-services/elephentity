# Proving an adaptor

## The suites

| Stack | Suite | Use |
|---|---|---|
| PHP | `packages/runtime/src/Storage/Testing/AdaptorConformance.php` | Subclass in the adaptor's tests; it runs the whole storage contract |
| C# | `Elephentity.Runtime.Testing` (`AdaptorConformance`, `ConformanceCase`) | Inherit the xUnit base class in `Elephentity.Runtime.Testing.Xunit` to run the cases as a theory |

Passing conformance is the minimum. It cannot know your store's limits.

## Add store-specific tests

- Unique conflicts surface as violations, not driver exceptions.
- Rollback leaves nothing behind; savepoints/batches behave under failure.
- Paging: stable keyset order, cursors survive concurrent inserts.
- Batch and size limits (Cosmos has a hard batch cap and a test for it).
- Install refuses a populated store; backup/restore round-trips if offered.
- Identifier rules: case-insensitive collisions, reserved words, length limits.

## Prove the emitted manifest, not a hand-written one

Load the builder's real output against the adaptor, using the tagged runtime version as a
test dependency, independent of any live application's vendor tree. Keep test dependency
locks separate from the build-time package so consumers' tooling does not pull runtimes in.

Use disposable fixtures. Never point tests at a live application's data.

## Acceptance pipeline

Generate a small real project (spec → `eleph generate` → adaptor), then
`eleph generate --check` and `eleph check`. `tools/test-package-install.sh` does this for a
Composer project end to end; `builders/codegen-sqlite/tools/` has the SQLite pipeline test.
