use indexmap::IndexMap;

use crate::error::SpecError;
use crate::model::PatternDefinition;

/// Expands an entity's `use:` list into the full, ordered set of patterns that apply.
///
/// Patterns may use other patterns, so expansion is recursive. A pattern reached twice by
/// different routes applies once rather than colliding with itself, and a cycle is
/// reported rather than hung on. A pattern's own dependencies apply before it does.
pub fn expand_patterns<'a>(
    patterns: &'a IndexMap<String, PatternDefinition>,
    uses: &[String],
    entity_file: &str,
) -> (Vec<&'a PatternDefinition>, Vec<SpecError>) {
    let mut resolved: IndexMap<&str, &PatternDefinition> = IndexMap::new();
    let mut errors = Vec::new();

    for name in uses {
        visit(
            name,
            patterns,
            entity_file,
            &mut resolved,
            &mut errors,
            &mut Vec::new(),
        );
    }

    (resolved.into_values().collect(), errors)
}

fn visit<'a>(
    name: &str,
    patterns: &'a IndexMap<String, PatternDefinition>,
    entity_file: &str,
    resolved: &mut IndexMap<&'a str, &'a PatternDefinition>,
    errors: &mut Vec<SpecError>,
    trail: &mut Vec<String>,
) {
    if trail.iter().any(|seen| seen == name) {
        let mut route = trail.clone();
        route.push(name.to_string());

        errors.push(SpecError::new(
            "pattern.cycle",
            format!("Pattern cycle: {}.", route.join(" → ")),
            Some(entity_file),
            None,
        ));

        return;
    }

    if resolved.contains_key(name) {
        return;
    }

    let Some((key, pattern)) = patterns.get_key_value(name) else {
        errors.push(SpecError::new(
            "pattern.unknown",
            format!("Unknown pattern \"{name}\". Expected patterns/{name}.yml."),
            Some(entity_file),
            None,
        ));

        return;
    };

    trail.push(name.to_string());

    for dependency in &pattern.uses {
        visit(dependency, patterns, entity_file, resolved, errors, trail);
    }

    trail.pop();

    resolved.insert(key, pattern);
}
