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
        &json!({"elephentity":1,"irVersion":"1.2","config":{"language":"php"},"schema":{"project":{"name":"Empty","driver":"sqlite","sourceFile":"p.yml"},"entities":[],"types":[]}}),
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

fn file(response: &Value, path: &str) -> String {
    response["files"]
        .as_array()
        .unwrap()
        .iter()
        .find(|file| file["path"] == path)
        .unwrap()["body"]
        .as_str()
        .unwrap()
        .into()
}

#[test]
fn field_defaults_become_column_defaults() {
    let mut request = fixture("many-to-one");
    let fields = &mut request["schema"]["entities"]["Owner"]["fields"];
    fields["checked"] = json!({"name":"checked","type":{"primitive":"bool","declaredType":null},"origin":{"pattern":null,"file":"fixture.yml"},"nullable":false,"hasDefault":true,"default":false});
    fields["mood"] = json!({"name":"mood","type":{"primitive":"enum","declaredType":null},"enum":{"inlineValues":["calm","busy"],"declaredType":null},"origin":{"pattern":null,"file":"fixture.yml"},"nullable":false,"hasDefault":true,"default":"busy"});
    fields["rank"]["hasDefault"] = json!(true);
    fields["rank"]["default"] = json!(-3);
    fields["name"]["hasDefault"] = json!(true);
    fields["name"]["default"] = json!(null);
    let response = generate(&request);
    assert_eq!(response["errors"], json!([]));
    let install = file(&response, "install.php");
    assert!(install.contains(r#""checked" INTEGER NOT NULL DEFAULT 0 CHECK"#));
    assert!(install.contains(r#""mood" TEXT NOT NULL DEFAULT \'busy\' CHECK"#));
    assert!(install.contains(r#""rank" INTEGER DEFAULT -3"#));
    assert!(!install.contains(r#""name" TEXT DEFAULT"#));
    let manifest = file(&response, "storage-manifest.php");
    assert!(manifest.contains("new Column('checked', 'INTEGER', false, false, '0')"));
    assert!(manifest.contains("new Column('name', 'TEXT', true, false, null)"));

    for (field, default) in [("checked", json!("yes")), ("mood", json!("asleep"))] {
        let mut request = request.clone();
        request["schema"]["entities"]["Owner"]["fields"][field]["default"] = default;
        let response = generate(&request);
        assert_eq!(response["files"], json!([]));
        assert!(
            !response["errors"].as_array().unwrap().is_empty(),
            "{field}"
        );
    }
}

fn csharp(name: &str) -> Value {
    let mut request = fixture(name);
    request["config"] = json!({"language": "csharp", "namespace": format!("Golden.{}", name.split('-').map(|p| p[..1].to_uppercase() + &p[1..]).collect::<String>())});
    request
}

const FIXTURES: [&str; 6] = [
    "clog",
    "relations",
    "one-to-one",
    "many-to-one",
    "one-to-many",
    "many-to-many",
];

#[test]
fn freezes_the_csharp_output() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/csharp");
    for name in FIXTURES {
        let response = generate(&csharp(name));
        assert_eq!(response["errors"], json!([]), "{name}");
        assert_eq!(response["headerStyle"], "line-comment");
        assert_eq!(response["extensions"], json!(["cs"]));
        assert_eq!(response["files"].as_array().unwrap().len(), 1);
        let path = root.join(name).join("SqliteStorageManifest.cs");
        let body = file(&response, "SqliteStorageManifest.cs");
        if std::env::var_os("ELEPH_REFRESH").is_some() {
            std::fs::create_dir_all(path.parent().unwrap()).unwrap();
            std::fs::write(&path, &body).unwrap();
        }
        assert_eq!(
            body,
            std::fs::read_to_string(&path).unwrap(),
            "{}",
            path.display()
        );
        assert!(!body.contains("<?php"));
    }
}

#[test]
fn the_language_is_required_and_must_be_known() {
    for config in [
        json!({}),
        json!(null),
        json!({"language": null}),
        json!({"language": "ruby"}),
        json!({"language": 3}),
        json!({"language": "PHP"}),
    ] {
        let mut request = fixture("one-to-one");
        request["config"] = config.clone();
        let response = generate(&request);
        assert_eq!(response["files"], json!([]), "{config}");
        assert_eq!(response["extensions"], json!([]), "{config}");
        assert_eq!(response["headerStyle"], "line-comment");
        let errors = response["errors"].as_array().unwrap();
        assert_eq!(errors.len(), 1, "{config}");
        assert!(
            errors[0].as_str().unwrap().contains("\"language\""),
            "{config}"
        );
    }
    let mut request = fixture("one-to-one");
    request.as_object_mut().unwrap().remove("config");
    assert!(!generate(&request)["errors"].as_array().unwrap().is_empty());
}

#[test]
fn a_csharp_target_needs_a_namespace_that_is_a_namespace() {
    for namespace in [
        json!(null),
        json!(""),
        json!("a..b"),
        json!("App.class"),
        json!("1App"),
        json!("My-App"),
        json!(7),
    ] {
        let mut request = fixture("one-to-one");
        request["config"] = json!({"language": "csharp", "namespace": namespace});
        let response = generate(&request);
        assert_eq!(response["files"], json!([]), "{namespace}");
        assert_eq!(
            response["extensions"],
            json!([]),
            "a failed run claims nothing: {namespace}"
        );
        assert!(
            response["errors"][0]
                .as_str()
                .unwrap()
                .contains("namespace"),
            "{namespace}"
        );
    }
    let mut request = fixture("one-to-one");
    request["config"] = json!({"language": "csharp"});
    assert!(!generate(&request)["errors"].as_array().unwrap().is_empty());
}

#[test]
fn a_php_target_ignores_the_namespace_and_claims_php_even_when_it_fails() {
    let mut request = fixture("one-to-one");
    request["config"] = json!({"language": "php", "namespace": "not a namespace"});
    let response = generate(&request);
    assert_eq!(response["errors"], json!([]));
    assert_eq!(response["headerStyle"], "php");
    assert_eq!(response["extensions"], json!(["php"]));
    request["schema"]["project"]["driver"] = json!("cosmos");
    let failed = generate(&request);
    assert_eq!(failed["extensions"], json!(["php"]));
    assert_eq!(failed["files"], json!([]));
}

#[test]
fn every_problem_comes_back_at_once() {
    let mut request = fixture("one-to-one");
    request["config"] = json!({"language": "csharp", "namespace": "class"});
    request["schema"]["project"]["driver"] = json!("cosmos");
    assert_eq!(generate(&request)["errors"].as_array().unwrap().len(), 2);
    request["config"] = json!({});
    assert_eq!(generate(&request)["errors"].as_array().unwrap().len(), 2);
}

#[test]
fn csharp_refuses_what_php_refuses() {
    for scenario in 0..5 {
        let mut request = csharp("one-to-one");
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
        assert_eq!(response["files"], json!([]), "scenario {scenario}");
        assert_eq!(response["extensions"], json!([]), "scenario {scenario}");
        assert!(
            !response["errors"].as_array().unwrap().is_empty(),
            "scenario {scenario}"
        );
    }
}

#[test]
fn csharp_literals_never_end_a_line_or_a_string() {
    let mut request = csharp("many-to-one");
    let fields = &mut request["schema"]["entities"]["Owner"]["fields"];
    fields["mood"] = json!({"name":"mood","type":{"primitive":"enum","declaredType":null},"enum":{"inlineValues":["a\"b","c\\d","it's"],"declaredType":null},"origin":{"pattern":null,"file":"fixture.yml"},"nullable":false,"hasDefault":true,"default":"it's"});
    let body = file(&generate(&request), "SqliteStorageManifest.cs");
    assert!(body.contains("DEFAULT 'it''s'"));
    assert!(body.contains("'a\\\"b'"));
    assert!(body.contains("'c\\\\d'"));
    assert!(body.lines().all(|line| !line.contains('\r')));
}

#[test]
fn the_csharp_target_provides_the_same_driver() {
    let describe = generate(
        &json!({"elephentity":1,"irVersion":"1.2","request":"describe","config":{"language":"csharp"}}),
    );
    assert_eq!(describe["provides"]["drivers"], json!(["sqlite"]));
}
