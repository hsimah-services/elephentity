//! `eleph install`: fetch the programs a project pins, without PHP or Composer.
//!
//! A project pins a release by writing `name@version` where it would write a program: a target's
//! `"builder": "eleph-gen-csharp@0.1.0"`, or `"codegen": "eleph-codegen@0.2.0"`. This downloads
//! that release from GitHub, checks it against the release's `SHA256SUMS`, unpacks the one binary
//! in the archive into `tools/builders/` (the first place the generator looks for a builder), and
//! writes down what it put there.
//!
//! **It fetches exactly what was pinned and nothing else.** There is no "latest": an unpinned
//! builder is somebody else's to provide (Composer, a package manager, `cargo install`), and is
//! only mentioned. A version written down is the whole of reproducibility here, so a build never
//! changes because a release was published.
//!
//! The names of the archives are a contract with `elephentity-codegen`'s `release-binary.yml`
//! (docs/RELEASING.md there): `<binary>-<version>-<rid>.<tar.gz|zip>` and one `SHA256SUMS`.
//!
//! `--check` is the CI form. It installs nothing and fails if anything pinned is missing, is a
//! different version than pinned, or is not the file that was installed.

use std::collections::BTreeMap;
use std::fs;
use std::io::Read;
use std::path::Path;

use serde_json::{json, Value};
use sha2::{Digest, Sha256};

use super::config::{split_pin, ProjectConfig};
use super::style::{error, line, success};
use super::{Codegen, Console, FAILURE, SUCCESS};

/// The owner of every repository in the built-in table.
const OWNER: &str = "hsimah-services";

/// Programs `eleph install` knows the repository of, so a project pins `eleph-gen-csharp@0.1.0`
/// and nothing more. Anything else needs `"source": "owner/repo"` on its target.
const KNOWN: &[(&str, &str)] = &[
    ("eleph", "elephentity"),
    ("eleph-codegen", "elephentity-codegen"),
    ("eleph-gen-php", "elephentity-codegen-php"),
    ("eleph-gen-wordpress", "elephentity-codegen-wordpress"),
    ("eleph-gen-wpgraphql", "elephentity-codegen-wpgraphql"),
    ("eleph-gen-sqlite", "elephentity-codegen-sqlite"),
    ("eleph-gen-graphql-php", "elephentity-codegen-graphql-php"),
    ("eleph-gen-tsquid", "elephentity-codegen-tsquid"),
    ("eleph-gen-csharp", "elephentity-codegen-csharp"),
    ("eleph-gen-cosmos", "elephentity-codegen-cosmos"),
    (
        "eleph-gen-graphql-csharp",
        "elephentity-codegen-graphql-csharp",
    ),
];

/// Where release assets are downloaded from. `file://` reads a directory laid out the same way,
/// which is how tests and an air-gapped mirror work.
const BASE_VARIABLE: &str = "ELEPH_RELEASES_URL";
const DEFAULT_BASE: &str = "https://github.com";

const RECORD: &str = ".eleph-install.json";

/// One program a project pins.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Pin {
    pub binary: String,
    pub version: String,
    /// `owner/repo`
    pub repository: String,
    /// What pinned it, for messages: `codegen` or `target "csharp"`.
    pub origin: String,
}

pub fn install(project: &str, check: bool, console: &mut Console<'_>) -> i32 {
    let config = match ProjectConfig::load(project) {
        Ok(config) => config,
        Err(message) => {
            let _ = std::io::Write::write_fmt(console.err, format_args!("{message}\n"));

            return FAILURE;
        }
    };

    let root = project.trim_end_matches('/');
    let directory = Path::new(root).join(&config.builders_directory);
    let mut pins = Vec::new();
    let mut problems = Vec::new();
    let mut skipped = Vec::new();

    // The generator first: it is what says which builders a project has.
    if let Some((binary, version)) = config.codegen.as_deref().and_then(split_pin) {
        match repository_of(binary, None) {
            Some(repository) => pins.push(Pin {
                binary: binary.to_string(),
                version: version.to_string(),
                repository,
                origin: "\"codegen\"".to_string(),
            }),
            None => problems.push(unknown(binary, "\"codegen\"")),
        }
    }

    let mut report = Report::default();

    for pin in std::mem::take(&mut pins) {
        report.record(settle(&pin, &directory, check, console), &pin);
    }

    // Only now can the generator be asked, because it may have just been installed.
    if report.failures.is_empty() && problems.is_empty() {
        match targets_pins(&config, root) {
            Ok((found, not_pinned)) => {
                pins = found;
                skipped = not_pinned;
            }
            Err(message) => problems.push(message),
        }
    }

    for pin in &pins {
        report.record(settle(pin, &directory, check, console), pin);
    }

    for name in &skipped {
        line(
            console,
            &format!("  skipped    {name} (not pinned: nothing to fetch)"),
        );
    }

    problems.extend(report.failures.iter().cloned());

    if !problems.is_empty() {
        error(
            console,
            &format!("{} problem(s) with the pinned programs.", problems.len()),
        );

        for problem in &problems {
            line(console, &format!("  {problem}"));
        }

        return FAILURE;
    }

    let total = report.installed + report.current;

    if total == 0 {
        line(
            console,
            "Nothing is pinned. Write \"name@version\" as a target's \"builder\" (or as \"codegen\") to have `eleph install` fetch it.",
        );
        success(console, "Nothing to install.");

        return SUCCESS;
    }

    success(
        console,
        &if check {
            format!("Everything pinned is installed ({total}).")
        } else {
            format!(
                "{} installed, {} already up to date.",
                report.installed, report.current
            )
        },
    );

    SUCCESS
}

#[derive(Default)]
struct Report {
    installed: usize,
    current: usize,
    failures: Vec<String>,
}

impl Report {
    fn record(&mut self, outcome: Result<Outcome, String>, pin: &Pin) {
        match outcome {
            Ok(Outcome::Installed) => self.installed += 1,
            Ok(Outcome::Current) => self.current += 1,
            Err(message) => self.failures.push(format!(
                "{} {} ({}): {message}",
                pin.binary, pin.version, pin.origin
            )),
        }
    }
}

enum Outcome {
    Installed,
    Current,
}

fn unknown(binary: &str, origin: &str) -> String {
    format!(
        "{origin} pins {binary}, which is not a program this knows the releases of. \
         Add \"source\": \"owner/repo\" to its target."
    )
}

fn repository_of(binary: &str, source: Option<&str>) -> Option<String> {
    if let Some(source) = source {
        return Some(source.to_string());
    }

    KNOWN
        .iter()
        .find(|(known, _)| *known == binary)
        .map(|(_, repository)| format!("{OWNER}/{repository}"))
}

/// The pins in the `targets` block, read through the generator that owns that parser.
///
/// Returns the pins and the names of builders that are not pinned.
fn targets_pins(config: &ProjectConfig, root: &str) -> Result<(Vec<Pin>, Vec<String>), String> {
    let codegen = Codegen::for_project(root, config);
    let json = codegen.capture(&[
        "targets".to_string(),
        "--project".to_string(),
        root.to_string(),
    ])?;

    let decoded: Value = serde_json::from_str(&json)
        .map_err(|error| format!("The generator did not describe its targets: {error}"))?;

    let mut pins = Vec::new();
    let mut skipped = Vec::new();
    let mut by_binary: BTreeMap<String, (String, String)> = BTreeMap::new();

    let Some(targets) = decoded.get("targets").and_then(Value::as_object) else {
        return Ok((pins, skipped));
    };

    for (name, target) in targets {
        let builder = target.get("builder").and_then(Value::as_str).unwrap_or("");
        let origin = format!("target \"{name}\"");

        let Some((binary, version)) = split_pin(builder) else {
            skipped.push(builder.to_string());

            continue;
        };

        // Two targets may share a builder, but not two versions of it: only one file can be at
        // tools/builders/<binary>.
        if let Some((other, was)) = by_binary.get(binary) {
            if was != version {
                return Err(format!(
                    "{origin} pins {binary}@{version} but {other} pins {binary}@{was}; one file cannot be both."
                ));
            }

            continue;
        }

        by_binary.insert(binary.to_string(), (origin.clone(), version.to_string()));

        let source = target.get("source").and_then(Value::as_str);

        pins.push(Pin {
            binary: binary.to_string(),
            version: version.to_string(),
            repository: repository_of(binary, source).ok_or_else(|| unknown(binary, &origin))?,
            origin,
        });
    }

    Ok((pins, skipped))
}

/// Bring one pin up to date, or in `--check` say whether it is.
fn settle(
    pin: &Pin,
    directory: &Path,
    check: bool,
    console: &mut Console<'_>,
) -> Result<Outcome, String> {
    let rid = runtime_identifier()?;
    let file = directory.join(format!("{}{}", pin.binary, std::env::consts::EXE_SUFFIX));
    let mut record = Record::load(directory);

    let state = record.state_of(pin, &rid, &file);

    if state.is_current() {
        line(
            console,
            &format!("  up to date {} {} ({rid})", pin.binary, pin.version),
        );

        return Ok(Outcome::Current);
    }

    if check {
        return Err(state.describe(pin));
    }

    let extension = if rid.starts_with("win-") {
        "zip"
    } else {
        "tar.gz"
    };
    let archive_name = format!("{}-{}-{rid}.{extension}", pin.binary, pin.version);

    let archive = fetch(&pin.repository, &pin.version, &archive_name)?;
    let sums = fetch(&pin.repository, &pin.version, "SHA256SUMS")?;
    let expected = digest_in(&String::from_utf8_lossy(&sums), &archive_name).ok_or_else(|| {
        format!(
            "SHA256SUMS of {} v{} has no line for {archive_name}.",
            pin.repository, pin.version
        )
    })?;
    let actual = hex(&Sha256::digest(&archive));

    if actual != expected {
        return Err(format!(
            "{archive_name} does not match SHA256SUMS (expected {expected}, got {actual}). Nothing was installed."
        ));
    }

    let name = format!("{}{}", pin.binary, std::env::consts::EXE_SUFFIX);
    let binary = extract(&archive, &archive_name, &name)?;

    fs::create_dir_all(directory)
        .map_err(|error| format!("Cannot create {}: {error}", directory.display()))?;
    place(&file, &binary)?;

    record.set(
        &pin.binary,
        json!({
            "version": pin.version,
            "source": pin.repository,
            "rid": rid,
            "archive": archive_name,
            "archiveSha256": actual,
            "binarySha256": hex(&Sha256::digest(&binary)),
        }),
    );
    record.save(directory)?;

    line(
        console,
        &format!("  installed  {} {} ({rid})", pin.binary, pin.version),
    );

    Ok(Outcome::Installed)
}

/// What `tools/builders/.eleph-install.json` says is there.
struct Record {
    installed: serde_json::Map<String, Value>,
}

enum State {
    Current,
    Missing,
    Unrecorded,
    Different(String),
    Modified,
}

impl State {
    fn is_current(&self) -> bool {
        matches!(self, Self::Current)
    }

    fn describe(&self, pin: &Pin) -> String {
        match self {
            Self::Current => "is installed.".to_string(),
            Self::Missing => format!("is not installed. Run `eleph install` (pinned by {}).", pin.origin),
            Self::Unrecorded => "is present but was not installed by `eleph install`, so its version is unknown. Run `eleph install` to replace it.".to_string(),
            Self::Different(was) => format!("{was} is installed but {} is pinned. Run `eleph install`.", pin.version),
            Self::Modified => "was changed after it was installed (its checksum differs from the record). Run `eleph install` to restore it.".to_string(),
        }
    }
}

impl Record {
    fn load(directory: &Path) -> Self {
        let installed = fs::read_to_string(directory.join(RECORD))
            .ok()
            .and_then(|text| serde_json::from_str::<Value>(&text).ok())
            .and_then(|value| value.get("installed").and_then(Value::as_object).cloned())
            .unwrap_or_default();

        Self { installed }
    }

    fn state_of(&self, pin: &Pin, rid: &str, file: &Path) -> State {
        if !file.is_file() {
            return State::Missing;
        }

        let Some(entry) = self.installed.get(&pin.binary) else {
            return State::Unrecorded;
        };

        let was = entry.get("version").and_then(Value::as_str).unwrap_or("?");
        let same_release = was == pin.version
            && entry.get("source").and_then(Value::as_str) == Some(pin.repository.as_str())
            && entry.get("rid").and_then(Value::as_str) == Some(rid);

        if !same_release {
            return State::Different(was.to_string());
        }

        let Ok(bytes) = fs::read(file) else {
            return State::Missing;
        };

        if entry.get("binarySha256").and_then(Value::as_str)
            == Some(hex(&Sha256::digest(&bytes)).as_str())
        {
            State::Current
        } else {
            State::Modified
        }
    }

    fn set(&mut self, binary: &str, entry: Value) {
        self.installed.insert(binary.to_string(), entry);
    }

    fn save(&self, directory: &Path) -> Result<(), String> {
        let text = serde_json::to_string_pretty(&json!({ "installed": self.installed }))
            .map_err(|error| error.to_string())?;
        let path = directory.join(RECORD);

        fs::write(&path, format!("{text}\n"))
            .map_err(|error| format!("Cannot write {}: {error}", path.display()))
    }
}

/// `linux-x64`, `linux-arm64`, `osx-arm64` or `win-x64`: the platforms release-binary.yml builds.
fn runtime_identifier() -> Result<String, String> {
    match (std::env::consts::OS, std::env::consts::ARCH) {
        ("linux", "x86_64") => Ok("linux-x64"),
        ("linux", "aarch64") => Ok("linux-arm64"),
        ("macos", "aarch64") => Ok("osx-arm64"),
        ("windows", "x86_64") => Ok("win-x64"),
        (os, arch) => Err(format!(
            "There is no prebuilt release for {os} on {arch}. Releases are built for linux-x64, linux-arm64, osx-arm64 and win-x64; build the program with cargo and put it in tools/builders/."
        )),
    }
    .map(str::to_string)
}

/// Read one release asset. A `file://` base is a directory laid out like GitHub's URLs.
fn fetch(repository: &str, version: &str, asset: &str) -> Result<Vec<u8>, String> {
    let base = std::env::var(BASE_VARIABLE).unwrap_or_else(|_| DEFAULT_BASE.to_string());
    let base = base.trim_end_matches('/');
    let location = format!("{base}/{repository}/releases/download/v{version}/{asset}");

    if let Some(path) = location.strip_prefix("file://") {
        return fs::read(path).map_err(|_| {
            format!("{repository} has no release v{version} with {asset} (looked for {path}).")
        });
    }

    match ureq::get(&location).call() {
        Ok(response) => {
            let mut bytes = Vec::new();
            response
                .into_reader()
                .read_to_end(&mut bytes)
                .map_err(|error| format!("Could not download {location}: {error}"))?;

            Ok(bytes)
        }
        Err(ureq::Error::Status(404, _)) => Err(format!(
            "{repository} has no release v{version} with {asset} ({location})."
        )),
        Err(error) => Err(format!("Could not download {location}: {error}")),
    }
}

/// The digest `sha256sum` recorded for `name`: `<hex>  <name>`, or `<hex> *<name>` in binary mode.
fn digest_in(sums: &str, name: &str) -> Option<String> {
    sums.lines().find_map(|line| {
        let (digest, file) = line.split_once(' ')?;

        (file.trim_start_matches([' ', '*']) == name).then(|| digest.to_ascii_lowercase())
    })
}

/// The one file named `name` at the top of an archive.
fn extract(archive: &[u8], archive_name: &str, name: &str) -> Result<Vec<u8>, String> {
    let mut bytes = Vec::new();

    if archive_name.ends_with(".zip") {
        let mut zip = zip::ZipArchive::new(std::io::Cursor::new(archive))
            .map_err(|error| format!("{archive_name} is not a zip archive: {error}"))?;
        let mut entry = zip
            .by_name(name)
            .map_err(|_| format!("{archive_name} has no {name} in it."))?;

        entry
            .read_to_end(&mut bytes)
            .map_err(|error| format!("Cannot read {name} from {archive_name}: {error}"))?;

        return Ok(bytes);
    }

    let mut tar = tar::Archive::new(flate2::read::GzDecoder::new(archive));
    let entries = tar
        .entries()
        .map_err(|error| format!("{archive_name} is not a tar.gz archive: {error}"))?;

    for entry in entries {
        let mut entry = entry.map_err(|error| format!("Cannot read {archive_name}: {error}"))?;
        let path = entry
            .path()
            .map_err(|error| format!("Cannot read {archive_name}: {error}"))?
            .into_owned();

        if path.components().count() == 1 && path.file_name().is_some_and(|file| file == name) {
            entry
                .read_to_end(&mut bytes)
                .map_err(|error| format!("Cannot read {name} from {archive_name}: {error}"))?;

            return Ok(bytes);
        }
    }

    Err(format!("{archive_name} has no {name} in it."))
}

/// Write the binary beside its destination and rename it into place, so a run interrupted halfway
/// never leaves a truncated program where the generator will look for one.
fn place(file: &Path, binary: &[u8]) -> Result<(), String> {
    let partial = file.with_extension("partial");

    fs::write(&partial, binary)
        .map_err(|error| format!("Cannot write {}: {error}", partial.display()))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        fs::set_permissions(&partial, fs::Permissions::from_mode(0o755))
            .map_err(|error| format!("Cannot make {} executable: {error}", partial.display()))?;
    }

    fs::rename(&partial, file)
        .map_err(|error| format!("Cannot move {} into place: {error}", file.display()))
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_known_program_needs_no_source() {
        assert_eq!(
            repository_of("eleph-gen-csharp", None).as_deref(),
            Some("hsimah-services/elephentity-codegen-csharp")
        );
        assert_eq!(repository_of("somebody-elses", None), None);
        assert_eq!(
            repository_of("somebody-elses", Some("them/theirs")).as_deref(),
            Some("them/theirs")
        );
    }

    #[test]
    fn a_digest_is_found_in_either_sha256sum_spelling() {
        let sums = "AA11  one.tar.gz\nbb22 *two.zip\ncc33  three.tar.gz\n";

        assert_eq!(digest_in(sums, "one.tar.gz").as_deref(), Some("aa11"));
        assert_eq!(digest_in(sums, "two.zip").as_deref(), Some("bb22"));
        assert_eq!(digest_in(sums, "four.zip"), None);
    }

    #[test]
    fn a_zip_release_yields_the_binary_at_its_top_level() {
        use std::io::Write;

        let mut buffer = std::io::Cursor::new(Vec::new());
        {
            let mut zip = zip::ZipWriter::new(&mut buffer);
            let options = zip::write::SimpleFileOptions::default();

            zip.start_file("eleph-gen-fake.exe", options).unwrap();
            zip.write_all(b"binary").unwrap();
            zip.start_file("LICENSE", options).unwrap();
            zip.write_all(b"licence").unwrap();
            zip.finish().unwrap();
        }

        assert_eq!(
            extract(buffer.get_ref(), "x.zip", "eleph-gen-fake.exe").unwrap(),
            b"binary"
        );
        assert!(extract(buffer.get_ref(), "x.zip", "missing.exe")
            .unwrap_err()
            .contains("has no missing.exe"));
    }
}
