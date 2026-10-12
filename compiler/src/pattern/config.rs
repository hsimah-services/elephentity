use indexmap::IndexMap;
use serde_json::{Map, Value};

use crate::error::SpecError;
use crate::model::{ConfigParameter, PatternDefinition};

/// Checks supplied values against declared parameters, and fills in the rest.
///
/// Shared by patterns and integrations. Both declare parameters and both have someone
/// supply values; one implementation of "is this value acceptable, what is the default,
/// what is missing" means the two cannot drift apart in what they accept.
///
/// The result is sorted by key.
pub fn resolve_values(
    declared: &IndexMap<String, ConfigParameter>,
    supplied: &IndexMap<String, Value>,
    subject: &str,
    file: &str,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) -> Map<String, Value> {
    let mut resolved: IndexMap<String, Value> = IndexMap::new();

    for (key, value) in supplied {
        let Some(parameter) = declared.get(key) else {
            let accepted = if declared.is_empty() {
                "nothing".to_string()
            } else {
                declared.keys().cloned().collect::<Vec<_>>().join(", ")
            };

            errors.push(SpecError::new(
                "config.unknown",
                format!("{subject} declares no configuration \"{key}\". It accepts: {accepted}."),
                Some(file),
                Some(format!("{pointer}/{key}")),
            ));

            continue;
        };

        if !parameter.accepts(value) {
            errors.push(SpecError::new(
                "config.invalidValue",
                format!(
                    "Configuration \"{key}\" expects {}.",
                    parameter.describe_expectation()
                ),
                Some(file),
                Some(format!("{pointer}/{key}")),
            ));

            continue;
        }

        resolved.insert(key.clone(), value.clone());
    }

    for (key, parameter) in declared {
        if resolved.contains_key(key) {
            continue;
        }

        if parameter.is_required() {
            errors.push(SpecError::new(
                "config.required",
                format!(
                    "{subject} requires configuration \"{key}\": {}.",
                    parameter.describe_expectation()
                ),
                Some(file),
                Some(pointer.to_string()),
            ));

            continue;
        }

        resolved.insert(
            key.clone(),
            if parameter.has_default {
                parameter.default.clone()
            } else {
                Value::Null
            },
        );
    }

    resolved.sort_keys();
    resolved.into_iter().collect()
}

/// Merges what patterns declare with what an entity supplies.
///
/// Every applied pattern contributes its parameters into one map, so a consumer reads the
/// keys it knows rather than looking up a pattern by name, which would mean renaming a
/// pattern silently disabled whatever depended on it. Two patterns declaring the same key is
/// a compile error, so a value in the resolved map has exactly one source.
pub fn resolve_config(
    patterns: &[&PatternDefinition],
    configure: Option<&Map<String, Value>>,
    entity_name: &str,
    entity_file: &str,
    errors: &mut Vec<SpecError>,
) -> Map<String, Value> {
    let mut declared: IndexMap<String, ConfigParameter> = IndexMap::new();
    let mut declared_by: IndexMap<String, String> = IndexMap::new();

    for pattern in patterns {
        for (name, parameter) in &pattern.config {
            if let Some(first) = declared_by.get(name) {
                errors.push(SpecError::new(
                    "config.collision",
                    format!(
                        "Patterns {first} and {} both declare configuration \"{name}\". A resolved value must have one source.",
                        pattern.name
                    ),
                    Some(entity_file),
                    None,
                ));

                continue;
            }

            declared.insert(name.clone(), parameter.clone());
            declared_by.insert(name.clone(), pattern.name.clone());
        }
    }

    let supplied = supplied(
        patterns,
        configure,
        &declared_by,
        entity_name,
        entity_file,
        errors,
    );

    resolve_values(
        &declared,
        &supplied,
        entity_name,
        entity_file,
        "/configure",
        errors,
    )
}

fn supplied(
    patterns: &[&PatternDefinition],
    configure: Option<&Map<String, Value>>,
    declared_by: &IndexMap<String, String>,
    entity_name: &str,
    entity_file: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, Value> {
    let mut supplied = IndexMap::new();

    let Some(configure) = configure else {
        return supplied;
    };

    for (pattern_name, raw_values) in configure {
        let Value::Object(values) = raw_values else {
            continue;
        };

        let Some(pattern) = patterns.iter().find(|p| &p.name == pattern_name) else {
            errors.push(SpecError::new(
                "config.unusedPattern",
                format!(
                    "{entity_name} configures \"{pattern_name}\" but does not use it. Add it to `use:`, or drop the configuration."
                ),
                Some(entity_file),
                Some(format!("/configure/{pattern_name}")),
            ));

            continue;
        };

        for (key, value) in values {
            // Reachable only alongside a collision, which is already reported.
            if pattern.config.contains_key(key)
                && declared_by.get(key).map(String::as_str) != Some(pattern_name.as_str())
            {
                continue;
            }

            supplied.insert(key.clone(), value.clone());
        }
    }

    supplied
}
