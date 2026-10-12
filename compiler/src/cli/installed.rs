//! What this project's builders provide, asked for rather than assumed.
//!
//! The compiler cannot validate `integrations: { wpgraphql: { singular: ... } }` until it knows
//! what keys `wpgraphql` accepts, and the package that owns that answer is the one that also
//! generates the GraphQL manifest. So the answer is fetched from the builders before a spec is
//! read: `eleph-codegen describe`, which resolves them and pools their replies.
//!
//! A builder that provides neither an integration nor a driver is the normal case: a language
//! generator turns the IR into source and knows no platform.
//!
//! Ported from `Installed`, `IntegrationCodec` and `StorageRulesCodec`.

use std::collections::BTreeMap;

use serde_json::{Map, Value};

use super::codegen::Codegen;
use crate::integration::{IntegrationDefinition, IntegrationRegistry};
use crate::model::{ConfigParameter, ConfigType};
use crate::spec::{RawSpec, SpecKind};
use crate::storage::{StorageRuleRegistry, StorageRules};

#[derive(Debug, Default)]
pub struct Installed {
    pub integrations: IntegrationRegistry,
    /// Storage drivers some installed builder can generate for, sorted.
    pub drivers: Vec<String>,
    /// Patterns some installed builder ships, pooled from `describe`.
    pub patterns: Vec<RawSpec>,
    /// Types some installed builder ships, pooled from `describe`.
    pub types: Vec<RawSpec>,
    pub storage_rules: StorageRuleRegistry,
}

impl Installed {
    pub fn described_by(codegen: &Codegen, project_root: &str) -> Result<Self, String> {
        let described = codegen
            .capture(&[
                "describe".to_string(),
                "--project".to_string(),
                project_root.to_string(),
            ])
            .and_then(|json| Self::from_json(&json));

        // The upgrade path runs through here. A generator installed before describe existed
        // reports only that the subcommand is unknown, which says nothing about what to do, and
        // it is the first thing every existing project will hit.
        described.map_err(|message| {
            format!(
                "Could not ask the code generator what this project's builders provide.\n{message}\n\
                 If that says the \"describe\" command is not defined, eleph-codegen predates the \
                 handshake: update it, and the builders with it."
            )
        })
    }

    pub fn from_json(json: &str) -> Result<Self, String> {
        let decoded: Value = serde_json::from_str(json).map_err(|error| {
            format!("The code generator described the builders unusably: {error}")
        })?;

        let targets = decoded
            .get("targets")
            .and_then(Value::as_object)
            .ok_or("The code generator described no targets.")?;

        let mut definitions = Vec::new();
        let mut drivers: BTreeMap<String, ()> = BTreeMap::new();
        let mut patterns = Vec::new();
        let mut types = Vec::new();
        let mut storage_rules = BTreeMap::new();

        for (target, declared) in targets {
            let Some(provides) = declared.as_object() else {
                continue;
            };

            definitions.extend(integrations_in(provides, target)?);

            let declared_drivers = drivers_in(provides);

            for driver in &declared_drivers {
                drivers.insert(driver.clone(), ());
            }

            patterns.extend(specs_in(provides, target, "patterns", SpecKind::Pattern)?);
            types.extend(specs_in(provides, target, "types", SpecKind::Type)?);

            // Storage rules are the driver's own declaration, not the compiler's, so they land
            // against every driver this target declares rather than against the target's name.
            match provides.get("storage") {
                None | Some(Value::Null) => {}
                Some(Value::Object(storage)) => {
                    let rules = decode_storage_rules(target, storage).map_err(|message| {
                        format!("The {target} builder declared storage rules unusably: {message}")
                    })?;

                    rules.check_pattern().map_err(|message| {
                        format!("The {target} builder declared storage rules unusably: {message}")
                    })?;

                    for driver in &declared_drivers {
                        storage_rules.insert(driver.clone(), rules.clone());
                    }
                }
                Some(_) => {
                    return Err(format!(
                        "The {target} builder declared \"storage\" as something other than an object."
                    ));
                }
            }
        }

        Ok(Self {
            integrations: IntegrationRegistry::new(definitions),
            drivers: drivers.into_keys().collect(),
            patterns,
            types,
            storage_rules: StorageRuleRegistry::new(storage_rules),
        })
    }

    /// Whether some installed builder can generate for this driver.
    pub fn provides(&self, driver: &str) -> bool {
        self.drivers.iter().any(|candidate| candidate == driver)
    }

    pub fn describe_drivers(&self) -> String {
        if self.drivers.is_empty() {
            "none are installed".to_string()
        } else {
            self.drivers.join(", ")
        }
    }
}

fn integrations_in(
    provides: &Map<String, Value>,
    target: &str,
) -> Result<Vec<IntegrationDefinition>, String> {
    let declared = match provides.get("integrations") {
        None | Some(Value::Null) => return Ok(Vec::new()),
        Some(Value::Object(declared)) => declared,
        Some(_) => {
            return Err(format!(
                "The {target} builder declared \"integrations\" as something other than an object."
            ))
        }
    };

    declared
        .iter()
        .map(|(name, definition)| {
            let Some(definition) = definition.as_object() else {
                return Err(format!(
                    "The {target} builder declared an unnamed integration."
                ));
            };

            decode_integration(name, definition).map_err(|message| {
                format!("The {target} builder declared integration \"{name}\" unusably: {message}")
            })
        })
        .collect()
}

fn decode_integration(
    name: &str,
    data: &Map<String, Value>,
) -> Result<IntegrationDefinition, String> {
    Ok(IntegrationDefinition {
        name: name.to_string(),
        description: data
            .get("description")
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_string(),
        project_config: decode_parameters(data.get("projectConfig"))?,
        entity_config: decode_parameters(data.get("entityConfig"))?,
        query_config: decode_parameters(data.get("queryConfig"))?,
    })
}

/// `hasDefault` travels rather than being inferred from `default`, because a parameter
/// defaulting to null and one with no default at all mean different things: the second is
/// required.
fn decode_parameters(
    value: Option<&Value>,
) -> Result<indexmap::IndexMap<String, ConfigParameter>, String> {
    let declared = match value {
        None | Some(Value::Null) => return Ok(indexmap::IndexMap::new()),
        Some(Value::Object(declared)) => declared,
        Some(_) => return Err("An integration's config block must be an object.".to_string()),
    };

    let mut parameters = indexmap::IndexMap::new();

    for (name, declaration) in declared {
        let Some(declaration) = declaration.as_object() else {
            return Err("Every config parameter must be named and be an object.".to_string());
        };

        let type_ = declaration
            .get("type")
            .and_then(Value::as_str)
            .and_then(config_type)
            .ok_or_else(|| {
                format!(
                    "Config parameter \"{name}\" declares type {}, which is not one this build knows.",
                    declaration.get("type").map_or("null".to_string(), describe_type)
                )
            })?;

        let of = match declaration.get("of").and_then(Value::as_str) {
            None => None,
            Some(of) => Some(config_type(of).ok_or_else(|| {
                format!("Config parameter \"{name}\" declares element type \"{of}\", which is not one this build knows.")
            })?),
        };

        parameters.insert(
            name.clone(),
            ConfigParameter {
                name: name.clone(),
                type_,
                description: declaration
                    .get("description")
                    .and_then(Value::as_str)
                    .map(str::to_string),
                of,
                values: declaration
                    .get("values")
                    .and_then(Value::as_array)
                    .map(|values| {
                        values
                            .iter()
                            .filter_map(Value::as_str)
                            .map(str::to_string)
                            .collect()
                    }),
                nullable: declaration.get("nullable") == Some(&Value::Bool(true)),
                default: declaration.get("default").cloned().unwrap_or(Value::Null),
                has_default: declaration.get("hasDefault") == Some(&Value::Bool(true)),
            },
        );
    }

    Ok(parameters)
}

fn config_type(name: &str) -> Option<ConfigType> {
    serde_json::from_value(Value::String(name.to_string())).ok()
}

fn describe_type(value: &Value) -> String {
    match value {
        Value::String(text) => format!("\"{text}\""),
        Value::Number(number) => format!("\"{number}\""),
        Value::Bool(flag) => format!("\"{flag}\""),
        Value::Null => "null".to_string(),
        Value::Array(_) => "array".to_string(),
        Value::Object(_) => "array".to_string(),
    }
}

fn drivers_in(provides: &Map<String, Value>) -> Vec<String> {
    provides
        .get("drivers")
        .and_then(Value::as_array)
        .map(|drivers| {
            drivers
                .iter()
                .filter_map(Value::as_str)
                .map(str::to_string)
                .collect()
        })
        .unwrap_or_default()
}

/// Patterns or types a builder ships, as the same document shape the loader would have parsed
/// off disk: the wire shape is the spec document, as JSON, so pooling needs no parser of its own
/// and the result gets schema validation and everything after it unchanged.
fn specs_in(
    provides: &Map<String, Value>,
    target: &str,
    key: &str,
    kind: SpecKind,
) -> Result<Vec<RawSpec>, String> {
    let declared = match provides.get(key) {
        None | Some(Value::Null) => return Ok(Vec::new()),
        Some(Value::Object(declared)) => declared,
        Some(_) => {
            return Err(format!(
                "The {target} builder declared \"{key}\" as something other than an object."
            ))
        }
    };

    declared
        .iter()
        .map(|(name, data)| {
            let Some(data) = data.as_object() else {
                return Err(format!(
                    "The {target} builder declared an unnamed {}.",
                    kind.name()
                ));
            };

            if !data.contains_key(kind.name()) {
                return Err(format!(
                    "The {target} builder declared {} \"{name}\" without a \"{}\" key.",
                    kind.name(),
                    kind.name()
                ));
            }

            Ok(RawSpec::pooled(
                kind,
                &format!(
                    "{target}:{}/{name}.yml",
                    kind.directory().unwrap_or_default()
                ),
                data.clone(),
            ))
        })
        .collect()
}

fn decode_storage_rules(target: &str, data: &Map<String, Value>) -> Result<StorageRules, String> {
    let handle = match data.get("handle") {
        None => Map::new(),
        Some(Value::Object(handle)) => handle.clone(),
        Some(_) => {
            return Err(format!(
            "The {target} builder declared \"storage.handle\" as something other than an object."
        ))
        }
    };

    let max_handle_length = match handle.get("maxLength") {
        None | Some(Value::Null) => None,
        Some(value) => Some(
            value
                .as_u64()
                .and_then(|n| usize::try_from(n).ok())
                .ok_or_else(|| {
                    format!(
                        "The {target} builder declared a non-integer \"storage.handle.maxLength\"."
                    )
                })?,
        ),
    };

    let handle_pattern = match handle.get("pattern") {
        None | Some(Value::Null) => None,
        Some(Value::String(pattern)) => Some(pattern.clone()),
        Some(_) => {
            return Err(format!(
                "The {target} builder declared a non-string \"storage.handle.pattern\"."
            ))
        }
    };

    let reserved_handles = match handle.get("reserved") {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::Array(reserved)) => {
            reserved.iter().filter_map(Value::as_str).map(str::to_string).collect()
        }
        Some(_) => {
            return Err(format!(
                "The {target} builder declared \"storage.handle.reserved\" as something other than a list."
            ))
        }
    };

    Ok(StorageRules {
        max_handle_length,
        handle_pattern,
        reserved_handles,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn from(json: &str) -> Result<Installed, String> {
        Installed::from_json(json)
    }

    #[test]
    fn pools_patterns_and_types_from_every_target() {
        let installed = from(
            r#"{"targets": {
                "wordpress": {"drivers": ["wordpress"], "patterns": {"Taxonomy": {"pattern": "Taxonomy"}}},
                "php": {"types": {"PostStatus": {"type": "PostStatus", "primitive": "string"}}}
            }}"#,
        )
        .unwrap();

        assert_eq!(installed.patterns.len(), 1);
        assert_eq!(
            installed.patterns[0].file,
            "wordpress:patterns/Taxonomy.yml"
        );
        assert_eq!(installed.types[0].file, "php:types/PostStatus.yml");
        assert_eq!(installed.drivers, ["wordpress"]);
        assert!(installed.provides("wordpress"));
        assert!(!installed.provides("sqlite"));
    }

    #[test]
    fn pools_integrations_and_names_what_a_parameter_requires() {
        let installed = from(
            r#"{"targets": {"gql": {"integrations": {"graphql": {
                "description": "GraphQL",
                "entityConfig": {"singular": {"type": "string"}, "kind": {"type": "enum", "values": ["a", "b"], "hasDefault": true, "default": "a"}}
            }}}}}"#,
        )
        .unwrap();

        assert_eq!(installed.integrations.names(), ["graphql"]);
    }

    #[test]
    fn pools_storage_rules_against_every_driver_the_target_declares() {
        let installed = from(
            r#"{"targets": {"wp": {
                "drivers": ["wordpress", "wpmu"],
                "storage": {"handle": {"maxLength": 20, "pattern": "/^[a-z]+$/", "reserved": ["post"]}}
            }}}"#,
        )
        .unwrap();

        for driver in ["wordpress", "wpmu"] {
            let rules = installed.storage_rules.for_driver(driver).unwrap();
            assert_eq!(rules.max_handle_length, Some(20));
            assert_eq!(rules.reserved_handles, ["post"]);
        }
    }

    #[test]
    fn a_driver_with_no_storage_declaration_has_no_rules() {
        let installed = from(r#"{"targets": {"php": {"drivers": ["memory"]}}}"#).unwrap();

        assert!(installed.storage_rules.for_driver("memory").is_none());
    }

    #[test]
    fn nothing_described_is_refused_and_so_is_unusable_json() {
        assert_eq!(
            from("{}").unwrap_err(),
            "The code generator described no targets."
        );
        assert!(from("not json")
            .unwrap_err()
            .contains("described the builders unusably"));
    }

    #[test]
    fn malformed_declarations_fail_naming_the_target() {
        let cases = [
            (
                r#"{"targets": {"t": {"storage": "x"}}}"#,
                "The t builder declared \"storage\" as something other than an object.",
            ),
            (
                r#"{"targets": {"t": {"storage": {"handle": {"maxLength": "x"}}}}}"#,
                "non-integer \"storage.handle.maxLength\"",
            ),
            (
                r#"{"targets": {"t": {"storage": {"handle": {"pattern": 3}}}}}"#,
                "non-string \"storage.handle.pattern\"",
            ),
            (
                r#"{"targets": {"t": {"patterns": "x"}}}"#,
                "The t builder declared \"patterns\" as something other than an object.",
            ),
            (
                r#"{"targets": {"t": {"patterns": {"A": "x"}}}}"#,
                "The t builder declared an unnamed pattern.",
            ),
            (
                r#"{"targets": {"t": {"patterns": {"A": {}}}}}"#,
                "The t builder declared pattern \"A\" without a \"pattern\" key.",
            ),
            (
                r#"{"targets": {"t": {"types": {"A": {}}}}}"#,
                "The t builder declared type \"A\" without a \"type\" key.",
            ),
            (
                r#"{"targets": {"t": {"integrations": {"g": {"entityConfig": {"x": {"type": "blob"}}}}}}}"#,
                "declared integration \"g\" unusably",
            ),
            (
                r#"{"targets": {"t": {"integrations": "x"}}}"#,
                "\"integrations\" as something other than an object",
            ),
        ];

        for (json, expected) in cases {
            let message = from(json).unwrap_err();

            assert!(message.contains(expected), "{json} gave {message}");
        }
    }

    #[test]
    fn a_target_that_is_not_an_object_is_skipped() {
        assert!(from(r#"{"targets": {"t": "x", "u": {"drivers": ["a"]}}}"#)
            .unwrap()
            .provides("a"));
    }

    #[test]
    fn describing_drivers_lists_them_or_says_none() {
        assert_eq!(
            Installed::default().describe_drivers(),
            "none are installed"
        );
        assert_eq!(
            from(r#"{"targets": {"a": {"drivers": ["x"]}, "b": {"drivers": ["y", "x"]}}}"#)
                .unwrap()
                .describe_drivers(),
            "x, y"
        );
    }
}
