//! What writing C# needs that is not about the schema: which words a namespace may not use, and how a string is quoted.

/// C# reserved words, which cannot be a namespace segment without an `@` escape.
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

/// Whether `namespace` is a dotted run of identifiers, none of them a reserved word.
pub fn is_namespace(namespace: &str) -> bool {
    !namespace.is_empty()
        && namespace.split('.').all(|segment| {
            let mut characters = segment.chars();

            characters
                .next()
                .is_some_and(|first| first == '_' || first.is_alphabetic())
                && characters.all(|rest| rest == '_' || rest.is_alphanumeric())
                && !KEYWORDS.contains(&segment)
        })
}

/// A quoted C# string literal that is always printable: nothing that ends a line, or ends the string, gets through.
pub fn literal(text: &str) -> String {
    let mut out = String::from("\"");

    for character in text.chars() {
        match character {
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
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
