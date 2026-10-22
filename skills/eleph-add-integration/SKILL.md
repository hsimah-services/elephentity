---
name: eleph-add-integration
description: Adding an integration to Elephentity — an API or external-system surface (such as GraphQL) that entities opt into with `integrations:` in the spec. Use when asked to expose entities over a new protocol, change what the GraphQL integration emits, or add keys to an integration's entity/query/project config. Covers the runtime registrar, the manifest, the builder's `provides.integrations` declaration and its verifier.
---

# Adding an integration

An **integration** exposes entities to something outside the framework. It adds no fields,
edges or actions (that is a *pattern*); it adds settings for the exposure. It takes the same
two halves as a storage adaptor:

| Half | Runs | Does |
|---|---|---|
| **Builder** | build | emits a *manifest* describing what is exposed, and a verifier |
| **Runtime library** | run time | loads the manifest and registers it with the host library, calling the core gateway |

Read `eleph-add-builder` for the builder half. The existing integration is `graphql`:

| Stack | Runtime | Builder | Host |
|---|---|---|---|
| PHP | `packages/graphql` | `builders/codegen-graphql-php` | webonyx |
| C# | `dotnet/src/Elephentity.GraphQL` | `builders/codegen-graphql-csharp` | GraphQL.NET |

## How the spec learns your keys

Keys are declared by the builder, in `rust/provides.json` under `integrations`, and reach
the compiler through `describe`. No spec YAML declares them:

```json
{ "integrations": { "graphql": {
    "description": "Exposes entities through GraphQL.",
    "projectConfig": {},
    "entityConfig": { "singular": { "type": "string" }, "plural": { "type": "string" } },
    "queryConfig":  { "field": { "type": "string" } } } } }
```

Opt-in is double: the project says what it speaks (`integrations: { graphql: {} }` in
`project.yml`), and each entity says whether it is exposed (an `integrations.graphql` block).
An entity that says nothing is **not exposed** — adding an entity must never silently widen a
public API. Queries opt in separately with their own `integrations` block.

Never pluralise on the author's behalf. If you need a plural, make it a key with a
documented default.

## Order of work

1. **Read the core contracts you call**: PHP `packages/runtime/src/Gateway/EntityGateway.php`,
   `Query/EntityQuery.php`, `Conformance/Verifier.php`; C# the equivalents under
   `Elephentity.Runtime`. Skip unrelated HTTP and application code.
2. **Design the manifest** as typed objects: types, enums, roots, relationships, mutations,
   actions, published queries. Read the nearest `Manifest/` constructors.
3. **Write the registrar and resolvers**: manifest in, host-library config out, resolvers calling
   the gateway. Resolvers must preserve runtime policies; never bypass the gateway.
4. **Write the builder**: `provides.json`, `manifest.rs`, and a verifier (`verify.php` in PHP;
   C# uses `ContractCheck` run as the target's `check`).
5. **Test against the real host library**, executing the schema, not only comparing snapshots.
6. Add the key docs to the builder README and to `skills/eleph/reference/spec-format.md`.

## Rules

- Confirm every class the manifest or verifier names exists in the *generic* runtime.
- Root entries can also drive delete mutations; clearing roots has consequences beyond queries.
- Do not advertise a project option the artifacts and runtime do not honour.
- Test names, enum members, inverse accessors, processed values, actions and custom query
  returns. Snapshot equality proves stability, not that the host accepts the schema.
- The application owns HTTP transport, authentication, sessions and deployment. The runtime
  library supplies the schema and resolvers, not a server.
- Keep integration output under the entity output directory so `eleph check` finds the
  verifier.

## Reference

- `reference/files.md` — what a runtime integration library and its builder contain
- `reference/lessons.md` — GraphQL lessons already paid for
- `eleph-add-builder` — protocol, anatomy, testing, distribution
- `builders/codegen-graphql-php/README.md` — a complete worked example of a builder README
