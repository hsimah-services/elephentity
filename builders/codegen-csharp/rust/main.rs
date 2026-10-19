//! `eleph-gen-csharp`: the C# builder.
//!
//! The IR in on stdin, one JSON object out on stdout, exit 0. It never touches the filesystem: it
//! returns paths and bodies, and signing and writing happen after it exits. This is the scaffold:
//! it speaks the protocol, validates its configuration and answers `describe`, and emits no files
//! yet. The emitters are the next step.
//!
//! The rules of the protocol (docs/PROTOCOL.md in elephentity-codegen):
//!
//! - malformed input is a non-zero exit with the reason on stderr and nothing on stdout;
//! - bad configuration is a normal response (exit 0) with `errors` filled in and no files, and all
//!   of them at once, so a build is not run once per mistake;
//! - the version gate refuses in both directions;
//! - `describe` works with no schema, and before the specs are compiled.

mod cs;
mod emit_bridges;
mod emit_catalogue;
mod emit_contracts;
mod emit_entity;
mod emit_shared;
mod emit_write;
mod gen;
mod ir;
mod names;
mod runtime;

use serde_json::{json, Map, Value};
use std::io::{self, Read};

type Result<T> = std::result::Result<T, String>;

/// The envelope this build speaks, and the wire IR it emits from. Declared here and again in every
/// other program: agreeing by sharing a constant would make three programs one program in three
/// files, and a disagreement would never be seen. The values come from the compiler's
/// `IR_VERSION` and the orchestrator's `Envelope`.
const PROTOCOL_VERSION: u64 = 1;
const IR_VERSION: &str = "1.2";

/// What this builder provides to the compiler that no configuration changes: no storage driver and
/// no integration, because a language builder turns the IR into source and knows no platform.
const PROVIDES: &str = include_str!("provides.json");

/// C# reserved words, which cannot be a namespace segment without an `@` escape.
const KEYWORDS: &[&str] = &[
    "abstract",
    "as",
    "base",
    "bool",
    "break",
    "byte",
    "case",
    "catch",
    "char",
    "checked",
    "class",
    "const",
    "continue",
    "decimal",
    "default",
    "delegate",
    "do",
    "double",
    "else",
    "enum",
    "event",
    "explicit",
    "extern",
    "false",
    "finally",
    "fixed",
    "float",
    "for",
    "foreach",
    "goto",
    "if",
    "implicit",
    "in",
    "int",
    "interface",
    "internal",
    "is",
    "lock",
    "long",
    "namespace",
    "new",
    "null",
    "object",
    "operator",
    "out",
    "override",
    "params",
    "private",
    "protected",
    "public",
    "readonly",
    "ref",
    "return",
    "sbyte",
    "sealed",
    "short",
    "sizeof",
    "stackalloc",
    "static",
    "string",
    "struct",
    "switch",
    "this",
    "throw",
    "true",
    "try",
    "typeof",
    "uint",
    "ulong",
    "unchecked",
    "unsafe",
    "ushort",
    "using",
    "virtual",
    "void",
    "volatile",
    "while",
];

fn text(value: &Value) -> &str {
    value.as_str().unwrap_or("")
}

/// Whether `namespace` is a dotted run of identifiers, none of them a reserved word.
fn is_namespace(namespace: &str) -> bool {
    !namespace.is_empty()
        && namespace.split('.').all(|segment| {
            let mut characters = segment.chars();

            characters
                .next()
                .is_some_and(|first| first == '_' || first.is_alphabetic())
                && characters.all(|rest| rest == '_' || rest.is_alphanumeric())
                && !KEYWORDS.contains(&segment)
        })
}

/// The command that checks this builder's tree, if the project configured one.
///
/// A non-empty array of non-empty strings, or nothing: a malformed one is reported by `generate`,
/// which is where configuration is validated, and not guessed at here.
fn checker(config: &Value) -> Option<Vec<String>> {
    let command = config.get("check")?.as_array()?;

    if command.is_empty() {
        return None;
    }

    command
        .iter()
        .map(|word| {
            word.as_str()
                .filter(|word| !word.is_empty())
                .map(str::to_string)
        })
        .collect()
}

/// What `describe` answers: the static declaration, with `check` merged in when configured.
///
/// Only the project knows where its contract-test project is, so it says so in the target's
/// `check`, and the builder echoes it back as `provides.check` for `eleph check` to run.
fn provides(config: &Value) -> Value {
    let mut provides: Map<String, Value> =
        serde_json::from_str(PROVIDES).expect("rust/provides.json is a JSON object");

    if let Some(command) = checker(config) {
        provides.insert("check".to_string(), json!({ "command": command }));
    }

    Value::Object(provides)
}

/// Every problem with the target's configuration, together.
fn config_problems(target: &str, config: &Value) -> Vec<String> {
    let mut problems = Vec::new();

    for key in ["namespace", "typeNamespace"] {
        match config.get(key) {
            Some(Value::String(namespace)) if is_namespace(namespace) => {}
            Some(Value::String(namespace)) if !namespace.is_empty() => problems.push(format!(
                "The {target} target's \"{key}\" (\"{namespace}\") is not a valid C# namespace: use dot-separated identifiers that are not reserved words."
            )),
            _ => problems.push(format!(
                "The {target} target needs \"{key}\" set to a non-empty string."
            )),
        }
    }

    if let Some(check) = config.get("check") {
        let usable = check.as_array().is_some_and(|command| {
            !command.is_empty()
                && command
                    .iter()
                    .all(|word| word.as_str().is_some_and(|word| !word.is_empty()))
        });

        if !usable {
            problems.push(format!(
                "The {target} target's \"check\" must be a non-empty array of non-empty strings: the command that runs the contract check."
            ));
        }
    }

    problems
}

fn response(files: Vec<Value>, errors: Vec<String>) -> Value {
    json!({
        "elephentity": PROTOCOL_VERSION,
        "irVersion": IR_VERSION,
        "headerStyle": "line-comment",
        // Nothing is claimed when generation failed, so a failed run sweeps nothing.
        "extensions": if errors.is_empty() { vec!["cs"] } else { vec![] },
        "files": files,
        "errors": errors,
    })
}

fn run(request: &Value) -> Result<Value> {
    let kind = match request.get("request") {
        None | Some(Value::Null) => "generate",
        Some(Value::String(kind)) => kind,
        Some(_) => {
            return Err("Unknown request. This build answers \"generate\" and \"describe\".".into())
        }
    };

    if !["generate", "describe"].contains(&kind) {
        return Err(format!(
            "Unknown request \"{kind}\". This build answers \"generate\" and \"describe\"."
        ));
    }

    if request["elephentity"].as_u64() != Some(PROTOCOL_VERSION) {
        return Err(format!(
            "Protocol version mismatch: this build speaks {PROTOCOL_VERSION}."
        ));
    }

    if request["irVersion"] != IR_VERSION {
        return Err(format!(
            "IR version mismatch: this build emits {IR_VERSION}."
        ));
    }

    if kind == "describe" {
        return Ok(json!({
            "elephentity": PROTOCOL_VERSION,
            "irVersion": IR_VERSION,
            "provides": provides(&request["config"]),
        }));
    }

    let target = text(&request["target"]);

    if target.is_empty() {
        return Err("The request names no target.".into());
    }

    if text(&request["outputDirectory"]).is_empty() {
        return Err("The request names no output directory.".into());
    }

    if request
        .get("config")
        .is_some_and(|config| !config.is_object())
    {
        return Err("\"config\" must be an object.".into());
    }

    let schema = &request["schema"];

    if !schema.is_object() {
        return Err("The request carries no schema.".into());
    }

    if !schema["project"].is_object() {
        return Err("The schema is not readable: missing project.".into());
    }

    // The IR must parse as this builder understands it: a builder that accepted anything would fail later, in the
    // emitters, for a reason that is really "the compiler is ahead of this builder".
    let normalized = ir::decode(schema)?;

    let problems = config_problems(target, &request["config"]);

    if !problems.is_empty() {
        return Ok(response(vec![], problems));
    }

    let generator = gen::Gen::new(
        &normalized,
        text(&request["config"]["namespace"]).to_string(),
        text(&request["config"]["typeNamespace"]).to_string(),
    );

    match generate(&generator) {
        Ok(files) => Ok(response(files, vec![])),
        Err(error) => Ok(response(vec![], vec![error])),
    }
}

/// Every file for the schema, signed by nobody yet and sorted by path, or the problems that stopped it.
fn generate(g: &gen::Gen<'_>) -> std::result::Result<Vec<Value>, String> {
    use gen::{b, vals};

    let mut files = g.shared()?;

    for e in g.entities() {
        files.push(g.read_model(e)?);
        files.push(g.hydrator(e)?);
        files.push(g.input(e)?);
        files.push(g.deleter(e));
        files.push(g.policies(e, false));
        files.push(g.policies(e, true));
        files.push(g.verifiers(e)?);
        files.push(g.side_effects(e));
        files.push(g.mutator(e)?);
        files.extend(g.contracts(e)?);
        files.extend(g.contexts(e)?);

        if !vals(&e["queries"]).is_empty() {
            files.push(g.finder(e)?);
        }
    }

    let _ = b;
    let contract_namespaces = [g.contract_ns(), g.processor_ns(), g.pattern_contract_ns()];
    let contracts: Vec<String> = files
        .iter()
        .filter(|f| {
            contract_namespaces.contains(&f.namespace)
                && f.path
                    .rsplit('/')
                    .next()
                    .is_some_and(|n| n.starts_with('I'))
        })
        .map(|f| {
            format!(
                "global::{}.{}",
                f.namespace,
                f.path
                    .rsplit('/')
                    .next()
                    .unwrap_or("")
                    .trim_end_matches(".cs")
            )
        })
        .collect();

    files.push(g.processors()?);
    files.push(g.catalogue(&contracts)?);
    files.push(g.wiring());

    let index: Vec<(String, String)> = files
        .iter()
        .map(|f| (f.namespace.clone(), f.path.clone()))
        .collect();
    files.push(g.class_map(&index));

    let problems = g.problems();

    if !problems.is_empty() {
        return Err(problems.join("\n"));
    }

    let mut rendered: Vec<(String, String)> = files.into_iter().map(cs::File::render).collect();
    rendered.sort_by(|a, b| a.0.cmp(&b.0));

    Ok(rendered
        .into_iter()
        .map(|(path, body)| json!({"path": path, "body": body}))
        .collect())
}

fn main() {
    let mut input = String::new();
    let result = io::stdin()
        .read_to_string(&mut input)
        .map_err(|error| error.to_string())
        .and_then(|_| {
            if input.trim().is_empty() {
                Err("expected a request on stdin.".into())
            } else {
                serde_json::from_str::<Value>(&input)
                    .map_err(|error| format!("Not valid JSON: {error}"))
            }
        })
        .and_then(|request| run(&request));

    match result {
        Ok(response) => print!("{response}"),
        Err(error) => {
            eprintln!("eleph-gen-csharp: {error}");
            std::process::exit(1);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn namespaces_are_dotted_identifiers_that_are_not_keywords() {
        assert!(is_namespace("Hsimah.Entity"));
        assert!(is_namespace("_Internal.V2"));
        assert!(!is_namespace(""));
        assert!(!is_namespace("a..b"));
        assert!(!is_namespace(".a"));
        assert!(!is_namespace("a."));
        assert!(!is_namespace("1abc"));
        assert!(!is_namespace("My-App"));
        assert!(!is_namespace("App.class"));
        assert!(!is_namespace("a b"));
    }

    #[test]
    fn a_checker_is_a_non_empty_array_of_non_empty_strings_or_nothing() {
        assert_eq!(
            checker(&json!({"check": ["dotnet", "test"]})),
            Some(vec!["dotnet".to_string(), "test".to_string()])
        );
        assert_eq!(checker(&json!({"check": []})), None);
        assert_eq!(checker(&json!({"check": ["dotnet", ""]})), None);
        assert_eq!(checker(&json!({"check": "dotnet test"})), None);
        assert_eq!(checker(&json!({})), None);
        assert_eq!(checker(&Value::Null), None);
    }
}
