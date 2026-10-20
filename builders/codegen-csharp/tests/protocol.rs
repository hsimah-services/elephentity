//! The real binary, run as the orchestrator runs it: a request on stdin, one JSON object out.
//!
//! The request fixtures are the PHP builder's golden requests (the canonical spec, the worked
//! example, patterns, a required edge, and the runtime contracts), copied from
//! elephentity-codegen-php/tests/fixtures/golden. They are what every real builder must be able to
//! read, so a scaffold that decodes all five has the IR right. They name a `php` target and PHP
//! namespaces, which this builder treats as configuration like any other.

use serde_json::{json, Value};
use std::{
    io::Write,
    path::Path,
    process::{Command, Output, Stdio},
};

fn invoke(input: &str) -> Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-csharp"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let payload = input.to_string();
    // A child that exits without reading everything closes the pipe; its exit code is the answer.
    let writer = std::thread::spawn(move || {
        let _ = stdin.write_all(payload.as_bytes());
    });
    let output = child.wait_with_output().unwrap();
    writer.join().unwrap();

    output
}

fn answer(request: &Value) -> Value {
    let output = invoke(&request.to_string());

    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(
        output.stderr.is_empty(),
        "stdout is the response and nothing else"
    );

    serde_json::from_slice(&output.stdout).unwrap()
}

fn fixture(name: &str) -> Value {
    let path = Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/fixtures/golden")
        .join(name)
        .join("request.json");

    serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap()
}

fn with_config(mut request: Value, config: Value) -> Value {
    request["config"] = config;
    request["target"] = json!("csharp");

    request
}

const FIXTURES: [&str; 5] = [
    "clog",
    "edge-required",
    "patterns",
    "runtime-contracts",
    "valid",
];

fn valid_config() -> Value {
    json!({"namespace": "Hsimah.Entity", "typeNamespace": "Hsimah.Type"})
}

/// The names the golden trees use for their namespaces: what `tests/dotnet/materialize.py` compiles.
fn golden_config(name: &str) -> Value {
    let pascal: String = name
        .split('-')
        .map(|part| part[..1].to_uppercase() + &part[1..])
        .collect();

    json!({"namespace": format!("Golden.{pascal}"), "typeNamespace": format!("Golden.{pascal}.Declared")})
}

#[test]
fn every_golden_request_generates_the_frozen_tree() {
    let refresh = std::env::var_os("ELEPH_REFRESH").is_some();

    for name in FIXTURES {
        let response = answer(&with_config(fixture(name), golden_config(name)));

        assert_eq!(response["errors"], json!([]), "{name}");
        assert_eq!(response["headerStyle"], "line-comment", "{name}");
        assert_eq!(response["extensions"], json!(["cs"]), "{name}");

        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures/golden")
            .join(name)
            .join("expected");
        let files = response["files"].as_array().expect("files");

        if refresh {
            let _ = std::fs::remove_dir_all(&root);

            for file in files {
                let path = root.join(file["path"].as_str().unwrap());

                std::fs::create_dir_all(path.parent().unwrap()).unwrap();
                std::fs::write(path, file["body"].as_str().unwrap()).unwrap();
            }

            continue;
        }

        let mut expected = Vec::new();
        collect(&root, &root, &mut expected);
        expected.sort();

        let produced: Vec<String> = files
            .iter()
            .map(|f| f["path"].as_str().unwrap().to_string())
            .collect();

        assert_eq!(
            produced, expected,
            "{name}: the set of files changed (ELEPH_REFRESH=1 cargo test to refresh)"
        );

        for file in files {
            let path = file["path"].as_str().unwrap();
            let frozen = std::fs::read_to_string(root.join(path)).unwrap();

            assert_eq!(
                file["body"].as_str().unwrap(),
                frozen,
                "{name}/{path} changed (ELEPH_REFRESH=1 cargo test to refresh)"
            );
        }
    }
}

fn collect(root: &std::path::Path, dir: &std::path::Path, into: &mut Vec<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else {
        return;
    };

    for entry in entries.flatten() {
        let path = entry.path();

        if path.is_dir() {
            collect(root, &path, into);
        } else {
            into.push(
                path.strip_prefix(root)
                    .unwrap()
                    .to_string_lossy()
                    .replace('\\', "/"),
            );
        }
    }
}

#[test]
fn a_request_from_another_envelope_or_ir_version_is_refused() {
    let mut newer_generate = fixture("valid");
    newer_generate["irVersion"] = json!("2.0");

    for request in [
        json!({"elephentity": 2, "irVersion": "1.2", "request": "describe"}),
        json!({"elephentity": 1, "irVersion": "99", "request": "describe"}),
        json!({"elephentity": 1, "irVersion": "1.1", "request": "describe"}),
        newer_generate,
    ] {
        let output = invoke(&request.to_string());

        assert!(!output.status.success(), "{request}");
        assert!(output.stdout.is_empty());
        assert!(
            String::from_utf8_lossy(&output.stderr).contains("version mismatch"),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
}

#[test]
fn malformed_input_is_a_non_zero_exit_with_nothing_on_stdout() {
    let mut no_schema = fixture("valid");
    no_schema.as_object_mut().unwrap().remove("schema");
    let mut no_project = fixture("valid");
    no_project["schema"] = json!({"entities": {}});
    let mut not_an_object = fixture("valid");
    not_an_object["config"] = json!("namespace");
    let mut unreadable = fixture("valid");
    unreadable["schema"]["project"]["name"] = json!(7);

    for input in [
        String::new(),
        "   ".to_string(),
        "{not json".to_string(),
        "[]".to_string(),
        json!({"elephentity": 1, "irVersion": "1.2", "request": "frobnicate"}).to_string(),
        json!({"elephentity": 1, "irVersion": "1.2", "request": 3}).to_string(),
        no_schema.to_string(),
        no_project.to_string(),
        not_an_object.to_string(),
        unreadable.to_string(),
    ] {
        let output = invoke(&input);

        assert!(!output.status.success(), "{input}");
        assert!(output.stdout.is_empty(), "{input}");
        assert!(
            String::from_utf8_lossy(&output.stderr).starts_with("eleph-gen-csharp: "),
            "{input}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
}

#[test]
fn a_request_with_no_target_or_output_directory_is_refused() {
    for key in ["target", "outputDirectory"] {
        let mut request = with_config(fixture("valid"), valid_config());
        request[key] = json!("");

        assert!(!invoke(&request.to_string()).status.success(), "{key}");
    }
}

#[test]
fn bad_configuration_is_a_response_with_every_error_and_no_files() {
    let response = answer(&with_config(
        fixture("valid"),
        json!({"namespace": "My-App", "check": []}),
    ));

    assert_eq!(response["files"], json!([]));
    assert_eq!(response["extensions"], json!([]));
    assert_eq!(response["headerStyle"], "line-comment");

    let errors: Vec<&str> = response["errors"]
        .as_array()
        .unwrap()
        .iter()
        .map(|error| error.as_str().unwrap())
        .collect();

    assert_eq!(errors.len(), 3, "{errors:?}");
    assert!(errors[0].contains("\"namespace\" (\"My-App\") is not a valid C# namespace"));
    assert_eq!(
        errors[1],
        "The csharp target needs \"typeNamespace\" set to a non-empty string."
    );
    assert!(errors[2].contains("\"check\" must be a non-empty array of non-empty strings"));
}

#[test]
fn a_missing_config_block_reports_both_namespaces() {
    let mut request = fixture("valid");
    request["target"] = json!("csharp");
    request.as_object_mut().unwrap().remove("config");

    let response = answer(&request);

    assert_eq!(response["errors"].as_array().unwrap().len(), 2);
}

#[test]
fn a_namespace_with_a_leading_or_trailing_dot_is_reported_as_written() {
    for namespace in [".Hsimah.Entity", "Hsimah.Entity.", "Hsimah..Entity", "."] {
        let response = answer(&with_config(
            fixture("valid"),
            json!({"namespace": namespace, "typeNamespace": "Hsimah.Type"}),
        ));
        let errors = response["errors"].as_array().unwrap();

        assert_eq!(errors.len(), 1, "{namespace}: {errors:?}");
        assert!(
            errors[0].as_str().unwrap().contains(&format!(
                "\"namespace\" (\"{namespace}\") is not a valid C# namespace"
            )),
            "{namespace}: {errors:?}"
        );
        assert_eq!(response["files"], json!([]));
    }
}

#[test]
fn a_reserved_word_is_not_a_namespace() {
    let response = answer(&with_config(
        fixture("valid"),
        json!({"namespace": "App.namespace", "typeNamespace": "App.Type"}),
    ));

    assert_eq!(response["errors"].as_array().unwrap().len(), 1);
}

#[test]
fn describe_works_with_no_schema_and_no_config_and_provides_nothing() {
    let response = answer(
        &json!({"elephentity": 1, "irVersion": "1.2", "request": "describe", "target": "csharp"}),
    );

    assert_eq!(
        response,
        json!({"elephentity": 1, "irVersion": "1.2", "provides": {}})
    );
}

#[test]
fn describe_echoes_the_configured_checker_as_provides_check() {
    let response = answer(&json!({
        "elephentity": 1,
        "irVersion": "1.2",
        "request": "describe",
        "target": "csharp",
        "config": {
            "builder": "eleph-gen-csharp@0.1.0",
            "output": "Generated",
            "namespace": "Hsimah.Entity",
            "typeNamespace": "Hsimah.Type",
            "check": ["dotnet", "run", "--project", "tests/Contracts", "--", "eleph-check"]
        }
    }));

    assert_eq!(
        response["provides"],
        json!({"check": {"command": ["dotnet", "run", "--project", "tests/Contracts", "--", "eleph-check"]}})
    );
}

#[test]
fn describe_does_not_echo_a_malformed_checker() {
    for check in [
        json!([]),
        json!("dotnet test"),
        json!(["dotnet", ""]),
        json!(7),
    ] {
        let response = answer(&json!({
            "elephentity": 1,
            "irVersion": "1.2",
            "request": "describe",
            "target": "csharp",
            "config": {"check": check}
        }));

        assert_eq!(response["provides"], json!({}), "{check}");
    }
}

#[test]
fn a_describe_without_a_request_key_is_a_generate() {
    // The orchestrator always names the request; one that does not is an old caller asking to generate.
    let mut request = with_config(fixture("valid"), valid_config());
    request.as_object_mut().unwrap().remove("request");

    assert_eq!(answer(&request)["errors"], json!([]));
}
