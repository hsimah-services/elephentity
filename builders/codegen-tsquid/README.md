> **This is the source of truth.** `hsimah-services/elephentity-codegen-tsquid` is a read-only mirror of [`builders/codegen-tsquid/`](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen-tsquid) in [`hsimah-services/elephentity`](https://github.com/hsimah-services/elephentity), kept for Composer and Packagist. Open issues and pull requests on `elephentity`.

# elephentity-codegen-tsquid

Build-time Rust manifest compiler for tsquid's initial route data, with a standalone PHP runtime, and a C# target for
[`Elephentity.Tsquid`](https://github.com/hsimah-services/elephentity/tree/main/dotnet). Uses the version-1 server manifest introduced in tsquid PR #8.

## Configure

Install `elephentity/codegen-tsquid` as a production Composer dependency: it also
provides the `Eleph\Tsquid` runtime.

```sh
composer require elephentity/codegen-tsquid:^0.1
```

In this checkout, build the generator with:

```sh
cargo build --release --locked
```

For a Composer installation, pass
`--manifest-path vendor/elephentity/codegen-tsquid/Cargo.toml` to that build command.
The deployed app needs PHP and the Composer package, but neither Rust nor the
generator executable.

Enable the project integration in `spec/project.yml`:

```yaml
integrations:
  tsquid:
```

Add a target alongside your existing targets in `eleph.json`:

```json
{
  "tsquid": {
    "builder": "vendor/bin/eleph-gen-tsquid",
    "output": "generated/tsquid",
    "manifest": "../../../client/src/routes/__generated__/routes.manifest.json"
  }
}
```

**The manifest path is relative to the target's output directory**, or absolute.
The example assumes `server/eleph.json` and a sibling `client/`. It does not depend
on the shell's working directory. The output directory may be absent on the first run.

Generate Relay's persisted queries and tsquid's route manifest first, then run
`eleph generate`. `eleph generate --check` detects route/query drift. The builder
reads the manifest and returns `tsquid-manifest.php`; the orchestrator signs and
writes it. No YAML, TypeScript, or JSON is parsed on application requests.

On the client, declare each route's `query` and wire `readInitialData()` and
`withInitialData()` into the Relay environment as described in tsquid's server
integration skill. The entrypoint must preload the same operation and variables.

## C# (.NET)

The same builder emits C# for `Elephentity.Tsquid`. Set the target's `language` to `"csharp"` (`"php"` is the default, and its output is
unchanged) and give it a `namespace`:

```json
{
  "tsquid": {
    "builder": "eleph-gen-tsquid@0.1.0",
    "output": "Generated/Tsquid",
    "manifest": "../../client/src/routes/__generated__/routes.manifest.json",
    "language": "csharp",
    "namespace": "Hsimah.Tsquid"
  }
}
```

No Composer or PHP is needed: `eleph install` fetches the binary pinned as `name@version`. It writes `TsquidManifest.cs`, whose
`TsquidManifestFactory.Create()` returns the `RouteManifest` that `app.UseElephentityTsquid(...)` takes. `eleph generate --check` detects
route/query drift exactly as it does for PHP. The validation (version 1, field kinds, path parameters, query bindings) is shared; only the
output differs.

## Serve

In the existing HTML GET handler, after establishing the viewer:

```php
$initial = new \Eleph\Tsquid\InitialData(
    \Eleph\Tsquid\Manifest::fromFile(__DIR__ . '/generated/tsquid/tsquid-manifest.php'),
    static fn ($text, $variables, $operation, $id) =>
        $application->executeGraphQL($text, $variables, $operation),
);
$response = $initial->render($_SERVER['REQUEST_URI'], $htmlShell);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
```

The execution callback is application-owned: use the same viewer, authorization,
GraphQL validation limits and response format as the normal endpoint. The fourth
argument is the persisted ID if your transport needs it. Return `data`, `errors`
and `extensions` unchanged; return `null` or throw on transport failure. Set
transport/database timeouts in the executor; this synchronous adapter cannot
interrupt a slow callback. `examples/serve.php` shows a webonyx executor.

The runtime matches all routes, including `ssr: false`, and inlines only the winning
route's query. Invalid URLs, tied routes, client-only routes and execution/encoding
failures preserve the shell. GraphQL errors are valid responses and are preserved.
It inserts the JSON script before the shell's first script or closing head, escapes
`<`, and returns `Cache-Control: private, no-store` whenever data is inlined. Apply
those headers before emitting HTML; the runtime does not modify global HTTP state.
An existing quoted `id="tsquid-data"` is left alone to prevent duplicate payloads.

## Verify

```sh
cargo fmt --check
cargo clippy --all-targets -- -D warnings
cargo test --locked
php tests/runtime.php
php tests/integration.php /path/to/elephentity-dev /tmp/tsquid-payload.json   # needs eleph on PATH (install.sh) or ELEPH
node tests/client.mjs /path/to/tsquid/packages/routes/dist/initialData.js /tmp/tsquid-payload.json
```

The integration test needs the sibling Elephentity CLI/codegen and GraphQL packages'
installed Composer dependencies and a built SQLite generator. It compiles real YAML through the existing
describe/generate protocol, loads signed PHP, executes the exact starter query for
two viewers, and checks drift and atomic failure. The client check consumes that
payload with tsquid's actual runtime. Runtime vectors are copied unchanged from
tsquid main commit `df1f461` (PR #8); refresh them when the upstream contract changes.

This package doesn't add HTTP routing or session management. Hook it into the handler
that already serves your app shell. Only URL-driven route queries are described by
the manifest; other queries continue to fetch on the client.
