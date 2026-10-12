use serde::Serialize;

/// One reason a spec was rejected.
///
/// Errors are collected rather than returned one at a time, so a single run reports
/// everything wrong with the specs. The code, file and pointer are the parity surface with
/// the PHP compiler's `SpecError`; the message is for people.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct SpecError {
    /// Stable, machine-readable identifier, e.g. `pattern.collision`.
    pub code: String,
    pub message: String,
    pub file: Option<String>,
    /// JSON pointer into the spec document, when the error has a location.
    pub pointer: Option<String>,
}

impl SpecError {
    pub fn new(
        code: &str,
        message: impl Into<String>,
        file: Option<&str>,
        pointer: Option<String>,
    ) -> Self {
        Self {
            code: code.to_string(),
            message: message.into(),
            file: file.map(str::to_string),
            pointer,
        }
    }

    pub fn describe(&self) -> String {
        let mut location = self
            .file
            .clone()
            .unwrap_or_else(|| "<unknown file>".to_string());

        if let Some(pointer) = self.pointer.as_deref().filter(|p| !p.is_empty()) {
            location.push(' ');
            location.push_str(pointer);
        }

        format!("{location}: {} [{}]", self.message, self.code)
    }
}
