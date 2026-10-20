use serde_json::{json, Value};
use std::{
    fs,
    io::Write,
    path::Path,
    process::{Command, Stdio},
};
fn invoke(args: &[&str], request: &Value) -> std::process::Output {
    let mut child = Command::new(env!("CARGO_BIN_EXE_eleph-codegen"))
        .args(args)
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
    output
}
fn request() -> Value {
    json!({"elephentity":1,"irVersion":"1.2","schema":{"opaque":"the orchestrator must not inspect this"}})
}
fn response() -> Value {
    json!({"elephentity":1,"irVersion":"1.2","headerStyle":"php","extensions":["php"],"files":[{"path":"Post.php","body":"namespace Fixture;\n\nfinal class Post\n{\n}\n"}],"errors":[]})
}
#[cfg(unix)]
fn builder(root: &Path, name: &str, response: &Value, output_first: bool) {
    use std::os::unix::fs::PermissionsExt;
    let path = root.join(name);
    let print = format!(
        "printf '%s' '{}'\n",
        response.to_string().replace('\'', "'\\''")
    );
    let script = if output_first {
        format!("#!/bin/sh\n{print}cat >/dev/null\n")
    } else {
        format!("#!/bin/sh\ncat >/dev/null\n{print}")
    };
    fs::write(&path, script).unwrap();
    fs::set_permissions(path, fs::Permissions::from_mode(0o755)).unwrap();
}
fn config(root: &Path, targets: Value) {
    fs::write(
        root.join("eleph.json"),
        json!({"spec":"spec","targets":targets}).to_string(),
    )
    .unwrap();
}
#[test]
fn rejects_unsupported_compiler_version() {
    let output = invoke(
        &["generate"],
        &json!({"elephentity":1,"irVersion":"99","schema":{}}),
    );
    assert_eq!(output.status.code(), Some(2));
}
#[test]
#[cfg(unix)]
fn rejects_output_aliases_before_writing() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    fs::create_dir(root.join("generated")).unwrap();
    std::os::unix::fs::symlink(root.join("generated"), root.join("alias")).unwrap();
    fs::write(root.join("generated/keep.php"), "keep").unwrap();
    builder(root, "builder", &response(), false);
    for alias in [
        "./generated",
        "unused/../generated",
        "alias",
        root.join("generated").to_str().unwrap(),
    ] {
        config(
            root,
            json!({"a":{"output":"generated","builder":"./builder"},"b":{"output":alias,"builder":"./builder"}}),
        );
        let output = invoke(
            &["generate", "--project", root.to_str().unwrap()],
            &request(),
        );
        assert_eq!(output.status.code(), Some(1));
        assert!(String::from_utf8_lossy(&output.stderr).contains("all write to"));
        assert_eq!(
            fs::read_to_string(root.join("generated/keep.php")).unwrap(),
            "keep"
        );
        assert!(!root.join("generated/Post.php").exists());
    }
}
#[test]
#[cfg(unix)]
fn signs_checks_and_preserves_nested_targets() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    let path = root.to_str().unwrap();
    builder(root, "builder", &response(), false);
    config(
        root,
        json!({"php":{"output":"./generated","builder":"./builder"},"nested":{"output":"generated/unused/../nested","builder":"./builder"}}),
    );
    let output = invoke(&["generate", "--project", path], &request());
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let generated = root.join("generated/Post.php");
    let signed = fs::read_to_string(&generated).unwrap();
    assert!(
        signed.contains("sha256:cff42c4dd731d4940868532b99381523560408f85b8d8d103321e24bc02cde0f")
    );
    assert!(
        invoke(&["generate", "--project", path, "--check"], &request())
            .status
            .success()
    );
    fs::write(&generated, signed.replace("class Post", "class Edited")).unwrap();
    let before = fs::read(&generated).unwrap();
    let output = invoke(&["generate", "--project", path, "--check"], &request());
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stdout).contains("Hand-edited"));
    assert_eq!(before, fs::read(&generated).unwrap());
    fs::write(root.join("generated/stale.php"), "old").unwrap();
    fs::write(root.join("generated/notes.txt"), "keep").unwrap();
    fs::write(root.join("generated/nested/extra.php"), "reserved").unwrap();
    assert!(invoke(
        &["generate", "--project", path, "--targets", "php"],
        &request()
    )
    .status
    .success());
    assert!(!root.join("generated/stale.php").exists());
    assert!(root.join("generated/notes.txt").exists());
    assert!(root.join("generated/nested/extra.php").exists());
}
#[test]
#[cfg(unix)]
fn pools_errors_before_writing_any_target() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    builder(root, "good", &response(), false);
    builder(
        root,
        "bad-a",
        &json!({"elephentity":1,"irVersion":"1.2","headerStyle":"php","errors":["first failure"]}),
        false,
    );
    builder(
        root,
        "bad-b",
        &json!({"elephentity":1,"irVersion":"1.2","headerStyle":"php","errors":["second failure"]}),
        false,
    );
    config(
        root,
        json!({"good":{"output":"good-out","builder":"./good"},"a":{"output":"a-out","builder":"./bad-a"},"b":{"output":"b-out","builder":"./bad-b"}}),
    );
    let output = invoke(
        &["generate", "--project", root.to_str().unwrap()],
        &request(),
    );
    assert_eq!(output.status.code(), Some(1));
    let text = String::from_utf8_lossy(&output.stdout);
    assert!(text.contains("first failure") && text.contains("second failure"));
    assert!(!root.join("good-out").exists());
}
#[test]
#[cfg(unix)]
fn rejects_paths_outside_output_and_forwards_large_payloads_without_deadlock() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    config(
        root,
        json!({"php":{"output":"generated","builder":"./builder"}}),
    );
    let mut bad = response();
    bad["files"][0]["path"] = json!("../escape.php");
    builder(root, "builder", &bad, false);
    assert!(!invoke(
        &["generate", "--project", root.to_str().unwrap()],
        &request()
    )
    .status
    .success());
    assert!(!root.join("escape.php").exists());
    let mut large = response();
    large["files"][0]["body"] = json!("x".repeat(256 * 1024));
    builder(root, "builder", &large, true);
    let mut input = request();
    input["schema"]["large"] = json!("y".repeat(256 * 1024));
    assert!(
        invoke(&["generate", "--project", root.to_str().unwrap()], &input)
            .status
            .success()
    );
    assert!(fs::metadata(root.join("generated/Post.php")).unwrap().len() > 256 * 1024);
}

#[test]
#[cfg(unix)]
fn a_pinned_builder_is_found_without_its_version_and_source_is_kept_from_it() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    let path = root.to_str().unwrap();
    fs::create_dir_all(root.join("tools/builders")).unwrap();
    // The script reports the config it was handed, so the test can see what reached a builder.
    builder(
        &root.join("tools/builders"),
        "eleph-gen-fixture",
        &response(),
        false,
    );
    config(
        root,
        json!({"php":{"output":"generated","builder":"eleph-gen-fixture@1.2.3","source":"owner/repo","flag":true}}),
    );

    let targets = invoke(&["targets", "--project", path], &request());
    let described: Value = serde_json::from_slice(&targets.stdout).unwrap();

    assert_eq!(
        described["targets"]["php"],
        json!({"output":"generated","builder":"eleph-gen-fixture@1.2.3","source":"owner/repo"})
    );

    let output = invoke(&["generate", "--project", path], &request());

    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(root.join("generated/Post.php").exists());
}

#[test]
#[cfg(unix)]
fn a_path_containing_an_at_sign_is_never_split() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    let path = root.to_str().unwrap();
    fs::create_dir_all(root.join("node_modules/@scope")).unwrap();
    builder(&root.join("node_modules/@scope"), "gen", &response(), false);
    config(
        root,
        json!({"php":{"output":"generated","builder":"node_modules/@scope/gen"}}),
    );

    let output = invoke(&["generate", "--project", path], &request());

    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
fn a_missing_pinned_builder_is_reported_by_its_bare_name() {
    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    config(
        root,
        json!({"php":{"output":"generated","builder":"eleph-gen-nothing@9.9.9"}}),
    );

    let output = invoke(
        &["generate", "--project", root.to_str().unwrap()],
        &request(),
    );
    let said = String::from_utf8_lossy(&output.stdout);

    assert_eq!(output.status.code(), Some(1));
    assert!(said.contains("No builder \"eleph-gen-nothing\""), "{said}");
    assert!(said.contains("tools/builders/eleph-gen-nothing"), "{said}");
    assert!(said.contains("`eleph install`"), "{said}");
}

#[test]
#[cfg(unix)]
fn describe_is_given_the_targets_config_without_the_keys_this_program_owns() {
    use std::os::unix::fs::PermissionsExt;

    let dir = tempfile::tempdir().unwrap();
    let root = dir.path();
    let path = root.to_str().unwrap();
    fs::create_dir_all(root.join("tools/builders")).unwrap();
    // Records what it was asked, then answers describe.
    let script = root.join("tools/builders/eleph-gen-recorder");
    fs::write(
        &script,
        format!(
            "#!/bin/sh\ncat > '{}'\nprintf '%s' '{{\"elephentity\":1,\"irVersion\":\"1.2\",\"provides\":{{}}}}'\n",
            root.join("asked.json").display()
        ),
    )
    .unwrap();
    fs::set_permissions(&script, fs::Permissions::from_mode(0o755)).unwrap();
    config(
        root,
        json!({"cs":{"output":"generated","builder":"eleph-gen-recorder@1.0.0","source":"owner/repo","check":["dotnet","test"]}}),
    );

    let output = invoke(&["describe", "--project", path], &request());

    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );

    let asked: Value = serde_json::from_slice(&fs::read(root.join("asked.json")).unwrap()).unwrap();

    assert_eq!(asked["request"], "describe");
    assert_eq!(asked["target"], "cs");
    assert_eq!(
        asked["config"],
        json!({"output":"generated","builder":"eleph-gen-recorder@1.0.0","check":["dotnet","test"]})
    );
    assert!(asked.get("schema").is_none());
}
