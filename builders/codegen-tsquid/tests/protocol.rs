//! The real binary, run as the orchestrator runs it: a request on stdin, one JSON object out.
//!
//! `tests/fixtures/golden/php/*` was written by the builder before the C# target existed and is never refreshed: the PHP output must not
//! change. `tests/fixtures/golden/csharp/*` is frozen after `tests/dotnet` loaded it into `Elephentity.Tsquid` and ran the vectors.

use serde_json::{json, Value};
use std::{
    io::Write,
    path::{Path, PathBuf},
    process::{Command, Stdio},
};

const MANIFESTS: [&str; 2] = ["vectors", "starter"];

fn root() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures")
}

fn answer(request: &Value) -> Value {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-tsquid"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let payload = request.to_string();
    let writer = std::thread::spawn(move || {
        let _ = stdin.write_all(payload.as_bytes());
    });
    let output = child.wait_with_output().unwrap();
    writer.join().unwrap();

    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );

    serde_json::from_slice(&output.stdout).unwrap()
}

fn request(manifest: &str, config: Value) -> Value {
    let mut config = config;
    config["manifest"] = json!(format!("../{manifest}.manifest.json"));

    json!({
        "elephentity": 1,
        "irVersion": "1.2",
        "request": "generate",
        "target": "tsquid",
        "config": config,
        "outputDirectory": root().join("out"),
        "schema": {"project": {"integrations": {"tsquid": null}}},
    })
}

#[test]
fn php_output_is_byte_for_byte_what_it_was_before_the_csharp_target() {
    for name in MANIFESTS {
        for config in [json!({}), json!({"language": "php"})] {
            let response = answer(&request(name, config));

            assert_eq!(response["errors"], json!([]), "{name}");
            assert_eq!(response["headerStyle"], "php", "{name}");
            assert_eq!(response["extensions"], json!(["php"]), "{name}");
            assert_eq!(
                response["files"][0]["path"], "tsquid-manifest.php",
                "{name}"
            );
            assert_eq!(
                response["files"][0]["body"].as_str().unwrap(),
                std::fs::read_to_string(
                    root()
                        .join("golden/php")
                        .join(name)
                        .join("tsquid-manifest.php")
                )
                .unwrap(),
                "{name}"
            );
        }
    }
}

#[test]
fn csharp_output_is_the_frozen_manifest() {
    let refresh = std::env::var_os("ELEPH_REFRESH").is_some();

    for name in MANIFESTS {
        let namespace = format!(
            "Golden.{}",
            if name == "vectors" {
                "Vectors"
            } else {
                "Starter"
            }
        );
        let response = answer(&request(
            name,
            json!({"language": "csharp", "namespace": namespace}),
        ));

        assert_eq!(response["errors"], json!([]), "{name}");
        assert_eq!(response["headerStyle"], "line-comment", "{name}");
        assert_eq!(response["extensions"], json!(["cs"]), "{name}");
        assert_eq!(response["files"][0]["path"], "TsquidManifest.cs", "{name}");

        let body = response["files"][0]["body"].as_str().unwrap();
        let expected = root()
            .join("golden/csharp")
            .join(name)
            .join("TsquidManifest.cs");

        if refresh {
            std::fs::create_dir_all(expected.parent().unwrap()).unwrap();
            std::fs::write(&expected, body).unwrap();

            continue;
        }

        assert_eq!(
            body,
            std::fs::read_to_string(&expected).unwrap(),
            "{name} changed (ELEPH_REFRESH=1 cargo test to refresh)"
        );
    }
}

#[test]
fn a_csharp_target_needs_a_valid_namespace_and_says_so_with_no_files() {
    for (config, fragment) in [
        (json!({"language": "csharp"}), "needs \"namespace\""),
        (
            json!({"language": "csharp", "namespace": "My-App"}),
            "not a valid C# namespace",
        ),
        (
            json!({"language": "csharp", "namespace": "App.class"}),
            "not a valid C# namespace",
        ),
    ] {
        let response = answer(&request("starter", config.clone()));

        assert_eq!(response["files"], json!([]), "{config}");
        assert_eq!(response["extensions"], json!([]), "{config}");
        assert_eq!(response["headerStyle"], "line-comment", "{config}");
        assert!(
            response["errors"][0].as_str().unwrap().contains(fragment),
            "{config}: {response}"
        );
    }
}

#[test]
fn an_unknown_language_is_an_error() {
    for language in [json!("java"), json!(3), json!("PHP")] {
        let response = answer(&request("starter", json!({"language": language})));

        assert_eq!(response["files"], json!([]));
        assert_eq!(response["extensions"], json!([]));
        assert_eq!(response["headerStyle"], "line-comment");
        assert!(
            response["errors"][0]
                .as_str()
                .unwrap()
                .contains("\"php\" or \"csharp\""),
            "{response}"
        );
    }
}

#[test]
fn a_manifest_of_another_version_is_refused_for_both_languages() {
    for (tag, config) in [
        ("php", json!({})),
        ("cs", json!({"language": "csharp", "namespace": "App"})),
    ] {
        let directory = root().join(format!("out-version-{tag}"));

        std::fs::create_dir_all(&directory).unwrap();
        std::fs::write(
            directory.join("v2.json"),
            json!({"version": 2, "routes": []}).to_string(),
        )
        .unwrap();

        let mut request = request("starter", config);
        request["outputDirectory"] = json!(directory);
        request["config"]["manifest"] = json!("v2.json");

        let response = answer(&request);

        let _ = std::fs::remove_dir_all(&directory);

        assert_eq!(response["files"], json!([]));
        assert!(
            response["errors"][0].as_str().unwrap().contains("version"),
            "{response}"
        );
    }
}

#[test]
fn describe_and_the_empty_integration_are_unchanged() {
    let described = answer(&json!({"elephentity": 1, "irVersion": "1.2", "request": "describe"}));

    assert!(described["provides"]["integrations"]["tsquid"].is_object());

    for language in [json!("php"), json!("csharp")] {
        let response = answer(&json!({
            "elephentity": 1, "irVersion": "1.2", "request": "generate", "target": "tsquid",
            "config": {"language": language, "namespace": "App"}, "outputDirectory": "x",
            "schema": {"project": {"integrations": {}}},
        }));

        assert_eq!(response["files"], json!([]));
        assert_eq!(response["errors"], json!([]));
    }
}

/// A manifest written to a directory of its own (the tests run in parallel), and the generator asked about it.
fn manifest_with(tag: &str, routes: Value, config: Value) -> Value {
    let directory = root().join(format!("out-{tag}"));

    std::fs::create_dir_all(&directory).unwrap();
    std::fs::write(
        directory.join("broken.json"),
        json!({"version": 1, "routes": routes}).to_string(),
    )
    .unwrap();

    let mut request = request("starter", config);
    request["outputDirectory"] = json!(directory);
    request["config"]["manifest"] = json!("broken.json");

    let response = answer(&request);

    let _ = std::fs::remove_dir_all(&directory);

    response
}

#[test]
fn every_problem_in_a_manifest_is_reported_in_one_run_and_names_its_route() {
    let routes = json!([
        {"name": "Alpha", "path": "no-slash", "ssr": true, "fields": {}},
        {"name": "Beta", "path": "/b/:id", "ssr": true, "fields": {"id": {"kind": "float", "optional": false, "in": "path"}}},
        {"name": "Gamma", "path": "/g/:id", "ssr": true, "fields": {}, "query": {"operation": "", "id": "q", "text": "query { x }", "variables": {"v": {"input": "nope"}}}},
        {"name": "Alpha", "path": "/again", "ssr": true, "fields": {}},
    ]);
    let problems: Vec<String> = manifest_with("every", routes, json!({}))["errors"]
        .as_array()
        .unwrap()
        .iter()
        .map(|e| e.as_str().unwrap().to_string())
        .collect();

    for fragment in [
        "Route Alpha: the path \"no-slash\" must start with /.",
        "Invalid field Beta.id: kind \"float\"",
        "Route Gamma: the path parameter :id has no field with in: path.",
        "Route Gamma: the query has an empty operation.",
        "Route Gamma: the query variable v binds input nope",
        "Route name Alpha is used more than once.",
    ] {
        assert!(
            problems.iter().any(|p| p.contains(fragment)),
            "{fragment}\n{problems:#?}"
        );
    }
}

#[test]
fn a_bad_namespace_and_a_bad_manifest_are_reported_together() {
    let response = manifest_with(
        "together",
        json!([{"name": "", "path": "/", "ssr": true, "fields": {}}]),
        json!({"language": "csharp", "namespace": "My-App"}),
    );
    let problems = response["errors"].as_array().unwrap();

    assert!(
        problems
            .iter()
            .any(|p| p.as_str().unwrap().contains("not a valid C# namespace")),
        "{problems:?}"
    );
    assert!(
        problems
            .iter()
            .any(|p| p.as_str().unwrap().contains("has no name")),
        "{problems:?}"
    );

    let _ = std::fs::remove_dir_all(root().join("out"));
}
