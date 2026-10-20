//! The real binary, run as the orchestrator runs it: a request on stdin, one JSON object out.
//!
//! The request fixtures are `eleph-gen-graphql-php`'s (`book`, `relations`, `clog`), retargeted at this builder.
//! `tests/fixtures/<name>/expected/GraphQLManifest.cs` is what the builder must write for each, frozen after `tests/dotnet`
//! compiled it with the generated entities and executed and printed the schema it makes, and found the SDL equal to the PHP
//! stack's (`tests/parity`).

use serde_json::{json, Value};
use std::{
    io::Write,
    path::Path,
    process::{Command, Output, Stdio},
};

fn invoke(input: &str) -> Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-graphql-csharp"))
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

const FIXTURES: [&str; 3] = ["book", "clog", "relations"];

fn errors(response: &Value) -> Vec<String> {
    response["errors"]
        .as_array()
        .unwrap()
        .iter()
        .map(|error| error.as_str().unwrap().to_string())
        .collect()
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
        assert_eq!(files[0]["path"], "GraphQLManifest.cs", "{name}");

        let expected = Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("tests/fixtures")
            .join(name)
            .join("expected/GraphQLManifest.cs");
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
fn a_project_that_does_not_take_part_in_graphql_generates_nothing_and_says_nothing() {
    let mut request = fixture("book");
    request["schema"]["project"]["integrations"] = json!({});

    let response = answer(&request);

    assert_eq!(response["files"], json!([]));
    assert_eq!(response["errors"], json!([]));
}

#[test]
fn bad_configuration_is_a_response_with_every_error_and_no_files() {
    for (config, expected) in [
        (json!({}), 2),
        (json!({"namespace": "App.GraphQL"}), 1),
        (json!({"entityNamespace": "App"}), 1),
        (
            json!({"namespace": "My-App", "entityNamespace": "App.class"}),
            2,
        ),
    ] {
        let mut request = fixture("book");
        request["config"] = config.clone();

        let response = answer(&request);

        assert_eq!(response["files"], json!([]), "{config}");
        assert_eq!(response["extensions"], json!([]), "{config}");
        assert_eq!(
            errors(&response).len(),
            expected,
            "{config}: {:?}",
            errors(&response)
        );
    }
}

#[test]
fn describe_provides_the_graphql_integration_under_the_key_the_php_target_uses() {
    let response = answer(
        &json!({"elephentity": 1, "irVersion": "1.2", "request": "describe", "target": "graphql-csharp"}),
    );

    assert_eq!(
        response["provides"]["integrations"]
            .as_object()
            .unwrap()
            .keys()
            .collect::<Vec<_>>(),
        ["graphql"]
    );
    assert_eq!(
        response["provides"]["integrations"]["graphql"]["entityConfig"]
            .as_object()
            .unwrap()
            .keys()
            .collect::<Vec<_>>(),
        ["singular", "plural"]
    );
    assert!(response["provides"].get("drivers").is_none());
}

/// The same integration declaration as `eleph-gen-graphql-php`, so a spec means the same to both.
#[test]
fn describe_is_the_php_targets_declaration_apart_from_its_description() {
    let php = std::fs::read_to_string(
        Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/php-provides.json"),
    )
    .unwrap();
    let mut php: Value = serde_json::from_str(&php).unwrap();
    let ours = answer(
        &json!({"elephentity": 1, "irVersion": "1.2", "request": "describe", "target": "graphql-csharp"}),
    );
    let mut ours = ours["provides"].clone();

    php["integrations"]["graphql"]["description"] = json!(null);
    ours["integrations"]["graphql"]["description"] = json!(null);

    assert_eq!(ours, php);
}

#[test]
fn a_describe_without_a_request_key_is_a_generate() {
    let mut request = fixture("book");
    request.as_object_mut().unwrap().remove("request");

    assert_eq!(answer(&request)["errors"], json!([]));
}

// ----- the validation the PHP builder does, rule for rule -------------------------------------------------------------

fn generate_error(request: &Value) -> String {
    let response = answer(request);

    assert_eq!(response["files"], json!([]), "{response}");

    errors(&response).remove(0)
}

fn add_entity(request: &mut Value, name: &str, singular: &str, plural: &str) {
    let mut entity = request["schema"]["entities"]["Book"].clone();

    entity["name"] = json!(name);
    entity["integrations"] = json!({"graphql": {"singular": singular, "plural": plural}});
    entity["queries"] = json!({});
    entity["actions"] = json!({});
    request["schema"]["entities"][name] = entity;
}

#[test]
fn two_entities_cannot_share_a_graphql_type_name() {
    let mut request = fixture("book");
    add_entity(&mut request, "Volume", "Book", "Volumes");

    assert!(generate_error(&request).contains("\"Book\" is duplicated"));
}

#[test]
fn a_singular_and_a_plural_cannot_be_the_same_root_field() {
    let mut request = fixture("book");
    request["schema"]["entities"]["Book"]["integrations"]["graphql"]["plural"] = json!("Book");

    assert!(generate_error(&request).contains("root fields"));
}

#[test]
fn an_entity_named_like_a_type_the_builder_generates_is_refused() {
    let mut request = fixture("book");
    add_entity(&mut request, "Info", "PageInfo", "PageInfos");

    assert!(generate_error(&request).contains("\"PageInfo\" is duplicated"));

    let mut request = fixture("book");
    add_entity(
        &mut request,
        "Shadow",
        "RootQueryToBookConnection",
        "Shadows",
    );

    assert!(generate_error(&request).contains("RootQueryToBookConnection"));
}

#[test]
fn a_field_named_like_the_relay_envelope_or_the_identity_fields_is_refused() {
    for field in ["id", "databaseId", "clientMutationId"] {
        let mut request = fixture("book");
        let template = request["schema"]["entities"]["Book"]["fields"]
            .as_object()
            .unwrap()
            .values()
            .next()
            .unwrap()
            .clone();
        let mut renamed = template;

        renamed["name"] = json!(field);
        request["schema"]["entities"]["Book"]["fields"][field] = renamed;

        assert!(generate_error(&request).contains(field), "{field}");
    }
}

#[test]
fn a_published_query_must_return_an_exposed_entity() {
    let mut request = fixture("book");
    request["schema"]["entities"]["Book"]["integrations"] =
        json!({"graphql": {"singular": "Book", "plural": "Books"}});
    add_entity(&mut request, "Hidden", "Hidden", "Hiddens");
    request["schema"]["entities"]["Hidden"]
        .as_object_mut()
        .unwrap()
        .remove("integrations");
    request["schema"]["entities"]["Book"]["queries"]["search"]["returns"]["type"] = json!("Hidden");

    let problem = generate_error(&request);

    assert!(
        problem.contains("is not exposed")
            || problem.contains("duplicated")
            || problem.contains("Hidden"),
        "{problem}"
    );
}

#[test]
fn an_enum_with_no_values_or_two_labels_that_upper_case_alike_is_refused() {
    let mut request = fixture("relations");
    request["schema"]["types"]["PostStatus"]["values"] = json!(["draft", "Draft"]);

    assert!(generate_error(&request).contains("DRAFT"));

    request["schema"]["types"]["PostStatus"]["values"] = json!([]);

    assert!(generate_error(&request).contains("has no values"));
}

#[test]
fn a_request_from_another_envelope_or_ir_version_is_refused() {
    let mut newer_generate = fixture("book");
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
    let mut no_schema = fixture("book");
    no_schema.as_object_mut().unwrap().remove("schema");
    let mut no_project = fixture("book");
    no_project["schema"] = json!({"entities": {}});
    let mut not_an_object = fixture("book");
    not_an_object["config"] = json!("namespace");
    let mut unreadable = fixture("book");
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
            String::from_utf8_lossy(&output.stderr).starts_with("eleph-gen-graphql-csharp: "),
            "{input}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
}

#[test]
fn every_problem_in_the_spec_is_reported_in_one_run() {
    let mut request = fixture("book");
    let template = request["schema"]["entities"]["Book"]["fields"]["title"].clone();
    let mut envelope = template;

    envelope["name"] = json!("clientMutationId");
    request["schema"]["entities"]["Book"]["fields"]["clientMutationId"] = envelope;
    request["schema"]["entities"]["Book"]["integrations"]["graphql"]["plural"] = json!("Book");
    add_entity(&mut request, "Volume", "Book", "Volumes");

    let response = answer(&request);
    let problems = errors(&response);

    assert_eq!(response["files"], json!([]));
    assert!(
        problems.iter().any(|p| p.contains("clientMutationId")),
        "{problems:?}"
    );
    assert!(
        problems.iter().any(|p| p.contains("root fields")),
        "{problems:?}"
    );
    assert!(
        problems
            .iter()
            .any(|p| p.contains("\"Book\" is duplicated")),
        "{problems:?}"
    );
}

#[test]
fn configuration_and_spec_problems_come_back_together() {
    let mut request = fixture("book");
    request["config"] = json!({"namespace": "My-App", "entityNamespace": "App"});
    request["schema"]["entities"]["Book"]["integrations"]["graphql"]["plural"] = json!("Book");

    let problems = errors(&answer(&request));

    assert!(
        problems
            .iter()
            .any(|p| p.contains("not a valid C# namespace")),
        "{problems:?}"
    );
    assert!(
        problems.iter().any(|p| p.contains("root fields")),
        "{problems:?}"
    );
}

#[test]
fn an_unexposed_query_target_is_collected_with_the_rest() {
    let mut request = fixture("book");
    request["schema"]["entities"]["Book"]["queries"]["search"]["integrations"] =
        json!({"graphql": {"field": "searchBooks"}});
    add_entity(&mut request, "Hidden", "Hidden", "Hiddens");
    request["schema"]["entities"]["Hidden"]
        .as_object_mut()
        .unwrap()
        .remove("integrations");
    request["schema"]["entities"]["Book"]["queries"]["search"]["returns"]["type"] = json!("Hidden");
    request["schema"]["entities"]["Book"]["integrations"]["graphql"]["plural"] = json!("Book");

    let problems = errors(&answer(&request));

    assert!(
        problems.iter().any(|p| p.contains("is not exposed")),
        "{problems:?}"
    );
    assert!(
        problems.iter().any(|p| p.contains("root fields")),
        "{problems:?}"
    );
}
