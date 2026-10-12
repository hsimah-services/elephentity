//! `eleph.json`, as far as the compiler is concerned: where the specs are.
//!
//! The file has more in it than this, a `targets` block saying what a project generates and
//! which builder produces each one, and none of that is read here. Generating is a separate
//! program's job, and it is the only thing that parses those keys. Two parsers would eventually
//! disagree about something small, like whether a trailing slash on `output` matters, and the
//! disagreement would surface as a tree written to the wrong place.
//!
//! So this reads `spec`, and `codegen` when a project needs to say where its generator is.
//! Anything it does not recognise it leaves alone, because the file is not only its.

use std::fs;

use serde_json::Value;

pub const FILENAME: &str = "eleph.json";

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProjectConfig {
    pub spec_directory: String,
    pub codegen: Option<String>,
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

        Ok(Self {
            spec_directory: spec.to_string(),
            codegen,
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
