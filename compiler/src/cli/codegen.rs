//! Finds and runs the code generator.
//!
//! Elephentity compiles specs. Turning the result into files is a separate program,
//! `eleph-codegen`, which resolves a project's builders, runs them, and signs and writes what
//! they return. This is the whole of the coupling between them: a path to an executable and JSON
//! on its stdin.
//!
//! **Resolution, never acquisition**, exactly as the generator treats its own builders. Four
//! places, in order: an explicit `"codegen"` path in eleph.json; alongside the `eleph` binary
//! that is running; the nearest `vendor/bin` above the project, which is where Composer puts it;
//! then `PATH`. When none of them has it the error names all four, because "not found" without
//! saying where it looked is the least useful thing a build can say.
//!
//! Output is forwarded rather than re-rendered. The generator owns how a run is reported (which
//! files changed, which had been hand-edited, which target is out of date) and a wrapper that
//! restated any of that would be a second thing to keep in step with a report it does not
//! produce.
//!
//! *How* it is forwarded depends on where it is going. Attached to a terminal the child inherits
//! this process's streams directly, so it decides about colour and its progress appears as it
//! happens. Anywhere else (a pipe, CI, a test) it is captured and written back out through the
//! caller's streams, because a command whose child wrote straight to file descriptor 1 could not
//! be redirected, silenced or asserted on.
//!
//! Ported from `Codegen`.

use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::thread;

use super::config::FILENAME;

pub const COMMAND: &str = "eleph-codegen";

/// The file name to look for: `eleph-codegen`, or `eleph-codegen.exe` on Windows.
fn file_name() -> String {
    format!("{COMMAND}{}", std::env::consts::EXE_SUFFIX)
}

/// Where the generator's report goes, and whether it may write there directly.
pub struct Console<'a> {
    pub out: &'a mut dyn Write,
    pub err: &'a mut dyn Write,
    /// Both streams are terminals, so the child may be handed them.
    pub attached: bool,
}

pub struct Codegen {
    project_root: String,
    override_path: Option<String>,
}

impl Codegen {
    pub fn new(project_root: &str, override_path: Option<String>) -> Self {
        Self {
            project_root: project_root.to_string(),
            override_path,
        }
    }

    /// Run a subcommand with `input` on stdin, forwarding its output.
    ///
    /// Returns the generator's exit code, which is the gate.
    pub fn run(
        &self,
        arguments: &[String],
        input: &str,
        console: &mut Console<'_>,
    ) -> Result<i32, String> {
        let executable = self.resolve()?;
        let mut command = Command::new(&executable);
        command.args(arguments).stdin(Stdio::piped());

        if console.attached {
            command.stdout(Stdio::inherit()).stderr(Stdio::inherit());
        } else {
            command.stdout(Stdio::piped()).stderr(Stdio::piped());
        }

        let described = describe(&executable, arguments);
        let mut child = command
            .spawn()
            .map_err(|_| format!("Could not run {described}."))?;

        // stdin, stdout and stderr are each drained on their own thread. An IR of any size can
        // exceed a pipe buffer, and a child that has not finished reading stdin before it
        // starts writing stdout would deadlock if one thread did all three, but only on large
        // schemas, which is the worst possible time to discover it.
        let writer = child.stdin.take().map(|mut stdin| {
            let input = input.to_string();

            thread::spawn(move || {
                // A child that exits without reading everything closes the pipe; its exit code
                // is what matters then, not this error.
                let _ = stdin.write_all(input.as_bytes());
            })
        });
        let stdout = child.stdout.take().map(drain);
        let stderr = child.stderr.take().map(drain);

        let status = child
            .wait()
            .map_err(|_| format!("Could not run {described}."))?;

        if let Some(writer) = writer {
            let _ = writer.join();
        }

        // Raw, because the generator already decided how its report reads.
        if let Some(stdout) = stdout {
            let _ = console.out.write_all(&stdout.join().unwrap_or_default());
        }

        if let Some(stderr) = stderr {
            let _ = console.err.write_all(&stderr.join().unwrap_or_default());
        }

        Ok(status.code().unwrap_or(1))
    }

    /// Run a subcommand that answers a question, and return what it said.
    pub fn capture(&self, arguments: &[String]) -> Result<String, String> {
        let executable = self.resolve()?;
        let described = describe(&executable, arguments);

        // stderr is captured rather than inherited, because for these subcommands it carries the
        // answer to "why not": which builder could not be found, and where it looked.
        let output = Command::new(&executable)
            .args(arguments)
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .output()
            .map_err(|_| format!("Could not run {described}."))?;

        if !output.status.success() {
            let reported = String::from_utf8_lossy(&output.stderr).trim().to_string();

            return Err(if reported.is_empty() {
                format!("{described} failed and said nothing.")
            } else {
                reported
            });
        }

        Ok(String::from_utf8_lossy(&output.stdout).into_owned())
    }

    fn resolve(&self) -> Result<PathBuf, String> {
        let mut looked = Vec::new();

        if let Some(override_path) = &self.override_path {
            let path = if override_path.starts_with('/') {
                PathBuf::from(override_path)
            } else {
                Path::new(&self.project_root).join(override_path)
            };

            looked.push(path.display().to_string());

            // Someone who wrote a path meant that file. Falling back to a different one on PATH
            // would be worse than failing.
            return if path.is_file() {
                executable(path)
            } else {
                Err(missing(&looked))
            };
        }

        for candidate in [self.beside_the_cli(), self.in_nearest_vendor_bin()]
            .into_iter()
            .flatten()
        {
            looked.push(candidate.display().to_string());

            if candidate.is_file() {
                return executable(candidate);
            }
        }

        if let Some(found) = on_path() {
            return executable(found);
        }

        looked.push("anywhere on PATH".to_string());

        Err(missing(&looked))
    }

    /// Composer installs `eleph` and `eleph-codegen` into the same bin directory, and a release
    /// archive unpacks them side by side, so the one running is the best evidence of where the
    /// other is.
    fn beside_the_cli(&self) -> Option<PathBuf> {
        let current = std::env::current_exe().ok()?.canonicalize().ok()?;

        Some(current.parent()?.join(file_name()))
    }

    /// The nearest `vendor/bin` above the project. Walking rather than counting, because a
    /// project can sit at any depth under the directory Composer installed into.
    fn in_nearest_vendor_bin(&self) -> Option<PathBuf> {
        let start = Path::new(&self.project_root).canonicalize().ok()?;

        start
            .ancestors()
            .map(|directory| directory.join("vendor/bin").join(file_name()))
            .find(|candidate| candidate.is_file())
    }
}

fn drain<R: Read + Send + 'static>(mut stream: R) -> thread::JoinHandle<Vec<u8>> {
    thread::spawn(move || {
        let mut bytes = Vec::new();
        let _ = stream.read_to_end(&mut bytes);
        bytes
    })
}

fn describe(executable: &Path, arguments: &[String]) -> String {
    std::iter::once(executable.display().to_string())
        .chain(arguments.iter().cloned())
        .collect::<Vec<_>>()
        .join(" ")
}

fn on_path() -> Option<PathBuf> {
    std::env::split_paths(&std::env::var_os("PATH")?)
        .filter(|directory| !directory.as_os_str().is_empty())
        .map(|directory| directory.join(file_name()))
        .find(|candidate| candidate.is_file())
}

#[cfg(unix)]
fn is_executable(path: &Path) -> bool {
    use std::os::unix::fs::PermissionsExt;

    path.metadata()
        .map(|metadata| metadata.permissions().mode() & 0o111 != 0)
        .unwrap_or(false)
}

/// Windows has no executable bit; whether a file runs is decided when it is started.
#[cfg(not(unix))]
fn is_executable(_path: &Path) -> bool {
    true
}

fn executable(path: PathBuf) -> Result<PathBuf, String> {
    if is_executable(&path) {
        Ok(path)
    } else {
        Err(format!(
            "The code generator is at {0} but is not executable. Try `chmod +x {0}`.",
            path.display()
        ))
    }
}

fn missing(looked: &[String]) -> String {
    format!(
        "No {COMMAND} found. Looked in:\n  {}\n\
         Install it with `composer require --dev elephentity/codegen`, or set a \"codegen\" path \
         in {FILENAME}. Elephentity compiles specs; it does not generate code itself.",
        looked.join("\n  ")
    )
}
