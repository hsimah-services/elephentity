//! What a storage driver requires of an entity's `storage.handle`.
//!
//! Declared by the driver itself, through its builder's `provides.storage`, so the compiler
//! asks what a driver requires instead of naming one. Every rule is optional: a driver that
//! imposes none, or has no notion of a handle, declares nothing.

use std::collections::BTreeMap;

use regex::RegexBuilder;
use serde::Deserialize;

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct StorageRules {
    pub max_handle_length: Option<usize>,
    /// A PCRE pattern with delimiters, as the PHP compiler's builders declare it: `/^[a-z]+$/`.
    pub handle_pattern: Option<String>,
    /// Handles no entity may take, spelled by the driver.
    #[serde(default)]
    pub reserved_handles: Vec<String>,
}

impl StorageRules {
    /// Whether `handle` has the shape the driver requires.
    ///
    /// A pattern that cannot be read is a mismatch, as it is in PHP, where `preg_match`
    /// returns false: the driver's declaration is wrong, and every handle is refused until
    /// it is fixed.
    pub fn handle_matches(&self, handle: &str) -> bool {
        let Some(pattern) = &self.handle_pattern else {
            return true;
        };

        pcre(pattern).is_some_and(|regex| regex.is_match(handle))
    }
}

/// The storage rules available to a project, one set per driver.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct StorageRuleRegistry {
    #[serde(default)]
    rules: BTreeMap<String, StorageRules>,
}

impl StorageRuleRegistry {
    pub fn new(rules: BTreeMap<String, StorageRules>) -> Self {
        Self { rules }
    }

    pub fn for_driver(&self, driver: &str) -> Option<&StorageRules> {
        self.rules.get(driver)
    }
}

/// A delimited PCRE pattern (`/.../i`) as a regex. Only the syntax both engines share is
/// supported, with the `i`, `m`, `s`, `x` and `u` modifiers.
fn pcre(pattern: &str) -> Option<regex::Regex> {
    let mut chars = pattern.chars();
    let open = chars
        .next()
        .filter(|c| !c.is_alphanumeric() && *c != '\\' && !c.is_whitespace())?;
    let close = match open {
        '(' => ')',
        '[' => ']',
        '{' => '}',
        '<' => '>',
        other => other,
    };

    let rest = chars.as_str();
    let end = rest.rfind(close)?;
    let (body, modifiers) = (&rest[..end], &rest[end + close.len_utf8()..]);

    let mut builder = RegexBuilder::new(body);

    for modifier in modifiers.chars() {
        match modifier {
            'i' => builder.case_insensitive(true),
            'm' => builder.multi_line(true),
            's' => builder.dot_matches_new_line(true),
            'x' => builder.ignore_whitespace(true),
            'u' => &mut builder,
            _ => return None,
        };
    }

    builder.build().ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rules(pattern: &str) -> StorageRules {
        StorageRules {
            handle_pattern: Some(pattern.to_string()),
            ..StorageRules::default()
        }
    }

    #[test]
    fn matches_a_delimited_pattern() {
        let rules = rules("/^[a-z][a-z0-9_-]*$/");

        assert!(rules.handle_matches("post_type"));
        assert!(!rules.handle_matches("Post"));
        assert!(!rules.handle_matches("1post"));
    }

    #[test]
    fn honours_modifiers_and_other_delimiters() {
        assert!(rules("#^abc$#i").handle_matches("ABC"));
        assert!(rules("{^abc$}").handle_matches("abc"));
    }

    #[test]
    fn an_unreadable_pattern_matches_nothing() {
        assert!(!rules("^abc$").handle_matches("abc"));
        assert!(!rules("/abc/Q").handle_matches("abc"));
        assert!(!rules("/(/").handle_matches("abc"));
    }

    #[test]
    fn no_pattern_means_no_rule() {
        assert!(StorageRules::default().handle_matches("anything"));
    }
}
