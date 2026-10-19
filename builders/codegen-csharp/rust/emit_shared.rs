//! Files that belong to the project rather than to one entity: enums, the processor interfaces of declared types, and the
//! interfaces of patterns.

use crate::cs::File;
use crate::gen::*;
use crate::names::{literal, pascal, Members};
use crate::runtime::*;
use std::collections::BTreeMap;

impl Gen<'_> {
    pub fn shared(&self) -> Result<Vec<File>> {
        let mut files = Vec::new();

        for t in vals(&self.schema["types"]) {
            let name = s(&t["name"]);

            if !t["values"].is_null() {
                files.push(self.enumeration(
                    name,
                    &t["values"],
                    s(&t["primitive"]) == "int",
                    t["description"].as_str(),
                )?);
            }

            if b(&t["hasProcessors"]) && t["values"].is_null() {
                files.push(self.processor(t, false));
                files.push(self.processor(t, true));
            }
        }

        for e in self.entities() {
            for f in vals(&e["fields"]) {
                // A field that came from a pattern has the pattern's enum, written once below.
                if !f["enum"]["inlineValues"].is_null() && f["origin"]["pattern"].is_null() {
                    files.push(self.enumeration(
                        &format!("{}{}", pascal(s(&e["name"])), pascal(s(&f["name"]))),
                        &f["enum"]["inlineValues"],
                        false,
                        Some(&format!("Values of {}.{}.", s(&e["name"]), s(&f["name"]))),
                    )?);
                }
            }
        }

        for p in vals(&self.schema["patterns"]) {
            for f in vals(&p["fields"]) {
                if !f["enum"]["inlineValues"].is_null() {
                    files.push(self.enumeration(
                        &format!("{}{}", pascal(s(&p["name"])), pascal(s(&f["name"]))),
                        &f["enum"]["inlineValues"],
                        false,
                        Some(&format!("Values of {}.{}.", s(&p["name"]), s(&f["name"]))),
                    )?);
                }
            }

            files.extend(self.pattern(p)?);
        }

        Ok(files)
    }

    /// `Day`, `InProgress`: the case a label becomes.
    fn case_name(label: &str) -> String {
        pascal(label)
    }

    /// An enum, and the static class that maps it to and from what is stored.
    fn enumeration(
        &self,
        name: &str,
        values: &serde_json::Value,
        int: bool,
        comment: Option<&str>,
    ) -> Result<File> {
        let type_name = pascal(name);
        let ns = self.enum_ns();
        let mut file = self.file(
            format!("Enums/{type_name}.cs"),
            &ns,
            &type_name,
            &format!("enum {name}"),
        );
        let labels: Vec<&str> = list(values).into_iter().map(s).collect();
        let mut cases: BTreeMap<String, &str> = BTreeMap::new();

        for label in &labels {
            let case = Self::case_name(label);

            if let Some(previous) = cases.insert(case.clone(), label) {
                return Err(format!(
                    "C# enum {name}: labels \"{previous}\" and \"{label}\" both generate case {case}."
                ));
            }
        }

        let code = &mut file.code;

        if let Some(comment) = comment {
            code.doc(comment);
        }

        code.open(format!("public enum {type_name}"));

        for label in &labels {
            code.line(format!("{},", Self::case_name(label)));
        }

        code.close();
        code.blank();

        code.doc(&format!(
            "How {type_name} is stored: {}.",
            if int {
                "by position, as an integer"
            } else {
                "as its label, as text"
            }
        ));
        code.open(format!("public static class {type_name}Wire"));

        let wire = if int { "long" } else { "string" };

        code.doc("The value that is stored.");
        code.open(format!(
            "public static {wire} ToWire(this {type_name} value) => value switch"
        ));

        for (index, label) in labels.iter().enumerate() {
            let stored = if int {
                format!("{index}L")
            } else {
                literal(label)
            };

            code.line(format!(
                "{type_name}.{} => {stored},",
                Self::case_name(label)
            ));
        }

        code.line(format!("_ => throw new global::System.ArgumentOutOfRangeException(nameof(value), value, \"Not a member of {type_name}.\"),"));
        code.close_with(";");
        code.blank();

        code.doc("The member a stored value means, or nothing when no member does.");
        code.open(format!(
            "public static {type_name}? FromWire(object? value)"
        ));

        if int {
            code.open("var position = value switch");
            code.line("long number => number,");
            code.line("int number => number,");
            code.line("string text when long.TryParse(text, global::System.Globalization.NumberStyles.AllowLeadingSign, global::System.Globalization.CultureInfo.InvariantCulture, out var number) => number,");
            code.line("_ => (long?)null,");
            code.close_with(";");
            code.blank();
            code.open("return position switch");

            for (index, label) in labels.iter().enumerate() {
                code.line(format!(
                    "{index}L => {type_name}.{},",
                    Self::case_name(label)
                ));
            }
        } else {
            code.open("return value switch");

            for label in &labels {
                code.line(format!(
                    "{} => {type_name}.{},",
                    literal(label),
                    Self::case_name(label)
                ));
            }
        }

        code.line(format!("_ => ({type_name}?)null,"));
        code.close_with(";");
        code.close();
        code.blank();

        code.doc("The write processor the unit of work uses for this type: it checks the member is one of ours and stores its value.");
        code.line(format!(
            "public static {WRITE_PROCESSOR} Processor {{ get; }} = new WriteProcessor();"
        ));
        code.blank();
        code.open(format!(
            "private sealed class WriteProcessor : {WRITE_PROCESSOR}"
        ));
        code.line(format!(
            "public {VERIFICATION} Verify(object value, {MUTATION_CONTEXT} context) => value is {type_name} ? {VERIFICATION}.Ok() : {VERIFICATION}.Failed(new {VIOLATION}(\"enum.member\", \"This is not a {type_name}.\"));"
        ));
        code.blank();
        code.line(format!(
            "public object Write(object value) => (({type_name})value).ToWire();"
        ));
        code.close();
        code.close();

        Ok(file)
    }

    /// The typed interface an application implements for a declared type, bridged to the runtime's untyped one.
    fn processor(&self, t: &serde_json::Value, write: bool) -> File {
        let name = s(&t["name"]);
        let (stored, via) = self.stored(name);
        let interface = format!(
            "I{}{}Processor",
            pascal(name),
            if write { "Write" } else { "Read" }
        );
        let ns = self.processor_ns();
        let mut file = self.file(
            format!("Types/{interface}.cs"),
            &ns,
            &interface,
            &format!(
                "the {} processor of {name}",
                if write { "write" } else { "read" }
            ),
        );
        let value = format!("global::{}.{}", self.types, pascal(name));
        let code = &mut file.code;
        let convert = match via {
            "Whole" => "global::System.Convert.ToInt64(value, global::System.Globalization.CultureInfo.InvariantCulture)",
            "Number" => "global::System.Convert.ToDouble(value, global::System.Globalization.CultureInfo.InvariantCulture)",
            "Bool" => "global::System.Convert.ToBoolean(value, global::System.Globalization.CultureInfo.InvariantCulture)",
            _ => "global::System.Convert.ToString(value, global::System.Globalization.CultureInfo.InvariantCulture)!",
        };

        if write {
            code.doc(&format!(
                "Checks a {name} and turns it back into a stored {stored}."
            ));
            code.open(format!("public interface {interface} : {WRITE_PROCESSOR}"));
            code.doc("Whether the value is acceptable.");
            code.line(format!(
                "{VERIFICATION} Verify({value} value, {MUTATION_CONTEXT} context);"
            ));
            code.blank();
            code.doc("The stored form of the value.");
            code.line(format!("{stored} Write({value} value);"));
            code.blank();
            code.line(format!(
                "{VERIFICATION} {WRITE_PROCESSOR}.Verify(object value, {MUTATION_CONTEXT} context) => Verify(({value})value, context);"
            ));
            code.blank();
            code.line(format!(
                "object {WRITE_PROCESSOR}.Write(object value) => Write(({value})value);"
            ));
        } else {
            code.doc(&format!("Turns a stored {stored} into a {name}."));
            code.open(format!("public interface {interface} : {READ_PROCESSOR}"));
            code.doc("The value a stored one means.");
            code.line(format!("{value} Read({stored} stored);"));
            code.blank();
            code.line(format!(
                "object {READ_PROCESSOR}.Read(object value) => Read({convert});"
            ));
        }

        code.close();

        file
    }

    /// A pattern's interface, for code that needs only its fields and edges, and its policy contracts.
    fn pattern(&self, p: &serde_json::Value) -> Result<Vec<File>> {
        let name = s(&p["name"]);
        let interface = self.pattern_interface(name);
        let ns = self.pattern_ns();
        let mut files = Vec::new();
        let mut file = self.file(
            format!("Pattern/{}/{interface}.cs", pascal(name)),
            &ns,
            &interface,
            &format!("pattern {name}"),
        );
        let mut members = Members::new(&interface);
        let code = &mut file.code;

        code.doc(&format!(
            "The {name} fields and edges, for code that only needs those and not the whole entity.\n\nEvery entity using {name} already has these members: this names the shape, it adds nothing an application has to implement."
        ));
        code.open(format!("public interface {interface}"));

        for f in vals(&p["fields"]) {
            let ty = self.pattern_field_ty(p, f)?;

            if let Some(d) = f["description"].as_str() {
                code.doc(d);
            }

            let property = members.claim(
                &pascal(s(&f["name"])),
                &format!("field {name}.{}", s(&f["name"])),
            );
            code.line(format!(
                "{} {property} {{ get; }}",
                ty.with(b(&f["nullable"]))
            ));
        }

        for edge in vals(&p["edges"]) {
            let target = self.entity(s(&edge["to"]));

            if target.is_null() {
                continue;
            }

            let target_type = self.type_name(target);
            let edge_name = pascal(s(&edge["name"]));
            let what = format!("edge {name}.{}", s(&edge["name"]));

            if edge["cardinality"] == "one" {
                let method = members.claim(&format!("Get{edge_name}Async"), &what);
                code.line(format!("{VALUE_TASK}<{target_type}?> {method}({CANCELLATION_TOKEN} cancellationToken = default);"));
            } else {
                let method = members.claim(&edge_name, &what);
                code.line(format!("{ENTITY_QUERY}<{target_type}> {method}();"));
            }
        }

        code.close();
        self.fail_all(members.errors);
        files.push(file);

        for write in [false, true] {
            for policy in vals(
                &p[if write {
                    "writePolicies"
                } else {
                    "readPolicies"
                }],
            ) {
                files.push(self.pattern_policy(p, policy, write));
            }
        }

        Ok(files)
    }

    /// A pattern's field type: pattern fields have no entity, so an inline enum is named for the pattern.
    fn pattern_field_ty(&self, p: &serde_json::Value, f: &serde_json::Value) -> Result<Ty> {
        if f["type"]["primitive"] == "enum" {
            let source = &f["enum"];

            return Ok(Ty {
                name: self.enum_type(&if source["inlineValues"].is_null() {
                    s(&source["declaredType"]).to_string()
                } else {
                    format!("{}{}", s(&p["name"]), pascal(s(&f["name"])))
                }),
                kind: Kind::Enum,
            });
        }

        self.reference(&f["type"])
    }

    fn pattern_policy(
        &self,
        p: &serde_json::Value,
        policy: &serde_json::Value,
        write: bool,
    ) -> File {
        let pattern = s(&p["name"]);
        let interface = self.pattern_policy_name(pattern, policy, write);
        let ns = self.pattern_contract_ns();
        let mut file = self.file(
            format!("Pattern/{}/Contract/{interface}.cs", pascal(pattern)),
            &ns,
            &interface,
            &format!(
                "{} policy {} of pattern {pattern}",
                if write { "write" } else { "read" },
                s(&policy["name"])
            ),
        );
        let entity = self.fq_pattern(pattern);
        let code = &mut file.code;

        code.doc(&format!(
            "Application policy {} for pattern {pattern}.",
            s(&policy["name"])
        ));
        code.open(format!("public interface {interface}"));

        if write {
            code.line(format!(
                "{VALUE_TASK}<{POLICY_DECISION}> DecideAsync({entity}? entity, {WRITE_CONTEXT} context, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default);"
            ));
        } else {
            code.line(format!(
                "{VALUE_TASK}<{POLICY_DECISION}> DecideAsync({entity} entity, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default);"
            ));
        }

        code.close();

        file
    }
}
