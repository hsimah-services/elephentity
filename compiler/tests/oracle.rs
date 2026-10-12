//! Parity with the PHP compiler.
//!
//! `tools/compare-compiler --refresh` runs the PHP stages over every fixture in
//! `packages/schema/tests/fixtures` and commits what they produce to `tests/oracle/<case>/`.
//! Each case here must resolve to the same snapshot: the same resolved project, types and
//! entities (compared in order, byte for byte once serialized) or the same errors by code,
//! file and pointer.
//!
//! One deliberate difference, recorded in `docs/divergence.md`: for `spec.invalid`, Rust
//! must report every location PHP does and may report more. opis stops descending into an
//! object after its first failing keyword; jsonschema reports each failing leaf. Every other
//! error code must match exactly.

use std::collections::BTreeSet;
use std::fs;
use std::path::{Path, PathBuf};

use eleph_compiler::integration::{IntegrationDefinition, IntegrationRegistry};
use eleph_compiler::spec::{RawSpec, SpecKind, SpecSource};
use eleph_compiler::{resolve, Options};
use serde_json::{Map, Value};

/// An error's code, file and pointer: the parity surface.
type ErrorKey = (String, Option<String>, Option<String>);

fn compiler_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
}

fn read_json(path: &Path) -> Value {
    let text = fs::read_to_string(path).unwrap_or_else(|e| panic!("{}: {e}", path.display()));

    serde_json::from_str(&text).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
}

fn registry(oracle: &Path) -> IntegrationRegistry {
    let snapshot = read_json(&oracle.join("registry.json"));
    let definitions: Vec<IntegrationDefinition> =
        serde_json::from_value(snapshot["integrations"].clone()).expect("registry.json parses");

    IntegrationRegistry::new(definitions)
}

fn pooled(kind: SpecKind, entries: &Value) -> Vec<RawSpec> {
    entries
        .as_array()
        .expect("pooled documents are a list")
        .iter()
        .map(|entry| {
            let data: Map<String, Value> = entry["data"].as_object().expect("a map").clone();

            RawSpec::pooled(kind, entry["file"].as_str().expect("a file"), data)
        })
        .collect()
}

/// Paths in snapshots are relative to the fixture; the root itself is ".".
fn relative(path: &str, root: &str) -> String {
    if path == root {
        ".".to_string()
    } else {
        path.strip_prefix(&format!("{root}/"))
            .unwrap_or(path)
            .to_string()
    }
}

fn relativize(value: &mut Value, root: &str) {
    match value {
        Value::String(text) => *text = relative(text, root),
        Value::Array(items) => items.iter_mut().for_each(|item| relativize(item, root)),
        Value::Object(map) => map.values_mut().for_each(|item| relativize(item, root)),
        _ => {}
    }
}

/// Every leaf where two documents differ, as `pointer: php != rust`. Key order is checked
/// separately, by comparing the serialized text, so this only reports values.
fn differences(php: &Value, rust: &Value, pointer: &str, out: &mut Vec<String>) {
    match (php, rust) {
        (Value::Object(a), Value::Object(b)) => {
            for key in a.keys().chain(b.keys().filter(|k| !a.contains_key(*k))) {
                let child = format!("{pointer}/{key}");

                match (a.get(key), b.get(key)) {
                    (Some(x), Some(y)) => differences(x, y, &child, out),
                    (Some(x), None) => out.push(format!("{child}: php {x} != rust <missing>")),
                    (None, Some(y)) => out.push(format!("{child}: php <missing> != rust {y}")),
                    (None, None) => {}
                }
            }
        }
        (Value::Array(a), Value::Array(b)) if a.len() == b.len() => {
            for (index, (x, y)) in a.iter().zip(b).enumerate() {
                differences(x, y, &format!("{pointer}/{index}"), out);
            }
        }
        _ if php != rust => {
            out.push(format!("{pointer}: php {php} != rust {rust}"));
        }
        _ => {}
    }
}

fn cases() -> Vec<PathBuf> {
    let oracle = compiler_dir().join("tests/oracle");
    let mut cases: Vec<PathBuf> = fs::read_dir(&oracle)
        .expect("tests/oracle exists")
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .filter(|path| path.is_dir())
        .collect();

    cases.sort();
    assert!(
        !cases.is_empty(),
        "no oracle cases; run tools/compare-compiler --refresh"
    );
    cases
}

#[test]
fn every_fixture_matches_the_php_compiler() {
    let oracle = compiler_dir().join("tests/oracle");
    let registry = registry(&oracle);
    let mut failures = Vec::new();

    for case in cases() {
        let name = case.file_name().unwrap().to_string_lossy().to_string();
        let input = read_json(&case.join("input.json"));

        let root = compiler_dir()
            .join("../packages/schema/tests/fixtures")
            .join(input["fixture"].as_str().expect("a fixture"));
        let root = fs::canonicalize(&root).expect("fixture exists");
        let root = root.to_string_lossy().to_string();

        let options = Options {
            integrations: registry.clone(),
            pooled_patterns: pooled(SpecKind::Pattern, &input["pooledPatterns"]),
            pooled_types: pooled(SpecKind::Type, &input["pooledTypes"]),
        };

        let result = resolve(&SpecSource::new(root.clone()), &options);

        match result {
            Ok(resolved) => {
                let expected_path = case.join("resolved.json");

                if !expected_path.exists() {
                    failures.push(format!("{name}: resolved, but PHP reported errors"));
                    continue;
                }

                let mut actual = serde_json::to_value(&resolved).expect("serializes");
                relativize(&mut actual, &root);

                // Compact strings keep key order significant, which a Value comparison would not.
                let expected = serde_json::to_string(&read_json(&expected_path)).unwrap();
                let actual = serde_json::to_string(&actual).unwrap();

                if expected != actual {
                    let mut found = Vec::new();
                    differences(
                        &read_json(&expected_path),
                        &serde_json::from_str(&actual).unwrap(),
                        "",
                        &mut found,
                    );

                    if found.is_empty() {
                        found.push("same values, different key order".to_string());
                    }

                    failures.push(format!(
                        "{name}: resolved output differs\n  {}",
                        found.join("\n  ")
                    ));
                }
            }
            Err(errors) => {
                let expected_path = case.join("errors.json");

                if !expected_path.exists() {
                    let described: Vec<_> = errors.iter().map(|e| e.describe()).collect();
                    failures.push(format!("{name}: failed, but PHP resolved: {described:?}"));
                    continue;
                }

                let triple = |code: &str, file: Option<&str>, pointer: Option<&str>| {
                    (
                        code.to_string(),
                        file.map(|f| relative(f, &root)),
                        pointer.map(str::to_string),
                    )
                };

                let actual: BTreeSet<ErrorKey> = errors
                    .iter()
                    .map(|e| triple(&e.code, e.file.as_deref(), e.pointer.as_deref()))
                    .collect();

                let expected: BTreeSet<ErrorKey> = read_json(&expected_path)
                    .as_array()
                    .expect("errors are a list")
                    .iter()
                    .map(|e| {
                        triple(
                            e["code"].as_str().unwrap(),
                            e["file"].as_str(),
                            e["pointer"].as_str(),
                        )
                    })
                    .collect();

                let schema_errors = |set: &BTreeSet<ErrorKey>| -> BTreeSet<ErrorKey> {
                    set.iter()
                        .filter(|e| e.0 == "spec.invalid")
                        .cloned()
                        .collect()
                };
                let other_errors = |set: &BTreeSet<ErrorKey>| -> BTreeSet<ErrorKey> {
                    set.iter()
                        .filter(|e| e.0 != "spec.invalid")
                        .cloned()
                        .collect()
                };

                if other_errors(&expected) != other_errors(&actual)
                    || !schema_errors(&expected).is_subset(&schema_errors(&actual))
                {
                    failures.push(format!(
                        "{name}: errors differ\n  php:  {expected:?}\n  rust: {actual:?}"
                    ));
                }
            }
        }
    }

    assert!(failures.is_empty(), "\n{}\n", failures.join("\n"));
}
