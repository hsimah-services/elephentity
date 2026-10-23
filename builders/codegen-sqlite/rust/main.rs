mod csharp;
mod emit_csharp;
mod emit_php;
mod ir;
mod storage;
use serde_json::{json, Value};
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
fn q(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}
fn low(s: &str) -> String {
    let mut c = s.chars();
    c.next()
        .map(|f| f.to_lowercase().collect::<String>() + c.as_str())
        .unwrap_or_default()
}
fn snake(s: &str) -> String {
    s.chars()
        .enumerate()
        .map(|(i, c)| {
            if i > 0 && c.is_ascii_uppercase() {
                format!("_{}", c.to_ascii_lowercase())
            } else {
                c.to_ascii_lowercase().to_string()
            }
        })
        .collect()
}
/// Which language a run writes. The target must say: there is no default, so a project never gets the wrong language's output
/// by omission.
#[derive(PartialEq)]
enum Language {
    Php,
    Csharp,
}

fn language(config: &Value) -> Result<Language> {
    match config.get("language") {
        None | Some(Value::Null) => {
            Err("The sqlite target needs \"language\" set to \"php\" or \"csharp\".".into())
        }
        Some(Value::String(name)) if name == "php" => Ok(Language::Php),
        Some(Value::String(name)) if name == "csharp" => Ok(Language::Csharp),
        _ => Err("The sqlite target's \"language\" must be \"php\" or \"csharp\".".into()),
    }
}

/// Every problem with a C# target's `namespace`.
fn namespace_problems(config: &Value) -> Vec<String> {
    match config.get("namespace") {
        Some(Value::String(namespace)) if csharp::is_namespace(namespace) => vec![],
        Some(Value::String(namespace)) if !namespace.is_empty() => vec![format!(
            "The sqlite target's \"namespace\" (\"{namespace}\") is not a valid C# namespace: use dot-separated identifiers that are not reserved words."
        )],
        _ => vec!["The sqlite target needs \"namespace\" set to a non-empty string when \"language\" is \"csharp\".".into()],
    }
}

/// An answer that claims no output at all: the language is not one this builder writes.
fn response_unclaimed(errors: Vec<String>) -> Value {
    json!({"elephentity":1,"irVersion":"1.2","headerStyle":"line-comment","extensions":[],"files":[],"errors":errors})
}

fn response(files: Vec<Value>, errors: Vec<String>, language: &Language) -> Value {
    match language {
        Language::Php => {
            json!({"elephentity":1,"irVersion":"1.2","headerStyle":"php","extensions":["php"],"files":files,"errors":errors})
        }
        // Nothing is claimed when generation failed, so a failed run sweeps nothing.
        Language::Csharp => {
            json!({"elephentity":1,"irVersion":"1.2","headerStyle":"line-comment","extensions": if errors.is_empty() { vec!["cs"] } else { vec![] },"files":files,"errors":errors})
        }
    }
}
fn run(v: &Value) -> Result<Value> {
    if v["elephentity"].as_u64() != Some(1) {
        return Err("Protocol version mismatch: this builder speaks 1.".into());
    }
    if v["irVersion"] != "1.2" {
        return Err("IR version mismatch: this builder speaks 1.2.".into());
    }
    let kind = match v.get("request") {
        None | Some(Value::Null) => "generate",
        Some(Value::String(kind)) => kind.as_str(),
        Some(_) => {
            return Err(
                "Unknown request. This builder answers \"generate\" and \"describe\".".into(),
            )
        }
    };
    match kind {
        "describe" => {
            return Ok(
                json!({"elephentity":1,"irVersion":"1.2","provides":serde_json::from_str::<Value>(include_str!("provides.json")).map_err(|e|e.to_string())?}),
            )
        }
        "generate" => (),
        other => {
            return Err(format!(
                "Unknown request \"{other}\". This builder answers \"generate\" and \"describe\"."
            ))
        }
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
    // The configuration's problems and the project's come back together.
    let mut problems = vec![];
    let language = match language(&v["config"]) {
        Ok(language) => Some(language),
        Err(error) => {
            problems.push(error);
            None
        }
    };
    if language == Some(Language::Csharp) {
        problems.extend(namespace_problems(&v["config"]));
    }
    if schema["project"]["driver"] != "sqlite" {
        problems.push(format!("This project declares driver \"{}\", so the sqlite target has nothing to generate. Remove it from eleph.json, or change the driver in project.yml.",s(&schema["project"]["driver"])));
    }
    let Some(language) = language else {
        return Ok(response_unclaimed(problems));
    };
    if !problems.is_empty() {
        return Ok(response(vec![], problems, &language));
    }
    let model = match storage::generate(schema) {
        Ok(model) => model,
        Err(error) => return Ok(response(vec![], vec![error], &language)),
    };
    Ok(match language {
        Language::Php => {
            let (manifest, installer) = emit_php::render(&model);
            response(
                vec![
                    json!({"path":"storage-manifest.php","body":manifest}),
                    json!({"path":"install.php","body":installer}),
                ],
                vec![],
                &language,
            )
        }
        Language::Csharp => {
            let body = emit_csharp::render(&model, s(&v["config"]["namespace"]));
            response(
                vec![json!({"path":"SqliteStorageManifest.cs","body":body})],
                vec![],
                &language,
            )
        }
    })
}
fn main() {
    let mut input = String::new();
    let r = io::stdin()
        .read_to_string(&mut input)
        .map_err(|e| e.to_string())
        .and_then(|_| {
            if input.trim().is_empty() {
                Err("Expected a request on stdin.".into())
            } else {
                serde_json::from_str::<Value>(&input)
                    .map_err(|e| format!("Expected one JSON object on stdin: {e}"))
            }
        })
        .and_then(|v| run(&v));
    match r {
        Ok(v) => print!("{v}"),
        Err(e) => {
            eprintln!("eleph-gen-sqlite: {e}");
            std::process::exit(1);
        }
    }
}
