use indexmap::IndexMap;

use crate::error::SpecError;
use crate::model::{Contributed, ParsedSections};

/// Merges pattern sections into an entity's own, sealed.
///
/// A member declared twice is a hard error, whoever declared it. Overridable patterns would
/// mean reading one file no longer tells you what a field is, which is the drift the
/// framework exists to prevent, so the resolution is to stop using the pattern, not to
/// shadow it.
///
/// `contributions` are the patterns first, the entity's own last.
pub fn merge_sections(
    contributions: &[ParsedSections],
    entity_name: &str,
    entity_file: &str,
) -> (ParsedSections, Vec<SpecError>) {
    let mut errors = Vec::new();

    let fields = merge_section(
        contributions.iter().map(|c| &c.fields).collect(),
        "field",
        entity_name,
        entity_file,
        &mut errors,
    );
    let edges = merge_section(
        contributions.iter().map(|c| &c.edges).collect(),
        "edge",
        entity_name,
        entity_file,
        &mut errors,
    );
    let queries = merge_section(
        contributions.iter().map(|c| &c.queries).collect(),
        "query",
        entity_name,
        entity_file,
        &mut errors,
    );
    let actions = merge_section(
        contributions.iter().map(|c| &c.actions).collect(),
        "action",
        entity_name,
        entity_file,
        &mut errors,
    );
    let side_effects = merge_section(
        contributions.iter().map(|c| &c.side_effects).collect(),
        "sideEffect",
        entity_name,
        entity_file,
        &mut errors,
    );
    let read_policies = merge_section(
        contributions.iter().map(|c| &c.read_policies).collect(),
        "readPolicy",
        entity_name,
        entity_file,
        &mut errors,
    );
    let write_policies = merge_section(
        contributions.iter().map(|c| &c.write_policies).collect(),
        "writePolicy",
        entity_name,
        entity_file,
        &mut errors,
    );

    let merged = ParsedSections {
        fields,
        edges,
        queries,
        actions,
        side_effects,
        read_policies,
        write_policies,
    };

    (merged, errors)
}

fn merge_section<T: Contributed + Clone>(
    contributions: Vec<&IndexMap<String, T>>,
    section: &str,
    entity_name: &str,
    entity_file: &str,
    errors: &mut Vec<SpecError>,
) -> IndexMap<String, T> {
    let mut merged: IndexMap<String, T> = IndexMap::new();

    for members in contributions {
        for (name, member) in members {
            if let Some(existing) = merged.get(name) {
                errors.push(SpecError::new(
                    "pattern.collision",
                    format!(
                        "{entity_name} declares {section} \"{name}\" from both {} and {}. Patterns are sealed: to change it, stop using the pattern.",
                        existing.origin().describe(),
                        member.origin().describe(),
                    ),
                    Some(entity_file),
                    // The pointer is the section name plus an "s", whatever the plural is,
                    // exactly as the PHP compiler writes it ("/readPolicys/owner").
                    Some(format!("/{section}s/{name}")),
                ));

                continue;
            }

            merged.insert(name.clone(), member.clone());
        }
    }

    merged
}
