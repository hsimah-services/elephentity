//! The Cosmos layout of a project: which property each field is stored in, where each edge's reference lives, which fields
//! are unique keys, and which orderings need a composite index. Placement is inferred from the IR and never declared, so two
//! entities cannot disagree about it.

use serde_json::Value;
use std::collections::BTreeMap;

const NS: &str = "global::Elephentity.Cosmos.Manifest";
const RUNTIME: &str = "global::Elephentity.Runtime.Storage";

/// Cosmos allows ten unique keys per container. Every entity's unique fields take slots `_u0…` from the same ten, so
/// the entity with the most of them decides. (Azure Cosmos DB service quotas: 10 unique keys, 16 paths per key; each of ours
/// is one path.)
const MAX_UNIQUE_KEYS: usize = 10;

/// The primitives the runtime can store. A document holds JSON, so anything the IR names is representable; a name this
/// builder has never heard of is the compiler being ahead of it.
const PRIMITIVES: &[&str] = &[
    "string", "text", "int", "float", "bool", "datetime", "json", "id", "enum",
];

fn text(value: &Value) -> &str {
    value.as_str().unwrap_or("")
}

fn flag(value: &Value) -> bool {
    value.as_bool().unwrap_or(false)
}

fn values(value: &Value) -> Vec<&Value> {
    value
        .as_object()
        .map(|map| map.values().collect())
        .unwrap_or_default()
}

fn lower_first(name: &str) -> String {
    let mut characters = name.chars();

    characters
        .next()
        .map(|first| first.to_lowercase().collect::<String>() + characters.as_str())
        .unwrap_or_default()
}

/// A quoted C# string literal that is always printable: nothing that ends a line, or ends the string, gets through.
pub fn literal(text: &str) -> String {
    let mut out = String::from("\"");

    for character in text.chars() {
        match character {
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            other if other.is_control() || matches!(other, '\u{2028}' | '\u{2029}') => {
                let mut units = [0u16; 2];

                for unit in other.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04X}"));
                }
            }
            other => out.push(other),
        }
    }

    out.push('"');
    out
}

/// What the container keeps for itself: `id`, `pk`, `type`, and anything starting with an underscore (`_etag`, the
/// unique-key slots `_u0…`).
fn reserved(name: &str) -> bool {
    matches!(name, "id" | "pk" | "type") || name.starts_with('_')
}

fn plain(name: &str) -> bool {
    let mut characters = name.chars();

    characters
        .next()
        .is_some_and(|first| first.is_ascii_alphabetic() || first == '_')
        && characters.all(|rest| rest.is_ascii_alphanumeric() || rest == '_')
}

/// The property a field is stored in. A name the container reserves gets a suffix (`type` becomes `type_`, `_x`
/// becomes `f_x`) so the spec can use it; the adaptor maps field to property both ways, so nothing else sees the difference.
fn property(field: &str) -> String {
    if field.starts_with('_') {
        format!("f{field}")
    } else if reserved(field) {
        format!("{field}_")
    } else {
        field.to_string()
    }
}

#[derive(Clone, Copy, PartialEq)]
enum Relation {
    OneToOne,
    ManyToOne,
    OneToMany,
    ManyToMany,
}

impl Relation {
    fn name(self) -> &'static str {
        match self {
            Relation::OneToOne => "OneToOne",
            Relation::ManyToOne => "ManyToOne",
            Relation::OneToMany => "OneToMany",
            Relation::ManyToMany => "ManyToMany",
        }
    }
}

struct Placement {
    edge: String,
    target: String,
    relation: Relation,
    property: Option<String>,
}

struct Layout {
    name: String,
    fields: Vec<(String, String)>,
    unique: Vec<String>,
    edges: Vec<Placement>,
}

fn field_problem(schema: &Value, entity: &str, field: &Value) -> Option<String> {
    let name = text(&field["name"]);
    let declared = text(&field["type"]["declaredType"]);
    let primitive = field["type"]["primitive"]
        .as_str()
        .unwrap_or_else(|| text(&schema["types"][declared]["primitive"]));

    if !plain(name) {
        return Some(format!(
            "Field {entity}.{name} is not a usable Cosmos property name: use letters, digits and underscores."
        ));
    }

    if !PRIMITIVES.contains(&primitive) {
        return Some(format!(
            "Field {entity}.{name} has type \"{primitive}\", which the cosmos driver cannot store."
        ));
    }

    None
}

fn claim(
    claimed: &mut BTreeMap<(String, String), String>,
    problems: &mut Vec<String>,
    entity: &str,
    property: &str,
    by: String,
) {
    if !plain(property) || reserved(property) {
        problems.push(format!(
            "\"{property}\" ({by}) is not a usable property name: the container reserves id, pk, type and anything starting with an underscore."
        ));
    }

    match claimed.get(&(entity.to_string(), property.to_string())) {
        Some(other) => problems.push(format!(
            "Property \"{property}\" on {entity} is claimed by both {other} and {by}."
        )),
        None => {
            claimed.insert((entity.to_string(), property.to_string()), by);
        }
    }
}

/// The C# source of `CosmosStorageManifest.cs`, or every reason there is not one.
pub fn generate(schema: &Value, namespace: &str) -> Result<String, Vec<String>> {
    let mut problems = Vec::new();
    let mut claimed = BTreeMap::new();
    let mut layouts: Vec<Layout> = Vec::new();
    let entities = values(&schema["entities"]);

    for entity in &entities {
        let name = text(&entity["name"]);

        if entity["storage"]["driver"] != "cosmos" {
            problems.push(format!("Entity {name} does not use cosmos."));
        }

        if flag(&entity["config"]["taxonomy"]) || flag(&entity["config"]["account"]) {
            problems.push(format!(
                "Entity {name} requests platform-owned storage; Cosmos stores ordinary entities only."
            ));
        }

        let mut layout = Layout {
            name: name.to_string(),
            fields: Vec::new(),
            unique: Vec::new(),
            edges: Vec::new(),
        };

        for field in values(&entity["fields"]) {
            if let Some(problem) = field_problem(schema, name, field) {
                problems.push(problem);

                continue;
            }

            let field_name = text(&field["name"]);
            let stored = property(field_name);

            claim(
                &mut claimed,
                &mut problems,
                name,
                &stored,
                format!("field {field_name}"),
            );
            layout.fields.push((field_name.to_string(), stored));

            if flag(&field["unique"]) {
                layout.unique.push(field_name.to_string());
            }
        }

        if layout.unique.len() > MAX_UNIQUE_KEYS {
            problems.push(format!(
                "Entity {name} has {} unique fields ({}); a Cosmos container allows {MAX_UNIQUE_KEYS} unique keys, and every entity shares them.",
                layout.unique.len(),
                layout.unique.join(", ")
            ));
        }

        layouts.push(layout);
    }

    for entity in &entities {
        let name = text(&entity["name"]);

        for edge in values(&entity["edges"]) {
            let edge_name = text(&edge["name"]);
            let to = text(&edge["to"]);

            if schema["entities"][to].is_null() {
                problems.push(format!("Edge {name}.{edge_name} has an unknown target."));

                continue;
            }

            let one = edge["cardinality"] == "one";
            let unique = flag(&edge["inverse"]["unique"]);
            let relation = match (one, unique) {
                (true, true) => Relation::OneToOne,
                (true, false) => Relation::ManyToOne,
                (false, true) => Relation::OneToMany,
                (false, false) => Relation::ManyToMany,
            };
            let (owner, stored) = match relation {
                Relation::ManyToMany => (name, None),
                // The reference is the entity's own property.
                Relation::OneToOne | Relation::ManyToOne => (name, Some(format!("{edge_name}Id"))),
                // Each target row carries the reference back, under the inverse's name.
                Relation::OneToMany => {
                    let inverse = if flag(&edge["inverse"]["derived"]) || edge["inverse"].is_null()
                    {
                        lower_first(name)
                    } else {
                        text(&edge["inverse"]["name"]).to_string()
                    };

                    (to, Some(format!("{inverse}Id")))
                }
            };

            if let Some(stored) = &stored {
                claim(
                    &mut claimed,
                    &mut problems,
                    owner,
                    stored,
                    format!("edge {name}.{edge_name}"),
                );
            }

            if let Some(layout) = layouts.iter_mut().find(|layout| layout.name == name) {
                layout.edges.push(Placement {
                    edge: edge_name.to_string(),
                    target: to.to_string(),
                    relation,
                    property: stored,
                });
            }
        }
    }

    if !problems.is_empty() {
        return Err(problems);
    }

    Ok(render(schema, namespace, &layouts))
}

fn render(schema: &Value, namespace: &str, layouts: &[Layout]) -> String {
    let mut out = String::new();
    let project = text(&schema["project"]["name"]);

    out.push_str("// <auto-generated/>\n#nullable enable\n#pragma warning disable CS1591\n\n");
    out.push_str(&format!("namespace {namespace};\n\n"));
    out.push_str("/// <summary>\n/// How this project's entities are laid out in a Cosmos DB container: the storage manifest <c>Elephentity.Cosmos</c> loads.\n/// Which account, database and container to use is configuration, not this file.\n/// </summary>\n");
    out.push_str("public static class CosmosStorageManifestFactory\n{\n");
    out.push_str(&format!(
        "    /// <summary>The manifest. The container name is the only thing a deployment may choose.</summary>\n    public static {NS}.CosmosStorageManifest Create(string container = {NS}.CosmosStorageManifest.DefaultContainer) =>\n        new(\n            {},\n            new global::System.Collections.Generic.Dictionary<string, {NS}.CosmosEntityLayout>\n            {{\n",
        literal(project)
    ));

    for layout in layouts {
        out.push_str(&format!(
            "                [{}] = new(\n                    {},\n",
            literal(&layout.name),
            literal(&layout.name)
        ));
        out.push_str("                    new global::System.Collections.Generic.Dictionary<string, string>\n                    {\n");

        for (field, stored) in &layout.fields {
            out.push_str(&format!(
                "                        [{}] = {},\n",
                literal(field),
                literal(stored)
            ));
        }

        out.push_str("                    },\n");

        if layout.unique.is_empty() {
            out.push_str("                    [],\n");
        } else {
            out.push_str("                    [\n");

            for field in &layout.unique {
                out.push_str(&format!(
                    "                        new {NS}.CosmosUniqueKey({}),\n",
                    literal(field)
                ));
            }

            out.push_str("                    ],\n");
        }

        out.push_str(&format!(
            "                    new global::System.Collections.Generic.Dictionary<string, {NS}.CosmosEdgePlacement>\n                    {{\n"
        ));

        for placement in &layout.edges {
            let stored = placement
                .property
                .as_deref()
                .map(|p| format!(", {}", literal(p)))
                .unwrap_or_default();

            out.push_str(&format!(
                "                        [{}] = new({}, {}, {}, {RUNTIME}.RelationKind.{}{stored}),\n",
                literal(&placement.edge),
                literal(&layout.name),
                literal(&placement.edge),
                literal(&placement.target),
                placement.relation.name()
            ));
        }

        out.push_str("                    }),\n");
    }

    out.push_str("            },\n");

    // An ordering needs a composite index, and the spec declares none; a field marked indexed or unique is the
    // field a query is expected to sort or look up by, so each gets one per direction. Pages order by the sort keys and
    // then the id, always ascending, so descending is a different index from ascending, not its inverse.
    let mut orderings = Vec::new();

    for entity in values(&schema["entities"]) {
        for field in values(&entity["fields"]) {
            if (flag(&field["indexed"]) || flag(&field["unique"]))
                && layouts
                    .iter()
                    .any(|layout| layout.fields.iter().any(|(f, _)| f == text(&field["name"])))
            {
                orderings.push((text(&entity["name"]), text(&field["name"]), "Ascending"));
                orderings.push((text(&entity["name"]), text(&field["name"]), "Descending"));
            }
        }
    }

    if orderings.is_empty() {
        out.push_str("            [],\n");
    } else {
        out.push_str("            [\n");

        for (entity, field, direction) in orderings {
            out.push_str(&format!(
                "                new {NS}.CosmosOrdering({}, [{RUNTIME}.Order.{direction}({})]),\n",
                literal(entity),
                literal(field)
            ));
        }

        out.push_str("            ],\n");
    }

    out.push_str("            container);\n}\n");

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reserved_names_are_escaped_without_colliding_with_themselves() {
        assert_eq!(property("title"), "title");
        assert_eq!(property("type"), "type_");
        assert_eq!(property("id"), "id_");
        assert_eq!(property("pk"), "pk_");
        assert_eq!(property("_etag"), "f_etag");
        assert!(!reserved(&property("type")));
        assert!(!reserved(&property("_u0")));
    }

    #[test]
    fn literals_are_printable_and_never_end_a_line() {
        assert_eq!(literal("a\"b\\c\n"), "\"a\\\"b\\\\c\\n\"");
        assert_eq!(
            literal("\u{85}\u{2028}\u{2029}"),
            "\"\\u0085\\u2028\\u2029\""
        );
    }
}
