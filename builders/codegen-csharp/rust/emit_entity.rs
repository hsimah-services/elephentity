//! The read side of an entity: the read model, the hydrator that builds it from a stored row, and the input that applies
//! raw values to a pending change.

use crate::cs::File;
use crate::gen::*;
use crate::names::{camel, literal, pascal, Members};
use crate::runtime::*;

impl Gen<'_> {
    /// Edges that point at `e` from other entities and have an inverse side.
    pub fn inverses(
        &self,
        e: &serde_json::Value,
    ) -> Vec<(&serde_json::Value, &serde_json::Value, String)> {
        let mut found = Vec::new();

        for declaring in self.entities() {
            for edge in vals(&declaring["edges"]) {
                if edge["to"] == e["name"] && !edge["inverse"].is_null() {
                    let name = if b(&edge["inverse"]["derived"]) {
                        crate::names::camel(s(&declaring["name"]))
                    } else {
                        s(&edge["inverse"]["name"]).to_string()
                    };

                    found.push((declaring, edge, name));
                }
            }
        }

        found
    }

    pub fn has_edges(&self, e: &serde_json::Value) -> bool {
        !vals(&e["edges"]).is_empty() || !self.inverses(e).is_empty()
    }

    /// The read model: a sealed class of typed properties, with edges that read lazily.
    pub fn read_model(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let type_name = self.type_name(e);
        let mut file = self.file(
            self.path(e, &type_name),
            &self.root,
            &type_name,
            &format!("entity {entity}"),
        );
        let mut members = Members::new(&type_name);
        let of_name = format!("{type_name}.Of");
        let mut parameters = Members::new(&of_name);
        let has_edges = self.has_edges(e);

        members.claim("Id", "the id");
        members.claim("Of", "the factory");
        parameters.claim("id", "the id");

        if has_edges {
            parameters.claim("edgeLoader", "the edge loader");
        }

        let patterns: Vec<String> = list(&e["appliedPatterns"])
            .into_iter()
            .map(s)
            .filter(|p| !self.schema["patterns"][*p].is_null())
            .map(|p| self.fq_pattern(p))
            .collect();

        let mut declared: Vec<(String, String, bool)> = Vec::new();

        for f in vals(&e["fields"]) {
            let ty = self.field_ty(e, f)?;
            let property = members.claim(
                &pascal(s(&f["name"])),
                &format!("field {entity}.{}", s(&f["name"])),
            );
            let parameter = parameters.claim(
                &camel(s(&f["name"])),
                &format!("field {entity}.{}", s(&f["name"])),
            );

            declared.push((ty.with(b(&f["nullable"])), parameter, false));
            let _ = property;
        }

        let code = &mut file.code;

        code.doc(
            e["description"]
                .as_str()
                .unwrap_or(&format!("{entity}, as stored.")),
        );
        code.open(format!(
            "public sealed class {type_name}{}",
            if patterns.is_empty() {
                String::new()
            } else {
                format!(" : {}", patterns.join(", "))
            }
        ));

        if has_edges {
            code.line(format!("private readonly {EDGE_LOADER} edgeLoader;"));
            code.blank();
        }

        let parameter_list = |with_types: bool| -> String {
            let mut parts: Vec<String> = vec![if with_types {
                format!("{ENTITY_ID} id")
            } else {
                "id".to_string()
            }];

            if has_edges {
                parts.push(if with_types {
                    format!("{EDGE_LOADER} edgeLoader")
                } else {
                    "edgeLoader".to_string()
                });
            }

            for (ty, parameter, _) in &declared {
                parts.push(if with_types {
                    format!("{ty} {parameter}")
                } else {
                    parameter.clone()
                });
            }

            parts.join(", ")
        };

        code.open(format!("private {type_name}({})", parameter_list(true)));
        code.line("Id = id;");

        if has_edges {
            code.line("this.edgeLoader = edgeLoader;");
        }

        for (f, (_, parameter, _)) in vals(&e["fields"]).iter().zip(&declared) {
            code.line(format!("{} = {parameter};", pascal(s(&f["name"]))));
        }

        code.close();
        code.blank();
        code.doc(&format!("Builds a {entity} from values already read."));
        code.line(format!(
            "public static {type_name} Of({}) => new({});",
            parameter_list(true),
            parameter_list(false)
        ));
        code.blank();
        code.doc("The id this row has in storage.");
        code.line(format!("public {ENTITY_ID} Id {{ get; }}"));

        for f in vals(&e["fields"]) {
            let ty = self.field_ty(e, f)?;

            code.blank();

            if let Some(d) = f["description"].as_str() {
                code.doc(d);
            }

            code.line(format!(
                "public {} {} {{ get; }}",
                ty.with(b(&f["nullable"])),
                pascal(s(&f["name"]))
            ));
        }

        for edge in vals(&e["edges"]) {
            let target = self.entity(s(&edge["to"]));

            if target.is_null() {
                continue;
            }

            let target_type = self.type_name(target);
            let edge_name = s(&edge["name"]);
            let one = edge["cardinality"] == "one";

            code.blank();
            self.edge_read(
                &mut members,
                code,
                entity,
                edge_name,
                &target_type,
                one,
                &format!(
                    "edgeLoader.ToMany({}, Id, {})",
                    literal(entity),
                    literal(edge_name)
                ),
                &format!(
                    "edgeLoader.ToOneAsync({}, Id, {}, cancellationToken)",
                    literal(entity),
                    literal(edge_name)
                ),
                None,
            );
        }

        for (declaring, edge, name) in self.inverses(e) {
            let target_type = self.type_name(declaring);
            let one = b(&edge["inverse"]["unique"]);
            let declaring_name = s(&declaring["name"]);
            let edge_name = s(&edge["name"]);

            code.blank();
            self.edge_read(
                &mut members,
                code,
                entity,
                &name,
                &target_type,
                one,
                &format!(
                    "edgeLoader.InverseToMany({}, {}, Id)",
                    literal(declaring_name),
                    literal(edge_name)
                ),
                &format!(
                    "edgeLoader.InverseToOneAsync({}, {}, Id, cancellationToken)",
                    literal(declaring_name),
                    literal(edge_name)
                ),
                Some(format!(
                    "{} {declaring_name} whose \"{edge_name}\" points here.",
                    if one { "The" } else { "Every" }
                )),
            );
        }

        code.close();
        self.fail_all(members.errors);
        self.fail_all(parameters.errors);

        Ok(file)
    }

    #[allow(clippy::too_many_arguments)]
    fn edge_read(
        &self,
        members: &mut Members<'_>,
        code: &mut crate::cs::Code,
        owner: &str,
        name: &str,
        target_type: &str,
        one: bool,
        many_call: &str,
        one_call: &str,
        comment: Option<String>,
    ) {
        let what = format!("edge {owner}.{name}");

        if one {
            let method = members.claim(&format!("Get{}Async", pascal(name)), &what);

            if let Some(comment) = comment {
                code.doc(&comment);
            }

            code.open(format!(
                "public async {VALUE_TASK}<{target_type}?> {method}({CANCELLATION_TOKEN} cancellationToken = default)"
            ));
            code.line(format!(
                "return ({target_type}?)await {one_call}.ConfigureAwait(false);"
            ));
            code.close();
        } else {
            let method = members.claim(&pascal(name), &what);

            if let Some(comment) = comment {
                code.doc(&comment);
            }

            code.line(format!(
                "public {ENTITY_QUERY}<{target_type}> {method}() => {ENTITY_QUERIES}.Cast<{target_type}>({many_call});"
            ));
        }
    }

    /// Builds the read model from a stored row.
    pub fn hydrator(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let type_name = self.type_name(e);
        let class = self.class(e, "Hydrator");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the hydrator of {entity}"),
        );
        let mut members = Members::new(&class);
        let readers = self.readers(e, false);
        let code = &mut file.code;

        members.claim("Hydrate", "the hydrate method");

        code.doc(&format!("Builds a {entity} from a stored row."));
        code.open(format!(
            "public sealed class {class} : {HYDRATOR}<{type_name}>"
        ));

        for name in &readers {
            code.line(format!(
                "private readonly {} {};",
                self.read_processor(name),
                self.reader_field(name)
            ));
        }

        if !readers.is_empty() {
            code.blank();
            code.open(format!(
                "public {class}({})",
                readers
                    .iter()
                    .map(|n| format!("{} {}", self.read_processor(n), self.reader_field(n)))
                    .collect::<Vec<_>>()
                    .join(", ")
            ));

            for name in &readers {
                let field = self.reader_field(name);

                code.line(format!("this.{field} = {field};"));
            }

            code.close();
            code.blank();
        }

        let mut arguments = vec!["record.Id".to_string()];

        if self.has_edges(e) {
            arguments.push("edges".into());
        }

        for f in vals(&e["fields"]) {
            let method = members.claim(
                &format!("Read{}", pascal(s(&f["name"]))),
                &format!("field {entity}.{}", s(&f["name"])),
            );

            arguments.push(format!("{method}(record)"));
        }

        code.line(format!(
            "public {type_name} Hydrate({RECORD} record, {EDGE_LOADER} edges) => {type_name}.Of({});",
            arguments.join(", ")
        ));

        for f in vals(&e["fields"]) {
            let name = s(&f["name"]);
            let ty = self.field_ty(e, f)?;
            let label = literal(&format!("{entity}.{name}"));
            let enum_name = if ty.kind == Kind::Enum && f["type"]["primitive"] == "enum" {
                Some(self.field_enum_name(e, f)?)
            } else {
                None
            };
            let conversion = self.decode(&f["type"], &ty, "value", &label, enum_name.as_deref());

            code.blank();
            code.open(format!(
                "private {} Read{}({RECORD} record)",
                ty.with(b(&f["nullable"])),
                pascal(name)
            ));
            code.line(format!("var value = record.Value({});", literal(name)));

            if b(&f["nullable"]) {
                code.open("if (value is null)");
                code.line("return null;");
                code.close();
                code.blank();
            }

            code.line(format!("return {conversion};"));
            code.close();
        }

        code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// The C# for a field's default value, as the raw value the input would decode.
    fn default_value(&self, f: &serde_json::Value, ty: &Ty, decoder: &str) -> String {
        let default = &f["default"];

        if default.is_null() {
            return "null".into();
        }

        if let Some(label) = default.as_str() {
            let enumerated = !f["enum"].is_null()
                || (!s(&f["type"]["declaredType"]).is_empty()
                    && self.is_enum_type(s(&f["type"]["declaredType"])));

            if enumerated {
                return format!("{}.{}", ty.name, pascal(label));
            }
        }

        let raw = match default {
            serde_json::Value::Bool(v) => v.to_string(),
            serde_json::Value::Number(v) if v.is_i64() => format!("{}L", v),
            serde_json::Value::Number(v) => format!("{:?}", v.as_f64().unwrap_or_default()),
            serde_json::Value::String(v) => literal(v),
            // A JSON default is handed over as the JSON it is; the decoder parses it.
            other => literal(&other.to_string()),
        };

        format!("{decoder}({raw})")
    }

    /// Turns raw input into pending changes: the `Apply` the catalogue calls, and the decoders for actions and queries.
    pub fn input(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let class = self.class(e, "Input");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the input of {entity}"),
        );
        let mut members = Members::new(&class);
        let readers = self.readers(e, true);
        let dictionary = format!("{READ_ONLY_DICTIONARY}<string, object?>");
        let code = &mut file.code;

        members.claim("Apply", "apply");
        members.claim("DecodeAction", "decode action");
        members.claim("DecodeQuery", "decode query");

        code.doc(&format!("Turns raw input into pending {entity} changes."));
        code.open(format!("public sealed class {class}"));

        for name in &readers {
            code.line(format!(
                "private readonly {} {};",
                self.read_processor(name),
                self.reader_field(name)
            ));
        }

        if !readers.is_empty() {
            code.blank();
            code.open(format!(
                "public {class}({})",
                readers
                    .iter()
                    .map(|n| format!("{} {}", self.read_processor(n), self.reader_field(n)))
                    .collect::<Vec<_>>()
                    .join(", ")
            ));

            for name in &readers {
                let field = self.reader_field(name);

                code.line(format!("this.{field} = {field};"));
            }

            code.close();
        }

        code.blank();
        code.doc("Seeds declared defaults on create and applies explicit input.\n\nAbsent update keys and existing pending values are not reset by defaults.");
        code.open(format!(
            "public void Apply({MUTATION_BUFFER} buffer, {dictionary} input)"
        ));

        let mut first = true;

        for f in vals(&e["fields"]) {
            if !f["managed"].is_null() {
                continue;
            }

            let name = s(&f["name"]);
            let ty = self.field_ty(e, f)?;
            let decoder = format!("Decode{}", pascal(name));

            if !first {
                code.blank();
            }

            first = false;

            if b(&f["hasDefault"]) {
                let value = self.default_value(f, &ty, &decoder);

                code.open(format!(
                    "if (!buffer.Target.IsPersisted && !buffer.IsChanged({0}) && !input.ContainsKey({0}))",
                    literal(name)
                ));
                code.line(format!("buffer.Assign({}, {value});", literal(name)));
                code.close();
                code.blank();
            }

            code.open(format!(
                "if (input.TryGetValue({}, out var raw{}))",
                literal(name),
                pascal(name)
            ));
            code.line(format!(
                "buffer.Assign({}, {decoder}(raw{}));",
                literal(name),
                pascal(name)
            ));
            code.close();
        }

        for edge in vals(&e["edges"]) {
            let name = s(&edge["name"]);

            if !first {
                code.blank();
            }

            first = false;
            code.open(format!(
                "if (input.TryGetValue({}, out var rawEdge{}))",
                literal(name),
                pascal(name)
            ));
            code.line(format!(
                "buffer.Edge({}).Replace(DecodeEdge{}(rawEdge{}));",
                literal(name),
                pascal(name),
                pascal(name)
            ));
            code.close();
        }

        code.close();

        for f in vals(&e["fields"]) {
            if !f["managed"].is_null() {
                continue;
            }

            let name = s(&f["name"]);
            let ty = self.field_ty(e, f)?;
            let label = literal(&format!("{entity}.{name}"));
            let enum_name = if ty.kind == Kind::Enum && f["type"]["primitive"] == "enum" {
                Some(self.field_enum_name(e, f)?)
            } else {
                None
            };
            let conversion = self.decode(&f["type"], &ty, "value", &label, enum_name.as_deref());
            let method = members.claim(
                &format!("Decode{}", pascal(name)),
                &format!("field {entity}.{name}"),
            );

            code.blank();
            code.open(format!("private {}? {method}(object? value)", ty.name));
            code.open("if (value is null)");
            code.line("return null;");
            code.close();
            code.blank();
            code.line(format!("return {conversion};"));
            code.close();
        }

        for edge in vals(&e["edges"]) {
            let name = s(&edge["name"]);
            let label = literal(&format!("{entity}.{name}"));
            let method = members.claim(
                &format!("DecodeEdge{}", pascal(name)),
                &format!("edge {entity}.{name}"),
            );

            code.blank();
            code.open(format!(
                "private static {READ_ONLY_LIST}<{IDENTIFIER}> {method}(object? value)"
            ));
            code.open("if (value is null)");
            code.line("return [];");
            code.close();
            code.blank();

            if edge["cardinality"] == "one" {
                code.line(format!("return [{VALUE_DECODER}.Id(value, {label})];"));
            } else {
                code.open("if (value is not global::System.Collections.IEnumerable list || value is string)");
                code.line(format!(
                    "throw new {ARGUMENT_EXCEPTION}({}, nameof(value));",
                    literal(&format!("{entity}.{name} takes a list of ids."))
                ));
                code.close();
                code.blank();
                code.line(format!(
                    "var ids = new global::System.Collections.Generic.List<{IDENTIFIER}>();"
                ));
                code.blank();
                code.open("foreach (var id in list)");
                code.line(format!("ids.Add({VALUE_DECODER}.Id(id, {label}));"));
                code.close();
                code.blank();
                code.line("return ids;");
            }

            code.close();
        }

        self.argument_decoders(e, &mut file.code, &mut members, true, &dictionary)?;
        self.argument_decoders(e, &mut file.code, &mut members, false, &dictionary)?;

        file.code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// `DecodeAction` and `DecodeQuery`: the arguments of each, decoded into their declared types and boxed.
    fn argument_decoders(
        &self,
        e: &serde_json::Value,
        code: &mut crate::cs::Code,
        members: &mut Members<'_>,
        actions: bool,
        dictionary: &str,
    ) -> Result<()> {
        let entity = s(&e["name"]);
        let (kind, method, source) = if actions {
            ("action", "DecodeAction", "actions")
        } else {
            ("query", "DecodeQuery", "queries")
        };
        let items = vals(&e[source]);

        code.blank();
        code.doc(&format!(
            "Decodes the raw arguments of {kind} `{kind}` into their declared types."
        ));
        code.open(format!(
            "public {dictionary} {method}(string {kind}, {dictionary} args)"
        ));
        code.open(format!("return {kind} switch"));

        for item in &items {
            let name = s(&item["name"]);
            let arguments = vals(&item["arguments"]);
            let entries = arguments
                .iter()
                .map(|a| {
                    let argument = s(&a["name"]);

                    format!(
                        "[{}] = {}{}(args.GetValueOrDefault({}))",
                        literal(argument),
                        "",
                        format_args!("Decode{}{}{}", pascal(kind), pascal(name), pascal(argument)),
                        literal(argument)
                    )
                })
                .collect::<Vec<_>>()
                .join(", ");

            code.line(format!(
                "{} => new {DICTIONARY}<string, object?> {{ {entries} }},",
                literal(name)
            ));
        }

        code.line(format!(
            "_ => throw new {ARGUMENT_EXCEPTION}($\"{entity} has no {kind} named '{{{kind}}}'.\", nameof({kind})),"
        ));
        code.close_with(";");
        code.close();

        for item in &items {
            let name = s(&item["name"]);

            for a in vals(&item["arguments"]) {
                let argument = s(&a["name"]);
                let reference = self.reference(&a["type"])?;
                let label = literal(&format!("{entity}.{name}.{argument}"));
                let conversion = self.decode(&a["type"], &reference, "value", &label, None);
                let decoder = members.claim(
                    &format!("Decode{}{}{}", pascal(kind), pascal(name), pascal(argument)),
                    &format!("{kind} argument {entity}.{name}.{argument}"),
                );

                code.blank();
                code.open(format!("private object? {decoder}(object? value)"));

                if b(&a["nullable"]) {
                    code.open("if (value is null)");
                    code.line("return null;");
                    code.close();
                    code.blank();
                }

                code.line(format!("return {conversion};"));
                code.close();
            }
        }

        Ok(())
    }
}
