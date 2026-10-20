use serde_json::{json, Value};
use std::{
    io::Write,
    process::{Command, Stdio},
};
fn invoke(request: &Value) -> std::process::Output {
    raw(&request.to_string())
}
fn raw(payload: &str) -> std::process::Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-sqlite"))
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
    let mut request = fixture("one-to-one");
    let fields = request["schema"]["entities"]["Owner"]["fields"]
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
fn advertises_sqlite_and_rejects_wrong_driver() {
    let describe = generate(&json!({"elephentity":1,"irVersion":"1.2","request":"describe"}));
    assert_eq!(describe["provides"]["drivers"], json!(["sqlite"]));
    let mut request = fixture("one-to-one");
    request["schema"]["project"]["driver"] = json!("wordpress");
    let result = generate(&request);
    assert_eq!(result["files"], json!([]));
    assert!(!result["errors"].as_array().unwrap().is_empty());
}
#[test]
fn refuses_identifier_collisions_and_unknown_edges() {
    for scenario in 0..5 {
        let mut request = fixture("one-to-one");
        match scenario {
            0 => request["schema"]["entities"]["Target"]["storage"]["table"] = json!("OWNER"),
            1 => {
                request["schema"]["entities"]["Owner"]["storage"]["table"] =
                    json!("owner\"; DROP TABLE target;--")
            }
            2 => request["schema"]["entities"]["Owner"]["edges"]["target"]["to"] = json!("Missing"),
            3 => {
                request["schema"]["entities"]["Owner"]["fields"]["name"]["name"] = json!("targetId")
            }
            _ => request["schema"]["entities"]["Owner"]["config"] = json!({"account":true}),
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
fn accepts_php_empty_maps_and_does_not_double_prefix_tables() {
    let response = generate(
        &json!({"elephentity":1,"irVersion":"1.2","schema":{"project":{"name":"Empty","driver":"sqlite","sourceFile":"p.yml"},"entities":[],"types":[]}}),
    );
    assert_eq!(response["errors"], json!([]));
    let mut request = fixture("one-to-one");
    request["schema"]["project"]["tablePrefix"] = json!("domain_");
    request["schema"]["entities"]["Owner"]["storage"]["table"] = json!("domain_owner");
    let output = generate(&request).to_string();
    assert!(output.contains("domain_owner"));
    assert!(!output.contains("domain_domain_owner"));
}

#[test]
fn generated_php_has_checked_types_at_the_boundary() {
    let response = generate(&fixture("many-to-one"));
    let body = response["files"]
        .as_array()
        .unwrap()
        .iter()
        .find(|file| file["path"] == "install.php")
        .unwrap()["body"]
        .as_str()
        .unwrap();
    assert!(body.contains("is_string($detail)"));
}
