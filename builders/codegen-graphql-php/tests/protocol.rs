use serde_json::{json, Value};
use std::{
    io::Write,
    process::{Command, Stdio},
};
fn invoke(request: &Value) -> std::process::Output {
    raw(&request.to_string())
}
fn raw(payload: &str) -> std::process::Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-graphql-php"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let payload = payload.as_bytes().to_vec();
    let writer = std::thread::spawn(move || stdin.write_all(&payload).unwrap());
    let result = child.wait_with_output().unwrap();
    writer.join().unwrap();
    result
}
fn fixture(name: &str) -> Value {
    serde_json::from_slice(
        &std::fs::read(format!(
            "{}/tests/fixtures/{name}.json",
            env!("CARGO_MANIFEST_DIR")
        ))
        .unwrap(),
    )
    .unwrap()
}
fn generate(request: &Value) -> Value {
    let output = invoke(request);
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(output.stderr.is_empty());
    serde_json::from_slice(&output.stdout).unwrap()
}
#[test]
fn freezes_real_binary_output() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures");
    for entry in std::fs::read_dir(root).unwrap() {
        let path = entry.unwrap().path();
        let name = path.file_name().unwrap().to_string_lossy();
        if !name.ends_with(".response.json") {
            continue;
        }
        let expected: Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
        let actual = generate(&fixture(name.trim_end_matches(".response.json")));
        assert_eq!(actual, expected, "{}", path.display());
        for file in actual["files"].as_array().unwrap() {
            let body = file["body"].as_str().unwrap();
            assert!(!body.starts_with("<?php"));
            assert!(!body.contains("Eleph\\WordPress"));
            assert!(!body.contains("Eleph\\WPGraphQL"));
        }
    }
}
#[test]
fn rejects_invalid_envelopes_without_partial_output() {
    for request in [
        json!({"elephentity":2,"irVersion":"1.2","request":"describe"}),
        json!({"elephentity":1,"irVersion":"1.1","request":"describe"}),
        json!({"elephentity":1,"irVersion":"1.2","request":"unknown"}),
        json!({"elephentity":1,"irVersion":"1.2","request":false}),
        json!({"elephentity":1,"irVersion":"1.2","schema":{"project":{}}}),
    ] {
        let output = invoke(&request);
        assert!(!output.status.success());
        assert!(output.stdout.is_empty());
        assert!(!output.stderr.is_empty());
    }
    for text in ["", "{", "{} {}", "[]"] {
        let output = raw(text);
        assert!(!output.status.success());
        assert!(output.stdout.is_empty());
    }
}
#[test]
fn requires_nullable_ir_keys() {
    let mut request = fixture("book");
    let fields = request["schema"]["entities"]["Book"]["fields"]
        .as_object_mut()
        .unwrap();
    fields.values_mut().next().unwrap()["type"]
        .as_object_mut()
        .unwrap()
        .remove("declaredType");
    let output = invoke(&request);
    assert!(!output.status.success());
    assert!(output.stdout.is_empty());
}
#[test]
fn exposes_generic_integration_and_skips_unexposed_projects() {
    let description = generate(&json!({"elephentity":1,"irVersion":"1.2","request":"describe"}));
    assert!(description["provides"]["integrations"]["graphql"].is_object());
    let mut request = fixture("book");
    request["schema"]["project"]["integrations"] = json!([]);
    assert_eq!(generate(&request)["files"], json!([]));
}
#[test]
fn refuses_schema_name_collisions_and_unexposed_query_returns() {
    for scenario in 0..6 {
        let mut request = fixture("book");
        match scenario {
            0 => {
                request["schema"]["entities"]["Book"]["integrations"]["graphql"]["singular"] =
                    json!("__Book")
            }
            1 => {
                request["schema"]["entities"]["Book"]["integrations"]["graphql"]["plural"] =
                    json!("Book")
            }
            2 => request["schema"]["entities"]["Book"]["fields"]["title"]["name"] = json!("id"),
            3 => {
                request["schema"]["entities"]["Book"]["fields"]["status"]["enum"]["inlineValues"] =
                    json!(["draft", "DRAFT"])
            }
            4 => {
                request["schema"]["entities"]["Book"]["queries"]["search"]["returns"]["type"] =
                    json!("Missing")
            }
            _ => {
                request["schema"]["entities"]["Book"]["actions"]["rename"]["name"] = json!("create")
            }
        }
        let response = generate(&request);
        assert_eq!(response["files"], json!([]));
        assert!(
            !response["errors"].as_array().unwrap().is_empty(),
            "scenario {scenario}"
        );
    }
}
#[test]
fn gives_default_collection_a_distinct_name() {
    let mut request = fixture("book");
    request["schema"]["entities"]["Book"]["integrations"]["graphql"] = json!({});
    let response = generate(&request);
    assert_eq!(response["errors"], json!([]));
    assert!(response.to_string().contains("BookCollection"));
}

#[test]
fn generated_php_has_checked_types_at_the_boundary() {
    let response = generate(&fixture("book"));
    let body = response["files"]
        .as_array()
        .unwrap()
        .iter()
        .find(|file| file["path"] == "verify.php")
        .unwrap()["body"]
        .as_str()
        .unwrap();
    assert!(body.contains("assert($manifest instanceof Manifest);"));
}

#[test]
fn refuses_every_automatic_type_collision_before_emitting_files() {
    for name in [
        "RootQueryToBookConnection",
        "RootQueryToBookConnectionEdge",
        "CreateBookInput",
        "UpdateBookPayload",
        "RenameBookInput",
        "RootQueryToSearchBooksConnectionWhereArgs",
        "RootQueryToSearchBooksConnection",
    ] {
        let mut request = fixture("book");
        let mut shadow = request["schema"]["entities"]["Book"].clone();
        shadow["name"] = json!("Shadow");
        shadow["integrations"]["graphql"] =
            json!({"singular":name,"plural":format!("{name}Collection")});
        shadow["queries"] = json!({});
        shadow["actions"] = json!({});
        shadow["fields"] = json!({});
        request["schema"]["entities"]["Shadow"] = shadow;
        let response = generate(&request);
        assert_eq!(response["files"], json!([]), "{name}");
        assert!(!response["errors"].as_array().unwrap().is_empty(), "{name}");
    }
}

#[test]
fn reserves_forward_inverse_and_self_connection_types() {
    for name in [
        "PostToCommentConnection",
        "AuthorToCommentConnectionEdge",
        "PostToPostConnection",
        "TagToPostConnection",
    ] {
        let mut request = fixture("relations");
        let mut shadow = request["schema"]["entities"]["Author"].clone();
        shadow["name"] = json!("Shadow");
        shadow["integrations"]["graphql"] =
            json!({"singular":name,"plural":format!("{name}Collection")});
        shadow["fields"] = json!({});
        request["schema"]["entities"]["Shadow"] = shadow;
        let response = generate(&request);
        assert_eq!(response["files"], json!([]), "{name}");
        assert!(!response["errors"].as_array().unwrap().is_empty());
    }
}
