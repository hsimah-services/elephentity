//! `eleph.json`, as far as the compiler is concerned: where the specs are.
//!
//! The file has more in it than this, a `targets` block saying what a project generates and
//! which builder produces each one, and none of that is read here. Generating is a separate
//! program's job, and it is the only thing that parses those keys. Two parsers would eventually
//! disagree about something small, like whether a trailing slash on `output` matters, and the
//! disagreement would surface as a tree written to the wrong place.
//!
//! So this reads `spec`; `codegen`, when a project needs to say where its generator is (or which
//! release of it to install, `"eleph-codegen@0.2.0"`); and `builders`, the directory
//! `eleph install` places programs in and the generator looks in. Anything it does not recognise
//! it leaves alone, because the file is not only its.

use std::fs;

use serde_json::Value;

pub const FILENAME: &str = "eleph.json";

/// Where installed builders live, unless a project says otherwise. The generator's default too.
pub const DEFAULT_BUILDERS: &str = "tools/builders";

/// `"name@version"` is a pin: a program to find by `name` and, for `eleph install`, a release to
/// fetch. A value containing `/` is a path and is never split, because `@` is legal in one. The
/// same rule as the generator's, so the two cannot disagree about what a builder is called.
pub fn split_pin(value: &str) -> Option<(&str, &str)> {
    if value.contains('/') {
        return None;
    }

    value
        .split_once('@')
        .filter(|(name, version)| !name.is_empty() && !version.is_empty())
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProjectConfig {
    pub spec_directory: String,
    pub codegen: Option<String>,
    pub builders_directory: String,
}

impl ProjectConfig {
    pub fn load(directory: &str) -> Result<Self, String> {
        let path = format!("{}/{FILENAME}", directory.trim_end_matches('/'));

        if !std::path::Path::new(&path).is_file() {
            return Err(format!(
                "No {FILENAME} in {directory}. It needs \"spec\" and a \"targets\" block."
            ));
        }

        let contents = fs::read_to_string(&path).map_err(|_| format!("Cannot read {path}."))?;
        let data: Value = serde_json::from_str(&contents)
            .map_err(|error| format!("{path} is not valid JSON: {error}"))?;

        let spec = data
            .get("spec")
            .and_then(Value::as_str)
            .filter(|spec| !spec.is_empty())
            .ok_or_else(|| {
                format!("{path} is not usable:\n  \"spec\" must be a non-empty string.")
            })?;

        let codegen = data
            .get("codegen")
            .and_then(Value::as_str)
            .filter(|codegen| !codegen.is_empty())
            .map(str::to_string);

        let builders_directory = data
            .get("builders")
            .and_then(Value::as_str)
            .filter(|builders| !builders.is_empty())
            .unwrap_or(DEFAULT_BUILDERS)
            .trim_matches('/')
            .to_string();

        Ok(Self {
            spec_directory: spec.to_string(),
            codegen,
            builders_directory,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn project(contents: &str) -> tempfile::TempDir {
        let dir = tempfile::tempdir().unwrap();
        fs::write(dir.path().join(FILENAME), contents).unwrap();
        dir
    }

    #[test]
    fn the_spec_directory_is_read() {
        let dir = project(r#"{"spec": "spec", "targets": {}}"#);

        let config = ProjectConfig::load(dir.path().to_str().unwrap()).unwrap();

        assert_eq!(config.spec_directory, "spec");
        assert_eq!(config.codegen, None);
    }

    #[test]
    fn a_project_can_say_where_its_generator_is() {
        let dir = project(r#"{"spec": "s", "codegen": "bin/gen", "targets": {}}"#);

        assert_eq!(
            ProjectConfig::load(dir.path().to_str().unwrap())
                .unwrap()
                .codegen
                .as_deref(),
            Some("bin/gen")
        );
    }

    #[test]
    fn the_builders_directory_defaults_and_can_be_moved() {
        let default = project(r#"{"spec": "s", "targets": {}}"#);
        let moved = project(r#"{"spec": "s", "builders": "/bin/tools/", "targets": {}}"#);

        assert_eq!(
            ProjectConfig::load(default.path().to_str().unwrap())
                .unwrap()
                .builders_directory,
            "tools/builders"
        );
        assert_eq!(
            ProjectConfig::load(moved.path().to_str().unwrap())
                .unwrap()
                .builders_directory,
            "bin/tools"
        );
    }

    #[test]
    fn only_a_bare_name_at_a_version_is_a_pin() {
        assert_eq!(
            split_pin("eleph-gen-csharp@0.1.0"),
            Some(("eleph-gen-csharp", "0.1.0"))
        );
        assert_eq!(split_pin("eleph-gen-csharp"), None);
        assert_eq!(split_pin("@0.1.0"), None);
        assert_eq!(split_pin("name@"), None);
        assert_eq!(split_pin("node_modules/@scope/gen"), None);
    }

    #[test]
    fn a_config_with_no_spec_is_refused() {
        let dir = project(r#"{"targets": {}}"#);

        let message = ProjectConfig::load(dir.path().to_str().unwrap()).unwrap_err();

        assert!(
            message.contains("\"spec\" must be a non-empty string"),
            "{message}"
        );
    }

    #[test]
    fn a_targets_block_is_carried_past_without_being_judged() {
        let dir = project(r#"{"spec": "s", "targets": "not even an object"}"#);

        assert!(ProjectConfig::load(dir.path().to_str().unwrap()).is_ok());
    }

    #[test]
    fn a_file_that_is_not_json_says_so() {
        let dir = project("{nope");

        assert!(ProjectConfig::load(dir.path().to_str().unwrap())
            .unwrap_err()
            .contains("is not valid JSON"));
    }

    #[test]
    fn a_missing_file_names_what_it_needed() {
        let dir = tempfile::tempdir().unwrap();

        let message = ProjectConfig::load(dir.path().to_str().unwrap()).unwrap_err();

        assert!(message.starts_with("No eleph.json in "), "{message}");
        assert!(
            message.contains("\"spec\" and a \"targets\" block"),
            "{message}"
        );
    }
}
