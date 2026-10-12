//! Patterns: reusable fragments of an entity spec, expanded, merged and configured.

mod config;
mod merger;
mod resolver;

pub use config::{resolve_config, resolve_values};
pub use merger::merge_sections;
pub use resolver::expand_patterns;
