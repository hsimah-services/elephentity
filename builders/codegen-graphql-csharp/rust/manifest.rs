//! The C# GraphQL manifest: what `Elephentity.GraphQL` assembles into a schema.
//!
//! A port of `eleph-gen-graphql-php`'s `manifest.rs`, rule for rule, because the schema it describes must be indistinguishable from
//! the PHP one: the same names, the same nullability, the same roots, edges, inverses, enums, mutations and published queries. What
//! differs is only how a field is read. PHP names an accessor method; here the manifest holds a delegate onto the generated read
//! model (`eleph-gen-csharp`), so the C# compiler checks every accessor and nothing uses reflection.

use super::*;
use std::collections::BTreeMap;

const NS: &str = "global::Elephentity.GraphQL.Manifest";
const SYSTEM_CONVERT: &str = "global::System.Convert";
const INVARIANT: &str = "global::System.Globalization.CultureInfo.InvariantCulture";
const AS_OBJECTS: &str = "global::Elephentity.Runtime.Query.EntityQueries.AsObjects";

fn q(text: &str) -> String {
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

fn nullable(v: &Value) -> String {
    v.as_str().map(q).unwrap_or_else(|| "null".into())
}

/// A C# member or type name: `defaultExpiryUnit` is `DefaultExpiryUnit`. The rule `eleph-gen-csharp` uses.
fn pascal(name: &str) -> String {
    let mut out = String::new();

    for part in name.split(['_', '-', ' ']).filter(|part| !part.is_empty()) {
        let mut characters = part.chars();

        if let Some(first) = characters.next() {
            out.extend(first.to_uppercase());
            out.push_str(characters.as_str());
        }
    }

    if out
        .chars()
        .next()
        .is_some_and(|first| first.is_ascii_digit())
    {
        out.insert(0, '_');
    }

    out
}

fn ty(n: &str, nonnull: bool, list: bool) -> String {
    format!("new {NS}.GraphQLType({}, {nonnull}, {list})", q(n))
}

fn primitive(p: &str) -> &str {
    match p {
        "int" => "Int",
        "float" => "Float",
        "bool" => "Boolean",
        "id" => "ID",
        _ => "String",
    }
}

fn reference(schema: &Value, r: &Value) -> String {
    if r["primitive"].is_null() {
        let n = s(&r["declaredType"]);
        let t = &schema["types"][n];

        if !t["values"].is_null() {
            n.into()
        } else {
            primitive(s(&t["primitive"])).into()
        }
    } else {
        primitive(s(&r["primitive"])).into()
    }
}

/// The GraphQL type name of a field: an enum's name, or the scalar it travels as.
fn field_name(schema: &Value, e: &Value, f: &Value) -> String {
    if f["type"]["primitive"] == "enum" {
        if f["enum"].is_null() {
            String::new()
        } else if !f["enum"]["inlineValues"].is_null() {
            format!("{}{}", s(&e["name"]), cap(s(&f["name"])))
        } else {
            s(&f["enum"]["declaredType"]).into()
        }
    } else {
        reference(schema, &f["type"])
    }
}

fn encoding(schema: &Value, f: &Value) -> &'static str {
    let mut p = s(&f["type"]["primitive"]);

    if p.is_empty() {
        let t = &schema["types"][s(&f["type"]["declaredType"])];

        if !t["values"].is_null() {
            return "Value";
        }

        if b(&t["hasProcessors"]) {
            return "Processor";
        }

        p = s(&t["primitive"]);
    }

    match p {
        "datetime" => "Datetime",
        "json" => "Json",
        _ => "Value",
    }
}

/// The C# enum a field is, if it is one: its generated name (`eleph-gen-csharp`'s rule) and whether it is stored by position.
fn enum_of(schema: &Value, e: &Value, f: &Value) -> Option<(String, bool)> {
    let declared = |name: &str| (pascal(name), schema["types"][name]["primitive"] == "int");

    if f["type"]["primitive"] == "enum" {
        if f["enum"].is_null() {
            return None;
        }

        return Some(if f["enum"]["inlineValues"].is_null() {
            declared(s(&f["enum"]["declaredType"]))
        } else if let Some(pattern) = f["origin"]["pattern"].as_str() {
            // A pattern's field is one type for every entity that uses it.
            (
                format!("{}{}", pascal(pattern), pascal(s(&f["name"]))),
                false,
            )
        } else {
            (
                format!("{}{}", pascal(s(&e["name"])), pascal(s(&f["name"]))),
                false,
            )
        });
    }

    let declared_type = s(&f["type"]["declaredType"]);

    (f["type"]["primitive"].is_null() && !schema["types"][declared_type]["values"].is_null())
        .then(|| declared(declared_type))
}

/// How a field is read off the read model. An enum goes through its generated wire class, because the manifest's enum values are the
/// stored values (the label for text, the position for an integer enum) and the C# enum has no other way to say which it is.
fn read(schema: &Value, e: &Value, f: &Value, entity_ns: &str, entity: &str) -> String {
    let access = format!("(({entity})source).{}", pascal(s(&f["name"])));

    let Some((wire, int)) = enum_of(schema, e, f) else {
        return format!("source => {access}");
    };

    let stored = |value: &str| {
        let call = format!("global::{entity_ns}.Enums.{wire}Wire.ToWire({value})");

        if int {
            format!("{SYSTEM_CONVERT}.ToString({call}, {INVARIANT})")
        } else {
            call
        }
    };

    if b(&f["nullable"]) {
        format!(
            "source => {access} is {{ }} value ? {} : null",
            stored("value")
        )
    } else {
        format!("source => {}", stored(&access))
    }
}

fn field(n: &str, t: &str, read: &str, description: &str, encoding: &str, value: &str) -> String {
    format!(
        "                [{}] = new({}, {t}, {read}, {description}, {NS}.FieldEncoding.{encoding}, {value}),",
        q(n),
        q(n)
    )
}

/// A to-one edge costs a query, so it is read asynchronously.
fn field_async(n: &str, t: &str, entity: &str, method: &str, description: &str) -> String {
    format!(
        "                [{}] = new({}, {t}, null, {description}, {NS}.FieldEncoding.Value, null, async (source, cancellationToken) => await (({entity})source).{method}(cancellationToken).ConfigureAwait(false)),",
        q(n),
        q(n)
    )
}

fn connection(
    n: &str,
    from: &str,
    to: &str,
    edge: &str,
    entity: &str,
    method: &str,
    description: &str,
) -> String {
    format!(
        "                [{}] = new({}, {}, {}, {}, source => {AS_OBJECTS}((({entity})source).{method}()), {description}),",
        q(n),
        q(n),
        q(from),
        q(to),
        q(edge)
    )
}

fn block(lines: &[String]) -> String {
    if lines.is_empty() {
        "null".into()
    } else {
        format!("new global::System.Collections.Generic.Dictionary<string, {NS}.%T%>\n            {{\n{}\n            }}", lines.join("\n"))
    }
}

fn dictionary(lines: &[String], value_type: &str) -> String {
    block(lines).replace("%T%", value_type)
}

/// An enum's GraphQL values: the upper-cased label to the stored value, which is the label itself for a text enum and the label's
/// position for an integer one (`eleph-gen-csharp` stores it that way, and a GraphQL value that is the stored value needs no
/// translation in either direction).
fn enumeration(n: &str, values: &Value, int: bool) -> String {
    let mut members: Vec<(String, String)> = Vec::new();

    for (index, v) in list(values).into_iter().enumerate() {
        members.push((
            s(v).to_ascii_uppercase(),
            if int {
                index.to_string()
            } else {
                s(v).to_owned()
            },
        ));
    }

    format!(
        "        [{}] = new({}, new global::System.Collections.Generic.Dictionary<string, string> {{ {} }}),",
        q(n),
        q(n),
        members
            .iter()
            .map(|(k, v)| format!("[{}] = {}", q(k), q(v)))
            .collect::<Vec<_>>()
            .join(", ")
    )
}

fn mutation(
    n: &str,
    kind: &str,
    en: &str,
    inputs: &[(String, String)],
    method: &str,
    description: &str,
) -> String {
    let rendered = if inputs.is_empty() {
        "new global::System.Collections.Generic.Dictionary<string, ".to_owned()
            + NS
            + ".GraphQLType>()"
    } else {
        format!(
            "new global::System.Collections.Generic.Dictionary<string, {NS}.GraphQLType>\n            {{\n{}\n            }}",
            inputs
                .iter()
                .map(|(k, v)| format!("                [{}] = {v},", q(k)))
                .collect::<Vec<_>>()
                .join("\n")
        )
    };

    format!(
        "        [{}] = new(\n            {},\n            {NS}.MutationKind.{kind},\n            {},\n            {rendered},\n            {method},\n            {description}),",
        q(n),
        q(n),
        q(en)
    )
}

pub fn generate(
    schema: &Value,
    namespace: &str,
    entity_ns: &str,
) -> std::result::Result<String, Vec<String>> {
    let problems = validation::validate(schema);

    if !problems.is_empty() {
        return Err(problems);
    }

    let mut objects = BTreeMap::new();
    let mut enums = BTreeMap::new();
    let mut mutations = BTreeMap::new();
    let mut roots = BTreeMap::new();
    let mut queries = BTreeMap::new();
    let entities = vals(&schema["entities"]);

    for t in vals(&schema["types"]) {
        if !t["values"].is_null() {
            let n = s(&t["name"]);

            enums.insert(
                n.to_owned(),
                enumeration(n, &t["values"], t["primitive"] == "int"),
            );
        }
    }

    for e in &entities {
        if !exposure(e) {
            continue;
        }

        let en = s(&e["name"]);
        let n = name(e);
        let plural = plural(e);
        // The read model's type: the C# builder's entity type, in its namespace.
        let entity = format!("global::{entity_ns}.{}", pascal(en));

        roots.insert(
            n.to_owned(),
            format!(
                "        [{}] = new({}, {}, {}),",
                q(n),
                q(n),
                q(&plural),
                q(en)
            ),
        );

        let mut fields = vec![
            field(
                "id",
                &ty("ID", true, false),
                &format!("source => (({entity})source).Id"),
                &q("The globally unique identifier, opaque and safe to use as a cache key."),
                "GlobalId",
                "null",
            ),
            field(
                "databaseId",
                &ty("ID", true, false),
                &format!("source => (({entity})source).Id"),
                &q("The row as storage knows it, unique within its table rather than the schema."),
                "Id",
                "null",
            ),
        ];
        let mut connections = vec![];

        for f in vals(&e["fields"]) {
            let fnm = s(&f["name"]);
            let ft = field_name(schema, e, f);
            let enc = encoding(schema, f);

            fields.push(field(
                fnm,
                &ty(&ft, !b(&f["nullable"]), false),
                &read(schema, e, f, entity_ns, &entity),
                &nullable(&f["description"]),
                enc,
                &if enc == "Processor" {
                    q(&pascal(s(&f["type"]["declaredType"])))
                } else {
                    "null".into()
                },
            ));

            if f["type"]["primitive"] == "enum" && !f["enum"]["inlineValues"].is_null() {
                enums.insert(
                    ft.clone(),
                    enumeration(&ft, &f["enum"]["inlineValues"], false),
                );
            }
        }

        for edge in vals(&e["edges"]) {
            let target = &schema["entities"][s(&edge["to"])];

            if !exposure(target) {
                continue;
            }

            let ed = s(&edge["name"]);

            if edge["cardinality"] == "one" {
                fields.push(field_async(
                    ed,
                    &ty(name(target), false, false),
                    &entity,
                    &format!("Get{}Async", pascal(ed)),
                    &nullable(&edge["description"]),
                ));
            } else {
                connections.push(connection(
                    ed,
                    n,
                    name(target),
                    ed,
                    &entity,
                    &pascal(ed),
                    &nullable(&edge["description"]),
                ));
            }
        }

        for declaring in &entities {
            if !exposure(declaring) {
                continue;
            }

            for edge in vals(&declaring["edges"]) {
                if edge["to"] != e["name"] || edge["inverse"].is_null() {
                    continue;
                }

                let dn = s(&declaring["name"]);
                let ed = s(&edge["name"]);
                let inv = if b(&edge["inverse"]["derived"]) {
                    low(dn)
                } else {
                    s(&edge["inverse"]["name"]).into()
                };
                let description = q(&format!("The {dn} pointing here through \"{ed}\"."));

                if b(&edge["inverse"]["unique"]) {
                    fields.push(field_async(
                        &inv,
                        &ty(name(declaring), false, false),
                        &entity,
                        &format!("Get{}Async", pascal(&inv)),
                        &description,
                    ));
                } else {
                    connections.push(connection(
                        &inv,
                        n,
                        name(declaring),
                        ed,
                        &entity,
                        &pascal(&inv),
                        &description,
                    ));
                }
            }
        }

        objects.insert(
            n.to_owned(),
            format!(
                "        [{}] = new(\n            {},\n            {},\n            {},\n            source => source is {entity},\n            {},\n            {},\n            [\"Node\"]),",
                q(n),
                q(n),
                q(en),
                dictionary(&fields, "FieldEntry"),
                dictionary(&connections, "ConnectionEntry"),
                nullable(&e["description"])
            ),
        );

        let mut create = vec![];
        let mut update = vec![("id".to_owned(), ty("ID", true, false))];

        for f in vals(&e["fields"]) {
            if !f["managed"].is_null() {
                continue;
            }

            let ft = field_name(schema, e, f);

            create.push((
                s(&f["name"]).into(),
                ty(&ft, b(&f["required"]) && !b(&f["nullable"]), false),
            ));

            if !b(&f["immutable"]) {
                update.push((s(&f["name"]).into(), ty(&ft, false, false)));
            }
        }

        for edge in vals(&e["edges"]) {
            if !exposure(&schema["entities"][s(&edge["to"])]) {
                continue;
            }

            let t = ty("ID", false, edge["cardinality"] == "many");

            create.push((s(&edge["name"]).into(), t.clone()));
            update.push((s(&edge["name"]).into(), t));
        }

        for (kind, inputs) in [("Create", create), ("Update", update)] {
            let mn = format!("{}{n}", kind.to_ascii_lowercase());

            mutations.insert(
                mn.clone(),
                mutation(
                    &mn,
                    kind,
                    en,
                    &inputs,
                    "null",
                    &q(&format!("{kind} a {n}.")),
                ),
            );
        }

        for a in vals(&e["actions"]) {
            let an = s(&a["name"]);
            let mn = format!("{an}{n}");
            let mut inputs = vec![("id".to_owned(), ty("ID", true, false))];

            for arg in vals(&a["arguments"]) {
                inputs.push((
                    s(&arg["name"]).into(),
                    ty(
                        &reference(schema, &arg["type"]),
                        !b(&arg["nullable"]),
                        false,
                    ),
                ));
            }

            let desc = a["description"]
                .as_str()
                .map(str::to_owned)
                .unwrap_or(format!("Run {an} on a {n}."));

            mutations.insert(
                mn.clone(),
                mutation(&mn, "Action", en, &inputs, &q(an), &q(&desc)),
            );
        }

        for query in vals(&e["queries"]) {
            if !exposure(query) {
                continue;
            }

            let qn = s(&query["name"]);
            let target = &schema["entities"][s(&query["returns"]["type"])];

            let field = query["integrations"]["graphql"]["field"]
                .as_str()
                .unwrap_or(qn);
            let args = vals(&query["arguments"])
                .iter()
                .map(|a| {
                    format!(
                        "[{}] = {}",
                        q(s(&a["name"])),
                        ty(&reference(schema, &a["type"]), !b(&a["nullable"]), false)
                    )
                })
                .collect::<Vec<_>>()
                .join(", ");

            queries.insert(
                field.to_owned(),
                format!(
                    "        [{}] = new(\n            {},\n            {},\n            {},\n            {},\n            {},\n            new global::System.Collections.Generic.Dictionary<string, {NS}.GraphQLType> {{ {args} }},\n            {}),",
                    q(field),
                    q(field),
                    q(name(target)),
                    query["returns"]["cardinality"] == "many",
                    q(en),
                    q(qn),
                    nullable(&query["description"])
                ),
            );
        }
    }

    let mut body = format!(
        "// <auto-generated/>\n#nullable enable\n#pragma warning disable CS1591\n\nnamespace {namespace};\n\n/// <summary>\n/// What <c>Elephentity.GraphQL</c> assembles into this project's schema: the same types, fields, connections, mutations and\n/// published queries as the PHP stack builds from the same spec.\n/// </summary>\npublic static class GraphQLManifestFactory\n{{\n    /// <summary>The manifest.</summary>\n    public static {NS}.GraphQLManifest Create() => new(\n"
    );

    for (n, value_type, map) in [
        ("objects", "ObjectTypeEntry", objects),
        ("enums", "EnumTypeEntry", enums),
        ("mutations", "MutationEntry", mutations),
        ("roots", "RootFieldEntry", roots),
        ("queries", "QueryFieldEntry", queries),
    ] {
        body.push_str(&format!(
            "    {n}: new global::System.Collections.Generic.Dictionary<string, {NS}.{value_type}>\n    {{\n{}\n    }}{}\n",
            map.into_values().collect::<Vec<_>>().join("\n"),
            if n == "queries" { ");" } else { "," }
        ));
    }

    body.push_str("}\n");

    Ok(body)
}
