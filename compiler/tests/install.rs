//! `eleph install`, run as a user runs it, against a local directory laid out like GitHub Releases.
//!
//! A release here is a `file://` tree: `<owner>/<repo>/releases/download/<tag>/<asset>`. The
//! "programs" in it are shell scripts, so the tests need no network and no real builder, and what
//! was installed can be run to prove it is the file that was in the archive.

#![cfg(unix)]

use std::fs;
use std::io::Write;
use std::os::unix::fs::PermissionsExt;
use std::path::PathBuf;
use std::process::{Command, Output};

use sha2::{Digest, Sha256};
use tempfile::TempDir;

const ELEPH: &str = env!("CARGO_BIN_EXE_eleph");

fn rid() -> &'static str {
    match (std::env::consts::OS, std::env::consts::ARCH) {
        ("linux", "x86_64") => "linux-x64",
        ("linux", "aarch64") => "linux-arm64",
        ("macos", "aarch64") => "osx-arm64",
        other => panic!("no release is built for {other:?}"),
    }
}

fn hex(bytes: &[u8]) -> String {
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// A `.tar.gz` holding an executable `binary` and a LICENSE, as release-binary.yml packs one.
fn archive(binary: &str, script: &str) -> Vec<u8> {
    let mut tar = tar::Builder::new(Vec::new());

    for (name, body, mode) in [(binary, script, 0o755), ("LICENSE", "MIT", 0o644)] {
        let mut header = tar::Header::new_gnu();
        header.set_size(body.len() as u64);
        header.set_mode(mode);
        header.set_cksum();
        tar.append_data(&mut header, name, body.as_bytes()).unwrap();
    }

    let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::default());
    encoder.write_all(&tar.into_inner().unwrap()).unwrap();

    encoder.finish().unwrap()
}

struct World {
    releases: TempDir,
    project: TempDir,
}

impl World {
    fn new() -> Self {
        let world = Self {
            releases: tempfile::tempdir().unwrap(),
            project: tempfile::tempdir().unwrap(),
        };

        fs::create_dir_all(world.project.path().join("spec/entities")).unwrap();

        world
    }

    fn dir(&self, repository: &str, binary: &str, version: &str) -> PathBuf {
        let tag = if repository == "hsimah-services/elephentity" && binary != "eleph" {
            format!("{binary}-v{version}")
        } else {
            format!("v{version}")
        };
        let dir = self
            .releases
            .path()
            .join(repository)
            .join("releases/download")
            .join(tag);
        fs::create_dir_all(&dir).unwrap();

        dir
    }

    /// Publish `binary` as release `version` of `repository`, with a correct SHA256SUMS.
    fn publish(&self, repository: &str, binary: &str, version: &str, script: &str) {
        let dir = self.dir(repository, binary, version);
        let name = format!("{binary}-{version}-{}.tar.gz", rid());
        let bytes = archive(binary, script);

        fs::write(dir.join(&name), &bytes).unwrap();
        fs::write(dir.join("SHA256SUMS"), format!("{}  {name}\n", hex(&bytes))).unwrap();
    }

    fn config(&self, json: &str) {
        fs::write(self.project.path().join("eleph.json"), json).unwrap();
    }

    fn eleph(&self, arguments: &[&str]) -> Output {
        Command::new(ELEPH)
            .args(arguments)
            .current_dir(self.project.path())
            // Nothing on the host's PATH may satisfy a lookup the test means to fail.
            .env("PATH", "/usr/bin:/bin")
            .env(
                "ELEPH_RELEASES_URL",
                format!("file://{}", self.releases.path().display()),
            )
            .output()
            .unwrap()
    }

    fn builders(&self) -> PathBuf {
        self.project.path().join("tools/builders")
    }
}

fn out(output: &Output) -> String {
    String::from_utf8_lossy(&output.stdout).into_owned()
}

fn err(output: &Output) -> String {
    String::from_utf8_lossy(&output.stderr).into_owned()
}

/// A stand-in `eleph-codegen` that answers `targets` with one pinned builder.
fn codegen_script(builder: &str, extra: &str) -> String {
    format!(
        "#!/bin/sh\ncase \"$1\" in\n  targets) echo '{{\"spec\":\"spec\",\"targets\":{{\"csharp\":{{\"output\":\"gen\",\"builder\":\"{builder}\"{extra}}}}}}}' ;;\n  *) exit 64 ;;\nesac\n"
    )
}

const PINNED: &str = r#"{"spec": "spec", "codegen": "eleph-codegen@1.0.0", "targets": {}}"#;

fn publish_both(world: &World) {
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        &codegen_script("eleph-gen-csharp@0.1.0", ""),
    );
    world.publish(
        "hsimah-services/elephentity",
        "eleph-gen-csharp",
        "0.1.0",
        "#!/bin/sh\necho csharp builder\n",
    );
}

#[test]
fn it_installs_the_generator_and_every_pinned_builder_and_records_them() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);

    let output = world.eleph(&["install"]);
    let text = out(&output);

    assert_eq!(output.status.code(), Some(0), "{text}{}", err(&output));
    assert!(text.contains("installed  eleph-codegen 1.0.0"), "{text}");
    assert!(text.contains("installed  eleph-gen-csharp 0.1.0"), "{text}");
    assert!(
        text.contains("[OK] 2 installed, 0 already up to date."),
        "{text}"
    );

    let builder = world.builders().join("eleph-gen-csharp");
    let mode = fs::metadata(&builder).unwrap().permissions().mode();

    assert_ne!(mode & 0o111, 0, "the builder must be executable");
    assert_eq!(
        String::from_utf8_lossy(&Command::new(&builder).output().unwrap().stdout),
        "csharp builder\n"
    );

    let record: serde_json::Value = serde_json::from_str(
        &fs::read_to_string(world.builders().join(".eleph-install.json")).unwrap(),
    )
    .unwrap();
    let entry = &record["installed"]["eleph-gen-csharp"];

    assert_eq!(entry["version"], "0.1.0");
    assert_eq!(
        entry["source"],
        "hsimah-services/elephentity"
    );
    assert_eq!(entry["rid"], rid());
    assert_eq!(
        entry["archive"],
        format!("eleph-gen-csharp-0.1.0-{}.tar.gz", rid())
    );
}

#[test]
fn a_second_run_downloads_nothing() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);
    assert_eq!(world.eleph(&["install"]).status.code(), Some(0));

    // The releases are gone, so any download would fail. Up to date means up to date.
    fs::remove_dir_all(world.releases.path().join("hsimah-services")).unwrap();

    let output = world.eleph(&["install"]);
    let text = out(&output);

    assert_eq!(output.status.code(), Some(0), "{text}{}", err(&output));
    assert!(text.contains("up to date eleph-codegen 1.0.0"), "{text}");
    assert!(
        text.contains("[OK] 0 installed, 2 already up to date."),
        "{text}"
    );
}

#[test]
fn check_passes_when_everything_is_installed_and_installs_nothing_itself() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);

    let before = world.eleph(&["install", "--check"]);

    assert_eq!(before.status.code(), Some(1), "{}", out(&before));
    assert!(!world.builders().join("eleph-codegen").exists());

    assert_eq!(world.eleph(&["install"]).status.code(), Some(0));

    let after = world.eleph(&["install", "--check"]);

    assert_eq!(after.status.code(), Some(0), "{}", out(&after));
    assert!(
        out(&after).contains("[OK] Everything pinned is installed (2)."),
        "{}",
        out(&after)
    );
}

#[test]
fn check_fails_for_a_missing_a_different_and_a_modified_binary() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);
    assert_eq!(world.eleph(&["install"]).status.code(), Some(0));

    // Modified: same version, different bytes.
    fs::write(
        world.builders().join("eleph-gen-csharp"),
        "#!/bin/sh\necho tampered\n",
    )
    .unwrap();
    let modified = out(&world.eleph(&["install", "--check"]));
    assert!(
        modified.contains("was changed after it was installed"),
        "{modified}"
    );

    // Different: the pin moved.
    world.config(r#"{"spec": "spec", "codegen": "eleph-codegen@2.0.0", "targets": {}}"#);
    let different = out(&world.eleph(&["install", "--check"]));
    assert!(
        different.contains("1.0.0 is installed but 2.0.0 is pinned"),
        "{different}"
    );

    // Missing.
    world.config(PINNED);
    fs::remove_file(world.builders().join("eleph-codegen")).unwrap();
    let missing = world.eleph(&["install", "--check"]);
    assert_eq!(missing.status.code(), Some(1));
    assert!(
        out(&missing).contains("is not installed"),
        "{}",
        out(&missing)
    );
}

#[test]
fn a_wrong_checksum_installs_nothing() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);
    let dir = world.dir("hsimah-services/elephentity", "eleph-codegen", "1.0.0");
    fs::write(
        dir.join("SHA256SUMS"),
        format!("{}  eleph-codegen-1.0.0-{}.tar.gz\n", "0".repeat(64), rid()),
    )
    .unwrap();

    let output = world.eleph(&["install"]);
    let text = out(&output);

    assert_eq!(output.status.code(), Some(1), "{text}");
    assert!(text.contains("does not match SHA256SUMS"), "{text}");
    assert!(text.contains("Nothing was installed."), "{text}");
    assert!(!world.builders().join("eleph-codegen").exists());
}

#[test]
fn a_release_that_does_not_exist_names_the_repository_and_version() {
    let world = World::new();
    world.config(PINNED);

    let output = world.eleph(&["install"]);
    let text = out(&output);

    assert_eq!(output.status.code(), Some(1), "{text}");
    assert!(
        text.contains("hsimah-services/elephentity-codegen has no release v1.0.0"),
        "{text}"
    );
}

#[test]
fn an_archive_without_the_binary_is_refused() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);
    // Re-pack the generator's archive with the wrong name inside, and fix its checksum.
    let dir = world.dir("hsimah-services/elephentity", "eleph-codegen", "1.0.0");
    let name = format!("eleph-codegen-1.0.0-{}.tar.gz", rid());
    let bytes = archive("something-else", "#!/bin/sh\n");
    fs::write(dir.join(&name), &bytes).unwrap();
    fs::write(dir.join("SHA256SUMS"), format!("{}  {name}\n", hex(&bytes))).unwrap();

    let text = out(&world.eleph(&["install"]));

    assert!(text.contains("has no eleph-codegen in it"), "{text}");
}

#[test]
fn a_builder_the_table_does_not_know_needs_a_source_and_then_uses_it() {
    let world = World::new();
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        &codegen_script("their-builder@3.0.0", ""),
    );
    world.config(PINNED);

    let refused = out(&world.eleph(&["install"]));

    assert!(
        refused.contains("target \"csharp\" pins their-builder, which is not a program this knows the releases of."),
        "{refused}"
    );
    assert!(refused.contains("\"source\": \"owner/repo\""), "{refused}");

    // With a source, the same pin resolves to that repository.
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        &codegen_script("their-builder@3.0.0", ",\"source\":\"them/theirs\""),
    );
    world.publish(
        "them/theirs",
        "their-builder",
        "3.0.0",
        "#!/bin/sh\necho theirs\n",
    );
    // The generator is already installed (from the failed run), so replace it by hand.
    fs::remove_dir_all(world.builders()).unwrap();

    let output = world.eleph(&["install"]);

    assert_eq!(
        output.status.code(),
        Some(0),
        "{}{}",
        out(&output),
        err(&output)
    );
    assert!(world.builders().join("their-builder").is_file());
}

#[test]
fn an_unpinned_builder_is_mentioned_and_left_alone() {
    let world = World::new();
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        &codegen_script("vendor/bin/eleph-gen-php", ""),
    );
    world.config(PINNED);

    let output = world.eleph(&["install"]);
    let text = out(&output);

    assert_eq!(output.status.code(), Some(0), "{text}");
    assert!(
        text.contains("skipped    vendor/bin/eleph-gen-php (not pinned: nothing to fetch)"),
        "{text}"
    );
}

#[test]
fn two_targets_cannot_pin_two_versions_of_one_builder() {
    let world = World::new();
    let script = "#!/bin/sh\ncase \"$1\" in\n  targets) echo '{\"spec\":\"spec\",\"targets\":{\"a\":{\"output\":\"a\",\"builder\":\"eleph-gen-csharp@0.1.0\"},\"b\":{\"output\":\"b\",\"builder\":\"eleph-gen-csharp@0.2.0\"}}}' ;;\nesac\n";
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        script,
    );
    world.config(PINNED);

    let text = out(&world.eleph(&["install"]));

    assert!(text.contains("one file cannot be both"), "{text}");
}

#[test]
fn a_project_that_pins_nothing_says_so_and_succeeds() {
    let world = World::new();
    world.publish(
        "hsimah-services/elephentity",
        "eleph-codegen",
        "1.0.0",
        &codegen_script("vendor/bin/x", ""),
    );
    // The generator is found beside nothing and pinned nowhere: put it where the project says.
    world.config(r#"{"spec": "spec", "codegen": "tools/mine", "targets": {}}"#);
    let mine = world.project.path().join("tools/mine");
    fs::create_dir_all(mine.parent().unwrap()).unwrap();
    fs::write(&mine, codegen_script("tool", "")).unwrap();
    fs::set_permissions(&mine, fs::Permissions::from_mode(0o755)).unwrap();

    let output = world.eleph(&["install"]);

    assert_eq!(
        output.status.code(),
        Some(0),
        "{}{}",
        out(&output),
        err(&output)
    );
    assert!(
        out(&output).contains("Nothing is pinned."),
        "{}",
        out(&output)
    );
}

#[test]
fn a_pinned_generator_is_the_one_the_other_commands_run() {
    let world = World::new();
    publish_both(&world);
    world.config(PINNED);
    assert_eq!(world.eleph(&["install"]).status.code(), Some(0));

    // `check` asks the generator for its targets and then what its builders provide. The stand-in
    // answers the first and refuses the second, so reaching that refusal proves the pinned,
    // installed generator is the one that was run.
    let output = world.eleph(&["check"]);
    let text = out(&output);

    assert!(!text.contains("No eleph-codegen found"), "{text}");
    assert!(
        text.contains("Could not ask the code generator what this project's builders provide."),
        "{text}"
    );
}

#[test]
fn an_unpinned_generator_that_is_missing_still_says_where_it_looked() {
    let world = World::new();
    world.config(r#"{"spec": "spec", "targets": {}}"#);

    let text = out(&world.eleph(&["install"]));

    assert!(
        text.contains("No eleph-codegen found. Looked in:"),
        "{text}"
    );
    assert!(text.contains("eleph install"), "{text}");
}
