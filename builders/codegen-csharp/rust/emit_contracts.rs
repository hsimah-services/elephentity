//! The interfaces an application implements for one entity: queries, actions, side effects, verifiers and policies.

use crate::cs::File;
use crate::gen::*;
use crate::names::{camel, pascal};
use crate::runtime::*;

impl Gen<'_> {
    pub fn contracts(&self, e: &serde_json::Value) -> Result<Vec<File>> {
        let mut files = Vec::new();
        let entity = s(&e["name"]);
        let ns = self.contract_ns();

        for q in vals(&e["queries"]) {
            let target = self.entity(s(&q["returns"]["type"]));

            if target.is_null() {
                continue;
            }

            let name = s(&q["name"]);
            let interface = self.contract(e, &format!("{}Query", pascal(name)));
            let mut file = self.file(
                self.contract_path(e, &interface),
                &ns,
                &interface,
                &format!("query {entity}.{name}"),
            );
            let target_type = format!("global::{}.{}", self.root, self.type_name(target));
            let parameters = self.parameters(&q["arguments"])?;
            let code = &mut file.code;

            code.doc(
                q["description"]
                    .as_str()
                    .unwrap_or(&format!("Backs {entity}Finder.{}.", pascal(name))),
            );
            code.open(format!("public interface {interface}"));

            if q["returns"]["cardinality"] == "one" {
                code.line(format!(
                    "{VALUE_TASK}<{target_type}?> FindAsync({}{CANCELLATION_TOKEN} cancellationToken = default);",
                    if parameters.is_empty() { String::new() } else { format!("{parameters}, ") }
                ));
            } else {
                code.line(format!("{ENTITY_QUERY}<{target_type}> Find({parameters});"));
            }

            code.close();
            files.push(file);
        }

        for a in vals(&e["actions"]) {
            let name = s(&a["name"]);
            let interface = self.contract(e, &format!("{}Action", pascal(name)));
            let mut file = self.file(
                self.contract_path(e, &interface),
                &ns,
                &interface,
                &format!("action {entity}.{name}"),
            );
            let context = format!(
                "global::{}.{}",
                self.root,
                self.class(e, &format!("{}Context", pascal(name)))
            );
            let parameters = self.parameters(&a["arguments"])?;
            let code = &mut file.code;

            code.doc(&format!(
                "{}\n\nThe context exposes only what {name} declares it writes, so this cannot touch anything the spec does not say it touches.",
                a["description"].as_str().map(str::to_owned).unwrap_or(format!("Backs {entity}Mutator.{}Async.", pascal(name)))
            ));
            code.open(format!("public interface {interface}"));
            code.line(format!(
                "{VALUE_TASK} HandleAsync({context} context, {}{CANCELLATION_TOKEN} cancellationToken = default);",
                if parameters.is_empty() { String::new() } else { format!("{parameters}, ") }
            ));
            code.close();
            files.push(file);
        }

        for t in vals(&e["sideEffects"]) {
            let name = s(&t["name"]);
            let post = t["phase"] == "postCommit";
            let interface = self.contract(e, &format!("{}SideEffect", pascal(name)));
            let mut file = self.file(
                self.contract_path(e, &interface),
                &ns,
                &interface,
                &format!("side effect {entity}.{name}"),
            );
            let context = format!(
                "global::{}.{}",
                self.root,
                self.class(
                    e,
                    if post {
                        "MutationContext"
                    } else {
                        "PreCommitContext"
                    }
                )
            );
            let code = &mut file.code;
            let phase = if post {
                "postCommit: the transaction is closed, so writes here are a new unit of work and are not atomic with the commit that caused them. A throw is logged."
            } else {
                "preCommit: update pending fields and relationships before verification and storage. Throw to cancel the mutation. New entities still have pending IDs."
            };

            code.doc(&format!(
                "{}\n\n{phase}",
                t["description"]
                    .as_str()
                    .map(str::to_owned)
                    .unwrap_or(format!(
                        "Runs {} on {}.",
                        s(&t["phase"]),
                        list(&t["events"])
                            .iter()
                            .map(|v| s(v))
                            .collect::<Vec<_>>()
                            .join(", ")
                    ))
            ));
            code.open(format!("public interface {interface}"));
            code.line(format!("{VALUE_TASK} HandleAsync({context} context, {CANCELLATION_TOKEN} cancellationToken = default);"));
            code.close();
            files.push(file);
        }

        for f in vals(&e["fields"]) {
            if !b(&f["verify"]) {
                continue;
            }

            let name = s(&f["name"]);
            let interface = self.contract(e, &format!("{}Verifier", pascal(name)));
            let mut file = self.file(
                self.contract_path(e, &interface),
                &ns,
                &interface,
                &format!("verifier {entity}.{name}"),
            );
            let ty = self.field_ty(e, f)?;
            let context = format!("global::{}.{}", self.root, self.class(e, "MutationContext"));
            let code = &mut file.code;

            code.doc(&format!(
                "Entity-specific rules for {entity}.{name}.\n\nRuns before the type processor, and both run even when this fails, so violations from both tiers arrive together."
            ));
            code.open(format!("public interface {interface}"));
            code.line(format!(
                "{VALUE_TASK}<{VERIFICATION}> VerifyAsync({} value, {context} context, {CANCELLATION_TOKEN} cancellationToken = default);",
                ty.with(b(&f["nullable"]))
            ));
            code.close();
            files.push(file);
        }

        for write in [false, true] {
            for p in vals(
                &e[if write {
                    "writePolicies"
                } else {
                    "readPolicies"
                }],
            ) {
                if !p["origin"]["pattern"].is_null() {
                    continue;
                }

                let interface = self.policy_contract_name(e, p, write);
                let mut file = self.file(
                    self.contract_path(e, &interface),
                    &ns,
                    &interface,
                    &format!(
                        "{} policy {entity}.{}",
                        if write { "write" } else { "read" },
                        s(&p["name"])
                    ),
                );
                let entity_type = format!("global::{}.{}", self.root, self.type_name(e));
                let code = &mut file.code;

                code.doc(&format!(
                    "Application policy {} for {entity}.",
                    s(&p["name"])
                ));
                code.open(format!("public interface {interface}"));

                if write {
                    let context =
                        format!("global::{}.{}", self.root, self.class(e, "WriteContext"));

                    code.line(format!(
                        "{VALUE_TASK}<{POLICY_DECISION}> DecideAsync({entity_type}? entity, {context} context, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default);"
                    ));
                } else {
                    code.line(format!(
                        "{VALUE_TASK}<{POLICY_DECISION}> DecideAsync({entity_type} entity, {VIEWER} viewer, {CANCELLATION_TOKEN} cancellationToken = default);"
                    ));
                }

                code.close();
                files.push(file);
            }
        }

        let _ = camel;

        Ok(files)
    }
}
