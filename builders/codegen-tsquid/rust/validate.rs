//! The language-neutral half: what a tsquid `routes.manifest.json` must be before any language is written from it.

use serde::Deserialize;
use serde_json::Value;
use std::collections::BTreeMap;

#[derive(Deserialize)]
struct Manifest {
    version: u64,
    routes: Vec<Route>,
}
#[derive(Deserialize)]
struct Route {
    name: String,
    path: String,
    ssr: bool,
    fields: BTreeMap<String, Field>,
    query: Option<Query>,
}
#[derive(Deserialize)]
struct Field {
    kind: String,
    optional: bool,
    r#in: String,
}
#[derive(Deserialize)]
struct Query {
    operation: String,
    id: String,
    text: String,
    variables: BTreeMap<String, Binding>,
}
#[derive(Deserialize)]
struct Binding {
    input: Option<String>,
    #[serde(rename = "default")]
    _default: Option<Value>,
}

/// Every problem with a manifest, together, each naming the route (and field or variable) it is about. Empty when it is usable.
pub fn validate(value: &Value) -> Vec<String> {
    let manifest: Manifest = match serde_json::from_value(value.clone()) {
        Ok(manifest) => manifest,
        // Not even the right shape: nothing further can be said about its routes.
        Err(error) => return vec![format!("Invalid tsquid manifest: {error}")],
    };
    let mut problems = Vec::new();

    if manifest.version != 1 {
        problems.push(format!(
            "Unsupported tsquid manifest version {}; expected 1.",
            manifest.version
        ));
    }

    let mut names = std::collections::BTreeSet::new();

    for route in manifest.routes {
        // Whether a route is server-rendered is the server's business at request time, not a validity rule.
        let _ = route.ssr;

        if route.name.is_empty() {
            problems.push(format!("A route (path {}) has no name.", route.path));
        } else if !names.insert(route.name.clone()) {
            problems.push(format!("Route name {} is used more than once.", route.name));
        }

        if !route.path.starts_with('/') {
            problems.push(format!(
                "Route {}: the path {:?} must start with /.",
                route.name, route.path
            ));
        }

        let params: Vec<_> = route
            .path
            .split('/')
            .filter_map(|s| s.strip_prefix(':'))
            .collect();

        for (name, field) in &route.fields {
            if !["string", "int", "bool", "string[]", "int[]", "bool[]"]
                .contains(&field.kind.as_str())
            {
                problems.push(format!(
                    "Invalid field {}.{name}: kind {:?} is not string, int, bool or an array of one of them.",
                    route.name, field.kind
                ));
            }

            if !["path", "query"].contains(&field.r#in.as_str()) {
                problems.push(format!(
                    "Invalid field {}.{name}: \"in\" is {:?}, not path or query.",
                    route.name, field.r#in
                ));
            } else if field.r#in == "path" {
                if field.optional {
                    problems.push(format!(
                        "Invalid field {}.{name}: a path field cannot be optional.",
                        route.name
                    ));
                }

                if field.kind.ends_with("[]") {
                    problems.push(format!(
                        "Invalid field {}.{name}: a path field cannot be an array.",
                        route.name
                    ));
                }

                if !params.contains(&name.as_str()) {
                    problems.push(format!(
                        "Invalid field {}.{name}: it is in the path, but {} has no :{name} segment.",
                        route.name, route.path
                    ));
                }
            }
        }

        for param in &params {
            if route.fields.get(*param).is_none_or(|f| f.r#in != "path") {
                problems.push(format!(
                    "Route {}: the path parameter :{param} has no field with in: path.",
                    route.name
                ));
            }
        }

        if let Some(query) = route.query {
            for (part, empty) in [
                ("operation", query.operation.is_empty()),
                ("id", query.id.is_empty()),
                ("text", query.text.trim().is_empty()),
            ] {
                if empty {
                    problems.push(format!(
                        "Route {}: the query has an empty {part}.",
                        route.name
                    ));
                }
            }

            for (variable, binding) in &query.variables {
                if let Some(input) = binding
                    .input
                    .as_ref()
                    .filter(|name| !route.fields.contains_key(*name))
                {
                    problems.push(format!(
                        "Route {}: the query variable {variable} binds input {input}, which is not a field of the route.",
                        route.name
                    ));
                }
            }
        }
    }

    problems
}
