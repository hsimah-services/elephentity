//! The compiled schema and its wire encoding.
//!
//! The wire bytes are the contract with every builder, and they must match what the PHP
//! compiler's `IrCodec` produces to the byte. Key order is the constructor parameter order
//! of the PHP value objects, which is the field order of the structs in `model.rs`; the
//! rest (number formatting, escaping, which empty collections are `[]` and which `{}`) is
//! in `json.rs`.

mod json;

use indexmap::IndexMap;
use serde::{Serialize, Serializer};
use serde_json::{json, Map, Value};

use crate::model::{
    EdgeDefinition, EntityDefinition, FieldDefinition, PolicyDefinition, ProjectDefinition,
    TypeDefinition,
};

/// The wire format's version, carried in the request envelope.
///
/// The Rust counterpart of `IrCodec::VERSION`, and from step 3 the one that owns it: bump it
/// whenever a builder that understood the old shape would misread the new one. The
/// orchestrator and every builder declare their own copy and refuse a mismatch.
pub const IR_VERSION: &str = "1.2";

/// The compiled world: every entity with its patterns resolved, and every declared type.
#[derive(Debug, Serialize)]
pub struct Schema {
    pub project: ProjectDefinition,
    pub entities: IndexMap<String, EntityDefinition>,
    pub types: IndexMap<String, TypeDefinition>,
    /// Only patterns that opted into generating an interface and are used by at least one
    /// entity.
    pub patterns: IndexMap<String, PatternDeclaration>,
}

/// A pattern that opted into generating a shared interface and mutator trait for its own
/// fields and edges.
#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PatternDeclaration {
    pub name: String,
    pub fields: IndexMap<String, FieldDefinition>,
    pub edges: IndexMap<String, EdgeDefinition>,
    pub read_policies: IndexMap<String, PolicyDefinition>,
    pub write_policies: IndexMap<String, PolicyDefinition>,
}

impl Schema {
    /// What `eleph generate` sends to `eleph-codegen`, byte for byte.
    pub fn request(&self) -> String {
        let request = json!({
            "elephentity": 1,
            "irVersion": IR_VERSION,
            "schema": serde_json::to_value(self).expect("the IR serializes"),
            "files": Value::Object(Map::new()),
        });

        json::encode(&request)
    }
}

/// Arbitrary data the core never interprets (a default, a config value), shaped the way PHP
/// encodes it: a PHP array has no empty map, so an empty map is `[]`.
pub fn serialize_plain<S: Serializer>(value: &Value, serializer: S) -> Result<S::Ok, S::Error> {
    plain(value).serialize(serializer)
}

/// A map of settings the core never interprets. The map itself is always an object, and so
/// are its values when they are settings, but the data inside them is plain.
pub fn serialize_settings<S: Serializer>(
    settings: &Map<String, Value>,
    serializer: S,
) -> Result<S::Ok, S::Error> {
    plain_entries(settings).serialize(serializer)
}

/// A map of integration name to that integration's settings: objects all the way down, even
/// for an integration declared with none.
pub fn serialize_settings_by_name<S: Serializer>(
    integrations: &IndexMap<String, Map<String, Value>>,
    serializer: S,
) -> Result<S::Ok, S::Error> {
    let by_name: Map<String, Value> = integrations
        .iter()
        .map(|(name, settings)| (name.clone(), Value::Object(plain_entries(settings))))
        .collect();

    by_name.serialize(serializer)
}

fn plain_entries(settings: &Map<String, Value>) -> Map<String, Value> {
    settings
        .iter()
        .map(|(key, value)| (key.clone(), plain(value)))
        .collect()
}

fn plain(value: &Value) -> Value {
    match value {
        Value::Object(entries) if entries.is_empty() => Value::Array(Vec::new()),
        Value::Object(entries) => Value::Object(plain_entries(entries)),
        Value::Array(items) => Value::Array(items.iter().map(plain).collect()),
        other => other.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn plain_data_has_no_empty_map() {
        assert_eq!(
            plain(&json!({"a": {}, "b": [{}], "c": {"d": 1}})),
            json!({"a": [], "b": [[]], "c": {"d": 1}})
        );
    }
}
