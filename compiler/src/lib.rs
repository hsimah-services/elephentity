//! The Elephentity spec compiler.
//!
//! The PHP compiler in `packages/schema` is the migration oracle: this crate is measured
//! against the snapshots `tools/compare-compiler` takes from it.

pub mod cli;
pub mod compile;
pub mod error;
pub mod fmt;
pub mod integration;
pub mod ir;
pub mod model;
pub mod pattern;
pub mod schema;
pub mod semantic;
pub mod spec;
pub mod storage;

pub use compile::{compile, resolve, Options, Resolved};
pub use error::SpecError;
pub use ir::Schema;
