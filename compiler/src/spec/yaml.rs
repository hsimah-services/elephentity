//! YAML loading with source positions.
//!
//! saphyr is used because it keeps a span for every node and preserves mapping order. The
//! document is converted to a `serde_json::Value` (mapping order kept) so JSON Schema
//! validation and the rest of the compiler work on one representation, while the position
//! of each node stays available by JSON pointer.

use std::collections::BTreeMap;

use saphyr::{MarkedYaml, ScalarStyle, Tag, YamlData, YamlLoader};
use saphyr_parser::Parser;
use serde_json::{Map, Number, Value};

use super::scalar::resolve_plain;

/// Where a node's value starts, 1-based.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Position {
    pub line: usize,
    pub column: usize,
}

#[derive(Debug, Clone)]
pub struct Document {
    pub value: Value,
    /// Start of every node, keyed by JSON pointer (`""` is the root).
    pub positions: BTreeMap<String, Position>,
}

/// Parse a single YAML document. An empty stream is a document whose value is `null`.
///
/// Anything this compiler does not accept is an error, never a quiet reinterpretation:
/// several documents in one file, duplicate keys, and mapping keys that are not scalars.
/// Aliases are resolved by the parser, as they are by the PHP compiler's.
pub fn parse(source: &str) -> Result<Document, String> {
    // Scalars are left as text and typed by `resolve_plain`, so the rules are Symfony's, not
    // the YAML core schema's.
    let mut loader = YamlLoader::<MarkedYaml>::default();
    loader.early_parse(false);

    Parser::new_from_str(source)
        .load(&mut loader, true)
        .map_err(|error| error.to_string())?;

    if let Some(error) = loader.error() {
        return Err(error.to_string());
    }

    let mut documents = loader.into_documents();

    if documents.len() > 1 {
        return Err("A spec file must hold exactly one YAML document.".to_string());
    }

    let mut positions = BTreeMap::new();

    let value = match documents.pop() {
        None => Value::Null,
        Some(root) => convert(&root, "", &mut positions)?,
    };

    Ok(Document { value, positions })
}

fn convert(
    node: &MarkedYaml<'_>,
    pointer: &str,
    positions: &mut BTreeMap<String, Position>,
) -> Result<Value, String> {
    positions.insert(
        pointer.to_string(),
        Position {
            line: node.span.start.line(),
            column: node.span.start.col() + 1,
        },
    );

    match &node.data {
        YamlData::Representation(text, style, tag) => scalar(text, *style, tag.as_deref(), node),
        YamlData::Sequence(items) => {
            let mut list = Vec::with_capacity(items.len());

            for (index, item) in items.iter().enumerate() {
                list.push(convert(item, &format!("{pointer}/{index}"), positions)?);
            }

            Ok(Value::Array(list))
        }
        YamlData::Mapping(entries) => {
            let mut map = Map::new();

            for (key, value) in entries {
                let YamlData::Representation(name, _, _) = &key.data else {
                    return Err(format!(
                        "line {}: mapping keys must be scalars.",
                        key.span.start.line()
                    ));
                };

                let child = format!("{pointer}/{}", escape(name));
                map.insert(name.to_string(), convert(value, &child, positions)?);
            }

            Ok(Value::Object(map))
        }
        _ => Err(format!(
            "line {}: unsupported YAML value.",
            node.span.start.line()
        )),
    }
}

/// A scalar's value: quoted and block scalars are text, a plain one is typed by its content.
fn scalar(
    text: &str,
    style: ScalarStyle,
    tag: Option<&Tag>,
    node: &MarkedYaml<'_>,
) -> Result<Value, String> {
    let line = node.span.start.line();

    if let Some(tag) = tag {
        return match (tag.is_yaml_core_schema(), tag.suffix.as_str()) {
            (true, "str") => Ok(Value::String(text.to_string())),
            (true, "float") => text
                .trim()
                .parse::<f64>()
                .ok()
                .and_then(Number::from_f64)
                .map(Value::Number)
                .ok_or_else(|| format!("line {line}: \"{text}\" is not a float.")),
            _ => Err(format!(
                "line {line}: the tag {tag} is not supported in specs."
            )),
        };
    }

    if style != ScalarStyle::Plain {
        return Ok(Value::String(text.to_string()));
    }

    resolve_plain(text).map_err(|message| format!("line {line}: {message}"))
}

/// RFC 6901 escaping for one pointer segment.
pub fn escape(segment: &str) -> String {
    segment.replace('~', "~0").replace('/', "~1")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keeps_mapping_order_and_positions() {
        let document = parse("b: 1\na:\n  - x\n  - y: true\n").unwrap();

        let keys: Vec<_> = document.value.as_object().unwrap().keys().collect();
        assert_eq!(keys, ["b", "a"]);
        assert_eq!(document.value["a"][1]["y"], Value::Bool(true));

        assert_eq!(
            document.positions["/a/1/y"],
            Position { line: 4, column: 8 }
        );
        assert_eq!(document.positions["/b"].line, 1);
    }

    #[test]
    fn an_empty_stream_is_null() {
        assert_eq!(parse("").unwrap().value, Value::Null);
    }

    #[test]
    fn rejects_what_it_would_otherwise_reinterpret() {
        assert!(parse("a: 1\na: 2\n").is_err(), "duplicate keys");
        assert!(parse("a: 1\n---\nb: 2\n").is_err(), "two documents");
        assert!(parse("[a]: 1\n").is_err(), "non-scalar keys");
        assert!(parse("a: [1\n").is_err(), "syntax errors");
    }

    #[test]
    fn resolves_aliases() {
        let document = parse("a: &x 1\nb: *x\n").unwrap();

        assert_eq!(document.value["b"], Value::Number(1.into()));
    }

    #[test]
    fn escapes_pointer_segments() {
        let document = parse("'a/b': 1\n'c~d': 2\n").unwrap();

        assert!(document.positions.contains_key("/a~1b"));
        assert!(document.positions.contains_key("/c~0d"));
    }
}
