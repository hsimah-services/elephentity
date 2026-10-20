use super::*;
use std::collections::BTreeMap;
fn ty(n: &str, nonnull: bool, list: bool) -> String {
    format!("new GraphQLType({}, {nonnull}, {list})", q(n))
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
            return "BackedEnum";
        }
        if b(&t["hasProcessors"]) {
            return "Processor";
        }
        p = s(&t["primitive"]);
    }
    match p {
        "datetime" => "Datetime",
        "enum" => "BackedEnum",
        "json" => "Json",
        _ => "Value",
    }
}
fn field(
    n: &str,
    t: &str,
    accessor: &str,
    description: &str,
    encoding: &str,
    value: &str,
) -> String {
    format!("                {} => new FieldEntry({}, {t}, {}, {description}, FieldEncoding::{encoding}, {value}),",q(n),q(n),q(accessor))
}
fn connection(n: &str, from: &str, to: &str, edge: &str, description: &str) -> String {
    format!(
        "                {} => new ConnectionEntry({}, {}, {}, {}, {}, {description}),",
        q(n),
        q(n),
        q(from),
        q(to),
        q(n),
        q(edge)
    )
}
fn block(lines: &[String]) -> String {
    if lines.is_empty() {
        "[]".into()
    } else {
        format!("[\n{}\n            ]", lines.join("\n"))
    }
}
fn enumeration(n: &str, values: &Value) -> String {
    let mut members = serde_json::Map::new();
    for v in list(values) {
        members.insert(s(v).to_ascii_uppercase(), v.clone());
    }
    format!(
        "        {} => new EnumTypeEntry({}, [{}]),",
        q(n),
        q(n),
        members
            .iter()
            .map(|(k, v)| format!("{} => {}", q(k), q(s(v))))
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
    let inputs = inputs
        .iter()
        .map(|(k, v)| format!("{} => {v}", q(k)))
        .collect::<Vec<_>>()
        .join(",\n                ");
    let rendered = if inputs.is_empty() {
        "[]".to_owned()
    } else {
        format!("[\n                {inputs},\n            ]")
    };
    format!("        {} => new MutationEntry(\n            {},\n            {},\n            {},\n            {rendered},\n            {method},\n            {description},\n        ),",q(n),q(n),q(kind),q(en))
}
pub fn generate(schema: &Value) -> Result<String> {
    validation::validate(schema)?;
    let mut objects = BTreeMap::new();
    let mut enums = BTreeMap::new();
    let mut mutations = BTreeMap::new();
    let mut roots = BTreeMap::new();
    let mut queries = BTreeMap::new();
    let entities = vals(&schema["entities"]);
    for t in vals(&schema["types"]) {
        if !t["values"].is_null() {
            let n = s(&t["name"]);
            enums.insert(n.to_owned(), enumeration(n, &t["values"]));
        }
    }
    for e in &entities {
        if !exposure(e) {
            continue;
        }
        let en = s(&e["name"]);
        let n = name(e);
        let plural = plural(e);
        roots.insert(
            n.to_owned(),
            format!(
                "        {} => new RootFieldEntry({}, {}, {}),",
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
                "getId",
                &q("The globally unique identifier, opaque and safe to use as a cache key."),
                "GlobalId",
                "null",
            ),
            field(
                "databaseId",
                &ty("ID", true, false),
                "getId",
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
                &format!("get{}", cap(fnm)),
                &nullable(&f["description"]),
                enc,
                &if enc == "Processor" {
                    q(s(&f["type"]["declaredType"]))
                } else {
                    "null".into()
                },
            ));
            if f["type"]["primitive"] == "enum" && !f["enum"]["inlineValues"].is_null() {
                enums.insert(ft.clone(), enumeration(&ft, &f["enum"]["inlineValues"]));
            }
        }
        for edge in vals(&e["edges"]) {
            let target = &schema["entities"][s(&edge["to"])];
            if !exposure(target) {
                continue;
            }
            let ed = s(&edge["name"]);
            if edge["cardinality"] == "one" {
                fields.push(field(
                    ed,
                    &ty(name(target), false, false),
                    &format!("get{}", cap(ed)),
                    &nullable(&edge["description"]),
                    "Value",
                    "null",
                ));
            } else {
                connections.push(connection(
                    ed,
                    n,
                    name(target),
                    ed,
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
                    fields.push(field(
                        &inv,
                        &ty(name(declaring), false, false),
                        &format!("get{}", cap(&inv)),
                        &description,
                        "Value",
                        "null",
                    ));
                } else {
                    connections.push(connection(&inv, n, name(declaring), ed, &description));
                }
            }
        }
        objects.insert(n.to_owned(),format!("        {} => new ObjectTypeEntry(\n            {},\n            {},\n            {},\n            {},\n            {},\n            ['Node'],\n        ),",q(n),q(n),q(en),block(&fields),block(&connections),nullable(&e["description"])));
        let mut create = vec![];
        let mut update = vec![("id".into(), ty("ID", true, false))];
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
        for (kind, inputs) in [("create", create), ("update", update)] {
            let mn = format!("{kind}{n}");
            mutations.insert(
                mn.clone(),
                mutation(
                    &mn,
                    kind,
                    en,
                    &inputs,
                    "null",
                    &q(&format!("{} a {n}.", cap(kind))),
                ),
            );
        }
        for a in vals(&e["actions"]) {
            let an = s(&a["name"]);
            let mn = format!("{an}{n}");
            let mut inputs = vec![("id".into(), ty("ID", true, false))];
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
                mutation(&mn, "action", en, &inputs, &q(an), &q(&desc)),
            );
        }
        for query in vals(&e["queries"]) {
            if !exposure(query) {
                continue;
            }
            let qn = s(&query["name"]);
            let target = &schema["entities"][s(&query["returns"]["type"])];
            if !exposure(target) {
                return Err(format!("{en}::{qn} is published to GraphQL but returns {}, which is not exposed. Expose it, or stop publishing the query.",s(&query["returns"]["type"])));
            }
            let field = query["integrations"]["graphql"]["field"]
                .as_str()
                .unwrap_or(qn);
            let args = vals(&query["arguments"])
                .iter()
                .map(|a| {
                    format!(
                        "{} => {}",
                        q(s(&a["name"])),
                        ty(&reference(schema, &a["type"]), !b(&a["nullable"]), false)
                    )
                })
                .collect::<Vec<_>>()
                .join(", ");
            queries.insert(field.to_owned(),format!("        {} => new QueryFieldEntry(\n            {},\n            {},\n            {},\n            {},\n            {},\n            [{args}],\n            {},\n        ),",q(field),q(field),q(name(target)),query["returns"]["cardinality"]=="many",q(en),q(qn),nullable(&query["description"])));
        }
    }
    let mut body = format!(
        "namespace Eleph\\GraphQL\\Manifest;\n\n{}return new Manifest(\n",
        include_str!("manifest-header.txt")
    );
    for (n, map) in [
        ("objects", objects),
        ("enums", enums),
        ("mutations", mutations),
        ("roots", roots),
        ("queries", queries),
    ] {
        body.push_str(&format!(
            "    {n}: [\n{}\n    ],\n",
            map.into_values().collect::<Vec<_>>().join("\n")
        ));
    }
    body.push_str(");\n");
    Ok(body)
}
