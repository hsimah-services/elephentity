use super::*;
use php_codegen::modifiers::Modifier;
use std::collections::BTreeMap;
const UNKNOWN: &str = "throw new RuntimeException(sprintf('No entity named \"%s\".', $entity))";
fn matching(subject: &str, arms: &[String], default: &str) -> String {
    format!(
        "match ({subject}) {{\n{}\n    default => {default},\n}}",
        arms.join("\n")
    )
}
fn exported(values: Vec<String>) -> String {
    format!(
        "[{}]",
        values
            .iter()
            .map(|s| quote(s))
            .collect::<Vec<_>>()
            .join(", ")
    )
}
fn arm(key: &str, value: &str) -> String {
    format!("    {} => {value},", quote(key))
}
impl Gen<'_> {
    pub fn catalogue(&self, files: &[Value]) -> Value {
        let mut out = Source::new(format!("{}\\Catalogue", self.root));
        out.readonly();
        out.implements(ENTITY_CATALOGUE);
        for t in [
            ENTITY_SIDE_EFFECTS,
            ENTITY_VERIFIERS,
            ENTITY_READ_POLICIES,
            ENTITY_WRITE_POLICIES,
            NO_POLICIES,
            HYDRATOR,
            MUTATION_BUFFER,
            DELETION_RULE,
            r"Psr\Container\ContainerInterface",
            "RuntimeException",
        ] {
            out.import(t);
        }
        out.comment("What the runtime knows about this project's entities.");
        out.add(method("__construct", "", "").parameter(promoted(
            "container",
            "ContainerInterface",
            false,
        )));
        let entities = vals(&self.schema["entities"]);
        let mut names = entities
            .iter()
            .map(|e| s(&e["name"]).to_owned())
            .collect::<Vec<_>>();
        names.sort();
        out.add(
            method("entities", "array", &format!("return {};", exported(names)))
                .document(doc("@return list<string>")),
        );
        for (n, suffix, ret) in [
            ("hydrator", "Hydrator", "Hydrator"),
            ("verifiers", "Verifiers", "EntityVerifiers"),
            ("sideEffects", "SideEffects", "EntitySideEffects"),
        ] {
            let mut arms = vec![];
            for e in &entities {
                let ty = out.import(&self.name(e, suffix));
                arms.push(arm(
                    s(&e["name"]),
                    &format!("$this->container->get({ty}::class)"),
                ));
            }
            let mut m = method(
                n,
                ret,
                &format!(
                    "$service = {};\n\nassert($service instanceof {ret});\n\nreturn $service;",
                    matching("$entity", &arms, UNKNOWN)
                ),
            )
            .parameter(param("entity", "string", false));
            if n == "hydrator" {
                m = m.document(doc("@return Hydrator<object>"));
            }
            out.add(m);
        }
        for write in [false, true] {
            let n = if write {
                "writePolicies"
            } else {
                "readPolicies"
            };
            let suffix = if write {
                "WritePolicies"
            } else {
                "ReadPolicies"
            };
            let ret = if write {
                "EntityWritePolicies"
            } else {
                "EntityReadPolicies"
            };
            let mut arms = vec![];
            for e in &entities {
                if vals(&e[n]).is_empty() {
                    arms.push(arm(s(&e["name"]), "new NoPolicies()"));
                } else {
                    let ty = out.import(&self.name(e, suffix));
                    let mn = format!("{n}{ty}");
                    out.add(method(&mn,ret,&format!("$policies = $this->container->get({ty}::class);\n\nassert($policies instanceof {ty});\n\nreturn $policies;")).private());
                    arms.push(arm(s(&e["name"]), &format!("$this->{mn}()")));
                }
            }
            out.add(
                method(
                    n,
                    ret,
                    &format!("return {};", matching("$entity", &arms, UNKNOWN)),
                )
                .parameter(param("entity", "string", false)),
            );
        }
        for (n, shape) in [
            ("edgeTargets", "\"Entity.edge\" => target entity"),
            ("fieldTypes", "\"Entity.field\" => declared type"),
        ] {
            let mut map = BTreeMap::new();
            for e in &entities {
                for f in vals(
                    &e[if n == "edgeTargets" {
                        "edges"
                    } else {
                        "fields"
                    }],
                ) {
                    let value = if n == "edgeTargets" {
                        s(&f["to"])
                    } else {
                        let t = s(&f["type"]["declaredType"]);
                        if !b(&self.schema["types"][t]["hasProcessors"]) {
                            continue;
                        }
                        t
                    };
                    map.insert(
                        format!("{}.{}", s(&e["name"]), s(&f["name"])),
                        value.to_owned(),
                    );
                }
            }
            let lines = map
                .iter()
                .map(|(k, v)| arm(k, &quote(v)))
                .collect::<Vec<_>>();
            out.add(
                method(
                    n,
                    "array",
                    &if lines.is_empty() {
                        "return [];".into()
                    } else {
                        format!("return [\n{}\n];", lines.join("\n"))
                    },
                )
                .document(doc(&format!("@return array<string, string> {shape}"))),
            );
        }
        for n in [
            "fieldNames",
            "requiredFields",
            "nonNullableFields",
            "requiredEdges",
            "uniqueFields",
        ] {
            let mut arms = vec![];
            for e in &entities {
                let fields = vals(
                    &e[if n == "requiredEdges" {
                        "edges"
                    } else {
                        "fields"
                    }],
                );
                let names = fields
                    .into_iter()
                    .filter(|f| match n {
                        "requiredFields" => {
                            b(&f["required"]) && !b(&f["hasDefault"]) && f["managed"].is_null()
                        }
                        "nonNullableFields" => !b(&f["nullable"]),
                        "requiredEdges" => b(&f["required"]),
                        "uniqueFields" => b(&f["unique"]),
                        _ => true,
                    })
                    .map(|f| s(&f["name"]).into())
                    .collect();
                arms.push(arm(s(&e["name"]), &exported(names)));
            }
            out.add(
                method(
                    n,
                    "array",
                    &format!("return {};", matching("$entity", &arms, "[]")),
                )
                .parameter(param("entity", "string", false))
                .document(doc("@return list<string>")),
            );
        }
        let mut managed = vec![];
        for e in &entities {
            for f in vals(&e["fields"]) {
                if let Some(policy) = f["managed"].as_str() {
                    managed.push(arm(
                        &format!("{}.{}", s(&e["name"]), s(&f["name"])),
                        &format!("Managed::{}", cap(policy)),
                    ));
                }
            }
        }
        managed.sort();
        if !managed.is_empty() {
            out.import(MANAGED);
        }
        out.add(
            method(
                "managedFields",
                "array",
                &if managed.is_empty() {
                    "return [];".into()
                } else {
                    format!("return [\n{}\n];", managed.join("\n"))
                },
            )
            .document(doc(
                "@return array<string, Managed> \"Entity.field\" => policy",
            )),
        );
        let mut arms = vec![];
        for e in &entities {
            let ty = out.import(&self.name(e, "Deleter"));
            arms.push(arm(s(&e["name"]), &format!("{ty}::rules()")));
        }
        out.add(
            method(
                "deletionRules",
                "array",
                &format!("return {};", matching("$entity", &arms, "[]")),
            )
            .parameter(param("entity", "string", false))
            .document(doc("@return list<DeletionRule>")),
        );
        let mut arms = vec![];
        for e in &entities {
            if !vals(&e["queries"]).is_empty() {
                let ty = out.import(&self.name(e, "Finder"));
                arms.push(arm(
                    s(&e["name"]),
                    &format!("$this->container->get({ty}::class)"),
                ));
            }
        }
        out.add(
            method(
                "finder",
                "object",
                &format!(
                    "$finder = {};\n\nassert(is_object($finder));\n\nreturn $finder;",
                    matching(
                        "$entity",
                        &arms,
                        "throw new RuntimeException(sprintf('%s declares no queries.', $entity))"
                    )
                ),
            )
            .parameter(param("entity", "string", false)),
        );
        let mut arms = vec![];
        let mut has_actions = false;
        for e in &entities {
            let ty = out.import(&self.name(e, "Mutator"));
            let mut args = vec!["$buffer".to_owned()];
            for a in vals(&e["actions"]) {
                has_actions = true;
                let handler =
                    out.import(&self.contract(e, &format!("{}Action", cap(s(&a["name"])))));
                args.push(format!("$this->resolve({handler}::class)"));
            }
            arms.push(arm(
                s(&e["name"]),
                &format!("new {ty}({})", args.join(", ")),
            ));
        }
        out.add(
            method(
                "mutatorFor",
                "object",
                &format!("return {};", matching("$entity", &arms, UNKNOWN)),
            )
            .parameter(param("entity", "string", false))
            .parameter(param("buffer", "MutationBuffer", false)),
        );
        for action in [false, true] {
            let n = if action { "action" } else { "query" };
            let mut map = BTreeMap::new();
            for e in &entities {
                for a in vals(&e[if action { "actions" } else { "queries" }]) {
                    let args = vals(&a["arguments"])
                        .iter()
                        .map(|a| s(&a["name"]).into())
                        .collect();
                    map.insert(
                        format!("{}.{}", s(&e["name"]), s(&a["name"])),
                        exported(args),
                    );
                }
            }
            let arms = map.iter().map(|(k, v)| arm(k, v)).collect::<Vec<_>>();
            out.add(
                method(
                    &format!("{n}Arguments"),
                    "array",
                    &format!(
                        "return {};",
                        matching(&format!("$entity . '.' . ${n}"), &arms, "[]")
                    ),
                )
                .parameter(param("entity", "string", false))
                .parameter(param(n, "string", false))
                .document(doc("@return list<string>")),
            );
        }
        let mut arms = vec![];
        for e in &entities {
            let ty = out.import(&self.name(e, "Input"));
            let n = format!("decode{ty}ActionArguments");
            out.add(method(&n,"array",&format!("$input = $this->container->get({ty}::class);\n\nassert($input instanceof {ty});\n\nreturn $input->decodeAction($action, $args);")).private().parameter(param("action","string",false)).parameter(param("args","array",false)).document(doc("@param array<string, mixed> $args\n@return array<string, mixed>")));
            arms.push(arm(s(&e["name"]), &format!("$this->{n}($action, $args)")));
        }
        out.add(
            method(
                "decodeActionArguments",
                "array",
                &format!("return {};", matching("$entity", &arms, UNKNOWN)),
            )
            .parameter(param("entity", "string", false))
            .parameter(param("action", "string", false))
            .parameter(param("args", "array", false))
            .document(doc(
                "@param array<string, mixed> $args\n@return array<string, mixed>",
            )),
        );
        let mut arms = vec![];
        for e in &entities {
            let ty = out.import(&self.name(e, "Input"));
            arms.push(arm(
                s(&e["name"]),
                &format!("$this->container->get({ty}::class)"),
            ));
        }
        out.add(method("apply","void",&format!("$applier = {};\n\nassert(is_object($applier) && method_exists($applier, 'apply'));\n\n$applier->apply($buffer, $input);",matching("$entity",&arms,UNKNOWN))).parameter(param("entity","string",false)).parameter(param("buffer","MutationBuffer",false)).parameter(param("input","array",false)).document(doc("@param array<string, mixed> $input")));
        let mut contracts = files
            .iter()
            .filter(|f| {
                let p = s(&f["path"]);
                p.contains("/Contract/") || p.starts_with("Type/")
            })
            .map(|f| {
                format!(
                    "{}\\{}",
                    self.root,
                    s(&f["path"]).trim_end_matches(".php").replace('/', "\\")
                )
            })
            .collect::<Vec<_>>();
        contracts.sort();
        contracts.dedup();
        out.add(method("contracts","array",&format!("return {};",exported(contracts))).document(doc("Everything the application must implement before it can start.\n\n@return list<string>")));
        if has_actions {
            out.add(method("resolve","object","$service = $this->container->get($class);\n\nassert($service instanceof $class);\n\nreturn $service;").private().parameter(param("class","string",false)).document(doc("@template T of object\n@param class-string<T> $class\n@return T")));
        }
        out.file(&self.root)
    }
    pub fn wiring(&self) -> Value {
        let mut out = Source::new(format!("{}\\Wiring", self.root));
        out.import(r"Psr\Container\ContainerInterface");
        out.import("Closure");
        out.import(VALUE_DECODER);
        out.comment("Every generated class this project's container must be able to build.");
        let mut entries = vec![];
        for e in vals(&self.schema["entities"]) {
            for suffix in [
                "Hydrator",
                "Input",
                "SideEffects",
                "Verifiers",
                "ReadPolicies",
                "WritePolicies",
                "Finder",
            ] {
                if suffix == "Finder" && vals(&e["queries"]).is_empty() {
                    continue;
                }
                let mut deps = vec![];
                match suffix {
                    "Hydrator" | "Input" => {
                        deps.push(VALUE_DECODER.to_owned());
                        for n in self.readers(e, suffix == "Input") {
                            deps.push(format!("{}\\Type\\{n}ReadProcessor", self.root));
                        }
                    }
                    "SideEffects" | "Finder" => {
                        for t in vals(
                            &e[if suffix == "SideEffects" {
                                "sideEffects"
                            } else {
                                "queries"
                            }],
                        ) {
                            deps.push(self.contract(
                                e,
                                &format!(
                                    "{}{}",
                                    cap(s(&t["name"])),
                                    if suffix == "SideEffects" {
                                        "SideEffect"
                                    } else {
                                        "Query"
                                    }
                                ),
                            ));
                        }
                    }
                    "Verifiers" => {
                        for f in vals(&e["fields"]) {
                            if b(&f["verify"]) {
                                deps.push(
                                    self.contract(e, &format!("{}Verifier", cap(s(&f["name"])))),
                                );
                            }
                        }
                    }
                    _ => {
                        let write = suffix == "WritePolicies";
                        for p in vals(
                            &e[if write {
                                "writePolicies"
                            } else {
                                "readPolicies"
                            }],
                        ) {
                            deps.push(self.policy_handler(e, p, write));
                        }
                    }
                }
                let args = deps
                    .iter()
                    .map(|d| format!("self::resolve($c, {}::class)", out.import(d)))
                    .collect::<Vec<_>>()
                    .join(", ");
                let ty = out.import(&self.name(e, suffix));
                entries.push(format!("    {ty}::class => static fn (ContainerInterface $c): object => new {ty}({args}),"));
            }
        }
        out.add(method("registrations","array",&format!("return [\n{}\n];",entries.join("\n"))).modifier(Modifier::Static).document(doc("The hand-written Contract bindings are not here: BootCheck enforces those\nseparately, against contracts() on the catalogue.\n\n@return array<class-string, Closure(ContainerInterface): object>")));
        if !vals(&self.schema["entities"]).is_empty() {
            out.add(method("resolve","object","$service = $c->get($class);\n\nassert($service instanceof $class);\n\nreturn $service;").private().modifier(Modifier::Static).parameter(param("c","ContainerInterface",false)).parameter(param("class","string",false)).document(doc("@template T of object\n@param class-string<T> $class\n@return T")));
        }
        out.file(&self.root)
    }
    pub fn class_map(&self, files: &[Value]) -> Value {
        let entities = vals(&self.schema["entities"])
            .iter()
            .map(|e| (s(&e["name"]).to_owned(), self.name(e, "")))
            .collect::<BTreeMap<_, _>>();
        let classes = files
            .iter()
            .map(|f| {
                (
                    format!(
                        "{}\\{}",
                        self.root,
                        s(&f["path"]).trim_end_matches(".php").replace('/', "\\")
                    ),
                    s(&f["path"]).to_owned(),
                )
            })
            .collect::<BTreeMap<_, _>>();
        let render = |m: BTreeMap<String, String>| {
            m.iter()
                .map(|(k, v)| format!("        {} => {},", quote(k), quote(v)))
                .collect::<Vec<_>>()
                .join("\n")
        };
        json!({"path":"class-map.php","body":format!("return [\n    'entities' => [\n{}\n    ],\n    'classes' => [\n{}\n    ],\n];",render(entities),render(classes))})
    }
}
