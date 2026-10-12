use std::fs;
use std::path::Path;

use serde_json::Value;

use super::{yaml, RawSpec, SpecKind, SpecSource};
use crate::error::SpecError;

pub struct Loaded {
    pub specs: Vec<RawSpec>,
    pub errors: Vec<SpecError>,
}

/// Reads spec documents off disk and parses them. Shape is somebody else's problem.
///
/// The project spec comes first, then entities, patterns and types, each directory in
/// sorted path order so the same tree always loads the same way.
pub fn load(source: &SpecSource) -> Loaded {
    let mut specs = Vec::new();
    let mut errors = Vec::new();

    match load_project(source) {
        Some(Ok(spec)) => specs.push(spec),
        Some(Err(error)) => errors.push(error),
        None => {}
    }

    for kind in SpecKind::ALL {
        let Some(directory) = kind.directory() else {
            continue;
        };

        let directory = format!("{}/{directory}", source.trimmed_root());

        if !Path::new(&directory).is_dir() {
            continue;
        }

        for file in yaml_files_in(&directory) {
            match parse(&file, kind) {
                Ok(spec) => specs.push(spec),
                Err(error) => errors.push(error),
            }
        }
    }

    Loaded { specs, errors }
}

/// The project spec is one file beside the directories, not inside one.
fn load_project(source: &SpecSource) -> Option<Result<RawSpec, SpecError>> {
    ["yml", "yaml"]
        .iter()
        .map(|extension| format!("{}/project.{extension}", source.trimmed_root()))
        .find(|path| Path::new(path).is_file())
        .map(|path| parse(&path, SpecKind::Project))
}

fn yaml_files_in(directory: &str) -> Vec<String> {
    let mut files: Vec<String> = fs::read_dir(directory)
        .map(|entries| {
            entries
                .filter_map(Result::ok)
                .filter_map(|entry| entry.file_name().into_string().ok())
                // Like a shell glob, never pick up dotfiles.
                .filter(|name| !name.starts_with('.'))
                .filter(|name| name.ends_with(".yml") || name.ends_with(".yaml"))
                .map(|name| format!("{directory}/{name}"))
                .collect()
        })
        .unwrap_or_default();

    files.sort();
    files
}

fn parse(file: &str, kind: SpecKind) -> Result<RawSpec, SpecError> {
    let source = fs::read_to_string(file).map_err(|error| {
        SpecError::new(
            "spec.unparseable",
            format!("Could not read the file: {error}"),
            Some(file),
            None,
        )
    })?;

    let document = yaml::parse(&source).map_err(|message| {
        SpecError::new(
            "spec.unparseable",
            format!("Could not parse YAML: {message}"),
            Some(file),
            None,
        )
    })?;

    let data = match document.value {
        Value::Object(data) => data,
        // A list is a PHP array too, so it falls through to the kind check.
        Value::Array(_) => return Err(wrong_kind(file, kind)),
        _ => {
            return Err(SpecError::new(
                "spec.notAMap",
                "A spec document must be a map at its root.",
                Some(file),
                None,
            ))
        }
    };

    if !data.contains_key(kind.name()) {
        return Err(wrong_kind(file, kind));
    }

    Ok(RawSpec {
        kind,
        file: file.to_string(),
        data,
        positions: document.positions,
    })
}

fn wrong_kind(file: &str, kind: SpecKind) -> SpecError {
    SpecError::new(
        "spec.wrongKind",
        format!(
            "A file in {} must declare \"{}:\" at its root.",
            kind.directory()
                .map_or_else(|| "the spec root".to_string(), |d| format!("{d}/")),
            kind.name()
        ),
        Some(file),
        None,
    )
}
