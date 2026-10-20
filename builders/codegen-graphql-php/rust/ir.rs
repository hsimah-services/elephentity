//! Wire IR 1.2. Types are owned by this builder, independently of the compiler.
use serde::{Deserialize, Serialize};
use serde_json::Value;
#[derive(Debug, Clone, Serialize)]
#[serde(transparent)]
pub struct Map<T>(indexmap::IndexMap<String, T>);
impl<T> Default for Map<T> {
    fn default() -> Self {
        Self(indexmap::IndexMap::new())
    }
}
impl<'de, T: Deserialize<'de>> Deserialize<'de> for Map<T> {
    fn deserialize<D: serde::Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        #[derive(Deserialize)]
        #[serde(untagged)]
        enum Input<T> {
            Object(indexmap::IndexMap<String, T>),
            Array(Vec<Value>),
        }
        match Input::<T>::deserialize(deserializer)? {
            Input::Object(map) => Ok(Self(map)),
            Input::Array(values) if values.is_empty() => Ok(Self::default()),
            Input::Array(_) => Err(serde::de::Error::custom(
                "expected an object or an empty array",
            )),
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Primitive {
    #[serde(rename = "string")]
    String,
    #[serde(rename = "text")]
    Text,
    #[serde(rename = "int")]
    Int,
    #[serde(rename = "float")]
    Float,
    #[serde(rename = "bool")]
    Bool,
    #[serde(rename = "datetime")]
    Datetime,
    #[serde(rename = "id")]
    Id,
    #[serde(rename = "enum")]
    Enum,
    #[serde(rename = "json")]
    Json,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub enum Cardinality {
    #[serde(rename = "one")]
    One,
    #[default]
    #[serde(rename = "many")]
    Many,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub enum OnDelete {
    #[default]
    #[serde(rename = "restrict")]
    Restrict,
    #[serde(rename = "cascade")]
    Cascade,
    #[serde(rename = "nullify")]
    Nullify,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Managed {
    #[serde(rename = "created")]
    Created,
    #[serde(rename = "modified")]
    Modified,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum SideEffectEvent {
    #[serde(rename = "create")]
    Create,
    #[serde(rename = "update")]
    Update,
    #[serde(rename = "delete")]
    Delete,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub enum SideEffectPhase {
    #[default]
    #[serde(rename = "preCommit")]
    PreCommit,
    #[serde(rename = "postCommit")]
    PostCommit,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub enum TerminalRule {
    #[serde(rename = "allow")]
    Allow,
    #[default]
    #[serde(rename = "deny")]
    Deny,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Schema {
    #[serde(rename = "project")]
    pub project: ProjectDefinition,
    #[serde(default)]
    #[serde(rename = "entities")]
    pub entities: Map<EntityDefinition>,
    #[serde(default)]
    #[serde(rename = "types")]
    pub types: Map<TypeDefinition>,
    #[serde(default)]
    #[serde(rename = "patterns")]
    pub patterns: Map<PatternDeclaration>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ProjectDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "driver")]
    pub driver: String,
    #[serde(rename = "sourceFile")]
    pub source_file: String,
    #[serde(default)]
    #[serde(rename = "integrations")]
    pub integrations: Map<Map<Value>>,
    #[serde(default)]
    #[serde(rename = "tablePrefix")]
    pub table_prefix: String,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EntityDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "storage")]
    pub storage: StorageDefinition,
    #[serde(rename = "sourceFile")]
    pub source_file: String,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
    #[serde(default)]
    #[serde(rename = "uses")]
    pub uses: Vec<String>,
    #[serde(default)]
    #[serde(rename = "fields")]
    pub fields: Map<FieldDefinition>,
    #[serde(default)]
    #[serde(rename = "edges")]
    pub edges: Map<EdgeDefinition>,
    #[serde(default)]
    #[serde(rename = "queries")]
    pub queries: Map<QueryDefinition>,
    #[serde(default)]
    #[serde(rename = "actions")]
    pub actions: Map<ActionDefinition>,
    #[serde(default)]
    #[serde(rename = "sideEffects")]
    pub side_effects: Map<SideEffectDefinition>,
    #[serde(default)]
    #[serde(rename = "config")]
    pub config: Map<Value>,
    #[serde(default)]
    #[serde(rename = "integrations")]
    pub integrations: Map<Map<Value>>,
    #[serde(default)]
    #[serde(rename = "appliedPatterns")]
    pub applied_patterns: Vec<String>,
    #[serde(default)]
    #[serde(rename = "readPolicies")]
    pub read_policies: Map<PolicyDefinition>,
    #[serde(default)]
    #[serde(rename = "writePolicies")]
    pub write_policies: Map<PolicyDefinition>,
    #[serde(default)]
    #[serde(rename = "terminalRead")]
    pub terminal_read: TerminalRule,
    #[serde(default)]
    #[serde(rename = "terminalWrite")]
    pub terminal_write: TerminalRule,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct StorageDefinition {
    #[serde(rename = "driver")]
    pub driver: String,
    #[serde(rename = "table")]
    pub table: String,
    #[serde(default)]
    #[serde(rename = "handle")]
    pub handle: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TypeDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "primitive")]
    pub primitive: Primitive,
    #[serde(rename = "sourceFile")]
    pub source_file: String,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
    #[serde(default)]
    #[serde(rename = "hasProcessors")]
    pub has_processors: bool,
    #[serde(default)]
    #[serde(rename = "values")]
    pub values: Option<Vec<String>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PatternDeclaration {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(default)]
    #[serde(rename = "fields")]
    pub fields: Map<FieldDefinition>,
    #[serde(default)]
    #[serde(rename = "edges")]
    pub edges: Map<EdgeDefinition>,
    #[serde(default)]
    #[serde(rename = "readPolicies")]
    pub read_policies: Map<PolicyDefinition>,
    #[serde(default)]
    #[serde(rename = "writePolicies")]
    pub write_policies: Map<PolicyDefinition>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PolicyDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FieldDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "type")]
    pub r#type: TypeReference,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
    #[serde(default)]
    #[serde(rename = "required")]
    pub required: bool,
    #[serde(default)]
    #[serde(rename = "nullable")]
    pub nullable: bool,
    #[serde(default)]
    #[serde(rename = "default")]
    pub r#default: Value,
    #[serde(default)]
    #[serde(rename = "hasDefault")]
    pub has_default: bool,
    #[serde(default)]
    #[serde(rename = "unique")]
    pub unique: bool,
    #[serde(default)]
    #[serde(rename = "indexed")]
    pub indexed: bool,
    #[serde(default)]
    #[serde(rename = "immutable")]
    pub immutable: bool,
    #[serde(default)]
    #[serde(rename = "managed")]
    pub managed: Option<Managed>,
    #[serde(default)]
    #[serde(rename = "maxLength")]
    pub max_length: Option<i64>,
    #[serde(default)]
    #[serde(rename = "enum")]
    pub r#enum: Option<EnumSource>,
    #[serde(default)]
    #[serde(rename = "verify")]
    pub verify: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EdgeDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "to")]
    pub to: String,
    #[serde(rename = "cardinality")]
    pub cardinality: Cardinality,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
    #[serde(default)]
    #[serde(rename = "inverse")]
    pub inverse: Option<EdgeInverse>,
    #[serde(default)]
    #[serde(rename = "onDelete")]
    pub on_delete: OnDelete,
    #[serde(default)]
    #[serde(rename = "required")]
    pub required: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EdgeInverse {
    #[serde(rename = "derived")]
    pub derived: bool,
    #[serde(rename = "name", deserialize_with = "required_nullable")]
    pub name: Option<String>,
    #[serde(rename = "unique")]
    pub unique: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ActionDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "writes")]
    pub writes: ActionWrites,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "arguments")]
    pub arguments: Map<ArgumentDefinition>,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ActionWrites {
    #[serde(default)]
    #[serde(rename = "fields")]
    pub fields: Vec<String>,
    #[serde(default)]
    #[serde(rename = "edges")]
    pub edges: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QueryDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "returns")]
    pub returns: ReturnDefinition,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "arguments")]
    pub arguments: Map<ArgumentDefinition>,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
    #[serde(default)]
    #[serde(rename = "integrations")]
    pub integrations: Map<Map<Value>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReturnDefinition {
    #[serde(rename = "type")]
    pub r#type: String,
    #[serde(default)]
    #[serde(rename = "cardinality")]
    pub cardinality: Cardinality,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SideEffectDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "events")]
    pub events: Vec<SideEffectEvent>,
    #[serde(rename = "origin")]
    pub origin: Origin,
    #[serde(default)]
    #[serde(rename = "phase")]
    pub phase: SideEffectPhase,
    #[serde(default)]
    #[serde(rename = "description")]
    pub description: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ArgumentDefinition {
    #[serde(rename = "name")]
    pub name: String,
    #[serde(rename = "type")]
    pub r#type: TypeReference,
    #[serde(default)]
    #[serde(rename = "nullable")]
    pub nullable: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TypeReference {
    #[serde(rename = "primitive", deserialize_with = "required_nullable")]
    pub primitive: Option<Primitive>,
    #[serde(rename = "declaredType", deserialize_with = "required_nullable")]
    pub declared_type: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Origin {
    #[serde(rename = "pattern", deserialize_with = "required_nullable")]
    pub pattern: Option<String>,
    #[serde(rename = "file")]
    pub file: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EnumSource {
    #[serde(rename = "inlineValues", deserialize_with = "required_nullable")]
    pub inline_values: Option<Vec<String>>,
    #[serde(rename = "declaredType", deserialize_with = "required_nullable")]
    pub declared_type: Option<String>,
}

pub fn decode(value: &Value) -> Result<Value, String> {
    let schema: Schema = serde_json::from_value(value.clone())
        .map_err(|e| format!("The schema is not readable: {e}"))?;
    serde_json::to_value(schema).map_err(|e| e.to_string())
}

// Nullable does not mean optional: these keys are required by IR 1.2 constructors.
fn required_nullable<'de, D, T>(deserializer: D) -> Result<Option<T>, D::Error>
where
    D: serde::Deserializer<'de>,
    T: Deserialize<'de>,
{
    Option::<T>::deserialize(deserializer)
}
