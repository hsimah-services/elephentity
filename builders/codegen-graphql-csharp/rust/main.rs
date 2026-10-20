//! `eleph-gen-graphql-csharp`: the GraphQL builder for the .NET stack.
//!
//! The IR in on stdin, one JSON object out on stdout, exit 0. It never touches the filesystem: it
//! returns paths and bodies, and signing and writing happen after it exits. It provides the `graphql`
//! integration (the same key as `eleph-gen-graphql-php`, so a spec stays language-neutral) and emits one
//! file, `GraphQLManifest.cs`: the manifest `Elephentity.GraphQL` assembles into a schema.
//!
//! The rules of the protocol (docs/PROTOCOL.md in elephentity-codegen):
//!
//! - malformed input is a non-zero exit with the reason on stderr and nothing on stdout;
//! - bad configuration, and a spec this driver cannot lay out, is a normal response (exit 0) with
//!   `errors` filled in and no files, and all of them at once;
//! - the version gate refuses in both directions;
//! - `describe` works with no schema, and before the specs are compiled.

mod ir;
mod manifest;
mod validation;

use serde_json::{json, Value};
use std::io::{self, Read};

type Result<T> = std::result::Result<T, String>;

/// The envelope this build speaks, and the wire IR it emits from. Declared here and again in every
/// other program: agreeing by sharing a constant would make three programs one program in three
/// files, and a disagreement would never be seen. The values come from the compiler's
/// `IR_VERSION` and the orchestrator's `Envelope`.
const PROTOCOL_VERSION: u64 = 1;
const IR_VERSION: &str = "1.2";

/// What this builder provides to the compiler: the `graphql` integration, and no storage driver.
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

// The helpers `validation.rs` and `manifest.rs` share, with the names and the behaviour they have in
// eleph-gen-graphql-php, from which the naming rules are ported.
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

/// Whether the entity (or the project, or a query) takes part in the GraphQL schema.
fn exposure(e: &Value) -> bool {
    !e["integrations"]["graphql"].is_null()
}

/// The GraphQL type name: the integration's `singular`, else the entity's name.
fn name(e: &Value) -> &str {
    e["integrations"]["graphql"]["singular"]
        .as_str()
        .unwrap_or(s(&e["name"]))
}

/// The plural for root fields: the integration's `plural`, else `<Singular>Collection`.
fn plural(e: &Value) -> String {
    e["integrations"]["graphql"]["plural"]
        .as_str()
        .map(str::to_owned)
        .unwrap_or_else(|| format!("{}Collection", name(e)))
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

/// Every problem with the target's configuration, together.
fn config_problems(target: &str, config: &Value) -> Vec<String> {
    let mut problems = Vec::new();

    for key in ["namespace", "entityNamespace"] {
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
            "provides": serde_json::from_str::<Value>(PROVIDES).map_err(|error| error.to_string())?,
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
    // emitter, for a reason that is really "the compiler is ahead of this builder".
    let schema = ir::decode(schema)?;

    let mut problems = config_problems(target, &request["config"]);

    // A project that does not take part in GraphQL has nothing to generate, and says nothing about the spec; the configuration is
    // still checked, so a mistake there is not hidden until the project opts in.
    if !exposure(&schema["project"]) {
        return Ok(response(vec![], problems));
    }

    // The spec's problems and the configuration's come back together.
    if problems.is_empty() {
        match manifest::generate(
            &schema,
            text(&request["config"]["namespace"]),
            text(&request["config"]["entityNamespace"]),
        ) {
            Ok(body) => {
                return Ok(response(
                    vec![json!({"path": "GraphQLManifest.cs", "body": body})],
                    vec![],
                ))
            }
            Err(found) => problems = found,
        }
    } else {
        problems.extend(validation::validate(&schema));
    }

    Ok(response(vec![], problems))
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
            eprintln!("eleph-gen-graphql-csharp: {error}");
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
}
