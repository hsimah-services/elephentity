# Source provenance

The protocol entrypoint, typed IR decoder, checkout launcher and initial request
fixtures were adapted from `hsimah-services/elephentity-codegen-wpgraphql` at
`a71640e973e51026cb608b753524fb32094a6fe1`; that repository is relicensed to MIT in the same step and the code is owner-authored.

GraphQL manifest generation is adapted to the `graphql` integration and the
`Eleph\GraphQL` runtime. Naming validation and a self-contained conformance verifier
are added here. No WPGraphQL runtime class or registration hook is required.

Fixture requests retain example descriptions while using the standalone driver and
integration declarations. Golden responses are produced by this builder and are
also exercised against the tagged runtime packages through `tests/runtime.php`.
