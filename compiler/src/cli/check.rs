//! Gate four: does each generated tree agree with itself?
//!
//! Distinct from `generate --check`, which asks whether a tree matches the spec. This asks whether
//! the tree is coherent, and that is a question only the builder that wrote it can answer: "every
//! GraphQL field resolves to a method that exists" means something different in PHP, in C# and in
//! TypeScript. So the builder says how its tree is checked, in `describe`
//! (`provides.check.command`), and this runs those checkers. It knows no language and loads no
//! generated code, which is what lets a project with no PHP target be checked at all.
//!
//! The command may use two placeholders, `{project}` (the project directory) and `{output}` (the
//! target's output directory, project-relative paths already joined on). A checker's contract is
//! small: exit zero to pass, non-zero to fail, and one JSON object per line on stdout,
//! `{"severity": "error|warning|info", "message": "..."}`.
//!
//! **It never passes silently.** A target without a checker is reported, and a run in which no
//! target was checked fails, because "nothing to check" and "did not check" look identical to
//! whoever reads a green build.
//!
//! The target list comes from `eleph-codegen targets`, not from reading eleph.json again: two
//! parsers of one file eventually disagree about something small.

use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};

use serde_json::Value;

use super::style::{error, line, success};
use super::{Codegen, Console, Installed, ProjectConfig, FAILURE, SUCCESS};

#[derive(Debug, PartialEq)]
enum Severity {
    Error,
    Warning,
    Info,
}

impl Severity {
    fn name(&self) -> &'static str {
        match self {
            Self::Error => "error",
            Self::Warning => "warning",
            Self::Info => "info",
        }
    }
}

#[derive(Debug)]
struct Signal {
    severity: Severity,
    message: String,
}

impl Signal {
    fn error(message: String) -> Self {
        Self {
            severity: Severity::Error,
            message,
        }
    }
}

/// A target as `eleph-codegen targets` describes it.
struct Target {
    name: String,
    builder: String,
    output: String,
}

pub fn check(project: &str, console: &mut Console<'_>) -> i32 {
    let config = match ProjectConfig::load(project) {
        Ok(config) => config,
        Err(message) => {
            let _ = writeln!(console.err, "{message}");

            return FAILURE;
        }
    };

    // Absolute, once. The checker is started inside the project, so a relative `{project}` or
    // `{output}` would be applied twice: `--project examples/blog` would hand it
    // `examples/blog/generated` to look for under `examples/blog`.
    let root = match fs::canonicalize(project) {
        Ok(root) => root.to_string_lossy().into_owned(),
        Err(_) => {
            error(
                console,
                &format!("Cannot resolve the project directory {project}."),
            );

            return FAILURE;
        }
    };
    let root = root.as_str();
    let codegen = Codegen::new(root, config.codegen);

    let targets = match targets(&codegen, root) {
        Ok(targets) => targets,
        Err(message) => {
            error(console, &message);

            return FAILURE;
        }
    };

    let installed = match Installed::described_by(&codegen, root) {
        Ok(installed) => installed,
        Err(message) => {
            error(console, &message);

            return FAILURE;
        }
    };

    let mut signals = Vec::new();
    let mut unchecked = Vec::new();
    let mut checkers = 0;

    for target in &targets {
        let Some(command) = installed.checkers.get(&target.name) else {
            unchecked.push(target);

            continue;
        };

        checkers += 1;
        let words = substitute(command, root, &target.output);

        line(console, &format!("Checking {} ({})", target.name, words[0]));

        let found = run(&words, root, &target.name);

        for signal in &found {
            let text = format!("  [{}] {}", signal.severity.name(), signal.message);

            line(console, &text);
        }

        signals.extend(found);
    }

    for target in &unchecked {
        line(
            console,
            &format!(
                "  [warning] The \"{}\" target ({}) provides no checker, so nothing was checked there.",
                target.name, target.builder
            ),
        );
    }

    if checkers == 0 {
        let message = if targets.is_empty() {
            format!(
                "{} declares no targets, so there is nothing to check.",
                super::config::FILENAME
            )
        } else {
            format!(
                "No target was checked: none of {} provides a checker. \
                 Install a builder that does, or have the target's builder declare one in `describe` \
                 (provides.check.command).",
                names(&targets)
            )
        };

        error(console, &message);

        return FAILURE;
    }

    let errors = signals
        .iter()
        .filter(|signal| signal.severity == Severity::Error)
        .count();

    if errors > 0 {
        error(console, &format!("{errors} conformance error(s)."));

        return FAILURE;
    }

    success(
        console,
        &format!(
            "Conformant: {checkers} checker(s), {} signal(s).",
            signals.len()
        ),
    );

    SUCCESS
}

fn names(targets: &[Target]) -> String {
    targets
        .iter()
        .map(|target| format!("\"{}\"", target.name))
        .collect::<Vec<_>>()
        .join(", ")
}

/// Asks the generator, because it parses the targets block and this should not.
fn targets(codegen: &Codegen, root: &str) -> Result<Vec<Target>, String> {
    let json = codegen.capture(&[
        "targets".to_string(),
        "--project".to_string(),
        root.to_string(),
    ])?;

    let decoded: Value = serde_json::from_str(&json)
        .map_err(|error| format!("The generator did not describe its targets: {error}"))?;

    let Some(declared) = decoded.get("targets").and_then(Value::as_object) else {
        return Ok(Vec::new());
    };

    declared
        .iter()
        .map(|(name, target)| {
            let output = target
                .get("output")
                .and_then(Value::as_str)
                .filter(|output| !output.is_empty())
                .ok_or_else(|| {
                    format!("The generator described the \"{name}\" target without an output directory.")
                })?;

            Ok(Target {
                name: name.clone(),
                builder: target
                    .get("builder")
                    .and_then(Value::as_str)
                    .unwrap_or("unknown builder")
                    .to_string(),
                output: output.to_string(),
            })
        })
        .collect()
}

/// `{output}` is the target's directory under the project; `Path::join` keeps an output that is
/// already absolute as it is.
fn substitute(command: &[String], root: &str, output: &str) -> Vec<String> {
    let output = Path::new(root).join(output).to_string_lossy().into_owned();

    command
        .iter()
        .map(|word| word.replace("{project}", root).replace("{output}", &output))
        .collect()
}

/// Runs one checker and turns whatever it did into signals. A checker that cannot be started,
/// exits non-zero or prints something that is not a signal is an error signal, never a pass.
fn run(words: &[String], root: &str, target: &str) -> Vec<Signal> {
    let program = locate(&words[0], root);
    let output = Command::new(&program)
        .args(&words[1..])
        .current_dir(root)
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .output();

    let output = match output {
        Ok(output) => output,
        Err(_) => {
            return vec![Signal::error(format!(
                "Could not run the {target} checker ({}).",
                words.join(" ")
            ))]
        }
    };

    let mut signals = Vec::new();

    for text in String::from_utf8_lossy(&output.stdout).lines() {
        if text.trim().is_empty() {
            continue;
        }

        signals.push(parse(text).unwrap_or_else(|| {
            Signal::error(format!(
                "The {target} checker printed something that is not a signal: {}",
                text.trim()
            ))
        }));
    }

    // A checker that exits non-zero having already said why has said it once; a second error for the
    // exit code would count one problem twice.
    let explained = signals
        .iter()
        .any(|signal| signal.severity == Severity::Error);

    if !output.status.success() && !explained {
        let said = String::from_utf8_lossy(&output.stderr).trim().to_string();
        let code = output
            .status
            .code()
            .map_or("a signal".to_string(), |code| format!("code {code}"));

        signals.push(Signal::error(if said.is_empty() {
            format!("The {target} checker failed (exit {code}).")
        } else {
            format!("The {target} checker failed (exit {code}): {said}")
        }));
    }

    signals
}

fn parse(text: &str) -> Option<Signal> {
    let decoded: Value = serde_json::from_str(text).ok()?;
    let severity = match decoded.get("severity")?.as_str()? {
        "error" => Severity::Error,
        "warning" => Severity::Warning,
        "info" => Severity::Info,
        _ => return None,
    };

    Some(Signal {
        severity,
        message: decoded.get("message")?.as_str()?.to_string(),
    })
}

/// A path is used as written, relative to the project. A bare name is looked for where Composer
/// and a release archive put programs (the nearest `vendor/bin` above the project, then beside
/// the running `eleph`) and otherwise left to `PATH`.
fn locate(program: &str, root: &str) -> PathBuf {
    if program.contains('/') {
        let path = Path::new(program);

        return if path.is_absolute() {
            path.to_path_buf()
        } else {
            Path::new(root).join(path)
        };
    }

    let file = format!("{program}{}", std::env::consts::EXE_SUFFIX);

    let vendor = Path::new(root).canonicalize().ok().and_then(|start| {
        start
            .ancestors()
            .map(|directory| directory.join("vendor/bin").join(&file))
            .find(|candidate| candidate.is_file())
    });

    let beside = std::env::current_exe()
        .ok()
        .and_then(|current| current.canonicalize().ok())
        .and_then(|current| current.parent().map(|directory| directory.join(&file)))
        .filter(|candidate| candidate.is_file());

    vendor.or(beside).unwrap_or_else(|| PathBuf::from(program))
}
