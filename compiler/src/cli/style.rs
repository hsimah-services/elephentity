//! The few shapes of output the commands use, as plain text.
//!
//! The PHP commands use Symfony's `SymfonyStyle`, which draws coloured, padded blocks. The words
//! are what scripts and people read, so those are kept exactly; the box is not.

use super::Console;

pub fn error(console: &mut Console<'_>, message: &str) {
    block(console, "ERROR", message);
}

pub fn success(console: &mut Console<'_>, message: &str) {
    block(console, "OK", message);
}

pub fn line(console: &mut Console<'_>, message: &str) {
    let _ = writeln!(console.out, "{message}");
}

fn block(console: &mut Console<'_>, label: &str, message: &str) {
    let _ = writeln!(console.out);

    for (index, text) in message.lines().enumerate() {
        if index == 0 {
            let _ = writeln!(console.out, " [{label}] {text}");
        } else {
            let _ = writeln!(console.out, "{text}");
        }
    }

    let _ = writeln!(console.out);
}
