//! The `eleph` command line: `fmt`, `validate`, `generate` and `check`.
//!
//! The same commands, flags and exit codes as the PHP `eleph`, so a project's scripts and CI do
//! not change when the binary under them does. Output wording matches too. What differs is
//! presentation only: PHP's console library draws coloured, padded blocks, and this prints the
//! same words as plain `[ERROR]` and `[OK]` lines. See `docs/divergence.md`.
//!

mod check;
mod codegen;
mod commands;
pub mod config;
pub mod installed;
mod style;

use std::io::Write;

use clap::{Parser, Subcommand};

pub use codegen::{Codegen, Console};
pub use config::ProjectConfig;
pub use installed::Installed;

/// Exit codes, as Symfony's `Command` defines them.
pub const SUCCESS: i32 = 0;
pub const FAILURE: i32 = 1;
pub const INVALID: i32 = 2;

#[derive(Parser)]
#[command(
    name = "eleph",
    version,
    about = "Compile Elephentity specs and generate code from them."
)]
struct Cli {
    // Symfony's global flags. Scripts and CI pass them, so they are accepted; the output is never
    // coloured and there is nothing to ask, so they change nothing.
    /// Disable ANSI output (output is never coloured).
    #[arg(long, global = true)]
    no_ansi: bool,

    /// Do not ask any interactive question (nothing is ever asked).
    #[arg(short = 'n', long, global = true)]
    no_interaction: bool,

    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Check that specs are written in canonical key order.
    Fmt {
        /// Directory holding eleph.json.
        #[arg(short, long, default_value = ".")]
        project: String,

        /// Accepted for symmetry with `generate --check`; `fmt` only ever reports.
        #[arg(long)]
        check: bool,
    },

    /// Check that the specs are well-formed and semantically closed.
    Validate {
        /// Directory holding entities/, patterns/ and types/.
        #[arg(default_value = "spec")]
        spec: String,

        /// Directory holding eleph.json.
        #[arg(short, long, default_value = ".")]
        project: String,

        /// Saved eleph-codegen describe JSON; validate without invoking builders or reading eleph.json.
        #[arg(long)]
        provides: Option<String>,
    },

    /// Compile the specs and generate every configured target. Use --check to verify without writing.
    Generate {
        /// Report what would change and fail if anything would, without writing.
        #[arg(long)]
        check: bool,

        /// Directory holding eleph.json.
        #[arg(short, long, default_value = ".")]
        project: String,

        /// Comma-separated targets to generate. Every configured target if omitted.
        #[arg(short, long)]
        targets: Option<String>,
    },

    /// Verify every generated target is internally coherent, by running the checker its builder declares.
    Check {
        /// Directory holding eleph.json.
        #[arg(short, long, default_value = ".")]
        project: String,
    },
}

/// Runs the command line and returns the process exit code.
///
/// `attached` says whether stdout and stderr are both terminals, in which case the code
/// generator is handed them directly.
pub fn run(arguments: &[String], out: &mut dyn Write, err: &mut dyn Write, attached: bool) -> i32 {
    let cli = match Cli::try_parse_from(arguments) {
        Ok(cli) => cli,
        Err(error) => {
            // --help and --version are not failures; a bad invocation is INVALID.
            let code = if error.use_stderr() { INVALID } else { SUCCESS };
            let text = error.render().to_string();
            let stream: &mut dyn Write = if code == SUCCESS { out } else { err };
            let _ = stream.write_all(text.as_bytes());

            return code;
        }
    };

    let mut console = Console { out, err, attached };

    match cli.command {
        Command::Fmt { project, .. } => commands::fmt(&project, &mut console),
        Command::Validate {
            spec,
            project,
            provides,
        } => commands::validate(&spec, &project, provides.as_deref(), &mut console),
        Command::Generate {
            check,
            project,
            targets,
        } => commands::generate(&project, check, targets.as_deref(), &mut console),
        Command::Check { project } => check::check(&project, &mut console),
    }
}
