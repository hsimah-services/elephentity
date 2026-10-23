use super::*;
use std::collections::{BTreeMap, BTreeSet};

pub struct Column {
    pub name: String,
    pub ty: String,
    pub nullable: bool,
    pub identity: bool,
    pub default: Option<String>,
    pub checks: Vec<String>,
    pub reference: Option<String>,
}
pub struct Index {
    pub name: String,
    pub columns: Vec<String>,
    pub unique: bool,
}
pub struct Table {
    pub name: String,
    pub columns: Vec<Column>,
    pub indexes: Vec<Index>,
    pub primary: String,
}
pub struct Placement {
    pub entity: String,
    pub edge: String,
    pub target: String,
    pub relation: &'static str,
    pub table: String,
    pub local: String,
    pub remote: Option<String>,
    pub target_table: String,
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
        default: None,
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
    /// The statements that create this table and its indexes, as literal SQL and the names a deployment prefix applies to.
    pub fn statements(&self) -> Vec<Vec<Part>> {
        let mut create = vec![
            Part::Sql("CREATE TABLE ".into()),
            Part::Name(self.name.clone()),
            Part::Sql(" (\n    ".into()),
        ];
        for (position, c) in self.columns.iter().enumerate() {
            if position > 0 {
                create.push(Part::Sql(",\n    ".into()));
            }
            let mut sql = format!("{} {}", sql_name(&c.name), c.ty);
            if c.identity {
                sql.push_str(" PRIMARY KEY AUTOINCREMENT");
            } else if !c.nullable {
                sql.push_str(" NOT NULL");
            }
            if let Some(default) = &c.default {
                sql.push_str(&format!(" DEFAULT {default}"));
            }
            for check in &c.checks {
                sql.push_str(&format!(" CHECK ({check})"));
            }
            create.push(Part::Sql(sql));
            if let Some(reference) = &c.reference {
                create.push(Part::Sql(" REFERENCES ".into()));
                create.push(Part::Name(reference.clone()));
                create.push(Part::Sql("(\"id\") ON DELETE RESTRICT".into()));
            }
        }
        create.push(Part::Sql("\n)".into()));
        let mut statements = vec![create];
        for index in &self.indexes {
            statements.push(vec![
                Part::Sql(
                    if index.unique {
                        "CREATE UNIQUE INDEX "
                    } else {
                        "CREATE INDEX "
                    }
                    .into(),
                ),
                Part::Name(index.name.clone()),
                Part::Sql(" ON ".into()),
                Part::Name(self.name.clone()),
                Part::Sql(format!(
                    " ({})",
                    index
                        .columns
                        .iter()
                        .map(|c| sql_name(c))
                        .collect::<Vec<_>>()
                        .join(", ")
                )),
            ]);
        }
        statements
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
    if b(&f["hasDefault"]) && !f["default"].is_null() {
        c.default = Some(default(
            &name,
            primitive,
            integer_enum,
            values,
            &f["default"],
        )?);
    }
    Ok(c)
}
/// The column's DEFAULT clause. Generated input seeds a default on create too, but a
/// row written by anything else — or by input that predates the default — still needs
/// the column to supply one, or a NOT NULL field without a value cannot be inserted.
fn default(
    name: &str,
    primitive: &str,
    integer_enum: bool,
    values: &Value,
    value: &Value,
) -> Result<String> {
    let invalid = || format!("Field {name} has a default that does not fit a {primitive} column.");
    if let Some(values) = values.as_array() {
        let label = value.as_str().ok_or_else(invalid)?;
        let position = values
            .iter()
            .position(|v| v.as_str() == Some(label))
            .ok_or_else(|| {
                format!("Field {name} defaults to {label:?}, which is not one of its values.")
            })?;
        return Ok(if integer_enum {
            position.to_string()
        } else {
            sql_string(label)
        });
    }
    Ok(match primitive {
        "bool" => if value.as_bool().ok_or_else(invalid)? {
            "1"
        } else {
            "0"
        }
        .into(),
        "int" | "id" => value.as_i64().ok_or_else(invalid)?.to_string(),
        "float" if value.is_number() => value.to_string(),
        "json" => sql_string(&value.to_string()),
        "string" | "text" | "datetime" => sql_string(value.as_str().ok_or_else(invalid)?),
        _ => return Err(invalid()),
    })
}
/// A piece of an installer statement.
pub enum Part {
    /// Literal SQL, written as it is.
    Sql(String),
    /// A table or index name, which a deployment prefix goes in front of.
    Name(String),
}

/// One entity: the table its rows live in, and the column each field is stored in.
pub struct EntityModel {
    pub name: String,
    pub table: String,
    pub fields: Vec<(String, String)>,
}

/// The physical schema of a project, before it is written in any language.
pub struct Model {
    /// Every table, entity and join alike, by name.
    pub tables: BTreeMap<String, Table>,
    pub entities: Vec<EntityModel>,
    pub placements: Vec<Placement>,
    /// The names of the many-to-many link tables.
    pub join_tables: Vec<String>,
}

impl Model {
    /// The statements that create the initial schema, in order.
    pub fn install(&self) -> Vec<Vec<Part>> {
        self.tables.values().flat_map(Table::statements).collect()
    }
}

pub fn generate(schema: &Value) -> Result<Model> {
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
    let claimed = entities
        .iter()
        .map(|e| s(&e["storage"]["table"]))
        .collect::<Vec<_>>();
    let join_tables = tables
        .keys()
        .filter(|name| !claimed.contains(&name.as_str()))
        .cloned()
        .collect();
    let entity_models = entities
        .iter()
        .map(|e| EntityModel {
            name: s(&e["name"]).to_owned(),
            table: s(&e["storage"]["table"]).to_owned(),
            fields: vals(&e["fields"])
                .iter()
                .map(|f| (s(&f["name"]).to_owned(), snake(s(&f["name"]))))
                .collect(),
        })
        .collect();
    Ok(Model {
        tables,
        entities: entity_models,
        placements,
        join_tables,
    })
}
