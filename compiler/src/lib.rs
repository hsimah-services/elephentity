//! The Elephentity spec compiler.
//!
//! The PHP compiler in `packages/schema` is the migration oracle: this crate is measured
//! against the snapshots `tools/compare-compiler` takes from it.

pub mod error;
pub mod integration;
pub mod model;
pub mod pattern;
pub mod resolve;
pub mod schema;
pub mod spec;

pub use error::SpecError;
pub use resolve::{resolve, Options, Resolved};
