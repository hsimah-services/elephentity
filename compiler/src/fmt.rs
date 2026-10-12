//! Checks that specs are written in canonical form.
//!
//! Reports rather than rewrites, which is deliberate: the PHP compiler's YAML parsers discard
//! comments, so a formatter built on parse-and-dump would silently delete every explanatory note
//! an author had written, a far worse outcome than an ordering nit. This keeps the same contract
//! so the two compilers agree on what is out of order, and saying precisely what is wrong and
//! letting a human move two lines is the honest trade.
//!
//! Each report names the file, the path within it, and the order expected, so acting on one is
//! mechanical even though it is manual.
//!
//! Ported from `FormatChecker` and `CanonicalOrder`.

use serde_json::{Map, Value};

use crate::error::SpecError;
use crate::spec::{load, RawSpec, SpecKind, SpecSource};

/// Canonical mapping-key order. Section members retain declaration order, which is semantic for
/// side effects.
fn canonical(shape: &str) -> &'static [&'static str] {
    match shape {
        "project" => &["project", "description", "storage", "integrations"],
        "projectStorage" => &["driver", "tablePrefix"],
        "entity" => &[
            "entity",
            "description",
            "use",
            "configure",
            "integrations",
            "storage",
            "policies",
            "fields",
            "edges",
            "queries",
            "actions",
            "sideEffects",
            "readPolicies",
            "writePolicies",
        ],
        "pattern" => &[
            "pattern",
            "description",
            "requires",
            "config",
            "use",
            "storage",
            "fields",
            "edges",
            "queries",
            "actions",
            "sideEffects",
            "readPolicies",
            "writePolicies",
        ],
        "type" => &["type", "description", "primitive", "processors", "values"],
        "storage" => &["table", "handle"],
        "requires" => &["driver"],
        "field" => &[
            "type",
            "description",
            "required",
            "nullable",
            "default",
            "unique",
            "indexed",
            "immutable",
            "managed",
            "maxLength",
            "values",
            "verify",
        ],
        "edge" => &["to", "cardinality", "description", "inverse", "onDelete"],
        "inverse" => &["name", "unique"],
        "query" => &["description", "args", "returns", "integrations", "handler"],
        "returns" => &["type", "cardinality"],
        "action" => &["description", "args", "writes", "handler"],
        "writes" => &["fields", "edges"],
        "sideEffect" => &["description", "on", "phase", "handler"],
        "policy" => &["description", "handler"],
        "terminalRule" => &["read", "write"],
        "argument" => &["type", "nullable"],
        "configParameter" => &["type", "description", "of", "values", "nullable", "default"],
        _ => &[],
    }
}

/// The keys in canonical order: the known ones in the order the shape lists them, then any the
/// shape does not recognise in the order they were written. An unrecognised key is not an error
/// here; the JSON Schema is what rejects those.
fn sort<'a>(shape: &str, keys: &[&'a str]) -> Vec<&'a str> {
    let order = canonical(shape);

    if order.is_empty() {
        return keys.to_vec();
    }

    let mut sorted: Vec<&str> = order
        .iter()
        .copied()
        .filter(|key| keys.contains(key))
        .collect();
    sorted.extend(keys.iter().copied().filter(|key| !order.contains(key)));
    sorted
}

pub fn check(source: &SpecSource) -> Vec<SpecError> {
    let loaded = load(source);
    let mut errors = loaded.errors;

    for spec in &loaded.specs {
        check_document(spec, &mut errors);
    }

    errors
}

fn check_document(spec: &RawSpec, errors: &mut Vec<SpecError>) {
    check_mapping(spec, spec.kind.name(), &spec.data, "", errors);

    if let Some(Value::Object(parameters)) = spec.data.get("config") {
        for (name, parameter) in parameters {
            if let Value::Object(parameter) = parameter {
                check_mapping(
                    spec,
                    "configParameter",
                    parameter,
                    &format!("/config/{name}"),
                    errors,
                );
            }
        }
    }

    // The project's storage block holds different keys from an entity's.
    let storage_shape = if spec.kind == SpecKind::Project {
        "projectStorage"
    } else {
        "storage"
    };

    for shape in [storage_shape, "requires"] {
        let key = if shape == "projectStorage" {
            "storage"
        } else {
            shape
        };

        if let Some(Value::Object(nested)) = spec.data.get(key) {
            check_mapping(spec, shape, nested, &format!("/{key}"), errors);
        }
    }

    if let Some(Value::Object(rule)) = spec
        .data
        .get("policies")
        .and_then(Value::as_object)
        .and_then(|policies| policies.get("terminalRule"))
    {
        check_mapping(spec, "terminalRule", rule, "/policies/terminalRule", errors);
    }

    let sections = [
        ("fields", "field"),
        ("edges", "edge"),
        ("queries", "query"),
        ("actions", "action"),
        ("sideEffects", "sideEffect"),
        ("readPolicies", "policy"),
        ("writePolicies", "policy"),
    ];

    for (section, shape) in sections {
        let Some(Value::Object(members)) = spec.data.get(section) else {
            continue;
        };

        for (name, member) in members {
            let Value::Object(member) = member else {
                continue;
            };

            let path = format!("/{section}/{name}");
            check_mapping(spec, shape, member, &path, errors);

            for nested_key in ["inverse", "returns", "writes"] {
                if let Some(Value::Object(nested)) = member.get(nested_key) {
                    check_mapping(
                        spec,
                        nested_key,
                        nested,
                        &format!("{path}/{nested_key}"),
                        errors,
                    );
                }
            }

            let Some(Value::Object(arguments)) = member.get("args") else {
                continue;
            };

            for (argument, definition) in arguments {
                if let Value::Object(definition) = definition {
                    check_mapping(
                        spec,
                        "argument",
                        definition,
                        &format!("{path}/args/{argument}"),
                        errors,
                    );
                }
            }
        }
    }
}

fn check_mapping(
    spec: &RawSpec,
    shape: &str,
    mapping: &Map<String, Value>,
    path: &str,
    errors: &mut Vec<SpecError>,
) {
    let keys: Vec<&str> = mapping.keys().map(String::as_str).collect();
    let expected = sort(shape, &keys);

    if expected == keys {
        return;
    }

    errors.push(SpecError::new(
        "format.keyOrder",
        format!(
            "Keys are out of canonical order. Expected: {}.",
            expected.join(", ")
        ),
        Some(&spec.file),
        Some(if path.is_empty() {
            "/".to_string()
        } else {
            path.to_string()
        }),
    ));
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn known_keys_sort_by_the_shape_and_unknown_ones_follow_in_written_order() {
        assert_eq!(
            sort("field", &["verify", "zzz", "type", "aaa", "required"]),
            ["type", "required", "verify", "zzz", "aaa"]
        );
    }

    #[test]
    fn a_shape_with_no_order_is_left_alone() {
        assert_eq!(sort("nothing", &["b", "a"]), ["b", "a"]);
    }

    #[test]
    fn omitting_optional_keys_is_still_canonical() {
        assert_eq!(sort("entity", &["entity", "fields"]), ["entity", "fields"]);
    }
}
