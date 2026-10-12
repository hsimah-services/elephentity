//! Reading spec documents: where they live, how they are loaded, and typed access to them.

mod loader;
mod reader;
mod scalar;
pub mod sections;
pub mod yaml;

use std::collections::BTreeMap;

use serde_json::{Map, Value};

pub use loader::{load, Loaded};
pub use reader::SpecReader;

/// The kinds of spec document, discriminated by their required root key.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SpecKind {
    Project,
    Entity,
    Pattern,
    Type,
}

impl SpecKind {
    /// The order documents are loaded in.
    pub const ALL: [SpecKind; 4] = [
        SpecKind::Project,
        SpecKind::Entity,
        SpecKind::Pattern,
        SpecKind::Type,
    ];

    pub fn name(self) -> &'static str {
        match self {
            SpecKind::Project => "project",
            SpecKind::Entity => "entity",
            SpecKind::Pattern => "pattern",
            SpecKind::Type => "type",
        }
    }

    pub fn schema_uri(self) -> String {
        format!(
            "https://dev.hbla.ke/elephentity/v0/{}.schema.json",
            self.name()
        )
    }

    /// The directory this kind is found in, or `None` for the one that is a single file.
    pub fn directory(self) -> Option<&'static str> {
        match self {
            SpecKind::Project => None,
            SpecKind::Entity => Some("entities"),
            SpecKind::Pattern => Some("patterns"),
            SpecKind::Type => Some("types"),
        }
    }
}

/// Where the specs live: one root holding `entities/`, `patterns/` and `types/`.
#[derive(Debug, Clone)]
pub struct SpecSource {
    pub root: String,
}

impl SpecSource {
    pub fn new(root: impl Into<String>) -> Self {
        Self { root: root.into() }
    }

    /// The root without a trailing slash, the way every path below it is built.
    pub fn trimmed_root(&self) -> &str {
        self.root.trim_end_matches('/')
    }
}

/// A parsed but not yet validated spec document, with the path it came from.
#[derive(Debug, Clone)]
pub struct RawSpec {
    pub kind: SpecKind,
    pub file: String,
    pub data: Map<String, Value>,
    /// Where each node starts. Empty for documents that did not come from a file.
    pub positions: BTreeMap<String, yaml::Position>,
}

impl RawSpec {
    /// A document that did not come from a file, e.g. a pattern a builder ships.
    pub fn pooled(kind: SpecKind, file: &str, data: Map<String, Value>) -> Self {
        Self {
            kind,
            file: file.to_string(),
            data,
            positions: BTreeMap::new(),
        }
    }

    pub fn name(&self) -> String {
        self.data
            .get(self.kind.name())
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_string()
    }

    pub fn reader(&self) -> SpecReader<'_> {
        SpecReader::new(&self.data)
    }

    /// The document as the JSON value the schema validates.
    pub fn json(&self) -> Value {
        Value::Object(self.data.clone())
    }
}
