//! Where the Rust compiler deliberately differs from the PHP one. Each test here is a
//! decision recorded in `docs/divergence.md`; the oracle tests cover everything else.

use eleph_compiler::spec::SpecSource;
use eleph_compiler::{resolve, Options};

/// PHP cannot tell an empty map from an empty list, so it rejects an explicit `fields: {}`
/// as "must be an object". An empty map is a map.
#[test]
fn an_explicit_empty_map_is_accepted() {
    let root = concat!(env!("CARGO_MANIFEST_DIR"), "/tests/fixtures/empty-maps");

    let resolved = resolve(&SpecSource::new(root), &Options::default()).unwrap_or_else(|errors| {
        panic!(
            "{:#?}",
            errors.iter().map(|e| e.describe()).collect::<Vec<_>>()
        )
    });

    let entity = &resolved.entities["Empty"];

    assert!(entity.fields.is_empty() && entity.edges.is_empty() && entity.queries.is_empty());
    assert!(resolved.project.integrations.is_empty());
}
