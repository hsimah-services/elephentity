//! The files that tie the generated classes together: the catalogue, the processor registry, the DI wiring and the class map.

use crate::cs::File;
use crate::gen::*;
use crate::names::{literal, pascal};
use crate::runtime::*;
use std::collections::BTreeMap;

const UNKNOWN: &str = "throw Unknown(entity)";

fn names(values: Vec<String>) -> String {
    format!(
        "[{}]",
        values
            .iter()
            .map(|v| literal(v))
            .collect::<Vec<_>>()
            .join(", ")
    )
}

impl Gen<'_> {
    pub fn catalogue(&self, contracts: &[String]) -> Result<File> {
        let class = "Catalogue";
        let mut file = self.file("Catalogue.cs".into(), &self.root, class, "the catalogue");
        let entities = self.entities();
        let mut sorted: Vec<String> = entities.iter().map(|e| s(&e["name"]).to_string()).collect();
        sorted.sort();
        let code = &mut file.code;

        code.doc("What the runtime knows about this project's entities.");
        code.open(format!("public sealed class {class} : {ENTITY_CATALOGUE}"));
        code.line(format!("private readonly {SERVICE_PROVIDER} services;"));
        code.blank();
        code.line(format!(
            "public {class}({SERVICE_PROVIDER} services) => this.services = services;"
        ));
        code.blank();
        code.line(format!(
            "private static readonly string[] EntityNames = {};",
            names(sorted)
        ));
        code.blank();
        code.line(format!(
            "public {READ_ONLY_LIST}<string> Entities() => EntityNames;"
        ));

        for (method, ret, suffix, doc) in [
            ("Hydrator", format!("{HYDRATOR}<object>"), "Hydrator", ""),
            ("Verifiers", ENTITY_VERIFIERS.to_string(), "Verifiers", ""),
            (
                "SideEffects",
                ENTITY_SIDE_EFFECTS.to_string(),
                "SideEffects",
                "",
            ),
        ] {
            let _ = doc;

            code.blank();
            code.open(format!(
                "public {ret} {method}(string entity) => entity switch"
            ));

            for e in &entities {
                code.line(format!(
                    "{} => Service<{}>(),",
                    literal(s(&e["name"])),
                    self.class(e, suffix)
                ));
            }

            code.line(format!("_ => {UNKNOWN},"));
            code.close_with(";");
        }

        for write in [false, true] {
            let (method, ret, suffix, key) = if write {
                (
                    "WritePolicies",
                    ENTITY_WRITE_POLICIES,
                    "WritePolicies",
                    "writePolicies",
                )
            } else {
                (
                    "ReadPolicies",
                    ENTITY_READ_POLICIES,
                    "ReadPolicies",
                    "readPolicies",
                )
            };

            code.blank();
            code.open(format!(
                "public {ret} {method}(string entity) => entity switch"
            ));

            for e in &entities {
                if vals(&e[key]).is_empty() {
                    code.line(format!(
                        "{} => new {NO_POLICIES}(),",
                        literal(s(&e["name"]))
                    ));
                } else {
                    code.line(format!(
                        "{} => Service<{}>(),",
                        literal(s(&e["name"])),
                        self.class(e, suffix)
                    ));
                }
            }

            code.line(format!("_ => {UNKNOWN},"));
            code.close_with(";");
        }

        code.blank();
        code.open(format!("public {READ_ONLY_LIST}<{DELETION_RULE}> DeletionRules(string entity) => entity switch"));

        for e in &entities {
            code.line(format!(
                "{} => {}.Rules(),",
                literal(s(&e["name"])),
                self.class(e, "Deleter")
            ));
        }

        code.line("_ => [],");
        code.close_with(";");

        for (method, shape, edges) in [
            ("EdgeTargets", "\"Entity.edge\" to target entity", true),
            (
                "FieldTypes",
                "\"Entity.field\" to declared type, for types with a processor and enums",
                false,
            ),
        ] {
            let mut map: BTreeMap<String, String> = BTreeMap::new();

            for e in &entities {
                if edges {
                    for edge in vals(&e["edges"]) {
                        map.insert(
                            format!("{}.{}", s(&e["name"]), s(&edge["name"])),
                            s(&edge["to"]).to_string(),
                        );
                    }
                } else {
                    for f in vals(&e["fields"]) {
                        let declared = s(&f["type"]["declaredType"]);

                        if f["type"]["primitive"] == "enum" {
                            map.insert(
                                format!("{}.{}", s(&e["name"]), s(&f["name"])),
                                self.field_enum_name(e, f)?,
                            );
                        } else if !declared.is_empty()
                            && (self.is_enum_type(declared)
                                || b(&self.declared(declared)["hasProcessors"]))
                        {
                            map.insert(
                                format!("{}.{}", s(&e["name"]), s(&f["name"])),
                                pascal(declared),
                            );
                        }
                    }
                }
            }

            code.blank();
            code.doc(shape);
            code.line(format!(
                "public {READ_ONLY_DICTIONARY}<string, string> {method}() =>"
            ));
            code.line(format!("    new {DICTIONARY}<string, string>"));
            code.line("    {");

            for (key, value) in &map {
                code.line(format!("        [{}] = {},", literal(key), literal(value)));
            }

            code.line("    };");
        }

        let per_entity: [(&str, &str); 5] = [
            ("FieldNames", "all"),
            ("RequiredFields", "required"),
            ("NonNullableFields", "nonNullable"),
            ("RequiredEdges", "requiredEdges"),
            ("UniqueFields", "unique"),
        ];

        for (method, kind) in per_entity {
            code.blank();
            code.open(format!(
                "public {READ_ONLY_LIST}<string> {method}(string entity) => entity switch"
            ));

            for e in &entities {
                let selected: Vec<String> = if kind == "requiredEdges" {
                    vals(&e["edges"])
                        .into_iter()
                        .filter(|edge| b(&edge["required"]))
                        .map(|edge| s(&edge["name"]).to_string())
                        .collect()
                } else {
                    vals(&e["fields"])
                        .into_iter()
                        .filter(|f| match kind {
                            "required" => {
                                b(&f["required"]) && !b(&f["hasDefault"]) && f["managed"].is_null()
                            }
                            "nonNullable" => !b(&f["nullable"]),
                            "unique" => b(&f["unique"]),
                            _ => true,
                        })
                        .map(|f| s(&f["name"]).to_string())
                        .collect()
                };

                code.line(format!(
                    "{} => {},",
                    literal(s(&e["name"])),
                    names(selected)
                ));
            }

            code.line("_ => [],");
            code.close_with(";");
        }

        code.blank();
        code.doc("\"Entity.field\" to when the framework fills it.");
        code.line(format!(
            "public {READ_ONLY_DICTIONARY}<string, {MANAGED}> ManagedFields() =>"
        ));
        code.line(format!("    new {DICTIONARY}<string, {MANAGED}>"));
        code.line("    {");

        for e in &entities {
            for f in vals(&e["fields"]) {
                if let Some(policy) = f["managed"].as_str() {
                    code.line(format!(
                        "        [{}] = {MANAGED}.{},",
                        literal(&format!("{}.{}", s(&e["name"]), s(&f["name"]))),
                        pascal(policy)
                    ));
                }
            }
        }

        code.line("    };");

        for (method, kind, key) in [
            ("QueryArguments", "query", "queries"),
            ("ActionArguments", "action", "actions"),
        ] {
            code.blank();
            code.open(format!("public {READ_ONLY_LIST}<string> {method}(string entity, string {kind}) => $\"{{entity}}.{{{kind}}}\" switch"));

            for e in &entities {
                for item in vals(&e[key]) {
                    let arguments: Vec<String> = vals(&item["arguments"])
                        .into_iter()
                        .map(|a| s(&a["name"]).to_string())
                        .collect();

                    code.line(format!(
                        "{} => {},",
                        literal(&format!("{}.{}", s(&e["name"]), s(&item["name"]))),
                        names(arguments)
                    ));
                }
            }

            code.line("_ => [],");
            code.close_with(";");
        }

        code.blank();
        code.open(format!(
            "public {READ_ONLY_DICTIONARY}<string, object?> DecodeActionArguments(string entity, string action, {READ_ONLY_DICTIONARY}<string, object?> args) => entity switch"
        ));

        for e in &entities {
            code.line(format!(
                "{} => Service<{}>().DecodeAction(action, args),",
                literal(s(&e["name"])),
                self.class(e, "Input")
            ));
        }

        code.line(format!("_ => {UNKNOWN},"));
        code.close_with(";");

        code.blank();
        code.open(format!("public void Apply(string entity, {MUTATION_BUFFER} buffer, {READ_ONLY_DICTIONARY}<string, object?> input)"));
        code.open("switch (entity)");

        for e in &entities {
            code.line(format!("case {}:", literal(s(&e["name"]))));
            code.line(format!(
                "    Service<{}>().Apply(buffer, input);",
                self.class(e, "Input")
            ));
            code.line("    break;");
        }

        code.line("default:");
        code.line(format!("    {UNKNOWN};"));
        code.close();
        code.close();

        // queries
        code.blank();
        code.open(format!(
            "public {VALUE_TASK}<object?> RunQueryAsync(string entity, string query, {READ_ONLY_DICTIONARY}<string, object?> args, {CANCELLATION_TOKEN} cancellationToken = default)"
        ));
        code.open("switch ($\"{entity}.{query}\")");

        for e in &entities {
            for q in vals(&e["queries"]) {
                if self.entity(s(&q["returns"]["type"])).is_null() {
                    continue;
                }

                let name = s(&q["name"]);
                let mut arguments = Vec::new();

                for a in vals(&q["arguments"]) {
                    let ty = self.reference(&a["type"])?;

                    arguments.push(self.unbox(
                        &ty,
                        &format!("decoded[{}]", literal(s(&a["name"]))),
                        b(&a["nullable"]),
                    ));
                }

                code.line(format!(
                    "case {}:",
                    literal(&format!("{}.{}", s(&e["name"]), name))
                ));
                code.block();
                code.line(format!(
                    "var decoded = Service<{}>().DecodeQuery(query, args);",
                    self.class(e, "Input")
                ));
                code.line(format!(
                    "var finder = Service<{}>();",
                    self.class(e, "Finder")
                ));

                if q["returns"]["cardinality"] == "one" {
                    arguments.push("cancellationToken".into());
                    code.line(format!(
                        "return Box(finder.{}Async({}));",
                        pascal(name),
                        arguments.join(", ")
                    ));
                } else {
                    code.line(format!(
                        "return new {VALUE_TASK}<object?>({ENTITY_QUERIES}.AsObjects(finder.{}({})));",
                        pascal(name),
                        arguments.join(", ")
                    ));
                }

                code.close();
            }
        }

        code.line("default:");
        code.line(format!("    throw new {ARGUMENT_EXCEPTION}($\"{{entity}} declares no query named '{{query}}'.\", nameof(query));"));
        code.close();
        code.close();

        // actions
        code.blank();
        code.open(format!(
            "public {VALUE_TASK} RunActionAsync(string entity, {MUTABLE_MUTATION_CONTEXT} mutation, {ACTION_CALL} action, {CANCELLATION_TOKEN} cancellationToken = default)"
        ));
        code.line("var arguments = action.Arguments;");
        code.blank();
        code.open("switch ($\"{entity}.{action.Name}\")");

        for e in &entities {
            for a in vals(&e["actions"]) {
                let name = s(&a["name"]);
                let handlers: Vec<String> = vals(&e["actions"])
                    .iter()
                    .map(|other| {
                        format!(
                            "Service<{}>()",
                            self.fq_contract(e, &format!("{}Action", pascal(s(&other["name"]))))
                        )
                    })
                    .collect();
                let mut call = Vec::new();

                for argument in vals(&a["arguments"]) {
                    let ty = self.reference(&argument["type"])?;

                    call.push(self.unbox(
                        &ty,
                        &format!("arguments[{}]", literal(s(&argument["name"]))),
                        b(&argument["nullable"]),
                    ));
                }

                call.push("cancellationToken".into());
                code.line(format!(
                    "case {}:",
                    literal(&format!("{}.{}", s(&e["name"]), name))
                ));
                code.line(format!(
                    "    return new {}(mutation{}).{}Async({});",
                    self.class(e, "Mutator"),
                    handlers
                        .iter()
                        .map(|h| format!(", {h}"))
                        .collect::<String>(),
                    pascal(name),
                    call.join(", ")
                ));
            }
        }

        code.line("default:");
        code.line(format!("    throw new {ARGUMENT_EXCEPTION}($\"{{entity}} declares no action named '{{action.Name}}'.\", nameof(action));"));
        code.close();
        code.close();

        // values
        code.blank();
        code.open(format!("public {READ_ONLY_DICTIONARY}<string, object?> ValuesOf(string entity, object instance)"));
        code.open("switch (entity)");

        for e in &entities {
            code.line(format!("case {}:", literal(s(&e["name"]))));
            code.block();
            code.line(format!("var typed = ({})instance;", self.type_name(e)));
            code.line(format!("return new {DICTIONARY}<string, object?>"));
            code.line("{");

            for f in vals(&e["fields"]) {
                code.line(format!(
                    "    [{}] = typed.{},",
                    literal(s(&f["name"])),
                    pascal(s(&f["name"]))
                ));
            }

            code.line("};");
            code.close();
        }

        code.line("default:");
        code.line(format!("    {UNKNOWN};"));
        code.close();
        code.close();

        code.blank();
        code.doc("Everything the application must implement before it can start.");
        code.line(format!(
            "public {READ_ONLY_LIST}<global::System.Type> Contracts() =>"
        ));
        code.line("[");

        for contract in contracts {
            code.line(format!("    typeof({contract}),"));
        }

        code.line("];");

        code.blank();
        code.line(format!("private T Service<T>() where T : notnull => {SERVICE_PROVIDER_EXTENSIONS}.GetRequiredService<T>(services);"));
        code.blank();
        code.line(format!("private static {ARGUMENT_EXCEPTION} Unknown(string entity) => new($\"No entity named '{{entity}}'.\", nameof(entity));"));
        code.blank();
        code.line(format!("private static async {VALUE_TASK}<object?> Box<T>({VALUE_TASK}<T> task) => await task.ConfigureAwait(false);"));

        code.close();

        Ok(file)
    }

    /// The registry the unit of work encodes values with: enums, which are ours, and declared types, which the application supplies.
    pub fn processors(&self) -> Result<File> {
        let class = "Processors";
        let mut file = self.file(
            "Processors.cs".into(),
            &self.root,
            class,
            "the processor registry",
        );
        let mut enums: Vec<String> = Vec::new();
        let declared = self.processor_types();

        for t in vals(&self.schema["types"]) {
            if !t["values"].is_null() {
                enums.push(pascal(s(&t["name"])));
            }
        }

        for e in self.entities() {
            for f in vals(&e["fields"]) {
                if f["type"]["primitive"] == "enum" && !f["enum"]["inlineValues"].is_null() {
                    let name = self.field_enum_name(e, f)?;

                    if !enums.contains(&name) {
                        enums.push(name);
                    }
                }
            }
        }

        let code = &mut file.code;

        code.doc("Where the unit of work finds how a declared type is stored: generated for enums, the application's for the rest.");
        code.open(format!(
            "public sealed class {class} : {PROCESSOR_REGISTRY}"
        ));
        code.line(format!("private readonly {SERVICE_PROVIDER} services;"));
        code.blank();
        code.line(format!(
            "public {class}({SERVICE_PROVIDER} services) => this.services = services;"
        ));
        code.blank();

        let all: Vec<String> = enums
            .iter()
            .cloned()
            .chain(declared.iter().map(|t| pascal(s(&t["name"]))))
            .collect();

        if all.is_empty() {
            code.line("public bool Has(string type) => false;");
        } else {
            code.line(format!(
                "public bool Has(string type) => type is {};",
                all.iter()
                    .map(|t| literal(t))
                    .collect::<Vec<_>>()
                    .join(" or ")
            ));
        }

        code.blank();
        code.open(format!(
            "public {READ_PROCESSOR} Read(string type) => type switch"
        ));

        for t in &declared {
            code.line(format!(
                "{} => {SERVICE_PROVIDER_EXTENSIONS}.GetRequiredService<{}>(services),",
                literal(&pascal(s(&t["name"]))),
                self.read_processor(s(&t["name"]))
            ));
        }

        code.line(format!(
            "_ => throw new {INVALID_OPERATION}($\"{{type}} declares no read processor.\"),"
        ));
        code.close_with(";");
        code.blank();
        code.open(format!(
            "public {WRITE_PROCESSOR} Write(string type) => type switch"
        ));

        for name in &enums {
            code.line(format!(
                "{} => global::{}.{name}Wire.Processor,",
                literal(name),
                self.enum_ns()
            ));
        }

        for t in &declared {
            code.line(format!(
                "{} => {SERVICE_PROVIDER_EXTENSIONS}.GetRequiredService<{}>(services),",
                literal(&pascal(s(&t["name"]))),
                self.write_processor(s(&t["name"]))
            ));
        }

        code.line(format!(
            "_ => throw new {INVALID_OPERATION}($\"{{type}} declares no write processor.\"),"
        ));
        code.close_with(";");
        code.close();

        Ok(file)
    }

    pub fn wiring(&self) -> File {
        let name = format!("Add{}Entities", self.project);
        let mut file = self.file("Wiring.cs".into(), &self.root, "Wiring", "the DI wiring");
        file.usings = vec![
            "Microsoft.Extensions.DependencyInjection".into(),
            "Microsoft.Extensions.DependencyInjection.Extensions".into(),
        ];
        let entities = self.entities();
        let code = &mut file.code;

        code.doc("Everything this project's container must be able to build. The hand-written contracts are not registered here: the application registers them, and the catalogue's contracts say which.");
        code.open("public static class Wiring");
        code.doc(&format!("Registers the {} entities: the catalogue, every generated class and the gateway. Anything already registered is kept, so an application can replace a piece (the viewer provider, say).", self.project));
        code.open(format!(
            "public static IServiceCollection {name}(this IServiceCollection services)"
        ));
        code.line(format!(
            "services.TryAddSingleton<{ENTITY_CATALOGUE}, Catalogue>();"
        ));
        code.line(format!(
            "services.TryAddSingleton<{PROCESSOR_REGISTRY}, Processors>();"
        ));
        code.line(format!(
            "services.TryAddSingleton<{VIEWER_PROVIDER}, {ANONYMOUS_VIEWER_PROVIDER}>();"
        ));

        for e in &entities {
            for suffix in [
                "Hydrator",
                "Input",
                "SideEffects",
                "Verifiers",
                "ReadPolicies",
                "WritePolicies",
            ] {
                code.line(format!(
                    "services.TryAddSingleton<{}>();",
                    self.class(e, suffix)
                ));
            }

            if !vals(&e["queries"]).is_empty() {
                code.line(format!(
                    "services.TryAddSingleton<{}>();",
                    self.class(e, "Finder")
                ));
            }
        }

        code.blank();
        code.line(format!("services.TryAddSingleton<{READ_GATE}>();"));
        code.line(format!("services.TryAddSingleton<{WRITE_GATE}>();"));
        code.line(format!(
            "services.TryAddSingleton<{UNIT_OF_WORK_FACTORY}>();"
        ));
        code.line(format!("services.TryAddSingleton<{RUNTIME}>();"));
        code.line(format!("services.TryAddSingleton<{ENTITY_GATEWAY}>(provider => provider.GetRequiredService<{RUNTIME}>());"));
        code.line(format!("services.TryAddSingleton<{HYDRATOR_REGISTRY}>(provider => provider.GetRequiredService<{RUNTIME}>());"));
        code.blank();
        code.line("return services;");
        code.close();
        code.close();

        file
    }

    /// `ClassMap`: entity to its class, and every generated class to its file, for tooling.
    pub fn class_map(&self, files: &[(String, String)]) -> File {
        let mut file = self.file(
            "ClassMap.cs".into(),
            &self.root,
            "ClassMap",
            "the class map",
        );
        let entities: BTreeMap<String, String> = self
            .entities()
            .iter()
            .map(|e| {
                (
                    s(&e["name"]).to_string(),
                    format!("{}.{}", self.root, self.type_name(e)),
                )
            })
            .collect();
        let classes: BTreeMap<String, String> = files
            .iter()
            .map(|(namespace, path)| {
                let stem = path
                    .rsplit('/')
                    .next()
                    .unwrap_or(path)
                    .trim_end_matches(".cs");

                (format!("{namespace}.{stem}"), path.clone())
            })
            .collect();
        let code = &mut file.code;

        code.doc("Which class represents each entity, and which file each generated class is in. The generated tree's own index, for tooling that must not import the generator.");
        code.open("public static class ClassMap");
        code.line(format!("public static {READ_ONLY_DICTIONARY}<string, string> Entities {{ get; }} = new {DICTIONARY}<string, string>"));
        code.line("{");

        for (key, value) in &entities {
            code.line(format!("    [{}] = {},", literal(key), literal(value)));
        }

        code.line("};");
        code.blank();
        code.line(format!("public static {READ_ONLY_DICTIONARY}<string, string> Classes {{ get; }} = new {DICTIONARY}<string, string>"));
        code.line("{");

        for (key, value) in &classes {
            code.line(format!("    [{}] = {},", literal(key), literal(value)));
        }

        code.line("};");
        code.close();

        file
    }
}
