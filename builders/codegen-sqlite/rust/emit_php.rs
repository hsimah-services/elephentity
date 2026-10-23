//! The PHP side: `storage-manifest.php`, which `Eleph\SQLite\Manifest\StorageManifest` is built from, and the `install.php`
//! closure that creates the initial schema.

use crate::q;
use crate::storage::{Model, Part, Table};

fn table_expression(t: &Table) -> String {
    let columns = t
        .columns
        .iter()
        .map(|c| {
            format!(
                "                {} => new Column({}, {}, {}, {}, {}),",
                q(&c.name),
                q(&c.name),
                q(&c.ty),
                c.nullable,
                c.identity,
                c.default.as_deref().map(q).unwrap_or("null".into())
            )
        })
        .collect::<Vec<_>>()
        .join("\n");
    let indexes = t
        .indexes
        .iter()
        .map(|i| {
            format!(
                "                {} => new Index({}, [{}], {}),",
                q(&i.name),
                q(&i.name),
                i.columns
                    .iter()
                    .map(|c| q(c))
                    .collect::<Vec<_>>()
                    .join(", "),
                i.unique
            )
        })
        .collect::<Vec<_>>()
        .join("\n");
    format!("new TableSchema(\n            {},\n            [\n{columns}\n            ],\n            [\n{indexes}\n            ],\n            {},\n        )", q(&t.name), q(&t.primary))
}

fn pairs(entries: &[(String, String)]) -> String {
    entries
        .iter()
        .map(|(key, value)| format!("        {} => {value},", q(key)))
        .collect::<Vec<_>>()
        .join("\n")
}

/// `(manifest, installer)`.
pub fn render(model: &Model) -> (String, String) {
    let mut manifest="namespace Eleph\\SQLite\\Manifest;\n\nuse Eleph\\SQLite\\Sql\\{Column, EdgePlacement, Index, TableSchema};\nuse Eleph\\Runtime\\Storage\\RelationKind;\n\nreturn new StorageManifest(\n".to_owned();
    let entity_tables = model
        .entities
        .iter()
        .map(|e| (e.name.clone(), table_expression(&model.tables[&e.table])))
        .collect::<Vec<_>>();
    let columns = model
        .entities
        .iter()
        .map(|e| {
            (
                e.name.clone(),
                format!(
                    "[{}]",
                    e.fields
                        .iter()
                        .map(|(field, column)| format!("{} => {}", q(field), q(column)))
                        .collect::<Vec<_>>()
                        .join(", ")
                ),
            )
        })
        .collect::<Vec<_>>();
    let placement_exprs = model
        .placements
        .iter()
        .map(|p| {
            (
                format!("{}.{}", p.entity, p.edge),
                format!(
                    "new EdgePlacement({}, {}, {}, RelationKind::{}, {}, {}, {}, {})",
                    q(&p.entity),
                    q(&p.edge),
                    q(&p.target),
                    p.relation,
                    q(&p.table),
                    q(&p.local),
                    p.remote.as_ref().map(|s| q(s)).unwrap_or("null".into()),
                    q(&p.target_table)
                ),
            )
        })
        .collect::<Vec<_>>();
    let joins = model
        .join_tables
        .iter()
        .map(|name| (name.clone(), table_expression(&model.tables[name])))
        .collect::<Vec<_>>();
    for (name, entries) in [
        ("tables", entity_tables),
        ("placements", placement_exprs),
        ("columns", columns),
        ("joinTables", joins),
    ] {
        manifest.push_str(&format!("    {name}: [\n{}\n    ],\n", pairs(&entries)));
    }
    manifest.push_str(");\n");
    let mut installer = include_str!("install-header.php").to_owned();
    for statement in model.install() {
        installer.push_str(&format!(
            "        {},\n",
            statement
                .iter()
                .map(|part| match part {
                    Part::Sql(text) => q(text),
                    Part::Name(name) => format!("$table({})", q(name)),
                })
                .collect::<Vec<_>>()
                .join(" . ")
        ));
    }
    installer.push_str(include_str!("install-footer.php"));
    (manifest, installer)
}
