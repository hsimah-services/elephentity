# Source provenance

The protocol entrypoint, typed IR decoder, checkout launcher and initial request
fixtures were adapted from `hsimah-services/elephentity-codegen-wordpress` at
`cfb27ea5d9723895567c517e0d2c540ae6ece71d`; that repository is relicensed to MIT in the same step and the code is owner-authored.

SQLite storage rendering and the initial installer are implemented here. The
WordPress generator supplied reference naming and relationship placement conventions;
WordPress registration, admin pages, accounts and taxonomies are not included.

Fixture requests retain example descriptions while using the standalone driver and
integration declarations. Golden responses are produced by this builder and are
also exercised against the tagged runtime packages through `tests/runtime.php`.
