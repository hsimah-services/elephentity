use serde_json::{Map, Value};

/// Typed access to a spec document that has already passed JSON Schema validation.
///
/// YAML is untyped, and validation has already established the shape. This narrows values
/// once, in one place, so the rest of the compiler works in real types.
///
/// Every failure here is an internal invariant violation, not user error: the JSON Schema
/// should have rejected the document first. So it panics, where user errors are collected.
#[derive(Clone, Copy)]
pub struct SpecReader<'a> {
    data: &'a Map<String, Value>,
}

impl<'a> SpecReader<'a> {
    pub fn new(data: &'a Map<String, Value>) -> Self {
        Self { data }
    }

    pub fn has(&self, key: &str) -> bool {
        self.data.contains_key(key)
    }

    pub fn all(&self) -> &'a Map<String, Value> {
        self.data
    }

    /// The value, or `null` when absent.
    pub fn raw(&self, key: &str) -> Value {
        self.data.get(key).cloned().unwrap_or(Value::Null)
    }

    pub fn string(&self, key: &str) -> String {
        match self.data.get(key) {
            Some(Value::String(value)) => value.clone(),
            _ => invariant(key, "a string"),
        }
    }

    pub fn optional_string(&self, key: &str) -> Option<String> {
        self.has(key).then(|| self.string(key))
    }

    pub fn bool(&self, key: &str, default: bool) -> bool {
        match self.data.get(key) {
            None | Some(Value::Null) => default,
            Some(Value::Bool(value)) => *value,
            Some(_) => invariant(key, "a boolean"),
        }
    }

    pub fn optional_int(&self, key: &str) -> Option<i64> {
        match self.data.get(key) {
            None | Some(Value::Null) => None,
            Some(Value::Number(value)) => Some(
                value
                    .as_i64()
                    .unwrap_or_else(|| invariant(key, "an integer")),
            ),
            Some(_) => invariant(key, "an integer"),
        }
    }

    /// A map of named sub-documents, e.g. fields, edges, actions, in declaration order.
    pub fn readers(&self, key: &str) -> Vec<(String, SpecReader<'a>)> {
        match self.data.get(key) {
            None | Some(Value::Null) => Vec::new(),
            Some(Value::Object(entries)) => entries
                .iter()
                .map(|(name, entry)| match entry {
                    Value::Object(inner) => (name.clone(), SpecReader::new(inner)),
                    _ => invariant(&format!("{key}.{name}"), "a map"),
                })
                .collect(),
            Some(_) => invariant(key, "a map"),
        }
    }

    pub fn reader(&self, key: &str) -> Option<SpecReader<'a>> {
        if !self.has(key) {
            return None;
        }

        match self.data.get(key) {
            Some(Value::Object(inner)) => Some(SpecReader::new(inner)),
            _ => invariant(key, "a map"),
        }
    }

    pub fn string_list(&self, key: &str) -> Vec<String> {
        match self.data.get(key) {
            None | Some(Value::Null) => Vec::new(),
            Some(Value::Array(items)) => items
                .iter()
                .map(|item| match item {
                    Value::String(value) => value.clone(),
                    _ => invariant(key, "a list of strings"),
                })
                .collect(),
            Some(_) => invariant(key, "a list"),
        }
    }
}

fn invariant(key: &str, expected: &str) -> ! {
    panic!("internal error: expected \"{key}\" to be {expected}; the JSON Schema should have rejected this document")
}
