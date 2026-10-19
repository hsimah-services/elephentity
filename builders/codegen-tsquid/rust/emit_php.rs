//! The PHP output: `tsquid-manifest.php`, unchanged from before the C# target existed (its golden files pin it).

use serde_json::{json, Value};

pub fn php(value: &Value) -> String {
    match value {
        Value::Null => "null".into(),
        Value::Bool(v) => v.to_string(),
        Value::Number(v) => v.to_string(),
        Value::String(v) => format!("'{}'", v.replace('\\', "\\\\").replace('\'', "\\'")),
        Value::Array(v) => format!("[{}]", v.iter().map(php).collect::<Vec<_>>().join(", ")),
        Value::Object(v) => format!(
            "(object) [{}]",
            v.iter()
                .map(|(k, v)| format!("{} => {}", php(&json!(k)), php(v)))
                .collect::<Vec<_>>()
                .join(", ")
        ),
    }
}

/// The file body for a validated manifest.
pub fn manifest(manifest: &Value) -> String {
    format!("return new \\Eleph\\Tsquid\\Manifest({});\n", php(manifest))
}
