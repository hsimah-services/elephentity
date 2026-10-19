use super::*;
use std::collections::{BTreeMap, BTreeSet};

struct Column {
    name: String,
    ty: String,
    nullable: bool,
    identity: bool,
    checks: Vec<String>,
    reference: Option<String>,
}
struct Index {
    name: String,
    columns: Vec<String>,
    unique: bool,
}
struct Table {
    name: String,
    columns: Vec<Column>,
    indexes: Vec<Index>,
    primary: String,
}
struct Placement {
    entity: String,
    edge: String,
    target: String,
    relation: &'static str,
    table: String,
    local: String,
    remote: Option<String>,
    target_table: String,
}
fn identifier(name: &str) -> Result<()> {
    let mut chars = name.chars();
    if !chars
        .next()
        .is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
        || !chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
        || name.to_ascii_lowercase().starts_with("sqlite_")
    {
        return Err(format!("Invalid SQLite identifier {name:?}."));
    }
    Ok(())
}
fn sql_name(name: &str) -> String {
    format!("\"{name}\"")
}
fn sql_string(value: &str) -> String {
    format!("'{}'", value.replace('\'', "''"))
}
fn column(name: &str, ty: &str, nullable: bool) -> Column {
    Column {
        name: name.into(),
        ty: ty.into(),
        nullable,
        identity: false,
        checks: vec![],
        reference: None,
    }
}
impl Table {
    fn add(&mut self, c: Column) -> Result<()> {
        identifier(&c.name)?;
        if self
            .columns
            .iter()
            .any(|old| old.name.eq_ignore_ascii_case(&c.name))
        {
            return Err(format!(
                "Column {}.{} is claimed more than once.",
                self.name, c.name
            ));
        }
        self.columns.push(c);
        Ok(())
    }
    fn index(&mut self, key: &str, columns: Vec<String>, unique: bool) {
        self.indexes.push(Index {
            name: format!(
                "{}_{}_{}",
                self.name,
                key,
                if unique { "uniq" } else { "idx" }
            ),
            columns,
            unique,
        });
    }
    fn expr(&self) -> String {
        let columns = self
            .columns
            .iter()
            .map(|c| {
                format!(
                    "                {} => new Column({}, {}, {}, {}, null),",
                    q(&c.name),
                    q(&c.name),
                    q(&c.ty),
                    c.nullable,
                    c.identity
                )
            })
            .collect::<Vec<_>>()
            .join("\n");
        let indexes = self
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
        format!("new TableSchema(\n            {},\n            [\n{columns}\n            ],\n            [\n{indexes}\n            ],\n            {},\n        )", q(&self.name), q(&self.primary))
    }
}
fn field(schema: &Value, f: &Value) -> Result<Column> {
    let name = snake(s(&f["name"]));
    let declared = &schema["types"][s(&f["type"]["declaredType"])];
    let mut primitive = f["type"]["primitive"]
        .as_str()
        .unwrap_or(s(&declared["primitive"]));
    let enum_type = if !declared["values"].is_null() {
        declared
    } else {
        &schema["types"][s(&f["enum"]["declaredType"])]
    };
    let integer_enum = !enum_type["values"].is_null() && enum_type["primitive"] == "int";
    if integer_enum {
        primitive = "int";
    }
    let ty = match primitive {
        "string" | "text" | "datetime" | "enum" | "json" => "TEXT",
        "int" | "bool" | "id" => "INTEGER",
        "float" => "REAL",
        _ => return Err(format!("Unknown field type for {}.", s(&f["name"]))),
    };
    let mut c = column(&name, ty, b(&f["nullable"]));
    if primitive == "bool" {
        c.checks.push(format!("{} IN (0, 1)", sql_name(&name)));
    }
    if let Some(length) = f["maxLength"].as_i64() {
        if length < 1 {
            return Err(format!("Field {name} has an invalid maxLength."));
        }
        c.checks
            .push(format!("length({}) <= {length}", sql_name(&name)));
    }
    let values = if !declared["values"].is_null() {
        &declared["values"]
    } else if !f["enum"]["inlineValues"].is_null() {
        &f["enum"]["inlineValues"]
    } else {
        &schema["types"][s(&f["enum"]["declaredType"])]["values"]
    };
    if let Some(values) = values.as_array() {
        if values.is_empty() {
            return Err(format!("Enum field {name} has no values."));
        }
        c.checks.push(format!(
            "{} IN ({})",
            sql_name(&name),
            values
                .iter()
                .enumerate()
                .map(|(i, v)| if integer_enum {
                    i.to_string()
                } else {
                    sql_string(s(v))
                })
                .collect::<Vec<_>>()
                .join(", ")
        ));
    } else if primitive == "enum" {
        return Err(format!("Enum field {name} has no definition."));
    }
    Ok(c)
}
fn pairs(entries: &[(String, String)]) -> String {
    entries
        .iter()
        .map(|(key, value)| format!("        {} => {value},", q(key)))
        .collect::<Vec<_>>()
        .join("\n")
}
pub fn generate(schema: &Value) -> Result<(String, String)> {
    let entities = vals(&schema["entities"]);
    let mut tables: BTreeMap<String, Table> = BTreeMap::new();
    let mut names = BTreeSet::new();
    let mut placements = vec![];
    for e in &entities {
        let name = s(&e["storage"]["table"]);
        identifier(name)?;
        if e["storage"]["driver"] != "sqlite" {
            return Err(format!("Entity {} does not use sqlite.", s(&e["name"])));
        }
        if b(&e["config"]["taxonomy"]) || b(&e["config"]["account"]) {
            return Err(format!(
                "Entity {} requests platform-owned storage; SQLite stores ordinary entities only.",
                s(&e["name"])
            ));
        }
        if !names.insert(name.to_ascii_lowercase()) {
            return Err(format!("Table {name} is claimed more than once."));
        }
        let mut id = column("id", "INTEGER", false);
        id.identity = true;
        let mut table = Table {
            name: name.into(),
            columns: vec![id],
            indexes: vec![],
            primary: "id".into(),
        };
        for f in vals(&e["fields"]) {
            let c = field(schema, f)?;
            let cn = c.name.clone();
            table.add(c)?;
            if b(&f["unique"]) || b(&f["indexed"]) {
                table.index(&cn, vec![cn.clone()], b(&f["unique"]));
            }
        }
        tables.insert(name.into(), table);
    }
    for e in &entities {
        let en = s(&e["name"]);
        let table = s(&e["storage"]["table"]);
        for edge in vals(&e["edges"]) {
            let target = &schema["entities"][s(&edge["to"])];
            if target.is_null() {
                return Err(format!(
                    "Edge {en}.{} has an unknown target.",
                    s(&edge["name"])
                ));
            }
            let to = s(&target["name"]);
            let tt = s(&target["storage"]["table"]);
            let name = s(&edge["name"]);
            let one = edge["cardinality"] == "one";
            let unique = b(&edge["inverse"]["unique"]);
            let relation = match (one, unique) {
                (true, true) => "OneToOne",
                (true, false) => "ManyToOne",
                (false, true) => "OneToMany",
                (false, false) => "ManyToMany",
            };
            let (pt, local, remote) = if relation == "ManyToMany" {
                (
                    format!("{table}_{}", snake(name)),
                    format!("{}_id", snake(&low(en))),
                    Some(format!(
                        "{}_id",
                        snake(&low(if en == to { name } else { to }))
                    )),
                )
            } else if one {
                (table.into(), format!("{}_id", snake(name)), None)
            } else {
                let inverse = if b(&edge["inverse"]["derived"]) || edge["inverse"].is_null() {
                    low(en)
                } else {
                    s(&edge["inverse"]["name"]).into()
                };
                (tt.into(), format!("{}_id", snake(&inverse)), None)
            };
            identifier(&pt)?;
            identifier(&local)?;
            if let Some(right) = &remote {
                identifier(right)?;
                if local.eq_ignore_ascii_case(right) {
                    return Err(format!("Edge {en}.{name} maps both join keys to {local}."));
                }
                if !names.insert(pt.to_ascii_lowercase()) {
                    return Err(format!("Join table {pt} collides with another table."));
                }
                let mut left = column(&local, "INTEGER", false);
                left.reference = Some(table.into());
                let mut right_column = column(right, "INTEGER", false);
                right_column.reference = Some(tt.into());
                let mut join = Table {
                    name: pt.clone(),
                    columns: vec![left, right_column],
                    indexes: vec![],
                    primary: String::new(),
                };
                join.index("pair", vec![local.clone(), right.clone()], true);
                join.index(right, vec![right.clone()], false);
                tables.insert(pt.clone(), join);
            } else {
                let mut fk = column(&local, "INTEGER", true);
                fk.reference = Some(if one { tt } else { table }.into());
                let physical = tables
                    .get_mut(&pt)
                    .ok_or_else(|| format!("Missing edge table {pt}."))?;
                physical.add(fk)?;
                physical.index(&local, vec![local.clone()], relation == "OneToOne");
            }
            placements.push(Placement {
                entity: en.into(),
                edge: name.into(),
                target: to.into(),
                relation,
                table: pt,
                local,
                remote,
                target_table: tt.into(),
            });
        }
    }
    // SQLite index names are database-wide, and share a namespace with tables.
    for table in tables.values() {
        for index in &table.indexes {
            identifier(&index.name)?;
            if !names.insert(index.name.to_ascii_lowercase()) {
                return Err(format!(
                    "Index {} collides with another schema object.",
                    index.name
                ));
            }
        }
    }
    let mut manifest="namespace Eleph\\SQLite\\Manifest;\n\nuse Eleph\\SQLite\\Sql\\{Column, EdgePlacement, Index, TableSchema};\nuse Eleph\\Runtime\\Storage\\RelationKind;\n\nreturn new StorageManifest(\n".to_owned();
    let entity_tables = entities
        .iter()
        .map(|e| {
            (
                s(&e["name"]).to_owned(),
                tables[s(&e["storage"]["table"])].expr(),
            )
        })
        .collect::<Vec<_>>();
    let columns = entities
        .iter()
        .map(|e| {
            (
                s(&e["name"]).to_owned(),
                format!(
                    "[{}]",
                    vals(&e["fields"])
                        .iter()
                        .map(|f| format!("{} => {}", q(s(&f["name"])), q(&snake(s(&f["name"])))))
                        .collect::<Vec<_>>()
                        .join(", ")
                ),
            )
        })
        .collect::<Vec<_>>();
    let placement_exprs = placements
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
    let claimed = entities
        .iter()
        .map(|e| s(&e["storage"]["table"]))
        .collect::<Vec<_>>();
    let joins = tables
        .iter()
        .filter(|(name, _)| !claimed.contains(&name.as_str()))
        .map(|(name, t)| (name.clone(), t.expr()))
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
    for table in tables.values() {
        let definitions = table
            .columns
            .iter()
            .map(|c| {
                let mut sql = format!("{} {}", sql_name(&c.name), c.ty);
                if c.identity {
                    sql.push_str(" PRIMARY KEY AUTOINCREMENT");
                } else if !c.nullable {
                    sql.push_str(" NOT NULL");
                }
                for check in &c.checks {
                    sql.push_str(&format!(" CHECK ({check})"));
                }
                let mut expression = q(&sql);
                if let Some(reference) = &c.reference {
                    expression.push_str(&format!(
                        " . ' REFERENCES ' . $table({}) . {}",
                        q(reference),
                        q("(\"id\") ON DELETE RESTRICT")
                    ));
                }
                expression
            })
            .collect::<Vec<_>>()
            .join(&format!(" . {} . ", q(",\n    ")));
        installer.push_str(&format!(
            "        'CREATE TABLE ' . $table({}) . {} . {definitions} . {},\n",
            q(&table.name),
            q(" (\n    "),
            q("\n)")
        ));
        for index in &table.indexes {
            installer.push_str(&format!(
                "        {} . $table({}) . ' ON ' . $table({}) . {},\n",
                q(if index.unique {
                    "CREATE UNIQUE INDEX "
                } else {
                    "CREATE INDEX "
                }),
                q(&index.name),
                q(&table.name),
                q(&format!(
                    " ({})",
                    index
                        .columns
                        .iter()
                        .map(|c| sql_name(c))
                        .collect::<Vec<_>>()
                        .join(", ")
                ))
            ));
        }
    }
    installer.push_str(include_str!("install-footer.php"));
    Ok((manifest, installer))
}
