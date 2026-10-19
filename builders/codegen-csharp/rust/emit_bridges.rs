//! The classes that adapt an entity's application contracts to the runtime's interfaces: policies, verifiers, side
//! effects, and the deleter.

use crate::cs::File;
use crate::gen::*;
use crate::names::{camel, literal, pascal};
use crate::runtime::*;

impl Gen<'_> {
    pub fn deleter(&self, e: &serde_json::Value) -> File {
        let entity = s(&e["name"]);
        let class = self.class(e, "Deleter");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the deleter of {entity}"),
        );
        let mut rules = Vec::new();

        for other in self.entities() {
            for edge in vals(&other["edges"]) {
                let on = s(&other["name"]);
                let to = s(&edge["to"]);
                let local = edge["cardinality"] == "one";
                let join = !local && (edge["inverse"].is_null() || !b(&edge["inverse"]["unique"]));
                let (dependent, referenced) = if join {
                    if on == entity {
                        (to, on)
                    } else {
                        (on, to)
                    }
                } else if local {
                    (on, to)
                } else {
                    (to, on)
                };

                if referenced != entity {
                    continue;
                }

                rules.push(format!(
                    "new({}, {}, {}, {DELETION_POLICY}.{}, {}),",
                    literal(dependent),
                    literal(s(&edge["name"])),
                    literal(on),
                    pascal(edge["onDelete"].as_str().unwrap_or("restrict")),
                    join
                ));
            }
        }

        let code = &mut file.code;

        code.doc(&format!(
            "Removes a {entity}, and whatever its edges say goes with it."
        ));
        code.open(format!("public sealed class {class}"));
        code.line(format!("private readonly {UNIT_OF_WORK} work;"));
        code.blank();
        code.line(format!(
            "public {class}({UNIT_OF_WORK} work) => this.work = work;"
        ));
        code.blank();
        code.doc("Registers the removal. Nothing happens until the unit of work commits.");
        code.line(format!(
            "public void Delete({ENTITY_ID} id) => work.Delete(new {DELETION}({}, id));",
            literal(entity)
        ));
        code.blank();
        code.doc("What depends on this entity, for the catalogue.");

        if rules.is_empty() {
            code.line(format!(
                "public static {READ_ONLY_LIST}<{DELETION_RULE}> Rules() => [];"
            ));
        } else {
            code.line(format!(
                "public static {READ_ONLY_LIST}<{DELETION_RULE}> Rules() =>"
            ));
            code.line("[");

            for rule in &rules {
                code.line(format!("    {rule}"));
            }

            code.line("];");
        }

        code.close();

        file
    }

    pub fn policies(&self, e: &serde_json::Value, write: bool) -> File {
        let entity = s(&e["name"]);
        let side = if write { "Write" } else { "Read" };
        let class = self.class(e, &format!("{side}Policies"));
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the {} policies of {entity}", side.to_lowercase()),
        );
        let policies = vals(
            &e[if write {
                "writePolicies"
            } else {
                "readPolicies"
            }],
        );
        let entity_type = self.type_name(e);
        let write_context = self.class(e, "WriteContext");
        let interface = if write {
            ENTITY_WRITE_POLICIES
        } else {
            ENTITY_READ_POLICIES
        };
        let code = &mut file.code;

        code.doc(&format!(
            "Dispatches to {entity}'s {} policies, in the order the spec declares them.",
            side.to_lowercase()
        ));
        code.open(format!("public sealed class {class} : {interface}"));

        for p in &policies {
            code.line(format!(
                "private readonly {} {}Policy;",
                self.fq_policy(e, p, write),
                camel(s(&p["name"]))
            ));
        }

        if !policies.is_empty() {
            code.blank();
            code.open(format!(
                "public {class}({})",
                policies
                    .iter()
                    .map(|p| format!(
                        "{} {}Policy",
                        self.fq_policy(e, p, write),
                        camel(s(&p["name"]))
                    ))
                    .collect::<Vec<_>>()
                    .join(", ")
            ));

            for p in &policies {
                let name = camel(s(&p["name"]));

                code.line(format!("this.{name}Policy = {name}Policy;"));
            }

            code.close();
        }

        code.blank();
        code.line(format!("public bool IsEmpty() => {};", policies.is_empty()));
        code.blank();

        let signature = if write {
            format!("(object? entity, {WRITE_CONTEXT} context, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default)")
        } else {
            format!("(object entity, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default)")
        };

        if policies.is_empty() {
            code.line(format!(
                "public {VALUE_TASK}<{POLICY_DECISION}> DecideAsync{signature} => {VALUE_TASK}.FromResult({POLICY_DECISION}.Allow());"
            ));
        } else {
            code.open(format!(
                "public async {VALUE_TASK}<{POLICY_DECISION}> DecideAsync{signature}"
            ));
            code.line(format!(
                "var subject = ({entity_type}{}) entity;",
                if write { "?" } else { "" }
            ));
            code.blank();

            for p in &policies {
                let name = camel(s(&p["name"]));
                let context = if write {
                    if p["origin"]["pattern"].is_null() {
                        format!("{write_context}.Of(context), ")
                    } else {
                        "context, ".into()
                    }
                } else {
                    String::new()
                };

                code.line(format!(
                    "var {name}Decision = await {name}Policy.DecideAsync(subject, {context}viewer, cancellationToken).ConfigureAwait(false);"
                ));
                code.open(format!(
                    "if ({name}Decision.Outcome != {POLICY_OUTCOME}.Skip)"
                ));
                code.line(format!(
                    "return {name}Decision.WithPolicy({});",
                    literal(s(&p["name"]))
                ));
                code.close();
                code.blank();
            }

            let allow = e[if write {
                "terminalWrite"
            } else {
                "terminalRead"
            }] == "allow";

            if allow {
                code.line(format!(
                    "return {POLICY_DECISION}.Allow().WithPolicy(\"terminal\");"
                ));
            } else {
                code.line(format!(
                    "return {POLICY_DECISION}.Deny({}).WithPolicy(\"terminal\");",
                    literal(&format!(
                        "No policy allowed this {}.",
                        if write { "write" } else { "read" }
                    ))
                ));
            }

            code.close();
        }

        code.close();

        file
    }

    pub fn verifiers(&self, e: &serde_json::Value) -> Result<File> {
        let entity = s(&e["name"]);
        let class = self.class(e, "Verifiers");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the verifiers of {entity}"),
        );
        let verified: Vec<&serde_json::Value> = vals(&e["fields"])
            .into_iter()
            .filter(|f| b(&f["verify"]))
            .collect();
        let context = self.class(e, "MutationContext");
        let code = &mut file.code;

        code.doc(&format!("Dispatches to {entity}'s field verifiers."));
        code.open(format!("public sealed class {class} : {ENTITY_VERIFIERS}"));

        for f in &verified {
            code.line(format!(
                "private readonly {} {}Verifier;",
                self.fq_contract(e, &format!("{}Verifier", pascal(s(&f["name"])))),
                camel(s(&f["name"]))
            ));
        }

        if !verified.is_empty() {
            code.blank();
            code.open(format!(
                "public {class}({})",
                verified
                    .iter()
                    .map(|f| format!(
                        "{} {}Verifier",
                        self.fq_contract(e, &format!("{}Verifier", pascal(s(&f["name"])))),
                        camel(s(&f["name"]))
                    ))
                    .collect::<Vec<_>>()
                    .join(", ")
            ));

            for f in &verified {
                let name = camel(s(&f["name"]));

                code.line(format!("this.{name}Verifier = {name}Verifier;"));
            }

            code.close();
        }

        code.blank();
        code.line(format!(
            "public {READ_ONLY_LIST}<string> VerifiedFields() => [{}];",
            verified
                .iter()
                .map(|f| literal(s(&f["name"])))
                .collect::<Vec<_>>()
                .join(", ")
        ));
        code.blank();
        code.open(format!(
            "public {VALUE_TASK}<{VERIFICATION}> VerifyAsync(string field, object? value, {MUTATION_CONTEXT} context, {CANCELLATION_TOKEN} cancellationToken = default) => field switch"
        ));

        for f in &verified {
            code.line(format!(
                "{} => Verify{}Async(value, context, cancellationToken),",
                literal(s(&f["name"])),
                pascal(s(&f["name"]))
            ));
        }

        code.line(format!(
            "_ => {VALUE_TASK}.FromResult({VERIFICATION}.Ok()),"
        ));
        code.close_with(";");

        for f in &verified {
            let name = s(&f["name"]);
            let ty = self.field_ty(e, f)?;
            let cast = self.unbox(&ty, "value", b(&f["nullable"]));

            code.blank();
            code.line(format!(
                "private {VALUE_TASK}<{VERIFICATION}> Verify{}Async(object? value, {MUTATION_CONTEXT} context, {CANCELLATION_TOKEN} cancellationToken) =>",
                pascal(name)
            ));
            code.line(format!(
                "    {}Verifier.VerifyAsync({cast}, {context}.Of(context), cancellationToken);",
                camel(name)
            ));
        }

        code.close();

        Ok(file)
    }

    pub fn side_effects(&self, e: &serde_json::Value) -> File {
        let entity = s(&e["name"]);
        let class = self.class(e, "SideEffects");
        let mut file = self.file(
            self.path(e, &class),
            &self.root,
            &class,
            &format!("the side effects of {entity}"),
        );
        let effects = vals(&e["sideEffects"]);
        let post = self.class(e, "MutationContext");
        let pre = self.class(e, "PreCommitContext");
        let code = &mut file.code;

        code.doc(&format!(
            "Runs {entity}'s side effects, in the order the spec declares them."
        ));
        code.open(format!(
            "public sealed class {class} : {ENTITY_SIDE_EFFECTS}"
        ));

        for t in &effects {
            code.line(format!(
                "private readonly {} {}SideEffect;",
                self.fq_contract(e, &format!("{}SideEffect", pascal(s(&t["name"])))),
                camel(s(&t["name"]))
            ));
        }

        if !effects.is_empty() {
            code.blank();
            code.open(format!(
                "public {class}({})",
                effects
                    .iter()
                    .map(|t| format!(
                        "{} {}SideEffect",
                        self.fq_contract(e, &format!("{}SideEffect", pascal(s(&t["name"])))),
                        camel(s(&t["name"]))
                    ))
                    .collect::<Vec<_>>()
                    .join(", ")
            ));

            for t in &effects {
                let name = camel(s(&t["name"]));

                code.line(format!("this.{name}SideEffect = {name}SideEffect;"));
            }

            code.close();
        }

        code.blank();
        code.open(format!(
            "public global::System.Collections.Generic.IEnumerable<global::System.Func<{CANCELLATION_TOKEN}, {VALUE_TASK}>> Handlers({SIDE_EFFECT_PHASE} phase, {SIDE_EFFECT_EVENT} sideEffectEvent, {MUTABLE_MUTATION_CONTEXT} context)"
        ));
        code.line(format!(
            "var handlers = new global::System.Collections.Generic.List<global::System.Func<{CANCELLATION_TOKEN}, {VALUE_TASK}>>();"
        ));

        for t in &effects {
            let events = list(&t["events"])
                .iter()
                .map(|v| format!("sideEffectEvent == {SIDE_EFFECT_EVENT}.{}", pascal(s(v))))
                .collect::<Vec<_>>()
                .join(" || ");
            let post_commit = t["phase"] == "postCommit";
            let name = camel(s(&t["name"]));

            code.blank();
            code.open(format!(
                "if (phase == {SIDE_EFFECT_PHASE}.{} && ({events}))",
                pascal(s(&t["phase"]))
            ));
            code.line(format!(
                "handlers.Add(token => {name}SideEffect.HandleAsync({}.Of(context), token));",
                if post_commit { &post } else { &pre }
            ));
            code.close();
        }

        code.blank();
        code.line("return handlers;");
        code.close();
        code.close();

        file
    }
}
