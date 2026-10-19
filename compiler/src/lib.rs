//! The Elephentity spec compiler.
//!
//! This crate was ported from the PHP compiler that used to live in `packages/schema` and
//! `packages/cli`. That compiler is retired; what it produced over every fixture is frozen
//! under `tests/oracle`, and this crate is still measured against it.

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
