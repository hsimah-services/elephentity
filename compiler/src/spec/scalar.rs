//! Plain YAML scalars, typed the way the PHP compiler's parser (symfony/yaml) types them.
//!
//! saphyr follows the YAML 1.2 core schema, which disagrees with Symfony in a few places
//! that change a spec's meaning: `007` stays the string "007", `1_000` is a number, an
//! integer too big for 64 bits stays a string, and `2024-01-31` is a UTC timestamp. Resolving
//! plain scalars from their text with Symfony's rules keeps the two compilers' output
//! byte-identical. Quoted and block scalars are always strings, as in both.
//!
//! Ported from `Inline::evaluateScalar` in symfony/yaml 7.

use serde_json::{Number, Value};

pub fn resolve_plain(text: &str) -> Result<Value, String> {
    let lower = text.to_ascii_lowercase();

    if lower == "null" || text.is_empty() || text == "~" {
        return Ok(Value::Null);
    }

    match lower.as_str() {
        "true" => return Ok(Value::Bool(true)),
        "false" => return Ok(Value::Bool(false)),
        _ => {}
    }

    if let Some(octal) = octal(text) {
        return octal;
    }

    let first = text.chars().next().unwrap_or(' ');

    if matches!(first, '+' | '-' | '.') || first.is_ascii_digit() {
        return numeric(text, &lower);
    }

    Ok(Value::String(text.to_string()))
}

/// `0o14`, `-0o1_7`: the YAML 1.2 octal form, with Symfony's underscores.
fn octal(text: &str) -> Option<Result<Value, String>> {
    let (negative, rest) = match text.as_bytes().first()? {
        b'-' => (true, &text[1..]),
        b'+' => (false, &text[1..]),
        _ => (false, text),
    };

    let digits = rest.strip_prefix("0o")?;

    if digits.is_empty() || !digits.bytes().all(|b| matches!(b, b'0'..=b'7' | b'_')) {
        return None;
    }

    let digits = digits.replace('_', "");

    Some(
        i64::from_str_radix(&digits, 8)
            .map(|n| Value::Number((if negative { -n } else { n }).into()))
            .map_err(|_| format!("The octal number {text} is out of range.")),
    )
}

fn numeric(text: &str, lower: &str) -> Result<Value, String> {
    let mut scalar = text.to_string();

    if is_int_with_underscores(&scalar) {
        scalar = scalar.replace('_', "");

        if scalar.starts_with('+') {
            scalar.remove(0);
        }
    }

    let digits = scalar.strip_prefix('-').unwrap_or(&scalar);

    if !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()) {
        // Anything that does not survive a round trip through an integer, such as a leading
        // zero, "-0" or a value past 64 bits, is kept as the string it was written as.
        return Ok(match scalar.parse::<i64>() {
            Ok(n) if n.to_string() == scalar => Value::Number(n.into()),
            _ => Value::String(scalar),
        });
    }

    if is_numeric(&scalar) || is_hex(&scalar) {
        let scalar = scalar.replace('_', "");

        return match scalar.strip_prefix("0x") {
            Some(digits) => hexdec(digits),
            None => float(
                scalar
                    .parse()
                    .map_err(|_| format!("Not a number: {text}."))?,
                text,
            ),
        };
    }

    match lower {
        ".inf" | "-.inf" | ".nan" => {
            return Err(format!("{text} cannot be represented in a spec."));
        }
        _ => {}
    }

    if is_float_with_underscores(&scalar) {
        let scalar = scalar.replace('_', "");

        return float(
            scalar
                .parse()
                .map_err(|_| format!("Not a number: {text}."))?,
            text,
        );
    }

    if let Some(timestamp) = timestamp(text) {
        return timestamp;
    }

    Ok(Value::String(scalar))
}

fn float(value: f64, text: &str) -> Result<Value, String> {
    Number::from_f64(value)
        .map(Value::Number)
        .ok_or_else(|| format!("{text} cannot be represented in a spec."))
}

fn hexdec(digits: &str) -> Result<Value, String> {
    // PHP's hexdec skips anything that is not a hex digit, so the underscores are harmless.
    let value = digits
        .chars()
        .filter_map(|c| c.to_digit(16))
        .try_fold(0i64, |acc, digit| {
            acc.checked_mul(16)
                .and_then(|n| n.checked_add(i64::from(digit)))
        });

    value
        .map(|n| Value::Number(n.into()))
        .ok_or_else(|| format!("The hexadecimal number 0x{digits} is out of range."))
}

/// `^[+-]?[0-9][0-9_]*$`
fn is_int_with_underscores(text: &str) -> bool {
    let rest = text.strip_prefix(['+', '-']).unwrap_or(text);

    rest.as_bytes().first().is_some_and(u8::is_ascii_digit)
        && rest.bytes().all(|b| b.is_ascii_digit() || b == b'_')
}

/// `^(-|\+)?[0-9][0-9_]*(\.[0-9_]+)?$`
fn is_float_with_underscores(text: &str) -> bool {
    let rest = text.strip_prefix(['+', '-']).unwrap_or(text);
    let (whole, fraction) = match rest.split_once('.') {
        Some((whole, fraction)) => (whole, Some(fraction)),
        None => (rest, None),
    };

    whole.as_bytes().first().is_some_and(u8::is_ascii_digit)
        && whole.bytes().all(|b| b.is_ascii_digit() || b == b'_')
        && fraction
            .is_none_or(|f| !f.is_empty() && f.bytes().all(|b| b.is_ascii_digit() || b == b'_'))
}

/// PHP's `is_numeric` for an already-trimmed string: `5`, `5.`, `.5`, `1.5e3`, `-2E-4`.
fn is_numeric(text: &str) -> bool {
    let rest = text.strip_prefix(['+', '-']).unwrap_or(text);
    let (mantissa, exponent) = match rest.find(['e', 'E']) {
        Some(at) => (&rest[..at], Some(&rest[at + 1..])),
        None => (rest, None),
    };

    let (whole, fraction) = match mantissa.split_once('.') {
        Some((whole, fraction)) => (whole, Some(fraction)),
        None => (mantissa, None),
    };

    let digits = |part: &str| part.bytes().all(|b| b.is_ascii_digit());

    let mantissa_ok = match fraction {
        None => !whole.is_empty() && digits(whole),
        Some(fraction) => {
            (!whole.is_empty() || !fraction.is_empty()) && digits(whole) && digits(fraction)
        }
    };

    let exponent_ok = exponent.is_none_or(|e| {
        let e = e.strip_prefix(['+', '-']).unwrap_or(e);

        !e.is_empty() && digits(e)
    });

    mantissa_ok && exponent_ok
}

/// `^0x[0-9a-fA-F_]++$`
fn is_hex(text: &str) -> bool {
    text.strip_prefix("0x").is_some_and(|digits| {
        !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_hexdigit() || b == b'_')
    })
}

/// A YAML timestamp, as the Unix time in UTC (`2024-01-31`, `2024-01-31T10:00:00.5+02:00`).
///
/// Symfony hands these to `DateTimeImmutable` with UTC as the default zone, and returns an
/// integer, or a float when there is a fractional second.
fn timestamp(text: &str) -> Option<Result<Value, String>> {
    let invalid = || {
        Some(Err(format!(
            "The date \"{text}\" could not be parsed as it is an invalid date."
        )))
    };

    let bytes = text.as_bytes();
    let mut at = 0;

    let number = |min: usize, max: usize, at: &mut usize| -> Option<i64> {
        let start = *at;

        while *at < bytes.len() && *at - start < max && bytes[*at].is_ascii_digit() {
            *at += 1;
        }

        (*at - start >= min)
            .then(|| text[start..*at].parse().ok())
            .flatten()
    };

    let year = number(4, 4, &mut at)?;
    expect(bytes, &mut at, b'-')?;
    let month = number(1, 2, &mut at)?;
    expect(bytes, &mut at, b'-')?;
    let day = number(1, 2, &mut at)?;

    let (mut hour, mut minute, mut second, mut micros, mut offset) = (0, 0, 0, 0u32, 0i64);

    if at < bytes.len() {
        // `T`, `t`, or spaces and tabs.
        if matches!(bytes[at], b'T' | b't') {
            at += 1;
        } else if matches!(bytes[at], b' ' | b'\t') {
            while at < bytes.len() && matches!(bytes[at], b' ' | b'\t') {
                at += 1;
            }
        } else {
            return None;
        }

        hour = number(1, 2, &mut at)?;
        expect(bytes, &mut at, b':')?;
        minute = number(2, 2, &mut at)?;
        expect(bytes, &mut at, b':')?;
        second = number(2, 2, &mut at)?;

        if at < bytes.len() && bytes[at] == b'.' {
            at += 1;
            let start = at;

            while at < bytes.len() && bytes[at].is_ascii_digit() {
                at += 1;
            }

            let fraction = format!("{:0<6}", &text[start..at]);
            micros = fraction[..6].parse().ok()?;
        }

        while at < bytes.len() && matches!(bytes[at], b' ' | b'\t') {
            at += 1;
        }

        if at < bytes.len() {
            if bytes[at] == b'Z' {
                at += 1;
            } else if matches!(bytes[at], b'+' | b'-') {
                let sign = if bytes[at] == b'-' { -1 } else { 1 };
                at += 1;
                let tz_hour = number(1, 2, &mut at)?;
                let tz_minute = if at < bytes.len() && bytes[at] == b':' {
                    at += 1;
                    number(2, 2, &mut at)?
                } else {
                    0
                };

                offset = sign * (tz_hour * 3600 + tz_minute * 60);
            } else {
                return None;
            }
        }
    }

    if at != bytes.len() {
        return None;
    }

    if !(1..=12).contains(&month)
        || !(1..=31).contains(&day)
        || hour > 24
        || minute > 59
        || second > 60
    {
        return invalid();
    }

    let seconds = days_from_civil(year, month, 1) * 86_400
        + (day - 1) * 86_400
        + hour * 3600
        + minute * 60
        + second
        - offset;

    if micros == 0 {
        return Some(Ok(Value::Number(seconds.into())));
    }

    if seconds < 0 {
        return Some(Err(format!(
            "The date \"{text}\" has a fractional second before 1970, which a spec cannot hold."
        )));
    }

    Some(
        format!("{seconds}.{micros:06}")
            .parse::<f64>()
            .map_err(|e| e.to_string())
            .and_then(|f| float(f, text)),
    )
}

fn expect(bytes: &[u8], at: &mut usize, wanted: u8) -> Option<()> {
    (bytes.get(*at) == Some(&wanted)).then(|| *at += 1)
}

/// Days since 1970-01-01 for a proleptic Gregorian date (Howard Hinnant's algorithm).
fn days_from_civil(year: i64, month: i64, day: i64) -> i64 {
    let year = if month <= 2 { year - 1 } else { year };
    let era = year.div_euclid(400);
    let year_of_era = year - era * 400;
    let day_of_year = (153 * ((month + 9) % 12) + 2) / 5 + day - 1;
    let day_of_era = year_of_era * 365 + year_of_era / 4 - year_of_era / 100 + day_of_year;

    era * 146_097 + day_of_era - 719_468
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn plain(text: &str) -> Value {
        resolve_plain(text).unwrap()
    }

    #[test]
    fn follows_symfony_rather_than_the_yaml_core_schema() {
        assert_eq!(plain("007"), json!("007"));
        assert_eq!(plain("-0"), json!("-0"));
        assert_eq!(plain("+007"), json!("007"));
        assert_eq!(plain("1_000"), json!(1000));
        assert_eq!(plain("1_000.5"), json!(1000.5));
        assert_eq!(plain("+5"), json!(5));
        assert_eq!(plain("9223372036854775808"), json!("9223372036854775808"));
        assert_eq!(plain("0o14"), json!(12));
        assert_eq!(plain("0x1F"), json!(31));
        assert_eq!(plain("0X1F"), json!("0X1F"));
        assert_eq!(plain("1e3"), json!(1000.0));
        assert_eq!(plain("5."), json!(5.0));
        assert_eq!(plain(".5"), json!(0.5));
        assert_eq!(plain("yes"), json!("yes"));
        assert_eq!(plain("True"), json!(true));
        assert_eq!(plain("NULL"), Value::Null);
    }

    #[test]
    fn dates_are_utc_unix_times() {
        assert_eq!(plain("2024-01-31"), json!(1_706_659_200));
        assert_eq!(plain("2024-01-31T10:00:00Z"), json!(1_706_695_200));
        assert_eq!(plain("2024-01-31 10:00:00 +02:00"), json!(1_706_688_000));
        assert_eq!(plain("2024-01-31T10:00:00.5Z"), json!(1_706_695_200.5));
        assert!(resolve_plain("2024-13-01").is_err());
    }

    #[test]
    fn text_stays_text() {
        assert_eq!(plain("hello"), json!("hello"));
        assert_eq!(plain("1.2.3"), json!("1.2.3"));
        assert_eq!(plain("-"), json!("-"));
        assert_eq!(plain("2024-01-31x"), json!("2024-01-31x"));
    }
}
