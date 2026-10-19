//! The write side of an entity: the mutator, the contexts handlers receive, and the finder.

use crate::cs::{Code, File};
use crate::gen::*;
use crate::names::{camel, literal, pascal, Members};
use crate::runtime::*;

impl Gen<'_> {
    fn setter(
        &self,
        code: &mut Code,
        members: &mut Members<'_>,
        class: &str,
        e: &serde_json::Value,
        f: &serde_json::Value,
    ) -> Result<()> {
        let name = s(&f["name"]);
        let ty = self.field_ty(e, f)?;
        let method = members.claim(
            &format!("Set{}", pascal(name)),
            &format!("field {}.{name}", s(&e["name"])),
        );
        let parameter = camel(name);

        code.line(format!(
            "public {class} {method}({} {parameter}) {{ buffer.Assign({}, {parameter}); return this; }}",
            ty.with(b(&f["nullable"])),
            literal(name)
        ));

        Ok(())
    }

    /// The typed arguments of an action or query as parameter declarations.
    pub fn parameters(&self, arguments: &serde_json::Value) -> Result<String> {
        let mut parts = Vec::new();

        for a in vals(arguments) {
            let ty = self.reference(&a["type"])?;

            parts.push(format!(
                "{} {}",
                ty.with(b(&a["nullable"])),
                camel(s(&a["name"]))
            ));
        }

        Ok(parts.join(", "))
    }

    pub fn argument_names(&self, arguments: &serde_json::Value) -> String {
        vals(arguments)
            .iter()
            .map(|a| camel(s(&a["name"])))
            .collect::<Vec<_>>()
            .join(", ")
    }

    /// Pending changes, as the entity's own typed setters.
    pub fn mutator(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let class = self.class(e, "Mutator");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the mutator of {entity}"),
        );
        let mut members = Members::new(&class);
        let actions = vals(&e["actions"]);
        let code = &mut file.code;

        code.doc(&format!("Pending changes to a {entity}."));
        code.open(format!("public sealed class {class}"));
        code.line(format!("private readonly {MUTATION_BUFFER} buffer;"));

        for a in &actions {
            code.line(format!(
                "private readonly {} {}Action;",
                self.fq_contract(e, &format!("{}Action", pascal(s(&a["name"])))),
                camel(s(&a["name"]))
            ));
        }

        let mut parameters = vec![format!("{MUTATION_BUFFER} buffer")];

        for a in &actions {
            parameters.push(format!(
                "{} {}Action",
                self.fq_contract(e, &format!("{}Action", pascal(s(&a["name"])))),
                camel(s(&a["name"]))
            ));
        }

        code.blank();
        code.open(format!("public {class}({})", parameters.join(", ")));
        code.line("this.buffer = buffer;");

        for a in &actions {
            let name = camel(s(&a["name"]));

            code.line(format!("this.{name}Action = {name}Action;"));
        }

        code.close();

        for p in list(&e["appliedPatterns"]) {
            let _ = p;
        }

        // Pattern setters are written out here rather than mixed in: C# has no traits, and a copy per entity keeps each mutator self-contained.
        for f in vals(&e["fields"]) {
            if !b(&f["immutable"]) && f["managed"].is_null() {
                code.blank();
                self.setter(code, &mut members, &class, e, f)?;
            }
        }

        for edge in vals(&e["edges"]) {
            let name = s(&edge["name"]);
            let target = s(&edge["to"]);

            code.blank();

            if edge["cardinality"] == "one" {
                let method = members.claim(
                    &format!("Set{}", pascal(name)),
                    &format!("edge {entity}.{name}"),
                );
                let parameter = camel(name);

                code.doc(&format!("Point this at one {target}, or at nothing."));
                code.open(format!(
                    "public {class} {method}({IDENTIFIER}? {parameter})"
                ));
                code.line(format!(
                    "buffer.Edge({}).Replace({parameter} is null ? [] : [{parameter}]);",
                    literal(name)
                ));
                code.line("return this;");
                code.close();
            } else {
                let method = members.claim(&pascal(name), &format!("edge {entity}.{name}"));

                code.doc(&format!(
                    "Add, remove or replace the {target} this links to."
                ));
                code.line(format!(
                    "public {EDGE_MUTATION} {method}() => buffer.Edge({});",
                    literal(name)
                ));
            }
        }

        for a in &actions {
            let name = s(&a["name"]);
            let method = members.claim(
                &format!("{}Async", pascal(name)),
                &format!("action {entity}.{name}"),
            );
            let context = self.class(e, &format!("{}Context", pascal(name)));
            let parameters = self.parameters(&a["arguments"])?;
            let arguments = self.argument_names(&a["arguments"]);

            code.blank();

            if let Some(d) = a["description"].as_str() {
                code.doc(d);
            }

            code.line(format!(
                "public {VALUE_TASK} {method}({}{CANCELLATION_TOKEN} cancellationToken = default) =>",
                if parameters.is_empty() { String::new() } else { format!("{parameters}, ") }
            ));
            code.line(format!(
                "    {}Action.HandleAsync({context}.Of(buffer){}, cancellationToken);",
                camel(name),
                if arguments.is_empty() {
                    String::new()
                } else {
                    format!(", {arguments}")
                }
            ));
        }

        code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// Collection-level queries, which delegate to the application's query contracts.
    pub fn finder(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let class = self.class(e, "Finder");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the finder of {entity}"),
        );
        let mut members = Members::new(&class);
        let queries: Vec<&serde_json::Value> = vals(&e["queries"])
            .into_iter()
            .filter(|q| !self.entity(s(&q["returns"]["type"])).is_null())
            .collect();
        let code = &mut file.code;

        code.doc(&format!("Collection-level queries over {entity}."));
        code.open(format!("public sealed class {class}"));

        for q in &queries {
            code.line(format!(
                "private readonly {} {}Query;",
                self.fq_contract(e, &format!("{}Query", pascal(s(&q["name"])))),
                camel(s(&q["name"]))
            ));
        }

        code.blank();
        code.open(format!(
            "public {class}({})",
            queries
                .iter()
                .map(|q| format!(
                    "{} {}Query",
                    self.fq_contract(e, &format!("{}Query", pascal(s(&q["name"])))),
                    camel(s(&q["name"]))
                ))
                .collect::<Vec<_>>()
                .join(", ")
        ));

        for q in &queries {
            let name = camel(s(&q["name"]));

            code.line(format!("this.{name}Query = {name}Query;"));
        }

        code.close();

        for q in &queries {
            let name = s(&q["name"]);
            let target = self.type_name(self.entity(s(&q["returns"]["type"])));
            let parameters = self.parameters(&q["arguments"])?;
            let arguments = self.argument_names(&q["arguments"]);

            code.blank();

            if q["returns"]["cardinality"] == "one" {
                let method = members.claim(
                    &format!("{}Async", pascal(name)),
                    &format!("query {entity}.{name}"),
                );

                code.line(format!(
                    "public {VALUE_TASK}<{target}?> {method}({}{CANCELLATION_TOKEN} cancellationToken = default) =>",
                    if parameters.is_empty() { String::new() } else { format!("{parameters}, ") }
                ));
                code.line(format!(
                    "    {}Query.FindAsync({}cancellationToken);",
                    camel(name),
                    if arguments.is_empty() {
                        String::new()
                    } else {
                        format!("{arguments}, ")
                    }
                ));
            } else {
                let method = members.claim(&pascal(name), &format!("query {entity}.{name}"));

                code.line(format!(
                    "public {ENTITY_QUERY}<{target}> {method}({parameters}) => {}Query.Find({arguments});",
                    camel(name)
                ));
            }
        }

        code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// The three contexts of an entity, and each action's context and arguments.
    pub fn contexts(&self, e: &serde_json::Value) -> Result<Vec<File>> {
        let mut files = Vec::new();
        let entity = s(&e["name"]);

        for a in vals(&e["actions"]) {
            files.push(self.action_context(e, a)?);
            files.push(self.action_arguments(e, a)?);
        }

        files.push(self.mutation_context(e, entity, false, false)?);
        files.push(self.mutation_context(e, entity, true, false)?);
        files.push(self.mutation_context(e, entity, false, true)?);

        Ok(files)
    }

    fn action_context(&self, e: &serde_json::Value, a: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let name = s(&a["name"]);
        let class = self.class(e, &format!("{}Context", pascal(name)));
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the context of action {entity}.{name}"),
        );
        let mut members = Members::new(&class);
        let mutation_context = self.class(e, "MutationContext");
        let code = &mut file.code;

        code.doc(&format!(
            "Everything {entity}.{name}() is allowed to write, and nothing else."
        ));
        code.open(format!("public sealed class {class}"));
        code.line(format!("private readonly {MUTATION_BUFFER} buffer;"));
        code.blank();
        code.line(format!(
            "private {class}({MUTATION_BUFFER} buffer) => this.buffer = buffer;"
        ));
        code.blank();
        code.line(format!(
            "public static {class} Of({MUTATION_BUFFER} buffer) => new(buffer);"
        ));
        code.blank();
        members.claim("Of", "the factory");
        members.claim("Context", "the context accessor");
        code.doc("Read original and pending state without widening the action's writes.");
        code.open(format!("public {mutation_context} Context()"));
        code.open(format!("if (buffer is not {MUTATION_CONTEXT} context)"));
        code.line(format!(
            "throw new {INVALID_OPERATION}(\"Action buffer must expose mutation state.\");"
        ));
        code.close();
        code.blank();
        code.line(format!("return {mutation_context}.Of(context);"));
        code.close();

        for f in list(&a["writes"]["fields"]) {
            let field = &e["fields"][s(f)];

            if !field.is_null() {
                code.blank();
                self.setter(code, &mut members, &class, e, field)?;
            }
        }

        for edge in list(&a["writes"]["edges"]) {
            let method = members.claim(&pascal(s(edge)), &format!("edge {entity}.{}", s(edge)));

            code.blank();
            code.line(format!(
                "public {EDGE_MUTATION} {method}() => buffer.Edge({});",
                literal(s(edge))
            ));
        }

        code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// `Context`, `PreCommitContext` or `WriteContext`: a typed wrapper over the runtime's.
    fn mutation_context(
        &self,
        e: &serde_json::Value,
        entity: &str,
        write: bool,
        mutable: bool,
    ) -> Result<File> {
        let suffix = if write {
            "WriteContext"
        } else if mutable {
            "PreCommitContext"
        } else {
            "MutationContext"
        };
        let class = self.class(e, suffix);
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the {suffix} of {entity}"),
        );
        let mut members = Members::new(&class);
        let runtime = if write {
            WRITE_CONTEXT
        } else if mutable {
            MUTABLE_MUTATION_CONTEXT
        } else {
            MUTATION_CONTEXT
        };
        let code = &mut file.code;
        let pending_prefix = if write {
            "context.Mutation?."
        } else {
            "context."
        };

        code.doc(&if write {
            format!("A pending {entity} write, with exact types.")
        } else {
            format!("A pending {entity} mutation, with exact types.")
        });
        code.open(format!("public sealed class {class} : {runtime}"));
        code.line(format!("private readonly {runtime} context;"));
        code.blank();
        code.line(format!(
            "private {class}({runtime} context) => this.context = context;"
        ));
        code.blank();
        code.line(format!(
            "public static {class} Of({runtime} context) => new(context);"
        ));
        members.claim("Of", "the factory");

        if write {
            for (name, ty) in [
                ("Entity", "string"),
                ("Operation", WRITE_OPERATION),
                ("Actions", &format!("{READ_ONLY_LIST}<{ACTION_CALL}>")),
                ("Action", "string?"),
                (
                    "Arguments",
                    &format!("{READ_ONLY_DICTIONARY}<string, object?>"),
                ),
                ("Mutation", &format!("{MUTATION_CONTEXT}?")),
            ] {
                members.claim(name, &format!("write context {name}"));
                code.blank();
                code.line(format!("public {ty} {name} => context.{name};"));
            }
        } else {
            for (name, ty) in [
                ("Entity", "string"),
                ("Actions", &format!("{READ_ONLY_LIST}<{ACTION_CALL}>")),
                ("OriginalEntity", "object?"),
                ("Id", IDENTIFIER),
                ("IsCreate", "bool"),
            ] {
                members.claim(name, &format!("context {name}"));
                code.blank();
                code.line(format!("public {ty} {name} => context.{name};"));
            }

            // A mutable context is both a reader and a buffer, and both declare these two: say which is meant.
            let reader = if mutable {
                format!("(({MUTATION_CONTEXT})context)")
            } else {
                "context".to_string()
            };

            for (name, ty) in [
                ("Original", "object?"),
                ("Pending", "object?"),
                ("IsChanged", "bool"),
            ] {
                members.claim(name, &format!("context {name}"));
                code.blank();
                code.line(format!(
                    "public {ty} {name}(string field) => {reader}.{name}(field);"
                ));
            }

            members.claim("Changes", "context Changes");
            code.blank();
            code.line(format!(
                "public {READ_ONLY_DICTIONARY}<string, object?> Changes() => {reader}.Changes();"
            ));
            members.claim("PendingEdge", "context PendingEdge");
            code.blank();
            code.line(format!("public {READ_ONLY_LIST}<{IDENTIFIER}> PendingEdge(string edge) => context.PendingEdge(edge);"));
            members.claim("IsEdgeChanged", "context IsEdgeChanged");
            code.blank();
            code.line("public bool IsEdgeChanged(string edge) => context.IsEdgeChanged(edge);");
        }

        if mutable {
            members.claim("Target", "context Target");
            code.blank();
            code.line(format!("public {IDENTIFIER} Target => context.Target;"));
            members.claim("Assign", "context Assign");
            code.blank();
            code.line(
                "public void Assign(string field, object? value) => context.Assign(field, value);",
            );
            members.claim("Edge", "context Edge");
            code.blank();
            code.line(format!(
                "public {EDGE_MUTATION} Edge(string edge) => context.Edge(edge);"
            ));

            for f in vals(&e["fields"]) {
                let name = s(&f["name"]);
                let ty = self.field_ty(e, f)?;
                let method = members.claim(
                    &format!("Set{}", pascal(name)),
                    &format!("field {entity}.{name}"),
                );

                code.blank();
                code.line(format!(
                    "public void {method}({} value) => context.Assign({}, value);",
                    ty.with(b(&f["nullable"])),
                    literal(name)
                ));
            }

            for edge in vals(&e["edges"]) {
                let name = s(&edge["name"]);
                let method = members.claim(&pascal(name), &format!("edge {entity}.{name}"));

                code.blank();
                code.line(format!(
                    "public {EDGE_MUTATION} {method}() => context.Edge({});",
                    literal(name)
                ));
            }
        }

        for f in vals(&e["fields"]) {
            let name = s(&f["name"]);
            let ty = self.field_ty(e, f)?;

            for side in ["Original", "Pending"] {
                let method = members.claim(
                    &format!("{side}{}", pascal(name)),
                    &format!("field {entity}.{name}"),
                );

                code.blank();
                code.line(format!(
                    "public {}? {method}() => ({}?){pending_prefix}{side}({});",
                    ty.name,
                    ty.name,
                    literal(name)
                ));
            }
        }

        if write {
            for a in vals(&e["actions"]) {
                let name = s(&a["name"]);
                let arguments = self.class(e, &format!("{}Arguments", pascal(name)));
                let method = members.claim(&pascal(name), &format!("action {entity}.{name}"));

                code.blank();
                code.line(format!(
                    "public {arguments}? {method}() => context.Action == {} ? {arguments}.Of(context.Arguments) : null;",
                    literal(name)
                ));
            }
        } else {
            for edge in vals(&e["edges"]) {
                let name = s(&edge["name"]);
                let one = edge["cardinality"] == "one";
                let pending = members.claim(
                    &format!("Pending{}", pascal(name)),
                    &format!("edge {entity}.{name}"),
                );
                let changed = members.claim(
                    &format!("Is{}Changed", pascal(name)),
                    &format!("edge {entity}.{name}"),
                );

                code.blank();

                if one {
                    code.line(format!(
                        "public {IDENTIFIER}? {pending}() => context.PendingEdge({}) is [var only, ..] ? only : null;",
                        literal(name)
                    ));
                } else {
                    code.line(format!(
                        "public {READ_ONLY_LIST}<{IDENTIFIER}> {pending}() => context.PendingEdge({});",
                        literal(name)
                    ));
                }

                code.blank();
                code.line(format!(
                    "public bool {changed}() => context.IsEdgeChanged({});",
                    literal(name)
                ));
            }
        }

        code.close();
        self.fail_all(members.errors);

        Ok(file)
    }

    /// An action's arguments, as a typed record.
    pub fn action_arguments(&self, e: &serde_json::Value, a: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let name = s(&a["name"]);
        let class = self.class(e, &format!("{}Arguments", pascal(name)));
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the arguments of action {entity}.{name}"),
        );
        let mut parts = Vec::new();
        let mut values = Vec::new();

        for argument in vals(&a["arguments"]) {
            let ty = self.reference(&argument["type"])?;
            let nullable = b(&argument["nullable"]);
            let arg = s(&argument["name"]);

            parts.push(format!("{} {}", ty.with(nullable), pascal(arg)));
            values.push(self.unbox(&ty, &format!("arguments[{}]", literal(arg)), nullable));
        }

        let code = &mut file.code;

        code.doc(&format!("The arguments {entity}.{name}() was called with."));
        code.open(format!(
            "public sealed record {class}({})",
            parts.join(", ")
        ));
        code.doc("Reads the decoded arguments an action call carries.");
        code.line(format!(
            "public static {class} Of({READ_ONLY_DICTIONARY}<string, object?> arguments) => new({});",
            values.join(", ")
        ));
        code.close();

        Ok(file)
    }
}
