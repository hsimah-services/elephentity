//! `json_encode`, as PHP 8 calls it for the generate request: compact, with
//! `JSON_UNESCAPED_SLASHES`, so no spaces, `/` left alone and every non-ASCII character as a
//! `\u` escape. Numbers follow `serialize_precision = -1`.
//!
//! Written by hand because the bytes are the contract: serde_json agrees on ASCII but
//! writes other characters raw and floats differently.

use serde_json::Value;

pub fn encode(value: &Value) -> String {
    let mut out = String::new();
    write(value, &mut out);
    out
}

fn write(value: &Value, out: &mut String) {
    match value {
        Value::Null => out.push_str("null"),
        Value::Bool(true) => out.push_str("true"),
        Value::Bool(false) => out.push_str("false"),
        Value::Number(number) => match number.as_f64() {
            Some(float) if number.is_f64() => out.push_str(&double(float)),
            _ => out.push_str(&number.to_string()),
        },
        Value::String(text) => string(text, out),
        Value::Array(items) => {
            out.push('[');

            for (index, item) in items.iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }

                write(item, out);
            }

            out.push(']');
        }
        Value::Object(entries) => {
            out.push('{');

            for (index, (key, item)) in entries.iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }

                string(key, out);
                out.push(':');
                write(item, out);
            }

            out.push('}');
        }
    }
}

fn string(text: &str, out: &mut String) {
    out.push('"');

    for character in text.chars() {
        match character {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c if c.is_ascii() => out.push(c),
            c => {
                // Outside the BMP a character is a surrogate pair, lowercase hex like PHP.
                let mut units = [0u16; 2];

                for unit in c.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }

    out.push('"');
}

/// PHP's `php_gcvt` with 17 digits and the shortest round-trip digits: fixed notation unless
/// the exponent is below -4 or at least 17 (`0.0001`, `1.0e-5`, `1.0e+17`), no `.0` on a
/// whole number (`100`, `-0`), and an exponent with no padding (`1.5e-7`).
fn double(value: f64) -> String {
    if value == 0.0 {
        return if value.is_sign_negative() { "-0" } else { "0" }.to_string();
    }

    // Rust's `{:e}` gives the shortest digits that round-trip, as `d.ddde<exp>`.
    let scientific = format!("{:e}", value.abs());
    let (mantissa, exponent) = scientific
        .split_once('e')
        .expect("a float formats with an exponent");
    let exponent: i32 = exponent.parse().expect("the exponent is an integer");
    let digits: String = mantissa.chars().filter(char::is_ascii_digit).collect();

    // PHP's decimal point position: the value is 0.<digits> x 10^point.
    let point = exponent + 1;
    let sign = if value < 0.0 { "-" } else { "" };

    let use_exponent = if point < 0 { point < -3 } else { point > 17 };

    if use_exponent {
        let exponent = point - 1;
        let (first, rest) = digits.split_at(1);
        let rest = if rest.is_empty() { "0" } else { rest };

        return format!(
            "{sign}{first}.{rest}e{}{}",
            if exponent < 0 { '-' } else { '+' },
            exponent.abs()
        );
    }

    if point <= 0 {
        return format!(
            "{sign}0.{}{digits}",
            "0".repeat(point.unsigned_abs() as usize)
        );
    }

    let point = point as usize;

    if digits.len() <= point {
        return format!("{sign}{digits}{}", "0".repeat(point - digits.len()));
    }

    format!("{sign}{}.{}", &digits[..point], &digits[point..])
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn floats_follow_serialize_precision_minus_one() {
        let cases = [
            (0.1, "0.1"),
            (100.0, "100"),
            (1.0e15, "1000000000000000"),
            (1.0e16, "10000000000000000"),
            (1.0e17, "1.0e+17"),
            (1.0e25, "1.0e+25"),
            (1.5e-7, "1.5e-7"),
            (0.00001, "1.0e-5"),
            (0.0001, "0.0001"),
            (123456789.12345679, "123456789.12345679"),
            (-0.0, "-0"),
            (0.0, "0"),
            (std::f64::consts::PI, "3.141592653589793"),
            (123456789012345678.0, "1.2345678901234568e+17"),
            (-2.5e-10, "-2.5e-10"),
            (0.30000000000000004, "0.30000000000000004"),
        ];

        for (value, expected) in cases {
            assert_eq!(double(value), expected, "{value:e}");
        }
    }

    #[test]
    fn strings_escape_the_way_php_does() {
        let value = json!("a/b \"q\" \\ \n\t\u{1} é ✓ 😀 \u{7f}");

        // Slashes and DEL stay as they are; everything else outside ASCII is a lowercase
        // \u escape, and a character beyond the BMP is a surrogate pair.
        let expected = "\"a/b \\\"q\\\" \\\\ \\n\\t\\u0001 \\u00e9 \\u2713 \\ud83d\\ude00 \u{7f}\"";

        assert_eq!(encode(&value), expected);
    }

    #[test]
    fn output_is_compact_and_ordered() {
        assert_eq!(
            encode(&json!({"b": [1, 2, {"c": null}], "a": true})),
            r#"{"b":[1,2,{"c":null}],"a":true}"#
        );
    }
}
