//! The C# output: `TsquidManifest.cs`, the same routes as data for `Elephentity.Tsquid`'s `RouteManifest`.
//!
//! Emitted from the validated JSON manifest, in the order the generator listed the routes (matching depends on it only for ties, which
//! are refused, but a diff should be stable) and with object keys sorted.

use serde_json::Value;
use std::fmt::Write;

const NS: &str = "global::Elephentity.Tsquid.Manifest";
const DICTIONARY: &str = "global::System.Collections.Generic.Dictionary";
const LIST: &str = "global::System.Collections.Generic.List";

/// A C# string literal that is always printable: nothing that ends a line, or ends the string, gets through.
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
                    let _ = write!(out, "\\u{unit:04X}");
                }
            }
            other => out.push(other),
        }
    }

    out.push('"');
    out
}

/// A JSON default as the plain values `VariableBinding.Default` holds.
fn value(v: &Value) -> String {
    match v {
        Value::Null => "null".into(),
        Value::Bool(flag) => flag.to_string(),
        Value::Number(number) => match number.as_i64() {
            Some(whole) => format!("{whole}L"),
            None => format!("{:?}D", number.as_f64().unwrap_or(0.0)),
        },
        Value::String(text) => q(text),
        Value::Array(items) => format!(
            "new {LIST}<object?> {{ {} }}",
            items.iter().map(value).collect::<Vec<_>>().join(", ")
        ),
        Value::Object(map) => format!(
            "new {DICTIONARY}<string, object?> {{ {} }}",
            map.iter()
                .map(|(key, item)| format!("[{}] = {}", q(key), value(item)))
                .collect::<Vec<_>>()
                .join(", ")
        ),
    }
}

fn field(f: &Value) -> String {
    let kind = f["kind"].as_str().unwrap_or("string");
    let array = kind.ends_with("[]");

    format!(
        "new({NS}.FieldKind.{}, {array}, {}, {NS}.FieldLocation.{})",
        match kind.trim_end_matches("[]") {
            "int" => "Whole",
            "bool" => "Flag",
            _ => "Text",
        },
        f["optional"].as_bool().unwrap_or(false),
        if f["in"] == "path" { "Path" } else { "Query" }
    )
}

fn binding(b: &Value) -> String {
    let input = b["input"].as_str().map_or_else(|| "null".into(), q);

    match b.get("default") {
        Some(default) => format!("new({input}, true, {})", value(default)),
        None => format!("new({input}, false, null)"),
    }
}

fn map(entries: Vec<String>, value_type: &str) -> String {
    if entries.is_empty() {
        return format!("new {DICTIONARY}<string, {NS}.{value_type}>()");
    }

    format!(
        "new {DICTIONARY}<string, {NS}.{value_type}>\n            {{\n{}\n            }}",
        entries.join("\n")
    )
}

fn route(r: &Value) -> String {
    let fields = r["fields"]
        .as_object()
        .map(|fields| {
            fields
                .iter()
                .map(|(name, f)| format!("                [{}] = {},", q(name), field(f)))
                .collect()
        })
        .unwrap_or_default();
    let query = match r.get("query").filter(|query| query.is_object()) {
        Some(query) => {
            let variables = query["variables"]
                .as_object()
                .map(|variables| {
                    variables
                        .iter()
                        .map(|(name, b)| {
                            format!("                    [{}] = {},", q(name), binding(b))
                        })
                        .collect()
                })
                .unwrap_or_default();

            format!(
                "new(\n                {},\n                {},\n                {},\n                {})",
                q(query["operation"].as_str().unwrap_or("")),
                q(query["id"].as_str().unwrap_or("")),
                q(query["text"].as_str().unwrap_or("")),
                map(variables, "VariableBinding").replace('\n', "\n        ")
            )
        }
        None => "null".into(),
    };

    format!(
        "        new(\n            {},\n            {},\n            {},\n            {},\n            {query}),",
        q(r["name"].as_str().unwrap_or("")),
        q(r["path"].as_str().unwrap_or("")),
        r["ssr"].as_bool().unwrap_or(false),
        map(fields, "RouteField")
    )
}

/// The file body for a validated manifest.
pub fn manifest(manifest: &Value, namespace: &str) -> String {
    let routes: Vec<String> = manifest["routes"]
        .as_array()
        .map(|routes| routes.iter().map(route).collect())
        .unwrap_or_default();

    format!(
        "// <auto-generated/>\n#nullable enable\n#pragma warning disable CS1591\n\nnamespace {namespace};\n\n/// <summary>\n/// The app's routes as data for <c>Elephentity.Tsquid</c>: the same manifest tsquid's generator wrote, with its version 1 rules checked.\n/// </summary>\npublic static class TsquidManifestFactory\n{{\n    /// <summary>The manifest.</summary>\n    public static {NS}.RouteManifest Create() => new(\n    [\n{}\n    ]);\n}}\n",
        routes.join("\n")
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn defaults_are_plain_values_and_strings_cannot_leave_their_literal() {
        assert_eq!(value(&json!(20)), "20L");
        assert_eq!(value(&json!(1.5)), "1.5D");
        assert_eq!(value(&json!(null)), "null");
        assert_eq!(value(&json!("NEWEST")), "\"NEWEST\"");
        assert_eq!(value(&json!({"a": [1, true]})), "new global::System.Collections.Generic.Dictionary<string, object?> { [\"a\"] = new global::System.Collections.Generic.List<object?> { 1L, true } }");
        assert_eq!(q("a\"b\\c\n\u{2028}"), "\"a\\\"b\\\\c\\n\\u2028\"");
    }

    #[test]
    fn a_null_default_is_a_default_and_a_missing_one_is_not() {
        assert!(binding(&json!({"default": null})).contains("true, null"));
        assert!(binding(&json!({"input": "id"})).contains("\"id\", false, null"));
    }
}
