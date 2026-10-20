# Elephentity.GraphQL

GraphQL.NET for the generated entities: the same schema `elephentity-graphql-php` builds from the same spec, so a client's
committed `schema.graphql` and tsquid's Relay compile do not care which server they talk to.

```csharp
services.AddElephentityGraphQL(GeneratedGraphQL.Manifest(), options =>
{
    options.DefaultPageSize = 20;   // hblake's choice; the default is 10
    options.MaxPageSize = 100;
    options.MaxDepth = 12;          // clog's limits, as examples
    options.MaxComplexity = 500;
});

app.MapElephentityGraphQL("/graphql");   // POST application/json
```

The generated `Add<Project>Entities` supplies the `IEntityGateway` this resolves against; the viewer is the runtime's
`IViewerProvider`, resolved per request, so every read and write is policy-gated. Sessions, CSRF and authentication stay in the
application's own middleware.

## What the package does for you

- `Node`, `PageInfo` and `node(id: ID!)`. Ids are `base64("eleph:<Type>:<id>")`; a raw row id is still accepted, and an id of the
  wrong type is an error, never a lookup of the wrong row.
- Connections `{From}To{To}Connection` (`{From}To{Ucfirst(field)}Connection` when the field takes `where`) with `first`/`after`
  paging. `last` and `before` are refused, a malformed cursor is refused, `first: 0` is an empty page, a negative `first` is an
  error and a large one is capped. `totalCount` is counted only when asked for.
- Relay mutations, `clientMutationId` on input and payload, and `delete<Type>` returning the global `deletedId`.
- Errors carry `extensions.code`: `FORBIDDEN` (access denied), `BAD_USER_INPUT` (a rejected commit, with `violations`, or something
  the client got wrong), `NOT_FOUND`, and `INTERNAL` for everything else, with the message masked and the exception logged.
- `ElephentitySchemaPrinter.Print(schema)` prints the SDL sorted the way `tsquid-schema fetch` normalises it, and
  `ElephentitySchemaPrinter.Check(schema, committed)` is the "has the client's schema drifted" comparison for an application's tests.

## Testing against the PHP schema

`tests/fixtures/graphql/regenerate.sh` rebuilds `book-author.graphql` from the PHP package in containers (PHP for the schema,
node for graphql-js's `printSchema`). `tests/Elephentity.GraphQL.Tests/BookModel.cs` is the C# twin of `book-author.php`; change one,
change the other, regenerate, and the SDL test says whether they still agree.

## Running the tests

`./tools/dotnet dotnet test tests/Elephentity.GraphQL.Tests` runs this package's tests. (`dotnet test --filter` over the whole
solution runs zero tests in the other projects, which Microsoft.Testing.Platform reports as exit code 8 even when everything passed.)
