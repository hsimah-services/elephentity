//! Gate one: is the document well-formed?
//!
//! The JSON Schemas live in `compiler/resources` and are embedded from there; they are also
//! what editors fetch (docs/PUBLISHING-SCHEMAS.md). Whether a document makes sense, as
//! opposed to being well formed, is semantic validation's job.

use jsonschema::{Registry, Resource, Validator};
use serde_json::Value;

use crate::error::SpecError;
use crate::spec::{RawSpec, SpecKind};

const COMMON: &str = include_str!("../resources/common.schema.json");
const PROJECT: &str = include_str!("../resources/project.schema.json");
const ENTITY: &str = include_str!("../resources/entity.schema.json");
const PATTERN: &str = include_str!("../resources/pattern.schema.json");
const TYPE: &str = include_str!("../resources/type.schema.json");

pub struct SchemaValidator {
    project: Validator,
    entity: Validator,
    pattern: Validator,
    type_: Validator,
}

impl SchemaValidator {
    pub fn new() -> Self {
        let documents = [
            ("common", COMMON),
            ("project", PROJECT),
            ("entity", ENTITY),
            ("pattern", PATTERN),
            ("type", TYPE),
        ]
        .map(|(name, text)| {
            let uri = format!("https://dev.hbla.ke/elephentity/v0/{name}.schema.json");
            let schema: Value = serde_json::from_str(text)
                .unwrap_or_else(|error| panic!("embedded {name} schema is invalid JSON: {error}"));

            (uri, schema)
        });

        let registry = Registry::new()
            .extend(
                documents
                    .iter()
                    .map(|(uri, schema)| (uri.as_str(), Resource::from_contents(schema.clone()))),
            )
            .expect("embedded schemas register")
            .prepare()
            .expect("embedded schemas resolve");

        let build = |kind: SpecKind| {
            let schema = documents
                .iter()
                .find(|(uri, _)| *uri == kind.schema_uri())
                .map(|(_, schema)| schema)
                .expect("every spec kind has an embedded schema");

            jsonschema::options()
                .with_registry(&registry)
                .build(schema)
                .unwrap_or_else(|error| panic!("{} schema does not compile: {error}", kind.name()))
        };

        Self {
            project: build(SpecKind::Project),
            entity: build(SpecKind::Entity),
            pattern: build(SpecKind::Pattern),
            type_: build(SpecKind::Type),
        }
    }

    pub fn validate(&self, spec: &RawSpec) -> Vec<SpecError> {
        let validator = match spec.kind {
            SpecKind::Project => &self.project,
            SpecKind::Entity => &self.entity,
            SpecKind::Pattern => &self.pattern,
            SpecKind::Type => &self.type_,
        };

        let document = spec.json();

        validator
            .iter_errors(&document)
            .map(|error| {
                let pointer = error.instance_path().to_string();

                SpecError::new(
                    "spec.invalid",
                    error.to_string(),
                    Some(&spec.file),
                    Some(if pointer.is_empty() {
                        "/".to_string()
                    } else {
                        pointer
                    }),
                )
            })
            .collect()
    }
}

impl Default for SchemaValidator {
    fn default() -> Self {
        Self::new()
    }
}
