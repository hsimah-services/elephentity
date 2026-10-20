# Elephentity.Tsquid

Server-side initial data for a [tsquid](https://github.com/hsimah-services/tsquid) app on ASP.NET Core. On a browser navigation it matches
the URL to a route, runs that route's GraphQL query as the requesting viewer, and inlines the response as
`<script type="application/json" id="tsquid-data">` before the first script, so the route renders without waiting for its first request.
It is best effort: whatever goes wrong, the plain shell is served and the client fetches.

```csharp
services.AddElephentityGraphQL(GraphQLManifestFactory.Create());          // the schema and the executor the query runs through

var manifest = ManifestJson.Parse(File.ReadAllText("routes.manifest.json"));   // or the generated C# manifest
var shell = File.ReadAllText("wwwroot/index.html");

app.UseElephentityTsquid(manifest, _ => ValueTask.FromResult<string?>(shell));
app.MapElephentityGraphQL("/graphql");
```

- Only `GET` requests that accept `text/html` are answered, and never under `/graphql` or `/assets/` (pass `skippedPaths` if your GraphQL
  route or assets live elsewhere). Everything else goes to the next middleware.
- The URL is matched as the client wrote it: the **raw** request target, still percent-encoded, because the matching rules read it so.
- When data is inlined the response is `Cache-Control: private, no-store`. With nothing to inline (no match, `ssr: false`, no query, a query
  that failed) the shell is served unchanged.
- The query runs through `ElephentityGraphQLExecutor` with the request's own services, so the viewer is the one whose page this is.
  A query that takes longer than `timeout` (one second unless you pass another) is abandoned and the plain shell is served, as the SKILL
  asks: a page that would otherwise fetch on load is better without the data than late. A query that fails or is abandoned is logged
  (warning, with the route and operation); one that gives no response is logged at debug.
- Every less-than sign in the payload is written `<` and U+2028/U+2029 are escaped, so a response cannot close the script element.

## Where the rules come from

The matching, the variable rules and the payload are the language-neutral
[`tsquid-server-integration` SKILL](https://github.com/hsimah-services/tsquid/tree/main/skills/tsquid-server-integration), and
`tests/Elephentity.Tsquid.Tests/Fixtures/vectors.json` is its conformance file (every case runs). The package ports
`elephentity-codegen-tsquid`'s `Manifest`, `InitialData` and `HtmlResponse`. No place was found where the PHP and the SKILL disagree.

`tests/Elephentity.Tsquid.Tests/client.mjs` feeds the payload the middleware produces to the real tsquid client, in the `tsquid-client` CI
job, because the dotnet container has no node. To run it by hand: build `@tsquid/routes` in a tsquid checkout, set `TSQUID_PAYLOAD_OUT` to
a file, run `./tools/dotnet dotnet test tests/Elephentity.Tsquid.Tests`, then `node client.mjs <tsquid>/packages/routes/dist/initialData.js <file>`.

## Running the tests

`./tools/dotnet dotnet test tests/Elephentity.Tsquid.Tests`. (`dotnet test --filter` over the whole solution runs zero tests in the other
projects, which Microsoft.Testing.Platform reports as exit code 8.)
