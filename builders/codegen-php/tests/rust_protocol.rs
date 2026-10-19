use serde_json::{json, Value};
use std::{
    io::Write,
    process::{Command, Stdio},
};
fn invoke(request: &Value) -> std::process::Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-gen-php"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut stdin = child.stdin.take().unwrap();
    let payload = request.to_string();
    let writer = std::thread::spawn(move || stdin.write_all(payload.as_bytes()).unwrap());
    let output = child.wait_with_output().unwrap();
    writer.join().unwrap();
    output
}
#[test]
fn golden_responses() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/golden");
    for entry in std::fs::read_dir(root).unwrap() {
        let dir = entry.unwrap().path();
        let request: Value =
            serde_json::from_slice(&std::fs::read(dir.join("request.json")).unwrap()).unwrap();
        let expected: Value =
            serde_json::from_slice(&std::fs::read(dir.join("response.json")).unwrap()).unwrap();
        let output = invoke(&request);
        assert!(
            output.status.success(),
            "{}: {}",
            dir.display(),
            String::from_utf8_lossy(&output.stderr)
        );
        assert!(output.stderr.is_empty());
        let actual: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(expected, actual, "{}", dir.display());
    }
}
#[test]
fn rejects_unsupported_versions_and_malformed_schema() {
    for request in [
        json!({"elephentity":1,"irVersion":"99","request":"describe"}),
        json!({"elephentity":2,"irVersion":"1.2","request":"describe"}),
        json!({"elephentity":1,"irVersion":"1.2","target":"test","outputDirectory":"out","schema":{"project":{}}}),
    ] {
        let output = invoke(&request);
        assert!(!output.status.success());
        assert!(output.stdout.is_empty());
        assert!(!output.stderr.is_empty());
    }
}

#[test]
fn nullable_ir_members_still_require_their_keys() {
    let mut request = json!({
        "elephentity":1,"irVersion":"1.2","target":"test","outputDirectory":"out",
        "config":{"namespace":"Example","typeNamespace":"ExampleType"},
        "schema":{"project":{"name":"Test","driver":"wordpress","sourceFile":"project.yml"},
        "entities":{"Item":{"name":"Item","storage":{"driver":"wordpress","table":"item"},"sourceFile":"item.yml",
        "fields":{"name":{"name":"name","type":{"primitive":"string","declaredType":null},"origin":{"pattern":null,"file":"item.yml"}}}}}}
    });
    request["schema"]["entities"]["Item"]["fields"]["name"]["type"]
        .as_object_mut()
        .unwrap()
        .remove("declaredType");
    let output = invoke(&request);
    assert!(!output.status.success());
    assert!(output.stdout.is_empty());
}

#[test]
fn enum_collisions_return_errors_without_files() {
    let original: Value =
        serde_json::from_str(include_str!("fixtures/golden/valid/request.json")).unwrap();
    for inline in [false, true] {
        let mut request = original.clone();
        if inline {
            request["schema"]["entities"]["Post"]["fields"]["visibility"]["enum"] =
                json!({"inlineValues":["fooBar", "foo_bar"],"declaredType":null});
        } else {
            request["schema"]["types"]["PostStatus"]["values"] = json!(["fooBar", "foo_bar"]);
        }
        let output = invoke(&request);
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert!(output.stderr.is_empty());
        let response: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(response["files"], json!([]));
        let errors = response["errors"].as_array().unwrap();
        assert_eq!(errors.len(), 1);
        for label in ["fooBar", "foo_bar"] {
            assert!(errors[0].as_str().unwrap().contains(label));
        }
    }
}

#[test]
fn describe_advertises_the_checker() {
    let output = invoke(&json!({"elephentity":1,"irVersion":"1.2","request":"describe"}));
    assert!(output.status.success());
    let actual: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(
        actual,
        json!({"elephentity":1,"irVersion":"1.2","provides":{
            "check": {"command": ["eleph-check-php", "--project", "{project}", "--output", "{output}"]}
        }})
    );
}
