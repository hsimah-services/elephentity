use std::path::Path;

use super::style::{error, line, success};
use super::{Codegen, Console, Installed, ProjectConfig, FAILURE, INVALID, SUCCESS};
use crate::fmt;
use crate::spec::SpecSource;
use crate::{compile, Options};

/// Gate: are the specs written in canonical form?
///
/// The spec is the entity's changelog, so a diff should show what changed and nothing else.
/// Without a fixed key order, the same field written by two authors, or the same LLM on two
/// different days, produces diff noise that buries the real change. This reports rather than
/// rewrites, so an author's comments are never at risk.
pub fn fmt(project: &str, console: &mut Console<'_>) -> i32 {
    let config = match ProjectConfig::load(project) {
        Ok(config) => config,
        Err(message) => return fatal(console, &message),
    };

    let problems = fmt::check(&SpecSource::new(format!(
        "{}/{}",
        project.trim_end_matches('/'),
        config.spec_directory
    )));

    if !problems.is_empty() {
        error(
            console,
            &format!("{} formatting problem(s).", problems.len()),
        );

        for problem in &problems {
            line(console, &format!("  {}", problem.describe()));
        }

        return FAILURE;
    }

    success(console, "Specs are in canonical form.");

    SUCCESS
}

/// Gate one of the build: are the specs well-formed and semantically closed?
///
/// Every problem is reported in a single run rather than one per invocation, so a spec with five
/// mistakes takes one pass to fix rather than five.
pub fn validate(
    root: &str,
    project: &str,
    provides: Option<&str>,
    console: &mut Console<'_>,
) -> i32 {
    if !Path::new(root).is_dir() {
        error(console, &format!("No such directory: {root}"));

        return INVALID;
    }

    let installed = match describe(project, provides) {
        Ok(installed) => installed,
        Err(message) => {
            error(console, &message);

            if provides.is_none() {
                line(
                    console,
                    "Point --project at the directory holding eleph.json, or use --provides with saved describe JSON.",
                );
            }

            return FAILURE;
        }
    };

    let options = Options {
        integrations: installed.integrations,
        pooled_patterns: installed.patterns,
        pooled_types: installed.types,
        storage_rules: installed.storage_rules,
    };

    let schema = match compile(&SpecSource::new(root), &options) {
        Ok(schema) => schema,
        Err(errors) => {
            error(
                console,
                &format!("{} problem(s) found in {root}", errors.len()),
            );

            for problem in &errors {
                line(console, &format!("  {}", problem.describe()));
            }

            return FAILURE;
        }
    };

    let entities = schema.entities.len();
    let types = schema.types.len();

    success(
        console,
        &format!(
            "Specs are valid: {entities} entit{}, {types} type{}.",
            if entities == 1 { "y" } else { "ies" },
            if types == 1 { "" } else { "s" },
        ),
    );

    if provides.is_some() {
        line(
            console,
            "Validated against supplied metadata; installed builder compatibility was not checked.",
        );
    }

    SUCCESS
}

/// Live discovery is the default: the installed builders own the integrations, pooled specs and
/// storage rules validation uses. A saved describe file stands in for it.
fn describe(project: &str, provides: Option<&str>) -> Result<Installed, String> {
    let Some(provides) = provides else {
        let config = ProjectConfig::load(project)?;

        return Installed::described_by(&Codegen::new(project, config.codegen), project);
    };

    if !Path::new(provides).is_file() {
        return Err("--provides must name a readable describe JSON file.".to_string());
    }

    let json = std::fs::read_to_string(provides).map_err(|_| format!("Cannot read {provides}."))?;

    Installed::from_json(&json)
}

/// Compiles the specs and hands them to the code generator.
///
/// The division is the whole point: this half knows what a spec means and nothing about what a
/// file looks like; `eleph-codegen` knows how to run builders, sign and write, and nothing about
/// YAML. Between them is one JSON document on a pipe, so the generator can be rewritten in
/// another language without this changing at all.
///
/// Spec errors are reported here, because they are about the spec. Everything after (a missing
/// builder, a target that rejected its config, a tree that has drifted) is reported by the
/// generator, and its output is forwarded rather than re-rendered. `--check` and `--targets` are
/// passed straight through for the same reason.
///
/// The generator is asked *twice*: once to describe what the project's builders provide, before
/// the specs can be read, and again to generate once they have been. What a spec may say depends
/// on what is installed, and this half knows neither until it asks.
pub fn generate(
    project: &str,
    check: bool,
    targets: Option<&str>,
    console: &mut Console<'_>,
) -> i32 {
    let config = match ProjectConfig::load(project) {
        Ok(config) => config,
        Err(message) => return fatal(console, &message),
    };

    let root = project.trim_end_matches('/');
    let codegen = Codegen::new(root, config.codegen.clone());

    // Before the specs are read, because what it answers is what they are read against: which
    // integrations may be named, which drivers exist.
    let installed = match Installed::described_by(&codegen, root) {
        Ok(installed) => installed,
        Err(message) => {
            error(console, &message);

            return FAILURE;
        }
    };

    let options = Options {
        integrations: installed.integrations.clone(),
        pooled_patterns: installed.patterns.clone(),
        pooled_types: installed.types.clone(),
        storage_rules: installed.storage_rules.clone(),
    };

    let schema = match compile(
        &SpecSource::new(format!("{root}/{}", config.spec_directory)),
        &options,
    ) {
        Ok(schema) => schema,
        Err(errors) => {
            error(
                console,
                &format!(
                    "{} problem(s) in the specs; nothing was generated.",
                    errors.len()
                ),
            );

            for problem in &errors {
                line(console, &format!("  {}", problem.describe()));
            }

            return FAILURE;
        }
    };

    if !installed.provides(&schema.project.driver) {
        error(
            console,
            &format!(
                "No installed builder generates for storage driver \"{}\" (available: {}). \
                 Add a target for it in eleph.json, or change the driver in project.yml.",
                schema.project.driver,
                installed.describe_drivers()
            ),
        );

        return FAILURE;
    }

    let mut arguments = vec![
        "generate".to_string(),
        "--project".to_string(),
        root.to_string(),
    ];

    if check {
        arguments.push("--check".to_string());
    }

    if let Some(targets) = targets.filter(|targets| !targets.is_empty()) {
        arguments.push("--targets".to_string());
        arguments.push(targets.to_string());
    }

    match codegen.run(&arguments, &schema.request(), console) {
        Ok(code) => code,
        Err(message) => {
            error(console, &message);

            FAILURE
        }
    }
}

/// A problem with the project itself, before any command has begun: reported on stderr and
/// failing, as an uncaught exception is in the PHP `eleph`.
fn fatal(console: &mut Console<'_>, message: &str) -> i32 {
    let _ = writeln!(console.err, "{message}");

    FAILURE
}
