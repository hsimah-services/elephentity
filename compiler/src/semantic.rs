//! Checks that a structurally valid schema also means something.
//!
//! JSON Schema establishes shape. This establishes closure: every edge target resolves,
//! every declared type exists, every inverse is derivable, every action writes only members
//! it could write. A schema that passes here is safe for a generator to consume without
//! defensive checks of its own.
//!
//! Ported from `SemanticValidator`, rule for rule and in the same order, because the errors
//! are compared by code, file and pointer.

use crate::error::SpecError;
use crate::ir::Schema;
use crate::model::{
    Cardinality, EdgeDefinition, EntityDefinition, FieldDefinition, Primitive, TypeReference,
};
use crate::storage::StorageRuleRegistry;

/// MariaDB with DYNAMIC row format allows 3072 bytes per index, and utf8mb4 is 4 bytes per
/// character.
const INDEX_CHARACTER_LIMIT: i64 = 768;

const DEFAULT_STRING_LENGTH: i64 = 255;

/// Entity names the generated tree already uses for its shared folders.
///
/// `Enum/` holds every enum and `Type/` every processor, so an entity of either name would
/// land its own classes in a folder that means something else.
const RESERVED_ENTITY_NAMES: [&str; 2] = ["Enum", "Type"];

pub fn validate(schema: &Schema, storage_rules: &StorageRuleRegistry) -> Vec<SpecError> {
    let mut errors = Vec::new();

    check_entity_names(schema, &mut errors);
    check_tables(schema, &mut errors);
    check_types(schema, &mut errors);

    for entity in schema.entities.values() {
        check_storage(entity, storage_rules, &mut errors);

        for field in entity.fields.values() {
            check_field(schema, entity, field, &mut errors);
        }

        for edge in entity.edges.values() {
            check_edge(schema, entity, edge, &mut errors);
        }

        check_queries(schema, entity, &mut errors);
        check_actions(entity, &mut errors);
        check_policies(schema, entity, &mut errors);
    }

    errors
}

fn error(code: &str, message: String, entity: &EntityDefinition, pointer: &str) -> SpecError {
    SpecError::new(
        code,
        message,
        Some(&entity.source_file),
        Some(pointer.to_string()),
    )
}

fn check_entity_names(schema: &Schema, errors: &mut Vec<SpecError>) {
    for entity in schema.entities.values() {
        if !RESERVED_ENTITY_NAMES.contains(&entity.name.as_str()) {
            continue;
        }

        errors.push(error(
            "entity.reservedName",
            format!(
                "\"{}\" is reserved: the generated tree uses a folder of that name for every {} in the schema.",
                entity.name,
                if entity.name == "Enum" { "enum" } else { "type processor" }
            ),
            entity,
            "/entity",
        ));
    }
}

fn check_tables(schema: &Schema, errors: &mut Vec<SpecError>) {
    let mut seen: Vec<(&str, &str)> = Vec::new();

    for entity in schema.entities.values() {
        let table = entity.storage.table.as_str();

        if let Some((_, owner)) = seen.iter().find(|(seen_table, _)| *seen_table == table) {
            errors.push(error(
                "storage.tableConflict",
                format!(
                    "Entities {owner} and {} both map to table \"{table}\".",
                    entity.name
                ),
                entity,
                "/storage/table",
            ));

            continue;
        }

        seen.push((table, entity.name.as_str()));
    }
}

fn check_types(schema: &Schema, errors: &mut Vec<SpecError>) {
    for type_ in schema.types.values() {
        if type_.values.is_none() || type_.primitive.is_enum_backing() {
            continue;
        }

        errors.push(SpecError::new(
            "type.enumBacking",
            format!(
                "Enum type \"{}\" is backed by {}; a declared enum must be backed by string or int.",
                type_.name,
                type_.primitive.name()
            ),
            Some(&type_.source_file),
            Some("/primitive".to_string()),
        ));
    }
}

fn check_storage(
    entity: &EntityDefinition,
    storage_rules: &StorageRuleRegistry,
    errors: &mut Vec<SpecError>,
) {
    let Some(handle) = &entity.storage.handle else {
        return;
    };

    let driver = &entity.storage.driver;

    let Some(rules) = storage_rules.for_driver(driver) else {
        return;
    };

    // strlen: bytes, not characters.
    if let Some(max) = rules.max_handle_length.filter(|max| handle.len() > *max) {
        errors.push(error(
            "storage.handleTooLong",
            format!(
                "Handle \"{handle}\" is {} characters; driver \"{driver}\" caps handles at {max}.",
                handle.len()
            ),
            entity,
            "/storage/handle",
        ));
    }

    if let Some(pattern) = &rules.handle_pattern {
        if !rules.handle_matches(handle) {
            errors.push(error(
                "storage.handleInvalid",
                format!(
                    "Handle \"{handle}\" does not match the shape driver \"{driver}\" requires ({pattern})."
                ),
                entity,
                "/storage/handle",
            ));
        }
    }

    if rules.reserved_handles.contains(handle) {
        errors.push(error(
            "storage.handleReserved",
            format!("Handle \"{handle}\" is reserved by driver \"{driver}\"."),
            entity,
            "/storage/handle",
        ));
    }
}

fn check_field(
    schema: &Schema,
    entity: &EntityDefinition,
    field: &FieldDefinition,
    errors: &mut Vec<SpecError>,
) {
    let pointer = format!("/fields/{}", field.name);

    if field.name == "id" {
        errors.push(error(
            "field.reserved",
            "Every entity has an implicit \"id\"; it cannot be declared or overridden.".to_string(),
            entity,
            &pointer,
        ));
    }

    check_type_reference(schema, &field.type_ref, entity, &pointer, errors);

    let primitive = field.type_ref.primitive;

    if primitive == Some(Primitive::Enum) && field.enum_source.is_none() {
        errors.push(error(
            "field.enumWithoutValues",
            format!(
                "Field \"{}\" is an enum but declares no values.",
                field.name
            ),
            entity,
            &pointer,
        ));
    }

    if primitive != Some(Primitive::Enum) && field.enum_source.is_some() {
        errors.push(error(
            "field.valuesWithoutEnum",
            format!(
                "Field \"{}\" declares values but is not of type enum.",
                field.name
            ),
            entity,
            &pointer,
        ));
    }

    check_enum_source(schema, entity, field, &pointer, errors);

    if field.max_length.is_some() && primitive != Some(Primitive::String) {
        errors.push(error(
            "field.maxLengthNotSizable",
            format!(
                "Field \"{}\" declares maxLength but is of type {}; only string is sized.",
                field.name,
                field.type_ref.name()
            ),
            entity,
            &pointer,
        ));
    }

    check_index_width(entity, field, &pointer, errors);
    check_managed(entity, field, &pointer, errors);
}

/// A managed field is the framework's to fill, which rules out the two ways a spec can also
/// say who fills it: `required` asks a caller for a value it cannot supply, and `immutable`
/// describes a field settable exactly once where a managed one is settable never.
fn check_managed(
    entity: &EntityDefinition,
    field: &FieldDefinition,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) {
    if field.managed.is_none() {
        return;
    }

    if field.type_ref.primitive != Some(Primitive::Datetime) {
        errors.push(error(
            "field.managedNotSupported",
            format!(
                "Field \"{}\" is managed but is of type {}. The framework only knows how to fill a datetime; anything else needs a sideEffect.",
                field.name,
                field.type_ref.name()
            ),
            entity,
            pointer,
        ));
    }

    if field.required {
        errors.push(error(
            "field.managedAndRequired",
            format!(
                "Field \"{}\" is managed and required. The framework fills it, so requiring a caller to supply it is a contradiction — and it is how a machine-managed timestamp becomes mandatory API input. Drop `required`.",
                field.name
            ),
            entity,
            pointer,
        ));
    }

    if field.immutable {
        errors.push(error(
            "field.managedAndImmutable",
            format!(
                "Field \"{}\" is managed and immutable. Managed already means no setter; saying it twice lets the two drift. Drop `immutable`.",
                field.name
            ),
            entity,
            pointer,
        ));
    }
}

fn check_enum_source(
    schema: &Schema,
    entity: &EntityDefinition,
    field: &FieldDefinition,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) {
    let Some(source) = &field.enum_source else {
        return;
    };

    if source.is_inline() {
        // An inline enum generates a class named from entity + field. A declared type of the
        // same name would be silently overwritten, so reject it.
        let derived = format!("{}{}", entity.name, ucfirst(&field.name));

        if schema.types.contains_key(&derived) {
            errors.push(error(
                "field.enumNameCollision",
                format!(
                    "Inline enum \"{}.{}\" generates \"{derived}\", which is already declared as a type. Reference it with \"values: {derived}\" instead.",
                    entity.name, field.name
                ),
                entity,
                pointer,
            ));
        }

        return;
    }

    let name = source.declared_type.clone().unwrap_or_default();

    let Some(type_) = schema.types.get(&name) else {
        errors.push(error(
            "field.unknownEnum",
            format!(
                "Field \"{}\" references unknown enum \"{name}\". Expected types/{name}.yml.",
                field.name
            ),
            entity,
            pointer,
        ));

        return;
    };

    if type_.values.is_none() {
        errors.push(error(
            "field.notAnEnum",
            format!(
                "Field \"{}\" references type \"{name}\", which declares no values and so is not an enum.",
                field.name
            ),
            entity,
            pointer,
        ));
    }
}

fn check_index_width(
    entity: &EntityDefinition,
    field: &FieldDefinition,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) {
    if field.type_ref.primitive != Some(Primitive::String) || (!field.indexed && !field.unique) {
        return;
    }

    let width = field.max_length.unwrap_or(DEFAULT_STRING_LENGTH);

    if width <= INDEX_CHARACTER_LIMIT {
        return;
    }

    // Never silently prefix-index: with unique: true a prefix index enforces uniqueness of the
    // prefix, so two genuinely different values collide and nothing tells you.
    errors.push(error(
        "field.indexTooWide",
        format!(
            "Field \"{}\" is indexed at maxLength {width}, beyond the {INDEX_CHARACTER_LIMIT}-character index limit. Narrow it rather than accepting a prefix index, which would enforce uniqueness of the prefix only.",
            field.name
        ),
        entity,
        pointer,
    ));
}

fn check_edge(
    schema: &Schema,
    entity: &EntityDefinition,
    edge: &EdgeDefinition,
    errors: &mut Vec<SpecError>,
) {
    let pointer = format!("/edges/{}", edge.name);
    let target = schema.entities.get(&edge.to);

    if target.is_none() {
        errors.push(error(
            "edge.unknownTarget",
            format!(
                "Edge \"{}\" points at unknown entity \"{}\".",
                edge.name, edge.to
            ),
            entity,
            &pointer,
        ));
    }

    if edge.required && edge.cardinality != Cardinality::One {
        errors.push(error(
            "edge.requiredNotToOne",
            format!(
                "Edge \"{}\" is required but cardinality: many. required only expresses \"this one relationship must be set\" — a to-many edge needing at least one target is a different rule this does not cover.",
                edge.name
            ),
            entity,
            &pointer,
        ));
    }

    let Some(inverse) = &edge.inverse else {
        return;
    };

    // Derivability does not depend on the target, so it is checked even when the target is
    // missing. Bailing early here would hide a second problem behind the first.
    if inverse.derived && !inverse.unique {
        errors.push(error(
            "edge.inverseNotDerivable",
            format!(
                "Edge \"{}\" declares a non-unique reverse, so the derived name would have to be pluralised. Name it explicitly: inverse: {{ name: <name>, unique: false }}.",
                edge.name
            ),
            entity,
            &pointer,
        ));

        return;
    }

    let Some(target) = target else {
        return;
    };

    let name = inverse.name_for(&entity.name);

    if target.fields.contains_key(&name) || target.edges.contains_key(&name) {
        errors.push(error(
            "edge.inverseCollision",
            format!(
                "Edge \"{}\" would generate \"{name}\" on {}, which already declares a member of that name.",
                edge.name, target.name
            ),
            entity,
            &pointer,
        ));
    }
}

fn check_queries(schema: &Schema, entity: &EntityDefinition, errors: &mut Vec<SpecError>) {
    for query in entity.queries.values() {
        let pointer = format!("/queries/{}", query.name);

        if !schema.entities.contains_key(&query.returns.type_name) {
            errors.push(error(
                "query.unknownReturn",
                format!(
                    "Query \"{}\" returns unknown entity \"{}\".",
                    query.name, query.returns.type_name
                ),
                entity,
                &pointer,
            ));
        }

        for argument in query.arguments.values() {
            check_type_reference(schema, &argument.type_ref, entity, &pointer, errors);
        }
    }
}

fn check_actions(entity: &EntityDefinition, errors: &mut Vec<SpecError>) {
    for action in entity.actions.values() {
        let pointer = format!("/actions/{}", action.name);

        for field in &action.writes.fields {
            let Some(declared) = entity.fields.get(field) else {
                errors.push(error(
                    "action.unknownWrite",
                    format!(
                        "Action \"{}\" declares it writes field \"{field}\", which {} does not declare.",
                        action.name, entity.name
                    ),
                    entity,
                    &pointer,
                ));

                continue;
            };

            if declared.managed.is_some() {
                // The context is generated from `writes:`, so a setter would have to exist
                // for a field nothing else can set. Refusing here keeps "managed means nobody
                // sets it" true without exception.
                errors.push(error(
                    "action.managedWrite",
                    format!(
                        "Action \"{}\" declares it writes field \"{field}\", which is managed. The framework fills it on every commit, including this one.",
                        action.name
                    ),
                    entity,
                    &pointer,
                ));
            }
        }

        for edge in &action.writes.edges {
            if !entity.edges.contains_key(edge) {
                errors.push(error(
                    "action.unknownWrite",
                    format!(
                        "Action \"{}\" declares it writes edge \"{edge}\", which {} does not declare.",
                        action.name, entity.name
                    ),
                    entity,
                    &pointer,
                ));
            }
        }
    }
}

fn check_policies(schema: &Schema, entity: &EntityDefinition, errors: &mut Vec<SpecError>) {
    for (kind, policies) in [
        ("read", &entity.read_policies),
        ("write", &entity.write_policies),
    ] {
        for policy in policies.values() {
            let Some(pattern) = &policy.origin.pattern else {
                continue;
            };

            if schema.patterns.contains_key(pattern) {
                continue;
            }

            errors.push(error(
                "policy.patternWithoutInterface",
                format!(
                    "Pattern \"{pattern}\" declares a {kind} policy but does not declare interface: true. Add interface: true to the pattern."
                ),
                entity,
                &format!("/{kind}Policies/{}", policy.name),
            ));
        }
    }
}

fn check_type_reference(
    schema: &Schema,
    reference: &TypeReference,
    entity: &EntityDefinition,
    pointer: &str,
    errors: &mut Vec<SpecError>,
) {
    if reference.is_primitive() {
        return;
    }

    let name = reference.declared_type.clone().unwrap_or_default();

    if !schema.types.contains_key(&name) {
        errors.push(error(
            "type.unknown",
            format!("Unknown type \"{name}\". Expected a primitive or types/{name}.yml."),
            entity,
            pointer,
        ));
    }
}

/// PHP's `ucfirst`: only an ASCII letter is changed.
fn ucfirst(text: &str) -> String {
    let mut characters = text.chars();

    match characters.next() {
        Some(first) => first.to_ascii_uppercase().to_string() + characters.as_str(),
        None => String::new(),
    }
}
