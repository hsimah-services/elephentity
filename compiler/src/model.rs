//! What a spec says, once it has been read: fields, edges, queries and the rest.
//!
//! These are the IR: they serialize to the shape `IrCodec` gives the PHP value objects, field
//! for field and in the same order, because the wire bytes are compared. Enum values are
//! validated by the JSON Schema before anything is read, so parsing one that does not exist
//! is an internal error.

use indexmap::IndexMap;
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};

use crate::ir::{serialize_plain, serialize_settings, serialize_settings_by_name};

/// Parse a schema-validated enum value.
pub fn parse_enum<T: DeserializeOwned>(raw: &str) -> T {
    serde_json::from_value(Value::String(raw.to_string())).unwrap_or_else(|_| {
        panic!("internal error: \"{raw}\" passed the JSON Schema but is not a known value")
    })
}

macro_rules! string_enum {
    ($name:ident { $($variant:ident),+ $(,)? }) => {
        #[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
        #[serde(rename_all = "camelCase")]
        pub enum $name { $($variant),+ }
    };
}

string_enum!(Cardinality { One, Many });
string_enum!(Managed { Created, Modified });
string_enum!(OnDelete {
    Restrict,
    Cascade,
    Nullify
});
string_enum!(Primitive {
    String,
    Text,
    Int,
    Float,
    Bool,
    Datetime,
    Id,
    Enum,
    Json
});
string_enum!(SideEffectEvent {
    Create,
    Update,
    Delete
});
string_enum!(SideEffectPhase {
    PreCommit,
    PostCommit
});
string_enum!(TerminalRule { Allow, Deny });
string_enum!(ConfigType {
    String,
    Int,
    Bool,
    Enum,
    List
});

impl Primitive {
    fn parse(raw: &str) -> Option<Self> {
        serde_json::from_value(Value::String(raw.to_string())).ok()
    }

    pub fn name(self) -> &'static str {
        match self {
            Primitive::String => "string",
            Primitive::Text => "text",
            Primitive::Int => "int",
            Primitive::Float => "float",
            Primitive::Bool => "bool",
            Primitive::Datetime => "datetime",
            Primitive::Id => "id",
            Primitive::Enum => "enum",
            Primitive::Json => "json",
        }
    }

    /// An enum type's backing: a declared enum must be a string or an int.
    pub fn is_enum_backing(self) -> bool {
        matches!(self, Primitive::String | Primitive::Int)
    }
}

/// A field or argument's declared type: either a primitive or a declared value type.
#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TypeReference {
    pub primitive: Option<Primitive>,
    pub declared_type: Option<String>,
}

impl TypeReference {
    /// A primitive if the spec string names one, a declared type otherwise. Whether that
    /// declared type exists is a semantic check.
    pub fn parse(raw: &str) -> Self {
        match Primitive::parse(raw) {
            Some(primitive) => Self {
                primitive: Some(primitive),
                declared_type: None,
            },
            None => Self {
                primitive: None,
                declared_type: Some(raw.to_string()),
            },
        }
    }

    pub fn is_primitive(&self) -> bool {
        self.primitive.is_some()
    }

    pub fn name(&self) -> String {
        self.primitive.map_or_else(
            || self.declared_type.clone().unwrap_or_default(),
            |primitive| primitive.name().to_string(),
        )
    }
}

/// Where a member came from: the entity's own spec, or a pattern it uses.
///
/// Tracked so that a sealed-collision error can name both contributors.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Origin {
    pub pattern: Option<String>,
    pub file: String,
}

impl Origin {
    pub fn entity(file: &str) -> Self {
        Self {
            pattern: None,
            file: file.to_string(),
        }
    }

    pub fn pattern(name: &str, file: &str) -> Self {
        Self {
            pattern: Some(name.to_string()),
            file: file.to_string(),
        }
    }

    pub fn describe(&self) -> String {
        match &self.pattern {
            None => "the entity spec".to_string(),
            Some(pattern) => format!("pattern {pattern}"),
        }
    }
}

/// A member that knows where it came from.
pub trait Contributed {
    fn origin(&self) -> &Origin;
}

/// Where an enum field's members come from: listed inline, or a declared type.
#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EnumSource {
    pub inline_values: Option<Vec<String>>,
    pub declared_type: Option<String>,
}

impl EnumSource {
    pub fn inline(values: Vec<String>) -> Self {
        Self {
            inline_values: Some(values),
            declared_type: None,
        }
    }

    pub fn declared(name: String) -> Self {
        Self {
            inline_values: None,
            declared_type: Some(name),
        }
    }

    pub fn is_inline(&self) -> bool {
        self.inline_values.is_some()
    }
}

#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FieldDefinition {
    pub name: String,
    #[serde(rename = "type")]
    pub type_ref: TypeReference,
    pub origin: Origin,
    pub description: Option<String>,
    pub required: bool,
    pub nullable: bool,
    #[serde(serialize_with = "serialize_plain")]
    pub default: Value,
    pub has_default: bool,
    pub unique: bool,
    pub indexed: bool,
    pub immutable: bool,
    pub managed: Option<Managed>,
    pub max_length: Option<i64>,
    #[serde(rename = "enum")]
    pub enum_source: Option<EnumSource>,
    pub verify: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct EdgeInverse {
    pub derived: bool,
    pub name: Option<String>,
    pub unique: bool,
}

impl EdgeInverse {
    /// The accessor's name on the target: the declared one, or the declaring entity's name
    /// with its first letter lowered (`Inventory` gives `inventory`).
    pub fn name_for(&self, declaring_entity: &str) -> String {
        if !self.derived {
            return self.name.clone().unwrap_or_default();
        }

        let mut characters = declaring_entity.chars();

        match characters.next() {
            Some(first) => first.to_ascii_lowercase().to_string() + characters.as_str(),
            None => String::new(),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EdgeDefinition {
    pub name: String,
    pub to: String,
    pub cardinality: Cardinality,
    pub origin: Origin,
    pub description: Option<String>,
    pub inverse: Option<EdgeInverse>,
    pub on_delete: OnDelete,
    pub required: bool,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ArgumentDefinition {
    pub name: String,
    #[serde(rename = "type")]
    pub type_ref: TypeReference,
    pub nullable: bool,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ReturnDefinition {
    #[serde(rename = "type")]
    pub type_name: String,
    pub cardinality: Cardinality,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct QueryDefinition {
    pub name: String,
    pub returns: ReturnDefinition,
    pub origin: Origin,
    pub arguments: IndexMap<String, ArgumentDefinition>,
    pub description: Option<String>,
    /// Raw until the project's enabled integrations are known, then resolved.
    #[serde(serialize_with = "serialize_settings_by_name")]
    pub integrations: IndexMap<String, Map<String, Value>>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ActionWrites {
    pub fields: Vec<String>,
    pub edges: Vec<String>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ActionDefinition {
    pub name: String,
    pub writes: ActionWrites,
    pub origin: Origin,
    pub arguments: IndexMap<String, ArgumentDefinition>,
    pub description: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct SideEffectDefinition {
    pub name: String,
    pub events: Vec<SideEffectEvent>,
    pub origin: Origin,
    pub phase: SideEffectPhase,
    pub description: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct PolicyDefinition {
    pub name: String,
    pub origin: Origin,
    pub description: Option<String>,
}

macro_rules! contributed {
    ($($type:ty),+) => {
        $(impl Contributed for $type {
            fn origin(&self) -> &Origin {
                &self.origin
            }
        })+
    };
}

contributed!(
    FieldDefinition,
    EdgeDefinition,
    QueryDefinition,
    ActionDefinition,
    SideEffectDefinition,
    PolicyDefinition
);

/// The declarable sections of a spec, parsed but not yet merged.
///
/// Entities and patterns produce the same shape, because a pattern is a fragment of an
/// entity spec. Members keep declaration order, which is semantic for side effects.
#[derive(Debug, Clone, Default)]
pub struct ParsedSections {
    pub fields: IndexMap<String, FieldDefinition>,
    pub edges: IndexMap<String, EdgeDefinition>,
    pub queries: IndexMap<String, QueryDefinition>,
    pub actions: IndexMap<String, ActionDefinition>,
    pub side_effects: IndexMap<String, SideEffectDefinition>,
    pub read_policies: IndexMap<String, PolicyDefinition>,
    pub write_policies: IndexMap<String, PolicyDefinition>,
}

/// One parameter a pattern or integration accepts.
///
/// The declarer states the shape; the compiler checks supplied values against it.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ConfigParameter {
    pub name: String,
    #[serde(rename = "type")]
    pub type_: ConfigType,
    pub description: Option<String>,
    pub of: Option<ConfigType>,
    pub values: Option<Vec<String>>,
    pub nullable: bool,
    pub default: Value,
    pub has_default: bool,
}

impl ConfigParameter {
    /// A parameter with neither a default nor permission to be null must be supplied.
    ///
    /// Falling back to null would let a missing value reach the generator as an empty
    /// string, and fail somewhere far from the spec that omitted it.
    pub fn is_required(&self) -> bool {
        !self.has_default && !self.nullable
    }

    pub fn accepts(&self, value: &Value) -> bool {
        if value.is_null() {
            return self.nullable;
        }

        match self.type_ {
            ConfigType::String => value.is_string(),
            ConfigType::Int => value.is_i64() || value.is_u64(),
            ConfigType::Bool => value.is_boolean(),
            ConfigType::Enum => value.as_str().is_some_and(|text| {
                self.values
                    .as_deref()
                    .unwrap_or_default()
                    .iter()
                    .any(|allowed| allowed == text)
            }),
            // A YAML map is a PHP array, so the PHP compiler accepts one here and checks
            // its values; this does the same.
            ConfigType::List => match value {
                Value::Array(items) => self.accepts_every(items.iter()),
                Value::Object(entries) => self.accepts_every(entries.values()),
                _ => false,
            },
        }
    }

    pub fn describe_expectation(&self) -> String {
        match self.type_ {
            ConfigType::Enum => format!(
                "one of {}",
                self.values.as_deref().unwrap_or_default().join(", ")
            ),
            ConfigType::List => format!(
                "a list of {}",
                self.of.map_or("string", |of| config_type_name(of))
            ),
            other => format!("a {}", config_type_name(other)),
        }
    }

    fn accepts_every<'a>(&self, mut values: impl Iterator<Item = &'a Value>) -> bool {
        let element = self.of.unwrap_or(ConfigType::String);

        values.all(|value| match element {
            ConfigType::Int => value.is_i64() || value.is_u64(),
            _ => value.is_string(),
        })
    }
}

fn config_type_name(type_: ConfigType) -> &'static str {
    match type_ {
        ConfigType::String => "string",
        ConfigType::Int => "int",
        ConfigType::Bool => "bool",
        ConfigType::Enum => "enum",
        ConfigType::List => "list",
    }
}

/// A reusable fragment of an entity spec.
#[derive(Debug, Clone)]
pub struct PatternDefinition {
    pub name: String,
    pub source_file: String,
    pub sections: ParsedSections,
    /// Patterns this pattern itself pulls in.
    pub uses: Vec<String>,
    pub requires_driver: Option<String>,
    /// Partial storage keys this pattern contributes.
    pub storage: IndexMap<String, String>,
    pub config: IndexMap<String, ConfigParameter>,
    pub description: Option<String>,
    pub generates_interface: bool,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ProjectDefinition {
    pub name: String,
    pub driver: String,
    pub source_file: String,
    #[serde(serialize_with = "serialize_settings_by_name")]
    pub integrations: IndexMap<String, Map<String, Value>>,
    pub table_prefix: String,
    pub description: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TypeDefinition {
    pub name: String,
    pub primitive: Primitive,
    pub source_file: String,
    pub description: Option<String>,
    pub has_processors: bool,
    pub values: Option<Vec<String>>,
}

#[derive(Debug, Clone, Serialize)]
pub struct StorageDefinition {
    pub driver: String,
    pub table: String,
    pub handle: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EntityDefinition {
    pub name: String,
    pub storage: StorageDefinition,
    pub source_file: String,
    pub description: Option<String>,
    pub uses: Vec<String>,
    pub fields: IndexMap<String, FieldDefinition>,
    pub edges: IndexMap<String, EdgeDefinition>,
    pub queries: IndexMap<String, QueryDefinition>,
    pub actions: IndexMap<String, ActionDefinition>,
    pub side_effects: IndexMap<String, SideEffectDefinition>,
    #[serde(serialize_with = "serialize_settings")]
    pub config: Map<String, Value>,
    #[serde(serialize_with = "serialize_settings_by_name")]
    pub integrations: IndexMap<String, Map<String, Value>>,
    pub applied_patterns: Vec<String>,
    pub read_policies: IndexMap<String, PolicyDefinition>,
    pub write_policies: IndexMap<String, PolicyDefinition>,
    pub terminal_read: TerminalRule,
    pub terminal_write: TerminalRule,
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn parameter(type_: ConfigType) -> ConfigParameter {
        ConfigParameter {
            name: "p".to_string(),
            type_,
            description: None,
            of: None,
            values: Some(vec!["a".to_string(), "b".to_string()]),
            nullable: false,
            default: Value::Null,
            has_default: false,
        }
    }

    #[test]
    fn accepts_follows_the_declared_type() {
        assert!(parameter(ConfigType::String).accepts(&json!("x")));
        assert!(!parameter(ConfigType::String).accepts(&json!(1)));
        assert!(parameter(ConfigType::Int).accepts(&json!(1)));
        assert!(!parameter(ConfigType::Int).accepts(&json!(1.5)));
        assert!(parameter(ConfigType::Enum).accepts(&json!("a")));
        assert!(!parameter(ConfigType::Enum).accepts(&json!("c")));
        assert!(!parameter(ConfigType::Bool).accepts(&Value::Null));
    }

    #[test]
    fn a_map_counts_as_a_list_because_it_does_in_php() {
        let list = parameter(ConfigType::List);

        assert!(list.accepts(&json!(["a", "b"])));
        assert!(list.accepts(&json!({"x": "a"})));
        assert!(!list.accepts(&json!([1])));
        assert!(!list.accepts(&json!("a")));
    }

    #[test]
    fn a_parameter_with_no_default_and_no_null_is_required() {
        assert!(parameter(ConfigType::String).is_required());

        let mut optional = parameter(ConfigType::String);
        optional.nullable = true;
        assert!(!optional.is_required());
    }
}
