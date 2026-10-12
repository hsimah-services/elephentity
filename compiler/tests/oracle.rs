//! Parity with the PHP compiler.
//!
//! `tools/compare-compiler --refresh` runs the PHP compiler over every fixture in
//! `packages/schema/tests/fixtures` and commits what it produces to `tests/oracle/<case>/`:
//!
//! - `resolved.json`: the IR after load, validation and patterns, before semantic checks.
//!   Compared in order, so key order counts.
//! - `ir.json`: the exact bytes `eleph generate` sends to `eleph-codegen`. Compared byte for
//!   byte.
//! - `errors.json`: the errors, compared by code, file and pointer.
//! - `fmt.json`: what `eleph fmt` reports, compared exactly, message included, because the
//!   message is where the canonical order it expects is spelled out.
//!
//! One deliberate difference, recorded in `docs/divergence.md`: for `spec.invalid`, Rust must
//! report every location PHP does and may report more. opis stops descending into an object
//! after its first failing keyword; jsonschema reports each failing leaf. Every other error
//! code must match exactly.

use std::collections::BTreeSet;
use std::fs;
use std::path::{Path, PathBuf};

use eleph_compiler::integration::{IntegrationDefinition, IntegrationRegistry};
use eleph_compiler::spec::{RawSpec, SpecKind, SpecSource};
use eleph_compiler::storage::StorageRuleRegistry;
use eleph_compiler::{compile, fmt, resolve, Options, Schema, SpecError};
use indexmap::IndexMap;
use serde_json::{Map, Value};

/// An error's code, file and pointer: the parity surface.
type ErrorKey = (String, Option<String>, Option<String>);

fn compiler_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
}

/// Paths in the IR and in errors are relative to the repository root, so every test runs
/// from there. Every test sets the same directory, so they can share the process.
fn from_repository_root() {
    std::env::set_current_dir(compiler_dir().join("..")).expect("the repository root exists");
}

fn read(path: &Path) -> String {
    fs::read_to_string(path).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
}

fn read_json(path: &Path) -> Value {
    serde_json::from_str(&read(path)).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
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

/// A fixture's errors name files relative to it; the root itself is ".".
fn relative(path: &str, root: &str) -> String {
    if path == root {
        ".".to_string()
    } else {
        path.strip_prefix(&format!("{root}/"))
            .unwrap_or(path)
            .to_string()
    }
}

fn keys_of_errors(errors: &[SpecError], root: &str) -> BTreeSet<ErrorKey> {
    errors
        .iter()
        .map(|e| {
            (
                e.code.clone(),
                e.file.as_deref().map(|f| relative(f, root)),
                e.pointer.clone(),
            )
        })
        .collect()
}

fn keys_of_snapshot(path: &Path) -> BTreeSet<ErrorKey> {
    read_json(path)
        .as_array()
        .expect("errors are a list")
        .iter()
        .map(|e| {
            (
                e["code"].as_str().unwrap().to_string(),
                e["file"].as_str().map(str::to_string),
                e["pointer"].as_str().map(str::to_string),
            )
        })
        .collect()
}

struct Case {
    name: String,
    dir: PathBuf,
    root: String,
    options: Options,
}

fn cases() -> Vec<Case> {
    let oracle = compiler_dir().join("tests/oracle");

    let registry_snapshot = read_json(&oracle.join("registry.json"));
    let definitions: Vec<IntegrationDefinition> =
        serde_json::from_value(registry_snapshot["integrations"].clone())
            .expect("registry.json parses");
    let registry = IntegrationRegistry::new(definitions);

    let storage_rules: StorageRuleRegistry =
        serde_json::from_value(read_json(&oracle.join("storage-rules.json")))
            .expect("storage-rules.json parses");

    let mut dirs: Vec<PathBuf> = fs::read_dir(&oracle)
        .expect("tests/oracle exists")
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .filter(|path| path.is_dir())
        .collect();

    dirs.sort();
    assert!(
        !dirs.is_empty(),
        "no oracle cases; run tools/compare-compiler --refresh"
    );

    dirs.into_iter()
        .map(|dir| {
            let input = read_json(&dir.join("input.json"));

            Case {
                name: dir.file_name().unwrap().to_string_lossy().to_string(),
                root: format!(
                    "packages/schema/tests/fixtures/{}",
                    input["fixture"].as_str().expect("a fixture")
                ),
                options: Options {
                    integrations: registry.clone(),
                    pooled_patterns: pooled(SpecKind::Pattern, &input["pooledPatterns"]),
                    pooled_types: pooled(SpecKind::Type, &input["pooledTypes"]),
                    storage_rules: storage_rules.clone(),
                },
                dir,
            }
        })
        .collect()
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

/// The first place two strings part, with some context either side.
fn first_difference(php: &str, rust: &str) -> String {
    let at = php
        .bytes()
        .zip(rust.bytes())
        .position(|(a, b)| a != b)
        .unwrap_or(php.len().min(rust.len()));
    let window = |text: &str| {
        let start = at.saturating_sub(100);
        let end = (at + 100).min(text.len());

        text.get(start..end)
            .unwrap_or("<not on a character boundary>")
            .to_string()
    };

    format!(
        "  php:  ...{}...\n  rust: ...{}...",
        window(php),
        window(rust)
    )
}

/// Stage two: load, validate, resolve patterns.
#[test]
fn every_fixture_resolves_like_the_php_compiler() {
    from_repository_root();

    let mut failures = Vec::new();

    for case in cases() {
        let name = &case.name;
        let resolved_path = case.dir.join("resolved.json");

        match resolve(&SpecSource::new(case.root.clone()), &case.options) {
            Ok(resolved) => {
                if !resolved_path.exists() {
                    failures.push(format!("{name}: resolved, but PHP reported errors"));
                    continue;
                }

                // The snapshot is the IR before declared patterns are added.
                let schema = Schema {
                    project: resolved.project,
                    entities: resolved.entities,
                    types: resolved.types,
                    patterns: IndexMap::new(),
                };

                let expected = read_json(&resolved_path);
                let actual = serde_json::to_value(&schema).expect("serializes");

                // Compact strings keep key order significant, which a Value comparison would not.
                let (expected_text, actual_text) = (expected.to_string(), actual.to_string());

                if expected_text != actual_text {
                    let mut found = Vec::new();
                    differences(&expected, &actual, "", &mut found);

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
                if resolved_path.exists() {
                    let described: Vec<_> = errors.iter().map(SpecError::describe).collect();
                    failures.push(format!("{name}: failed, but PHP resolved: {described:?}"));
                    continue;
                }

                let expected = keys_of_snapshot(&case.dir.join("errors.json"));
                let actual = keys_of_errors(&errors, &case.root);

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

/// Stage three: the whole compile, down to the bytes `eleph-codegen` receives.
#[test]
fn every_fixture_compiles_like_the_php_compiler() {
    from_repository_root();

    let mut failures = Vec::new();

    for case in cases() {
        let name = &case.name;
        let ir_path = case.dir.join("ir.json");
        let errors_path = case.dir.join("errors.json");
        let resolved_failed_in_php = !case.dir.join("resolved.json").exists();

        match compile(&SpecSource::new(case.root.clone()), &case.options) {
            Ok(schema) => {
                if !ir_path.exists() {
                    failures.push(format!("{name}: compiled, but PHP reported errors"));
                    continue;
                }

                // Raw bytes: the file has no trailing newline because the request has none.
                let expected = read(&ir_path);
                let actual = schema.request();

                if expected != actual {
                    failures.push(format!(
                        "{name}: request bytes differ\n{}",
                        first_difference(&expected, &actual)
                    ));
                }
            }
            Err(errors) => {
                if ir_path.exists() {
                    let described: Vec<_> = errors.iter().map(SpecError::describe).collect();
                    failures.push(format!("{name}: failed, but PHP compiled: {described:?}"));
                    continue;
                }

                let expected = keys_of_snapshot(&errors_path);
                let actual = keys_of_errors(&errors, &case.root);

                if resolved_failed_in_php {
                    // Stage two's errors were compared above, with the spec.invalid rule.
                    continue;
                }

                if expected != actual {
                    failures.push(format!(
                        "{name}: semantic errors differ\n  php:  {expected:?}\n  rust: {actual:?}"
                    ));
                }
            }
        }
    }

    assert!(failures.is_empty(), "\n{}\n", failures.join("\n"));
}

/// `eleph fmt`: the same problems, in the same order, expecting the same canonical order.
///
/// Every shape the checker knows is out of order somewhere in `fmt-shapes`, so a wrong row in
/// the order table fails here rather than for a user.
#[test]
fn every_fixture_formats_like_the_php_compiler() {
    from_repository_root();

    let mut failures = Vec::new();

    for case in cases() {
        // Only the ordering message is compared: it spells out the canonical order. A YAML parse
        // error's wording belongs to each parser, and its code, file and pointer are compared.
        let message = |code: &str, text: &str| {
            if code == "format.keyOrder" {
                text.to_string()
            } else {
                String::new()
            }
        };
        let expected: Vec<(String, Option<String>, Option<String>, String)> =
            read_json(&case.dir.join("fmt.json"))
                .as_array()
                .expect("fmt problems are a list")
                .iter()
                .map(|e| {
                    (
                        e["code"].as_str().unwrap().to_string(),
                        e["file"].as_str().map(str::to_string),
                        e["pointer"].as_str().map(str::to_string),
                        message(e["code"].as_str().unwrap(), e["message"].as_str().unwrap()),
                    )
                })
                .collect();

        let actual: Vec<_> = fmt::check(&SpecSource::new(case.root.clone()))
            .iter()
            .map(|e| {
                (
                    e.code.clone(),
                    e.file.as_deref().map(|f| relative(f, &case.root)),
                    e.pointer.clone(),
                    message(&e.code, &e.message),
                )
            })
            .collect();

        if expected != actual {
            failures.push(format!(
                "{}: fmt differs\n  php:  {expected:#?}\n  rust: {actual:#?}",
                case.name
            ));
        }
    }

    assert!(failures.is_empty(), "\n{}\n", failures.join("\n"));
}
