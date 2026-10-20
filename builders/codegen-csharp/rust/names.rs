//! How spec names become C# names, and the collisions that can follow.
//!
//! A collision is a builder error, reported with the names involved, never C# that fails to compile: the compiler's message
//! would name a generated line the author did not write, and the author could not fix it there anyway.

use std::collections::BTreeMap;

const KEYWORDS: &[&str] = &[
    "abstract",
    "as",
    "base",
    "bool",
    "break",
    "byte",
    "case",
    "catch",
    "char",
    "checked",
    "class",
    "const",
    "continue",
    "decimal",
    "default",
    "delegate",
    "do",
    "double",
    "else",
    "enum",
    "event",
    "explicit",
    "extern",
    "false",
    "finally",
    "fixed",
    "float",
    "for",
    "foreach",
    "goto",
    "if",
    "implicit",
    "in",
    "int",
    "interface",
    "internal",
    "is",
    "lock",
    "long",
    "namespace",
    "new",
    "null",
    "object",
    "operator",
    "out",
    "override",
    "params",
    "private",
    "protected",
    "public",
    "readonly",
    "ref",
    "return",
    "sbyte",
    "sealed",
    "short",
    "sizeof",
    "stackalloc",
    "static",
    "string",
    "struct",
    "switch",
    "this",
    "throw",
    "true",
    "try",
    "typeof",
    "uint",
    "ulong",
    "unchecked",
    "unsafe",
    "ushort",
    "using",
    "virtual",
    "void",
    "volatile",
    "while",
];

/// Members every class already has, which a generated member must not hide or redeclare.
const OBJECT_MEMBERS: &[&str] = &[
    "Equals",
    "GetHashCode",
    "ToString",
    "GetType",
    "Finalize",
    "MemberwiseClone",
    "ReferenceEquals",
];

pub fn is_keyword(name: &str) -> bool {
    KEYWORDS.contains(&name)
}

/// `@class`: a keyword used as an identifier.
pub fn escape(name: &str) -> String {
    if is_keyword(name) {
        format!("@{name}")
    } else {
        name.to_string()
    }
}

/// `defaultExpiryUnit`, `default_expiry_unit` and `DefaultExpiryUnit` all become `DefaultExpiryUnit`.
pub fn pascal(name: &str) -> String {
    let mut out = String::new();

    for part in name.split(['_', '-', ' ']).filter(|part| !part.is_empty()) {
        let mut characters = part.chars();

        if let Some(first) = characters.next() {
            out.extend(first.to_uppercase());
            out.push_str(characters.as_str());
        }
    }

    if out
        .chars()
        .next()
        .is_some_and(|first| first.is_ascii_digit())
    {
        out.insert(0, '_');
    }

    out
}

/// A parameter or local name: `defaultExpiryUnit`, with `@` if that is a keyword.
pub fn camel(name: &str) -> String {
    let pascal = pascal(name);
    let mut characters = pascal.chars();
    let lowered = match characters.next() {
        Some(first) => first.to_lowercase().collect::<String>() + characters.as_str(),
        None => String::new(),
    };

    escape(&lowered)
}

/// A quoted C# string literal.
pub fn literal(text: &str) -> String {
    let mut out = String::from("\"");

    for character in text.chars() {
        match character {
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            // The rest of what would end a line or print as nothing: written as escapes so the literal is always
            // printable, and the compiler never sees a line terminator inside a string.
            other if other.is_control() || matches!(other, '\u{2028}' | '\u{2029}') => {
                let mut units = [0u16; 2];

                for unit in other.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04X}"));
                }
            }
            other => out.push(other),
        }
    }

    out.push('"');
    out
}

/// Every type the run emits, so two things that would be the same type are noticed.
#[derive(Default)]
pub struct TypeRegistry {
    types: BTreeMap<String, String>,
    pub errors: Vec<String>,
}

impl TypeRegistry {
    pub fn claim(&mut self, full_name: &str, what: &str) {
        if let Some(previous) = self.types.insert(full_name.to_string(), what.to_string()) {
            self.errors.push(format!(
                "{full_name} would be generated twice: for {previous} and for {what}. Rename one of them in the spec."
            ));
        }
    }
}

/// The members of one class being written, so two that would share a name are noticed.
pub struct Members<'a> {
    owner: &'a str,
    taken: BTreeMap<String, String>,
    pub errors: Vec<String>,
}

impl<'a> Members<'a> {
    pub fn new(owner: &'a str) -> Self {
        Self {
            owner,
            taken: BTreeMap::new(),
            errors: Vec::new(),
        }
    }

    /// Reserve `name` for `what`; returns the name for convenience.
    pub fn claim(&mut self, name: &str, what: &str) -> String {
        if name == self.owner {
            self.errors.push(format!(
                "{what} would be a member named {name} inside {}: a C# member cannot have its type's name. Rename it in the spec.",
                self.owner
            ));
        } else if OBJECT_MEMBERS.contains(&name) {
            self.errors.push(format!(
                "{what} would be a member named {name} inside {}, which every C# object already has. Rename it in the spec.",
                self.owner
            ));
        } else if let Some(previous) = self.taken.get(name) {
            self.errors.push(format!(
                "{what} and {previous} would both be {}.{name}. Rename one of them in the spec.",
                self.owner
            ));
        } else {
            self.taken.insert(name.to_string(), what.to_string());
        }

        name.to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_become_pascal_case_whatever_they_started_as() {
        assert_eq!(pascal("defaultExpiryUnit"), "DefaultExpiryUnit");
        assert_eq!(pascal("default_expiry_unit"), "DefaultExpiryUnit");
        assert_eq!(pascal("DefaultExpiryUnit"), "DefaultExpiryUnit");
        assert_eq!(pascal("2fa"), "_2fa");
        assert_eq!(pascal("in-progress"), "InProgress");
    }

    #[test]
    fn a_keyword_is_escaped_as_a_local_and_never_needs_it_as_a_type() {
        assert_eq!(camel("class"), "@class");
        assert_eq!(camel("namespace"), "@namespace");
        assert_eq!(camel("title"), "title");
        assert_eq!(camel("Title"), "title");
        assert_eq!(pascal("class"), "Class");
        assert_eq!(escape("string"), "@string");
        assert_eq!(escape("String"), "String");
    }

    #[test]
    fn literals_escape_what_would_end_the_string() {
        assert_eq!(literal("a\"b\\c\n"), "\"a\\\"b\\\\c\\n\"");
        assert_eq!(
            literal("\u{85}\u{2028}\u{2029}"),
            "\"\\u0085\\u2028\\u2029\""
        );
        assert_eq!(literal("\0\u{1}\u{7f}"), "\"\\u0000\\u0001\\u007F\"");
    }

    #[test]
    fn colliding_members_and_types_are_reported_with_both_names() {
        let mut members = Members::new("Item");
        members.claim("Title", "field title");
        members.claim("Title", "field Title");
        members.claim("Item", "field item");
        members.claim("ToString", "field toString");

        assert_eq!(members.errors.len(), 3, "{:?}", members.errors);
        assert!(members.errors[0].contains("field Title and field title would both be Item.Title"));
        assert!(members.errors[1].contains("cannot have its type's name"));
        assert!(members.errors[2].contains("every C# object already has"));

        let mut types = TypeRegistry::default();
        types.claim("Ns.ItemInput", "the input of Item");
        types.claim("Ns.ItemInput", "entity ItemInput");

        assert_eq!(types.errors.len(), 1);
    }
}
