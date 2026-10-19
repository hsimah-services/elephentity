//! The real binary, run as the orchestrator runs it: a request on stdin, one JSON object out.
//!
//! The request fixtures are the SQLite builder's (a worked example and the five relation shapes), with the driver
//! changed to `cosmos`. `tests/fixtures/<name>/expected/` is what the builder must write for each, frozen after
//! `tests/dotnet` loaded it into `Elephentity.Cosmos` and ran the conformance suite against the emulator.

use serde_json::{json, Value};
use std::{
    io::Write,
    path::Path,
    process::{Command, Output, Stdio},
};

fn invoke(input: &str) -> Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-cosmos"))
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
        .join("tests/fixtures")
        .join(name)
        .join("request.json");

    serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap()
}

const FIXTURES: [&str; 6] = [
    "clog",
    "many-to-many",
    "many-to-one",
    "one-to-many",
    "one-to-one",
    "relations",
];

fn errors(response: &Value) -> Vec<String> {
    response["errors"]
        .as_array()
        .unwrap()
        .iter()
        .map(|error| error.as_str().unwrap().to_string())
        .collect()
}

/// The first entity of a fixture, for the tests that break one thing about it.
fn first_entity(request: &mut Value) -> &mut Value {
    request["schema"]["entities"]
        .as_object_mut()
        .unwrap()
        .values_mut()
        .next()
        .unwrap()
}

#[test]
fn every_fixture_generates_the_frozen_manifest() {
    let refresh = std::env::var_os("ELEPH_REFRESH").is_some();

    for name in FIXTURES {
        let response = answer(&fixture(name));

        assert_eq!(response["errors"], json!([]), "{name}");
        assert_eq!(response["headerStyle"], "line-comment", "{name}");
        assert_eq!(response["extensions"], json!(["cs"]), "{name}");

        let files = response["files"].as_array().unwrap();

        assert_eq!(files.len(), 1, "{name}");
        assert_eq!(files[0]["path"], "CosmosStorageManifest.cs", "{name}");

        let expected = Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures")
            .join(name)
            .join("expected/CosmosStorageManifest.cs");
        let body = files[0]["body"].as_str().unwrap();

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
fn the_output_is_the_same_every_time() {
    for name in FIXTURES {
        assert_eq!(answer(&fixture(name)), answer(&fixture(name)), "{name}");
    }
}

#[test]
fn another_driver_is_an_error_naming_it_and_no_files() {
    let mut request = fixture("relations");
    request["schema"]["project"]["driver"] = json!("sqlite");

    let response = answer(&request);

    assert_eq!(response["files"], json!([]));
    assert_eq!(response["extensions"], json!([]));
    assert!(errors(&response)[0].contains("declares driver \"sqlite\""));
}

#[test]
fn bad_configuration_is_a_response_with_every_error_and_no_files() {
    for (config, expected) in [
        (json!({}), "needs \"namespace\" set to a non-empty string"),
        (
            json!({"namespace": "My-App"}),
            "is not a valid C# namespace",
        ),
        (
            json!({"namespace": "App.class"}),
            "is not a valid C# namespace",
        ),
        (json!({"namespace": ".App"}), "is not a valid C# namespace"),
    ] {
        let mut request = fixture("relations");
        request["config"] = config.clone();

        let response = answer(&request);
        let problems = errors(&response);

        assert_eq!(response["files"], json!([]), "{config}");
        assert_eq!(problems.len(), 1, "{config}: {problems:?}");
        assert!(problems[0].contains(expected), "{config}: {problems:?}");
    }
}

#[test]
fn a_wrong_driver_and_a_bad_namespace_are_reported_together() {
    let mut request = fixture("relations");
    request["schema"]["project"]["driver"] = json!("sqlite");
    request["config"] = json!({"namespace": "My-App"});

    assert_eq!(errors(&answer(&request)).len(), 2);
}

#[test]
fn describe_provides_the_cosmos_driver_with_no_schema_and_no_config() {
    let response = answer(
        &json!({"elephentity": 1, "irVersion": "1.2", "request": "describe", "target": "cosmos"}),
    );

    assert_eq!(
        response,
        json!({"elephentity": 1, "irVersion": "1.2", "provides": {"drivers": ["cosmos"]}})
    );
}

#[test]
fn a_describe_without_a_request_key_is_a_generate() {
    let mut request = fixture("relations");
    request.as_object_mut().unwrap().remove("request");

    assert_eq!(answer(&request)["errors"], json!([]));
}

#[test]
fn reserved_property_names_are_escaped_and_the_escape_is_in_the_manifest() {
    let mut request = fixture("relations");
    let fields = first_entity(&mut request)["fields"]
        .as_object_mut()
        .unwrap();
    let mut template = fields.values().next().unwrap().clone();

    for name in ["id", "pk", "type", "_etag"] {
        template["name"] = json!(name);
        fields.insert(name.to_string(), template.clone());
    }

    let body = answer(&request)["files"][0]["body"]
        .as_str()
        .unwrap()
        .to_string();

    for line in [
        "[\"id\"] = \"id_\"",
        "[\"pk\"] = \"pk_\"",
        "[\"type\"] = \"type_\"",
        "[\"_etag\"] = \"f_etag\"",
    ] {
        assert!(body.contains(line), "{line}\n{body}");
    }
}

#[test]
fn two_fields_that_land_in_one_property_are_reported() {
    let mut request = fixture("relations");
    let fields = first_entity(&mut request)["fields"]
        .as_object_mut()
        .unwrap();
    let mut template = fields.values().next().unwrap().clone();

    // `type` is stored as `type_`, which a field of that name already claims.
    for name in ["type", "type_"] {
        template["name"] = json!(name);
        fields.insert(name.to_string(), template.clone());
    }

    let response = answer(&request);

    assert_eq!(response["files"], json!([]));
    assert!(
        errors(&response)
            .iter()
            .any(|e| e.contains("Property \"type_\"") && e.contains("claimed by both")),
        "{:?}",
        errors(&response)
    );
}

#[test]
fn an_edge_reference_that_collides_with_a_field_is_reported() {
    let mut request = fixture("many-to-one");
    let owner = &mut request["schema"]["entities"]["Owner"];
    let edge = owner["edges"]
        .as_object()
        .unwrap()
        .keys()
        .next()
        .unwrap()
        .clone();
    let mut field = owner["fields"]
        .as_object()
        .unwrap()
        .values()
        .next()
        .unwrap()
        .clone();
    field["name"] = json!(format!("{edge}Id"));
    owner["fields"][format!("{edge}Id")] = field;

    let problems = errors(&answer(&request));

    assert!(
        problems.iter().any(|e| e.contains("claimed by both")),
        "{problems:?}"
    );
}

#[test]
fn more_unique_fields_than_a_container_has_keys_is_reported_once_for_the_entity() {
    let mut request = fixture("relations");
    let fields = first_entity(&mut request)["fields"]
        .as_object_mut()
        .unwrap();
    let mut template = fields.values().next().unwrap().clone();
    template["unique"] = json!(true);

    for n in 0..11 {
        template["name"] = json!(format!("u{n}"));
        fields.insert(format!("u{n}"), template.clone());
    }

    let problems = errors(&answer(&request));

    assert_eq!(problems.len(), 1, "{problems:?}");
    assert!(
        problems[0].contains("allows 10 unique keys"),
        "{problems:?}"
    );
}

#[test]
fn exactly_ten_unique_fields_are_fine() {
    let mut request = fixture("relations");
    let fields = first_entity(&mut request)["fields"]
        .as_object_mut()
        .unwrap();
    let mut template = fields.values().next().unwrap().clone();
    fields.clear();
    template["unique"] = json!(true);

    for n in 0..10 {
        template["name"] = json!(format!("u{n}"));
        fields.insert(format!("u{n}"), template.clone());
    }

    assert_eq!(answer(&request)["errors"], json!([]));
}

#[test]
fn a_field_type_the_ir_does_not_define_is_refused_at_the_door() {
    let mut request = fixture("relations");
    let fields = first_entity(&mut request)["fields"]
        .as_object_mut()
        .unwrap();
    fields.values_mut().next().unwrap()["type"]["primitive"] = json!("geometry");

    let output = invoke(&request.to_string());

    assert!(!output.status.success());
    assert!(output.stdout.is_empty());
    assert!(String::from_utf8_lossy(&output.stderr).contains("not readable"));
}

#[test]
fn an_entity_on_another_driver_or_with_platform_storage_is_reported() {
    let mut request = fixture("relations");
    first_entity(&mut request)["storage"]["driver"] = json!("sqlite");
    first_entity(&mut request)["config"] = json!({"taxonomy": true});

    let problems = errors(&answer(&request));

    assert!(
        problems.iter().any(|e| e.contains("does not use cosmos")),
        "{problems:?}"
    );
    assert!(
        problems
            .iter()
            .any(|e| e.contains("platform-owned storage")),
        "{problems:?}"
    );
}

#[test]
fn a_project_name_with_a_line_terminator_cannot_leave_its_string() {
    let mut request = fixture("relations");
    request["schema"]["project"]["name"] = json!("Evil\u{2028}Name");

    let body = answer(&request)["files"][0]["body"]
        .as_str()
        .unwrap()
        .to_string();

    assert!(!body.contains('\u{2028}'));
    assert!(body.contains("Evil\\u2028Name"));
}

#[test]
fn a_request_from_another_envelope_or_ir_version_is_refused() {
    let mut newer_generate = fixture("relations");
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
    let mut no_schema = fixture("relations");
    no_schema.as_object_mut().unwrap().remove("schema");
    let mut no_project = fixture("relations");
    no_project["schema"] = json!({"entities": {}});
    let mut not_an_object = fixture("relations");
    not_an_object["config"] = json!("namespace");
    let mut unreadable = fixture("relations");
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
            String::from_utf8_lossy(&output.stderr).starts_with("eleph-gen-cosmos: "),
            "{input}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
}

#[test]
fn a_request_with_no_target_or_output_directory_is_refused() {
    for key in ["target", "outputDirectory"] {
        let mut request = fixture("relations");
        request[key] = json!("");

        assert!(!invoke(&request.to_string()).status.success(), "{key}");
    }
}
