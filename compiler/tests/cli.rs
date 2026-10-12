//! The `eleph` binary, run as a user runs it, against a stand-in for `eleph-codegen`.
//!
//! The stand-in records the arguments and stdin it is given and answers `describe` from a file,
//! so these tests can say exactly what crosses the pipe between the compiler and the generator
//! without needing the real one. The real one is exercised by `tools/test-rust-cli`.

use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Command, Output};

use serde_json::Value;
use tempfile::TempDir;

const ELEPH: &str = env!("CARGO_BIN_EXE_eleph");

fn stub() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/cli/stub-codegen")
}

/// A project on disk with a stand-in generator, and somewhere for it to write down what it saw.
struct Project {
    dir: TempDir,
    log: TempDir,
}

impl Project {
    fn new(describe: &str) -> Self {
        let dir = tempfile::tempdir().unwrap();
        let log = tempfile::tempdir().unwrap();

        fs::write(log.path().join("describe.json"), describe).unwrap();
        fs::write(
            dir.path().join("eleph.json"),
            format!(
                r#"{{"spec": "spec", "codegen": "{}", "targets": {{}}}}"#,
                stub().display()
            ),
        )
        .unwrap();
        fs::create_dir_all(dir.path().join("spec/entities")).unwrap();
        fs::write(
            dir.path().join("spec/project.yml"),
            "project: Demo\nstorage:\n  driver: stub\n",
        )
        .unwrap();
        fs::write(
            dir.path().join("spec/entities/Note.yml"),
            "entity: Note\nstorage:\n  table: notes\nfields:\n  title:\n    type: string\n",
        )
        .unwrap();

        Self { dir, log }
    }

    fn with_stub_driver() -> Self {
        Self::new(r#"{"targets": {"stub": {"drivers": ["stub"]}}}"#)
    }

    fn path(&self) -> &Path {
        self.dir.path()
    }

    fn write(&self, relative: &str, contents: &str) {
        let path = self.path().join(relative);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, contents).unwrap();
    }

    fn eleph(&self, arguments: &[&str]) -> Output {
        self.eleph_with(arguments, &[])
    }

    fn eleph_with(&self, arguments: &[&str], environment: &[(&str, &str)]) -> Output {
        Command::new(ELEPH)
            .args(arguments)
            .current_dir(self.path())
            .env("STUB_LOG", self.log.path())
            .env("STUB_DESCRIBE", self.log.path().join("describe.json"))
            .env("STUB_TARGETS", self.log.path().join("targets.json"))
            .envs(environment.iter().copied())
            .output()
            .unwrap()
    }

    /// What `eleph-codegen targets` answers.
    fn targets(&self, json: &str) {
        fs::write(self.log.path().join("targets.json"), json).unwrap();
    }

    /// A program inside the project, as a builder's checker would be.
    fn program(&self, relative: &str, body: &str) {
        use std::os::unix::fs::PermissionsExt;

        self.write(relative, &format!("#!/bin/sh\n{body}\n"));

        let path = self.path().join(relative);
        let mut permissions = fs::metadata(&path).unwrap().permissions();
        permissions.set_mode(0o755);
        fs::set_permissions(path, permissions).unwrap();
    }

    fn recorded(&self, file: &str) -> String {
        fs::read_to_string(self.log.path().join(file)).unwrap_or_default()
    }
}

fn stdout(output: &Output) -> String {
    String::from_utf8_lossy(&output.stdout).into_owned()
}

fn stderr(output: &Output) -> String {
    String::from_utf8_lossy(&output.stderr).into_owned()
}

#[test]
fn it_reports_its_version() {
    let output = Command::new(ELEPH).arg("--version").output().unwrap();

    assert!(output.status.success());
    assert!(stdout(&output).starts_with("eleph "), "{}", stdout(&output));
}

#[test]
fn an_unknown_command_is_invalid() {
    let output = Command::new(ELEPH).arg("nope").output().unwrap();

    assert_eq!(output.status.code(), Some(2));
    assert!(stderr(&output).contains("nope"), "{}", stderr(&output));
}

#[test]
fn fmt_passes_specs_in_canonical_order() {
    let project = Project::with_stub_driver();

    let output = project.eleph(&["fmt"]);

    assert_eq!(output.status.code(), Some(0), "{}", stdout(&output));
    assert!(stdout(&output).contains("[OK] Specs are in canonical form."));
}

#[test]
fn fmt_names_the_file_the_path_and_the_order_expected() {
    let project = Project::with_stub_driver();
    project.write(
        "spec/entities/Note.yml",
        "storage:\n  table: notes\nentity: Note\nfields:\n  title:\n    description: A title.\n    type: string\n",
    );

    let output = project.eleph(&["fmt"]);

    assert_eq!(output.status.code(), Some(1));

    let text = stdout(&output);

    assert!(text.contains("[ERROR] 2 formatting problem(s)."), "{text}");
    assert!(
        text.contains("Keys are out of canonical order. Expected: entity, storage, fields."),
        "{text}"
    );
    assert!(text.contains("/fields/title"), "{text}");
    assert!(text.contains("Expected: type, description."), "{text}");
    assert!(text.contains("./spec/entities/Note.yml"), "{text}");
    assert!(text.contains("[format.keyOrder]"), "{text}");
}

#[test]
fn fmt_without_a_project_file_says_what_it_needs() {
    let empty = tempfile::tempdir().unwrap();

    let output = Command::new(ELEPH)
        .arg("fmt")
        .current_dir(empty.path())
        .output()
        .unwrap();

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stderr(&output).contains("No eleph.json in ."),
        "{}",
        stderr(&output)
    );
}

#[test]
fn validate_asks_the_generator_what_is_installed() {
    let project = Project::with_stub_driver();

    let output = project.eleph(&["validate"]);

    assert_eq!(output.status.code(), Some(0), "{}", stdout(&output));
    assert!(stdout(&output).contains("[OK] Specs are valid: 1 entity, 0 types."));
    assert_eq!(project.recorded("args").trim(), "describe --project .");
}

#[test]
fn validate_reports_every_problem_in_one_run() {
    let project = Project::with_stub_driver();
    project.write(
        "spec/entities/Note.yml",
        "entity: Note\nstorage:\n  table: notes\nfields:\n  id:\n    type: string\n  other:\n    type: Mystery\nedges:\n  owner:\n    to: Nobody\n    cardinality: one\n",
    );

    let output = project.eleph(&["validate"]);

    assert_eq!(output.status.code(), Some(1));

    let text = stdout(&output);

    assert!(text.contains("3 problem(s) found in spec"), "{text}");
    assert!(text.contains("[field.reserved]"), "{text}");
    assert!(text.contains("[type.unknown]"), "{text}");
    assert!(text.contains("[edge.unknownTarget]"), "{text}");
}

#[test]
fn validate_takes_a_spec_directory_that_must_exist() {
    let project = Project::with_stub_driver();

    let output = project.eleph(&["validate", "nowhere"]);

    assert_eq!(output.status.code(), Some(2));
    assert!(stdout(&output).contains("No such directory: nowhere"));
}

#[test]
fn validate_with_provides_reads_a_saved_describe_and_never_runs_a_builder() {
    let project = Project::with_stub_driver();
    fs::write(
        project.path().join("saved.json"),
        r#"{"targets": {"stub": {}}}"#,
    )
    .unwrap();
    // No generator can be found, and no eleph.json is read.
    fs::remove_file(project.path().join("eleph.json")).unwrap();

    let output = project.eleph(&["validate", "--provides", "saved.json", "spec"]);

    assert_eq!(output.status.code(), Some(0), "{}", stdout(&output));
    assert!(stdout(&output).contains("Specs are valid: 1 entity"));
    assert!(stdout(&output).contains(
        "Validated against supplied metadata; installed builder compatibility was not checked."
    ));
    assert_eq!(project.recorded("args"), "");
}

#[test]
fn validate_with_provides_checks_names_against_what_was_supplied() {
    let project = Project::with_stub_driver();
    fs::write(
        project.path().join("saved.json"),
        r#"{"targets": {"stub": {}}}"#,
    )
    .unwrap();
    project.write(
        "spec/entities/Note.yml",
        "entity: Note\nstorage:\n  table: notes\nintegrations:\n  graphql: {}\nfields:\n  title:\n    type: string\n",
    );

    let output = project.eleph(&["validate", "--provides", "saved.json", "spec"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains("[integration.notEnabled]")
            || stdout(&output).contains("[integration.unknown]")
    );
}

#[test]
fn a_missing_or_unusable_provides_file_fails_without_falling_back_to_discovery() {
    let project = Project::with_stub_driver();
    fs::write(project.path().join("bad.json"), "not json").unwrap();

    let missing = project.eleph(&["validate", "--provides", "gone.json", "spec"]);
    let unusable = project.eleph(&["validate", "--provides", "bad.json", "spec"]);

    for output in [&missing, &unusable] {
        assert_eq!(output.status.code(), Some(1));
        assert!(
            !stdout(output).contains("Point --project at"),
            "{}",
            stdout(output)
        );
    }

    assert!(stdout(&missing).contains("--provides must name a readable describe JSON file."));
    assert!(stdout(&unusable).contains("described the builders unusably"));
    assert_eq!(project.recorded("args"), "");
}

#[test]
fn validate_without_a_project_says_how_to_proceed() {
    let project = Project::with_stub_driver();
    fs::remove_file(project.path().join("eleph.json")).unwrap();

    let output = project.eleph(&["validate"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(stdout(&output).contains("No eleph.json in ."));
    assert!(stdout(&output).contains(
        "Point --project at the directory holding eleph.json, or use --provides with saved describe JSON."
    ));
}

#[test]
fn generate_pipes_the_compiled_request_to_the_generator() {
    let project = Project::with_stub_driver();

    let output = project.eleph(&["generate"]);

    assert_eq!(
        output.status.code(),
        Some(0),
        "{}{}",
        stdout(&output),
        stderr(&output)
    );
    assert_eq!(
        project.recorded("args"),
        "describe --project .\ngenerate --project .\n"
    );

    let request: Value = serde_json::from_str(&project.recorded("stdin")).unwrap();

    assert_eq!(request["elephentity"], 1);
    assert_eq!(request["irVersion"], eleph_compiler::ir::IR_VERSION);
    assert_eq!(request["schema"]["project"]["name"], "Demo");
    assert_eq!(
        request["schema"]["entities"]["Note"]["fields"]["title"]["name"],
        "title"
    );
    assert_eq!(request["files"], serde_json::json!({}));
}

#[test]
fn generate_forwards_the_generators_report_and_exit_code() {
    let project = Project::with_stub_driver();

    let output = project.eleph_with(&["generate"], &[("STUB_EXIT", "3")]);

    // The generator's code is the gate, and what it said is not lost or restated.
    assert_eq!(output.status.code(), Some(3));
    assert_eq!(stdout(&output), "stub generated\n");
    assert_eq!(stderr(&output), "stub warning\n");
}

#[test]
fn generate_passes_check_and_targets_straight_through() {
    let project = Project::with_stub_driver();

    project.eleph(&["generate", "--check", "--targets", "a,b"]);

    assert_eq!(
        project.recorded("args"),
        "describe --project .\ngenerate --project . --check --targets a,b\n"
    );
}

#[test]
fn generate_does_not_parse_the_targets_block() {
    let project = Project::with_stub_driver();
    project.write(
        "eleph.json",
        &format!(
            r#"{{"spec": "spec", "codegen": "{}", "targets": "this is not even an object"}}"#,
            stub().display()
        ),
    );

    // Only eleph-codegen reads `targets`; the compiler carries it past without judging it.
    assert_eq!(project.eleph(&["generate"]).status.code(), Some(0));
}

#[test]
fn generate_stops_at_spec_errors_and_never_runs_the_generator() {
    let project = Project::with_stub_driver();
    project.write("spec/entities/Note.yml", "entity: Note\n");

    let output = project.eleph(&["generate"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(stdout(&output).contains("problem(s) in the specs; nothing was generated."));
    assert_eq!(project.recorded("args"), "describe --project .\n");
    assert_eq!(project.recorded("stdin"), "");
}

#[test]
fn generate_refuses_a_driver_no_installed_builder_provides() {
    let project = Project::new(r#"{"targets": {"other": {"drivers": ["wordpress", "sqlite"]}}}"#);

    let output = project.eleph(&["generate"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(stdout(&output).contains(
        "No installed builder generates for storage driver \"stub\" (available: sqlite, wordpress)."
    ));
    assert_eq!(project.recorded("stdin"), "");
}

#[test]
fn a_generator_that_predates_the_handshake_is_told_how_to_upgrade() {
    let project = Project::with_stub_driver();
    // The stand-in exits 64 for any command it does not know, as an old generator would.
    fs::write(
        project.path().join("old-codegen"),
        "#!/bin/sh\necho 'The \"describe\" command is not defined.' >&2\nexit 1\n",
    )
    .unwrap();
    fs::set_permissions(
        project.path().join("old-codegen"),
        std::os::unix::fs::PermissionsExt::from_mode(0o755),
    )
    .unwrap();
    project.write(
        "eleph.json",
        r#"{"spec": "spec", "codegen": "old-codegen", "targets": {}}"#,
    );

    let output = project.eleph(&["generate"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(stdout(&output)
        .contains("Could not ask the code generator what this project's builders provide."));
    assert!(stdout(&output).contains("The \"describe\" command is not defined."));
    assert!(stdout(&output).contains("eleph-codegen predates the handshake"));
}

#[test]
fn a_missing_generator_says_where_it_looked_and_what_to_install() {
    let project = Project::with_stub_driver();
    project.write(
        "eleph.json",
        r#"{"spec": "spec", "codegen": "bin/missing", "targets": {}}"#,
    );

    let output = project.eleph(&["generate"]);

    assert_eq!(output.status.code(), Some(1));

    let text = stdout(&output);

    assert!(
        text.contains("No eleph-codegen found. Looked in:"),
        "{text}"
    );
    assert!(text.contains("bin/missing"), "{text}");
    assert!(
        text.contains("composer require --dev elephentity/codegen"),
        "{text}"
    );
}

#[test]
fn a_generator_that_is_not_executable_says_how_to_fix_it() {
    let project = Project::with_stub_driver();
    project.write("flat-codegen", "#!/bin/sh\n");
    project.write(
        "eleph.json",
        r#"{"spec": "spec", "codegen": "flat-codegen", "targets": {}}"#,
    );

    let output = project.eleph(&["generate"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains("is not executable. Try `chmod +x "),
        "{}",
        stdout(&output)
    );
}

const ONE_TARGET: &str =
    r#"{"spec": "spec", "targets": {"stub": {"output": "gen", "builder": "stub-builder"}}}"#;

fn checked(check: &str) -> String {
    format!(r#"{{"targets": {{"stub": {{"drivers": ["stub"], "check": {check}}}}}}}"#)
}

#[test]
fn check_runs_the_checker_a_builder_declares_with_the_placeholders_filled_in() {
    let project = Project::new(&checked(
        r#"{"command": ["checkers/ok", "{project}", "{output}"]}"#,
    ));
    project.targets(ONE_TARGET);
    project.program(
        "checkers/ok",
        r#"printf '%s\n' "$*" > checked-args
echo '{"severity": "info", "message": "all fine"}'
echo '{"severity": "warning", "message": "a small thing"}'"#,
    );

    let output = project.eleph(&["check", "-p", "."]);
    let text = stdout(&output);

    assert_eq!(output.status.code(), Some(0), "{text}");
    assert!(text.contains("Checking stub (checkers/ok)"), "{text}");
    assert!(text.contains("  [info] all fine"), "{text}");
    assert!(text.contains("  [warning] a small thing"), "{text}");
    assert!(
        text.contains("[OK] Conformant: 1 checker(s), 2 signal(s)."),
        "{text}"
    );
    assert_eq!(
        fs::read_to_string(project.path().join("checked-args")).unwrap(),
        ". ./gen\n"
    );
}

#[test]
fn a_checker_that_exits_non_zero_fails_the_check() {
    let project = Project::new(&checked(r#"{"command": ["checkers/bad"]}"#));
    project.targets(ONE_TARGET);
    project.program("checkers/bad", "echo 'it is broken' >&2\nexit 3");

    let output = project.eleph(&["check"]);
    let text = stdout(&output);

    assert_eq!(output.status.code(), Some(1), "{text}");
    assert!(
        text.contains("The stub checker failed (exit code 3): it is broken"),
        "{text}"
    );
    assert!(text.contains("[ERROR] 1 conformance error(s)."), "{text}");
}

#[test]
fn an_error_signal_fails_even_when_the_checker_exits_zero() {
    let project = Project::new(&checked(r#"{"command": ["checkers/loud"]}"#));
    project.targets(ONE_TARGET);
    project.program(
        "checkers/loud",
        r#"echo '{"severity": "error", "message": "field Item.name does not resolve"}'"#,
    );

    let output = project.eleph(&["check"]);
    let text = stdout(&output);

    assert_eq!(output.status.code(), Some(1), "{text}");
    assert!(
        text.contains("  [error] field Item.name does not resolve"),
        "{text}"
    );
}

#[test]
fn a_checker_that_prints_something_that_is_not_a_signal_fails() {
    let project = Project::new(&checked(r#"{"command": ["checkers/chatty"]}"#));
    project.targets(ONE_TARGET);
    project.program("checkers/chatty", "echo 'hello there'");

    let output = project.eleph(&["check"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains("printed something that is not a signal: hello there"),
        "{}",
        stdout(&output)
    );
}

#[test]
fn a_project_where_nothing_has_a_checker_fails_naming_the_targets() {
    let project = Project::with_stub_driver();
    project.targets(
        r#"{"spec": "spec", "targets": {
            "stub": {"output": "gen", "builder": "stub-builder"},
            "other": {"output": "out", "builder": "other-builder"}}}"#,
    );

    let output = project.eleph(&["check"]);
    let text = stdout(&output);

    assert_eq!(output.status.code(), Some(1), "{text}");
    assert!(
        text.contains("No target was checked: none of \"stub\", \"other\" provides a checker."),
        "{text}"
    );
    assert!(
        text.contains("[warning] The \"stub\" target (stub-builder) provides no checker"),
        "{text}"
    );
}

#[test]
fn a_target_without_a_checker_is_a_warning_when_another_was_checked() {
    let project = Project::new(
        r#"{"targets": {
            "stub": {"drivers": ["stub"], "check": {"command": ["checkers/ok"]}},
            "other": {}}}"#,
    );
    project.targets(
        r#"{"spec": "spec", "targets": {
            "stub": {"output": "gen", "builder": "stub-builder"},
            "other": {"output": "out", "builder": "other-builder"}}}"#,
    );
    project.program("checkers/ok", "exit 0");

    let output = project.eleph(&["check"]);
    let text = stdout(&output);

    assert_eq!(output.status.code(), Some(0), "{text}");
    assert!(
        text.contains("[warning] The \"other\" target (other-builder) provides no checker"),
        "{text}"
    );
}

#[test]
fn a_project_with_no_targets_has_nothing_to_check_and_says_so() {
    let project = Project::with_stub_driver();

    let output = project.eleph(&["check"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains("declares no targets, so there is nothing to check."),
        "{}",
        stdout(&output)
    );
}

#[test]
fn a_checker_that_cannot_be_started_is_an_error_not_a_pass() {
    let project = Project::new(&checked(r#"{"command": ["checkers/missing"]}"#));
    project.targets(ONE_TARGET);

    let output = project.eleph(&["check"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains("Could not run the stub checker (checkers/missing)."),
        "{}",
        stdout(&output)
    );
}

#[test]
fn a_malformed_check_declaration_names_the_builder() {
    let project = Project::new(&checked(r#"{"command": []}"#));
    project.targets(ONE_TARGET);

    let output = project.eleph(&["check"]);

    assert_eq!(output.status.code(), Some(1));
    assert!(
        stdout(&output).contains(
            "The stub builder declared \"check\" unusably: \"command\" must be a non-empty list of strings."
        ),
        "{}",
        stdout(&output)
    );
}
