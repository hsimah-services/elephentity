//! Turns a validated spec document into sections.
//!
//! Everything here assumes the JSON Schema has already passed, so shapes are trusted and
//! enum values are known to be members. Meaning (does this type exist, does this edge
//! target resolve) is checked later.

use indexmap::IndexMap;
use serde_json::{Map, Value};

use super::SpecReader;
use crate::model::{
    parse_enum, ActionDefinition, ActionWrites, ArgumentDefinition, Cardinality, EdgeDefinition,
    EdgeInverse, EnumSource, FieldDefinition, Origin, ParsedSections, PolicyDefinition,
    QueryDefinition, ReturnDefinition, SideEffectDefinition, SideEffectPhase,
};

pub fn parse_sections(reader: SpecReader<'_>, origin: &Origin) -> ParsedSections {
    ParsedSections {
        fields: fields(reader, origin),
        edges: edges(reader, origin),
        queries: queries(reader, origin),
        actions: actions(reader, origin),
        side_effects: side_effects(reader, origin),
        read_policies: policies(reader, origin, "readPolicies"),
        write_policies: policies(reader, origin, "writePolicies"),
    }
}

fn policies(
    reader: SpecReader<'_>,
    origin: &Origin,
    section: &str,
) -> IndexMap<String, PolicyDefinition> {
    reader
        .readers(section)
        .into_iter()
        .map(|(name, policy)| {
            let definition = PolicyDefinition {
                name: name.clone(),
                origin: origin.clone(),
                description: policy.optional_string("description"),
            };

            (name, definition)
        })
        .collect()
}

fn fields(reader: SpecReader<'_>, origin: &Origin) -> IndexMap<String, FieldDefinition> {
    reader
        .readers("fields")
        .into_iter()
        .map(|(name, field)| {
            let definition = FieldDefinition {
                name: name.clone(),
                type_name: field.string("type"),
                origin: origin.clone(),
                description: field.optional_string("description"),
                required: field.bool("required", false),
                nullable: field.bool("nullable", false),
                default: field.raw("default"),
                has_default: field.has("default"),
                unique: field.bool("unique", false),
                indexed: field.bool("indexed", false),
                immutable: field.bool("immutable", false),
                managed: field.optional_string("managed").map(|m| parse_enum(&m)),
                max_length: field.optional_int("maxLength"),
                enum_source: enum_source(field),
                verify: field.bool("verify", false),
            };

            (name, definition)
        })
        .collect()
}

fn enum_source(field: SpecReader<'_>) -> Option<EnumSource> {
    if !field.has("values") {
        return None;
    }

    // The schema permits a string naming a declared enum, or an inline member list.
    Some(match field.raw("values") {
        Value::String(name) => EnumSource::Declared(name),
        _ => EnumSource::Inline(field.string_list("values")),
    })
}

fn edges(reader: SpecReader<'_>, origin: &Origin) -> IndexMap<String, EdgeDefinition> {
    reader
        .readers("edges")
        .into_iter()
        .map(|(name, edge)| {
            let definition = EdgeDefinition {
                name: name.clone(),
                to: edge.string("to"),
                cardinality: parse_enum(&edge.string("cardinality")),
                origin: origin.clone(),
                description: edge.optional_string("description"),
                inverse: inverse(edge),
                on_delete: edge
                    .optional_string("onDelete")
                    .map_or(crate::model::OnDelete::Restrict, |value| parse_enum(&value)),
                required: edge.bool("required", false),
            };

            (name, definition)
        })
        .collect()
}

fn inverse(edge: SpecReader<'_>) -> Option<EdgeInverse> {
    if !edge.has("inverse") {
        return None;
    }

    Some(match edge.raw("inverse") {
        Value::String(name) => EdgeInverse {
            derived: false,
            name: Some(name),
            unique: true,
        },
        Value::Object(_) => {
            let reader = edge.reader("inverse").expect("the inverse is a map");
            let name = reader.optional_string("name");
            let unique = reader.bool("unique", true);

            EdgeInverse {
                derived: name.is_none(),
                name,
                unique,
            }
        }
        // The remaining permitted form is the literal true.
        _ => EdgeInverse {
            derived: true,
            name: None,
            unique: true,
        },
    })
}

fn queries(reader: SpecReader<'_>, origin: &Origin) -> IndexMap<String, QueryDefinition> {
    reader
        .readers("queries")
        .into_iter()
        .map(|(name, query)| {
            let returns = query.reader("returns");
            let cardinality = returns.and_then(|r| r.optional_string("cardinality"));

            let definition = QueryDefinition {
                name: name.clone(),
                returns: ReturnDefinition {
                    type_name: returns.map(|r| r.string("type")).unwrap_or_default(),
                    cardinality: cardinality.map_or(Cardinality::Many, |value| parse_enum(&value)),
                },
                origin: origin.clone(),
                arguments: arguments(query),
                description: query.optional_string("description"),
                // Raw for now: what a given integration accepts is only knowable once the
                // registry and the project's enabled set are.
                integrations: raw_integrations(query),
            };

            (name, definition)
        })
        .collect()
}

fn actions(reader: SpecReader<'_>, origin: &Origin) -> IndexMap<String, ActionDefinition> {
    reader
        .readers("actions")
        .into_iter()
        .map(|(name, action)| {
            let writes = action.reader("writes");

            let definition = ActionDefinition {
                name: name.clone(),
                writes: ActionWrites {
                    fields: writes.map(|w| w.string_list("fields")).unwrap_or_default(),
                    edges: writes.map(|w| w.string_list("edges")).unwrap_or_default(),
                },
                origin: origin.clone(),
                arguments: arguments(action),
                description: action.optional_string("description"),
            };

            (name, definition)
        })
        .collect()
}

fn side_effects(reader: SpecReader<'_>, origin: &Origin) -> IndexMap<String, SideEffectDefinition> {
    reader
        .readers("sideEffects")
        .into_iter()
        .map(|(name, side_effect)| {
            let definition = SideEffectDefinition {
                name: name.clone(),
                events: side_effect
                    .string_list("on")
                    .iter()
                    .map(|event| parse_enum(event))
                    .collect(),
                origin: origin.clone(),
                phase: side_effect
                    .optional_string("phase")
                    .map_or(SideEffectPhase::PreCommit, |value| parse_enum(&value)),
                description: side_effect.optional_string("description"),
            };

            (name, definition)
        })
        .collect()
}

fn raw_integrations(reader: SpecReader<'_>) -> IndexMap<String, Map<String, Value>> {
    reader
        .reader("integrations")
        .map(|integrations| settings_by_name(integrations.all()))
        .unwrap_or_default()
}

/// Each integration's settings, with a non-map (a bare `name:`) meaning none.
pub fn settings_by_name(declared: &Map<String, Value>) -> IndexMap<String, Map<String, Value>> {
    declared
        .iter()
        .map(|(name, settings)| {
            let settings = match settings {
                Value::Object(map) => map.clone(),
                _ => Map::new(),
            };

            (name.clone(), settings)
        })
        .collect()
}

fn arguments(reader: SpecReader<'_>) -> IndexMap<String, ArgumentDefinition> {
    reader
        .readers("args")
        .into_iter()
        .map(|(name, argument)| {
            let definition = ArgumentDefinition {
                name: name.clone(),
                type_name: argument.string("type"),
                nullable: argument.bool("nullable", false),
            };

            (name, definition)
        })
        .collect()
}
