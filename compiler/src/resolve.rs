//! Spec documents to resolved project, types and entities.
//!
//! This is `SchemaCompiler::compile()` up to, but not including, building the schema: load,
//! validate against the JSON Schemas, then resolve patterns, configuration and integrations.
//! Semantic validation and the IR come in later steps.
//!
//! Errors accumulate rather than aborting, so one run reports everything wrong with the
//! specs. The one stage boundary is the same as the PHP compiler's: nothing is resolved
//! while any document is malformed.

use indexmap::IndexMap;
use serde::Serialize;
use serde_json::{Map, Value};

use crate::error::SpecError;
use crate::integration::{
    resolve_entity_integrations, resolve_project_integrations, resolve_query_integrations,
    IntegrationRegistry,
};
use crate::model::{
    parse_enum, ConfigParameter, EntityDefinition, Origin, ParsedSections, PatternDefinition,
    ProjectDefinition, QueryDefinition, StorageDefinition, TerminalRule, TypeDefinition,
};
use crate::pattern::{expand_patterns, merge_sections, resolve_config};
use crate::schema::SchemaValidator;
use crate::spec::sections::parse_sections;
use crate::spec::{load, RawSpec, SpecKind, SpecReader, SpecSource};

/// What the compiler is told by its caller rather than finding on disk.
#[derive(Default)]
pub struct Options {
    pub integrations: IntegrationRegistry,
    /// Patterns and types a builder ships, pooled from `describe` before any spec on disk is
    /// read. Listed first, so a project document colliding with one of these is the
    /// offender, not the incumbent.
    pub pooled_patterns: Vec<RawSpec>,
    pub pooled_types: Vec<RawSpec>,
}

#[derive(Debug, Serialize)]
pub struct Resolved {
    pub project: ProjectDefinition,
    pub types: IndexMap<String, TypeDefinition>,
    pub entities: IndexMap<String, EntityDefinition>,
}

pub fn resolve(source: &SpecSource, options: &Options) -> Result<Resolved, Vec<SpecError>> {
    let loaded = load(source);
    let mut errors = loaded.errors;

    let specs: Vec<&RawSpec> = options
        .pooled_patterns
        .iter()
        .chain(&options.pooled_types)
        .chain(&loaded.specs)
        .collect();

    let validator = SchemaValidator::new();

    for spec in &specs {
        errors.extend(validator.validate(spec));
    }

    if !errors.is_empty() {
        return Err(errors);
    }

    let project = build_project(&specs, source, &options.integrations, &mut errors);

    let Some(project) = project else {
        return Err(errors);
    };

    let types = build_types(&specs, &mut errors);
    let patterns = build_patterns(&specs, &mut errors);
    let entities = build_entities(
        &project,
        &specs,
        &patterns,
        &options.integrations,
        &mut errors,
    );

    if !errors.is_empty() {
        return Err(errors);
    }

    Ok(Resolved {
        project,
        types,
        entities,
    })
}

fn build_types(
    specs: &[&RawSpec],
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, TypeDefinition> {
    let mut types: IndexMap<String, TypeDefinition> = IndexMap::new();

    for spec in specs.iter().filter(|s| s.kind == SpecKind::Type) {
        let reader = spec.reader();
        let name = spec.name();

        if let Some(existing) = types.get(&name) {
            errors.push(SpecError::new(
                "type.duplicate",
                format!(
                    "Type \"{name}\" is already declared in {}.",
                    existing.source_file
                ),
                Some(&spec.file),
                None,
            ));
            continue;
        }

        types.insert(
            name.clone(),
            TypeDefinition {
                name,
                primitive: parse_enum(&reader.string("primitive")),
                source_file: spec.file.clone(),
                description: reader.optional_string("description"),
                has_processors: reader.bool("processors", false),
                values: reader.has("values").then(|| reader.string_list("values")),
            },
        );
    }

    types
}

fn build_patterns(
    specs: &[&RawSpec],
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, PatternDefinition> {
    let mut patterns: IndexMap<String, PatternDefinition> = IndexMap::new();

    for spec in specs.iter().filter(|s| s.kind == SpecKind::Pattern) {
        let reader = spec.reader();
        let name = spec.name();

        if let Some(existing) = patterns.get(&name) {
            errors.push(SpecError::new(
                "pattern.duplicate",
                format!(
                    "Pattern \"{name}\" is already declared in {}.",
                    existing.source_file
                ),
                Some(&spec.file),
                None,
            ));
            continue;
        }

        let storage = reader
            .reader("storage")
            .map(|storage| {
                storage
                    .all()
                    .iter()
                    .filter_map(|(key, value)| Some((key.clone(), value.as_str()?.to_string())))
                    .collect()
            })
            .unwrap_or_default();

        let definition = PatternDefinition {
            name: name.clone(),
            source_file: spec.file.clone(),
            sections: parse_sections(reader, &Origin::pattern(&name, &spec.file)),
            uses: reader.string_list("use"),
            requires_driver: reader
                .reader("requires")
                .and_then(|requires| requires.optional_string("driver")),
            storage,
            config: config_parameters(reader),
            description: reader.optional_string("description"),
            generates_interface: reader.bool("interface", false),
        };

        patterns.insert(name, definition);
    }

    patterns
}

fn config_parameters(reader: SpecReader<'_>) -> IndexMap<String, ConfigParameter> {
    reader
        .readers("config")
        .into_iter()
        .map(|(name, declaration)| {
            let parameter = ConfigParameter {
                name: name.clone(),
                type_: parse_enum(&declaration.string("type")),
                description: declaration.optional_string("description"),
                of: declaration.optional_string("of").map(|of| parse_enum(&of)),
                values: declaration
                    .has("values")
                    .then(|| declaration.string_list("values")),
                nullable: declaration.bool("nullable", false),
                default: declaration.raw("default"),
                has_default: declaration.has("default"),
            };

            (name, parameter)
        })
        .collect()
}

fn build_project(
    specs: &[&RawSpec],
    source: &SpecSource,
    registry: &IntegrationRegistry,
    errors: &mut Vec<SpecError>,
) -> Option<ProjectDefinition> {
    if let Some(spec) = specs.iter().find(|s| s.kind == SpecKind::Project) {
        let reader = spec.reader();
        let storage = reader.reader("storage");

        return Some(ProjectDefinition {
            name: spec.name(),
            driver: storage.map(|s| s.string("driver")).unwrap_or_default(),
            source_file: spec.file.clone(),
            integrations: resolve_project_integrations(
                registry,
                reader.reader("integrations").map(|r| r.all()),
                &spec.file,
                errors,
            ),
            table_prefix: storage
                .and_then(|s| s.optional_string("tablePrefix"))
                .unwrap_or_default(),
            description: reader.optional_string("description"),
        });
    }

    // Without one there is no driver, so nothing downstream can be resolved.
    errors.push(SpecError::new(
        "project.missing",
        format!(
            "No project.yml in {}. Every project needs one; it declares the storage driver.",
            source.root
        ),
        Some(&source.root),
        None,
    ));

    None
}

/// Validate each query's integration block once the project is known.
///
/// The parser cannot do it: what a given integration accepts depends on the registry, and
/// whether it is available depends on the project, neither of which exists when a pattern's
/// sections are first read.
fn expose_queries(
    queries: &IndexMap<String, QueryDefinition>,
    project: &ProjectDefinition,
    registry: &IntegrationRegistry,
    entity_name: &str,
    file: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, QueryDefinition> {
    queries
        .iter()
        .map(|(query_name, query)| {
            let declared: Option<Map<String, Value>> =
                (!query.integrations.is_empty()).then(|| {
                    query
                        .integrations
                        .iter()
                        .map(|(name, settings)| (name.clone(), Value::Object(settings.clone())))
                        .collect()
                });

            let mut exposed = query.clone();
            exposed.integrations = resolve_query_integrations(
                registry,
                declared.as_ref(),
                &project.integrations,
                &format!("{entity_name}::{query_name}"),
                file,
                &format!("/queries/{query_name}/integrations"),
                errors,
            );

            (query_name.clone(), exposed)
        })
        .collect()
}

fn build_entities(
    project: &ProjectDefinition,
    specs: &[&RawSpec],
    patterns: &IndexMap<String, PatternDefinition>,
    registry: &IntegrationRegistry,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, EntityDefinition> {
    let mut entities: IndexMap<String, EntityDefinition> = IndexMap::new();

    for spec in specs.iter().filter(|s| s.kind == SpecKind::Entity) {
        let reader = spec.reader();
        let name = spec.name();

        if let Some(existing) = entities.get(&name) {
            errors.push(SpecError::new(
                "entity.duplicate",
                format!(
                    "Entity \"{name}\" is already declared in {}.",
                    existing.source_file
                ),
                Some(&spec.file),
                None,
            ));
            continue;
        }

        let uses = reader.string_list("use");
        let (expansion, expansion_errors) = expand_patterns(patterns, &uses, &spec.file);
        errors.extend(expansion_errors);

        let storage_reader = reader.reader("storage");

        // The project prefix is applied here, so everything downstream (conflict detection,
        // DDL, queries) sees one resolved table name.
        let mut storage: IndexMap<String, String> = IndexMap::new();
        storage.insert("driver".to_string(), project.driver.clone());
        storage.insert(
            "table".to_string(),
            format!(
                "{}{}",
                project.table_prefix,
                storage_reader
                    .map(|s| s.string("table"))
                    .unwrap_or_default()
            ),
        );

        if let Some(handle) = storage_reader.and_then(|s| s.optional_string("handle")) {
            storage.insert("handle".to_string(), handle);
        }

        let mut contributions: Vec<ParsedSections> = Vec::new();
        let mut applied: Vec<&PatternDefinition> = Vec::new();

        for pattern in expansion {
            if let Some(required) = &pattern.requires_driver {
                if required != &project.driver {
                    errors.push(SpecError::new(
                        "pattern.driverMismatch",
                        format!(
                            "Pattern \"{}\" requires driver \"{required}\" but {name} uses \"{}\".",
                            pattern.name, project.driver
                        ),
                        Some(&spec.file),
                        None,
                    ));
                    continue;
                }
            }

            for (key, value) in &pattern.storage {
                if storage.contains_key(key) {
                    errors.push(SpecError::new(
                        "pattern.collision",
                        format!(
                            "{name} declares storage.{key} from both the entity spec and pattern {}. Patterns are sealed.",
                            pattern.name
                        ),
                        Some(&spec.file),
                        None,
                    ));
                    continue;
                }

                storage.insert(key.clone(), value.clone());
            }

            contributions.push(pattern.sections.clone());
            applied.push(pattern);
        }

        contributions.push(parse_sections(reader, &Origin::entity(&spec.file)));

        let configuration = resolve_config(
            &applied,
            reader.reader("configure").map(|r| r.all()),
            &name,
            &spec.file,
            errors,
        );

        let integrations = resolve_entity_integrations(
            registry,
            reader.reader("integrations").map(|r| r.all()),
            &project.integrations,
            &name,
            &spec.file,
            errors,
        );

        let (sections, merge_errors) = merge_sections(&contributions, &name, &spec.file);
        errors.extend(merge_errors);

        let terminal_rule = reader
            .reader("policies")
            .and_then(|policies| policies.reader("terminalRule"));

        if let Some(rule) = terminal_rule {
            if rule.has("read") && sections.read_policies.is_empty() {
                errors.push(SpecError::new(
                    "policy.terminalWithoutPolicies",
                    "A terminal read rule is declared but the entity has no read policies.",
                    Some(&spec.file),
                    Some("/policies/terminalRule/read".to_string()),
                ));
            }

            if rule.has("write") && sections.write_policies.is_empty() {
                errors.push(SpecError::new(
                    "policy.terminalWithoutPolicies",
                    "A terminal write rule is declared but the entity has no write policies.",
                    Some(&spec.file),
                    Some("/policies/terminalRule/write".to_string()),
                ));
            }
        }

        let queries = expose_queries(
            &sections.queries,
            project,
            registry,
            &name,
            &spec.file,
            errors,
        );

        let terminal = |side: &str| -> TerminalRule {
            terminal_rule
                .and_then(|rule| rule.optional_string(side))
                .map_or(TerminalRule::Deny, |value| parse_enum(&value))
        };

        entities.insert(
            name.clone(),
            EntityDefinition {
                name,
                storage: StorageDefinition {
                    driver: storage["driver"].clone(),
                    table: storage["table"].clone(),
                    handle: storage.get("handle").cloned(),
                },
                source_file: spec.file.clone(),
                description: reader.optional_string("description"),
                uses,
                applied_patterns: applied.iter().map(|p| p.name.clone()).collect(),
                fields: sections.fields,
                edges: sections.edges,
                queries,
                actions: sections.actions,
                side_effects: sections.side_effects,
                config: configuration,
                integrations,
                read_policies: sections.read_policies,
                write_policies: sections.write_policies,
                terminal_read: terminal("read"),
                terminal_write: terminal("write"),
            },
        );
    }

    entities
}
