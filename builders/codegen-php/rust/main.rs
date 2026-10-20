mod catalogue;
mod contracts;
mod ir;
mod models;
mod runtime;
mod services;
mod source;
use runtime::*;
use serde_json::{json, Value};
use source::*;
use std::io::{self, Read};
type Result<T> = std::result::Result<T, String>;
fn s(v: &Value) -> &str {
    v.as_str().unwrap_or("")
}
fn b(v: &Value) -> bool {
    v.as_bool().unwrap_or(false)
}
fn vals(v: &Value) -> Vec<&Value> {
    v.as_object()
        .map(|m| m.values().collect())
        .unwrap_or_default()
}
fn list(v: &Value) -> Vec<&Value> {
    v.as_array().map(|a| a.iter().collect()).unwrap_or_default()
}
fn cap(s: &str) -> String {
    let mut c = s.chars();
    c.next()
        .map(|f| f.to_uppercase().collect::<String>() + c.as_str())
        .unwrap_or_default()
}
fn low(s: &str) -> String {
    let mut c = s.chars();
    c.next()
        .map(|f| f.to_lowercase().collect::<String>() + c.as_str())
        .unwrap_or_default()
}
fn quote(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}
fn scalar(ty: &str) -> bool {
    ["string", "int", "float", "bool", "array"].contains(&ty)
}
struct Gen<'a> {
    schema: &'a Value,
    root: String,
    types: String,
}
impl Gen<'_> {
    fn name(&self, e: &Value, suffix: &str) -> String {
        format!(
            "{}\\{}\\{}{suffix}",
            self.root,
            s(&e["name"]),
            s(&e["name"])
        )
    }
    fn contract(&self, e: &Value, suffix: &str) -> String {
        format!(
            "{}\\{}\\Contract\\{}{suffix}",
            self.root,
            s(&e["name"]),
            s(&e["name"])
        )
    }
    fn pattern(&self, name: &str, suffix: &str) -> String {
        format!("{}\\Pattern\\{name}\\{name}{suffix}", self.root)
    }
    fn entity(&self, name: &str) -> &Value {
        &self.schema["entities"][name]
    }
    fn reference(&self, r: &Value) -> Result<String> {
        Ok(match s(&r["primitive"]) {
            "string" | "text" => "string".into(),
            "int" => "int".into(),
            "float" => "float".into(),
            "bool" => "bool".into(),
            "json" => "array".into(),
            "datetime" => "DateTimeImmutable".into(),
            "id" => ENTITY_ID.into(),
            "enum" => {
                return Err(
                    "Enums are resolved from their field, not from the primitive alone.".into(),
                )
            }
            _ => {
                let n = s(&r["declaredType"]);
                let t = &self.schema["types"][n];
                if t.is_null() {
                    return Err(format!(
                        "Unknown type \"{n}\" reached codegen; validation should have caught it."
                    ));
                }
                if t["values"].is_null() {
                    format!("{}\\{n}", self.types)
                } else {
                    format!("{}\\Enum\\{n}", self.root)
                }
            }
        })
    }
    fn field_type(&self, e: &Value, f: &Value) -> Result<String> {
        if f["type"]["primitive"] == "enum" {
            let en = &f["enum"];
            if en.is_null() {
                return Err(format!("Enum field {}.{} reached codegen without values; validation should have caught it.", s(&e["name"]), s(&f["name"])));
            }
            return Ok(format!(
                "{}\\Enum\\{}",
                self.root,
                if !en["inlineValues"].is_null() {
                    format!("{}{}", s(&e["name"]), cap(s(&f["name"])))
                } else {
                    s(&en["declaredType"]).into()
                }
            ));
        }
        self.reference(&f["type"])
    }
    fn inverses(&self, e: &Value) -> Vec<(&Value, &Value, String)> {
        let mut r = vec![];
        for declaring in vals(&self.schema["entities"]) {
            for edge in vals(&declaring["edges"]) {
                if edge["to"] == e["name"] && !edge["inverse"].is_null() {
                    let name = if b(&edge["inverse"]["derived"]) {
                        low(s(&declaring["name"]))
                    } else {
                        s(&edge["inverse"]["name"]).into()
                    };
                    r.push((declaring, edge, name));
                }
            }
        }
        r
    }
    fn has_edges(&self, e: &Value) -> bool {
        !vals(&e["edges"]).is_empty() || !self.inverses(e).is_empty()
    }
    fn args(
        &self,
        out: &mut Source,
        args: &Value,
        m: &mut php_codegen::method::Method,
    ) -> Result<()> {
        for a in vals(args) {
            let ty = out.import(&self.reference(&a["type"])?);
            let mut p = param(s(&a["name"]), &ty, b(&a["nullable"]));
            if b(&a["nullable"]) {
                p = p.default(php_codegen::literal::Value::Null);
            }
            m.parameters.push(p);
        }
        Ok(())
    }
}
fn response(files: Vec<Value>, errors: Vec<String>) -> Value {
    json!({"elephentity":1,"irVersion":"1.2","headerStyle":"php","extensions":if errors.is_empty() {vec!["php"]} else {vec![]},"files":files,"errors":errors})
}
fn run(v: &Value) -> Result<Value> {
    let kind = match v.get("request") {
        None | Some(Value::Null) => "generate",
        Some(Value::String(s)) => s,
        Some(_) => {
            return Err("Unknown request. This build answers \"generate\" and \"describe\".".into())
        }
    };
    if !["generate", "describe"].contains(&kind) {
        return Err(format!(
            "Unknown request \"{kind}\". This build answers \"generate\" and \"describe\"."
        ));
    }
    if v["elephentity"].as_u64() != Some(1) {
        return Err("Protocol version mismatch: this build speaks 1.".into());
    }
    if v["irVersion"] != "1.2" {
        return Err("IR version mismatch: this build emits 1.2.".into());
    }
    if kind == "describe" {
        // `check` is how this builder's tree is checked. The orchestrator passes `provides` through
        // untouched; `eleph check` fills in {project} and {output} and runs the command, finding
        // `eleph-check-php` in the nearest vendor/bin like any Composer-installed program.
        return Ok(json!({"elephentity":1,"irVersion":"1.2","provides":{
            "check": {"command": ["eleph-check-php", "--project", "{project}", "--output", "{output}"]}
        }}));
    }
    if s(&v["target"]).is_empty() {
        return Err("The request names no target.".into());
    }
    if s(&v["outputDirectory"]).is_empty() {
        return Err("The request names no output directory.".into());
    }
    if v.get("config").is_some_and(|c| !c.is_object()) {
        return Err("\"config\" must be an object.".into());
    }
    let schema = &v["schema"];
    if !schema.is_object() {
        return Err("The request carries no schema.".into());
    }
    if !schema["project"].is_object() {
        return Err("The schema is not readable: missing project.".into());
    }
    let normalized = ir::decode(schema)?;
    let schema = &normalized;
    let errors = ["namespace", "typeNamespace"]
        .iter()
        .filter(|k| s(&v["config"][**k]).is_empty())
        .map(|k| format!("The php target needs \"{k}\" set to a non-empty string."))
        .collect::<Vec<_>>();
    if !errors.is_empty() {
        return Ok(response(vec![], errors));
    }
    let g = Gen {
        schema,
        root: s(&v["config"]["namespace"]).trim_matches('\\').into(),
        types: s(&v["config"]["typeNamespace"]).trim_matches('\\').into(),
    };
    let mut files = match g.shared() {
        Ok(files) => files,
        Err(error) => return Ok(response(vec![], vec![error])),
    };
    for e in vals(&schema["entities"]) {
        files.push(g.hydrate_input(e, false)?);
        files.push(g.hydrate_input(e, true)?);
        files.push(g.deleter(e));
        files.extend(g.bridges(e)?);
        files.extend(g.contracts(e)?);
        files.push(g.read_model(e)?);
        files.push(g.mutator(e)?);
        files.extend(g.contexts(e)?);
        if !vals(&e["queries"]).is_empty() {
            files.push(g.finder(e)?);
        }
    }
    files.push(g.catalogue(&files));
    files.push(g.wiring());
    files.push(g.class_map(&files));
    files.sort_by(|a, b| s(&a["path"]).cmp(s(&b["path"])));
    Ok(response(files, vec![]))
}
fn main() {
    let mut input = String::new();
    let result = io::stdin()
        .read_to_string(&mut input)
        .map_err(|e| e.to_string())
        .and_then(|_| {
            if input.trim().is_empty() {
                Err("expected a request on stdin.".into())
            } else {
                serde_json::from_str::<Value>(&input).map_err(|e| format!("Not valid JSON: {e}"))
            }
        })
        .and_then(|v| run(&v));
    match result {
        Ok(v) => print!("{v}"),
        Err(e) => {
            eprintln!("eleph-gen-php: {e}");
            std::process::exit(1);
        }
    }
}
