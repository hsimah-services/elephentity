//! Integrations: external systems an entity can be exposed to.
//!
//! An integration adds no structure the way a pattern does. It says "this entity is visible
//! to GraphQL" and carries the settings that exposure needs. What an integration accepts is
//! declared by whoever provides it; the compiler validates against that without naming one.

use indexmap::IndexMap;
use serde::Deserialize;
use serde_json::{Map, Value};

use crate::error::SpecError;
use crate::model::ConfigParameter;
use crate::pattern::resolve_values;

#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct IntegrationDefinition {
    pub name: String,
    pub description: String,
    /// Settings declared once, on the project.
    #[serde(default)]
    pub project_config: IndexMap<String, ConfigParameter>,
    /// Settings each exposed entity supplies.
    #[serde(default)]
    pub entity_config: IndexMap<String, ConfigParameter>,
    /// Settings a declared query supplies to be exposed.
    #[serde(default)]
    pub query_config: IndexMap<String, ConfigParameter>,
}

/// The integrations available to a project, sorted by name.
#[derive(Debug, Clone, Default)]
pub struct IntegrationRegistry {
    integrations: IndexMap<String, IntegrationDefinition>,
}

impl IntegrationRegistry {
    pub fn new(definitions: impl IntoIterator<Item = IntegrationDefinition>) -> Self {
        let mut integrations: IndexMap<String, IntegrationDefinition> = definitions
            .into_iter()
            .map(|definition| (definition.name.clone(), definition))
            .collect();

        integrations.sort_keys();

        Self { integrations }
    }

    fn describe_available(&self) -> String {
        if self.integrations.is_empty() {
            "none are installed".to_string()
        } else {
            self.integrations
                .keys()
                .cloned()
                .collect::<Vec<_>>()
                .join(", ")
        }
    }
}

/// Where an exposure is being resolved: what to call it in messages, and where it sits.
struct Exposure<'a> {
    subject: &'a str,
    file: &'a str,
    /// The pointer the integration names are found under.
    base: &'a str,
}

/// Integrations the project speaks, with their project-level settings.
pub fn resolve_project_integrations(
    registry: &IntegrationRegistry,
    declared: Option<&Map<String, Value>>,
    file: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, Map<String, Value>> {
    let mut resolved = IndexMap::new();

    for (name, settings) in supplied(declared) {
        let Some(definition) = registry.integrations.get(&name) else {
            errors.push(unknown(
                registry,
                &name,
                file,
                format!("/integrations/{name}"),
            ));
            continue;
        };

        let values = resolve_values(
            &definition.project_config,
            &settings,
            &format!("Integration {name}"),
            file,
            &format!("/integrations/{name}"),
            errors,
        );

        resolved.insert(name, values);
    }

    resolved.sort_keys();
    resolved
}

/// Integrations this entity is exposed through, with its own settings.
pub fn resolve_entity_integrations(
    registry: &IntegrationRegistry,
    declared: Option<&Map<String, Value>>,
    enabled: &IndexMap<String, Map<String, Value>>,
    entity_name: &str,
    file: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, Map<String, Value>> {
    let exposure = Exposure {
        subject: entity_name,
        file,
        base: "/integrations",
    };

    resolve_exposure(
        registry,
        declared,
        enabled,
        &exposure,
        |definition| &definition.entity_config,
        errors,
    )
}

/// Integrations a declared query is exposed through.
///
/// Separate from the entity's own exposure: an entity being in the graph does not mean
/// every finder it declares should be a root field. Publishing a query is its own decision.
pub fn resolve_query_integrations(
    registry: &IntegrationRegistry,
    declared: Option<&Map<String, Value>>,
    enabled: &IndexMap<String, Map<String, Value>>,
    subject: &str,
    file: &str,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, Map<String, Value>> {
    let exposure = Exposure {
        subject,
        file,
        base: pointer,
    };

    resolve_exposure(
        registry,
        declared,
        enabled,
        &exposure,
        |definition| &definition.query_config,
        errors,
    )
}

/// `parameters` picks which of an integration's declared settings apply to this exposure.
fn resolve_exposure(
    registry: &IntegrationRegistry,
    declared: Option<&Map<String, Value>>,
    enabled: &IndexMap<String, Map<String, Value>>,
    exposure: &Exposure<'_>,
    parameters: impl Fn(&IntegrationDefinition) -> &IndexMap<String, ConfigParameter>,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, Map<String, Value>> {
    let Exposure {
        subject,
        file,
        base,
    } = *exposure;
    let mut resolved = IndexMap::new();

    for (name, settings) in supplied(declared) {
        let pointer = format!("{base}/{name}");

        let Some(definition) = registry.integrations.get(&name) else {
            errors.push(unknown(registry, &name, file, pointer));
            continue;
        };

        if !enabled.contains_key(&name) {
            // Exposure is opt-in twice on purpose: the project says what it speaks, the
            // entity says what it exposes. Skipping the first would let an entity widen the
            // project's surface on its own.
            errors.push(SpecError::new(
                "integration.notEnabled",
                format!(
                    "{subject} uses integration \"{name}\", which the project does not enable. Add it to project.yml."
                ),
                Some(file),
                Some(pointer),
            ));
            continue;
        }

        let values = resolve_values(
            parameters(definition),
            &settings,
            &format!("Integration {name}"),
            file,
            &pointer,
            errors,
        );

        resolved.insert(name, values);
    }

    resolved.sort_keys();
    resolved
}

fn unknown(registry: &IntegrationRegistry, name: &str, file: &str, pointer: String) -> SpecError {
    SpecError::new(
        "integration.unknown",
        format!(
            "Unknown integration \"{name}\". Installed: {}.",
            registry.describe_available()
        ),
        Some(file),
        Some(pointer),
    )
}

/// What was declared, each integration's settings as a map (a bare `name:` means none).
fn supplied(declared: Option<&Map<String, Value>>) -> Vec<(String, IndexMap<String, Value>)> {
    declared
        .into_iter()
        .flatten()
        .map(|(name, settings)| {
            let settings = match settings {
                Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v.clone())).collect(),
                _ => IndexMap::new(),
            };

            (name.clone(), settings)
        })
        .collect()
}
