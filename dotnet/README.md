# Elephentity for .NET

The .NET side of [Elephentity](https://github.com/hsimah-services/elephentity): the runtime
that generated C# code runs on, SQLite and Cosmos DB storage adaptors, GraphQL, and server-side
initial data for [tsquid](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen-tsquid).
One repository, one NuGet package per concern, all type-checked together.

Elephentity compiles human-readable specs into locked, signed business logic. The compiler
and the code generators are Rust programs; this repository holds only what the generated
code needs at run time. The plan, the decisions and the ordered steps are in the
[.NET epic](https://github.com/hsimah-services/elephentity/issues/115).

> **Status: under construction.** The storage port, identity, capabilities and the policy
> model are in `Elephentity.Runtime` (step 10). The gateway and unit of work follow in step 11,
> the memory driver and conformance in step 12, Cosmos in 15, GraphQL in 17 (see [docs/graphql.md](docs/graphql.md)) and tsquid in 19 (see [docs/tsquid.md](docs/tsquid.md)) of
> the epic. Every difference from the PHP runtime is listed in
> [docs/divergence.md](docs/divergence.md).

## Packages

| Package | What it is | Ports |
|---|---|---|
| `Elephentity.Runtime` | The storage port, unit of work, verification and policy contracts. Async-first. | `elephentity/packages/runtime` |
| `Elephentity.Runtime.Memory` | The in-memory storage driver: no physical schema, no platform. | `elephentity/packages/memory` |
| `Elephentity.Runtime.Testing` | Storage adaptor conformance cases and `ContractCheck`, which `eleph check` runs for C#. | `AdaptorConformance` |
| `Elephentity.Runtime.Testing.Xunit` | An xUnit base class that runs the conformance cases as a theory; inherit it to test an adaptor. | |
| `Elephentity.Cosmos` | The storage adaptor for Azure Cosmos DB. | `elephentity-sqlite` |
| `Elephentity.Sqlite` | The storage adaptor for SQLite. See [docs/sqlite.md](docs/sqlite.md). | `elephentity/packages/sqlite` |
| `Elephentity.GraphQL` | GraphQL.NET support that emits the same schema as the PHP runtime. | `elephentity-graphql-php` |
| `Elephentity.Tsquid` | Server-side initial route data for ASP.NET Core. | `elephentity-codegen-tsquid` |

## Using it

A project does not reference these packages by hand. It lists the C# builders in its
`eleph.json`, runs `eleph generate`, and the generated code names the types in
`Elephentity.Runtime` and the adaptors it was generated for. Add the packages the generated
project asks for:

```bash
dotnet add package Elephentity.Runtime
dotnet add package Elephentity.Cosmos   # or Elephentity.Sqlite
```

The libraries target `net10.0`, enable nullable reference types, treat warnings as errors
and are marked AOT compatible. Generated code uses no reflection.

## Working in this repository

There is no host dotnet. Everything runs in a container (podman first, docker as a fallback):

```bash
./tools/dotnet dotnet format --verify-no-changes
./tools/dotnet dotnet build -c Release
./tools/dotnet dotnet test
./tools/dotnet dotnet pack -c Release -o artifacts -p:Version=0.0.0-ci
```

Read [AGENTS.md](AGENTS.md) before porting anything: it carries the rules for working here.

## Licence

MIT. See [LICENSE](LICENSE).
