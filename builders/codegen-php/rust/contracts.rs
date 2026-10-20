use super::*;
impl Gen<'_> {
    pub fn contracts(&self, e: &Value) -> Result<Vec<Value>> {
        let mut files = vec![];
        let en = s(&e["name"]);
        for q in vals(&e["queries"]) {
            let target = self.entity(s(&q["returns"]["type"]));
            if target.is_null() {
                continue;
            }
            let n = s(&q["name"]);
            let mut out = Source::new(self.contract(e, &format!("{}Query", cap(n))));
            out.kind = "interface";
            out.comment(
                q["description"]
                    .as_str()
                    .unwrap_or(&format!("Backs {en}Finder::{n}().")),
            );
            let ty = out.import(&self.name(target, ""));
            let mut m = if q["returns"]["cardinality"] == "one" {
                declaration("find", &format!("?{ty}"))
            } else {
                let ret = out.import(ENTITY_QUERY);
                declaration("find", &ret).document(doc(&format!("@return EntityQuery<{ty}>")))
            };
            self.args(&mut out, &q["arguments"], &mut m)?;
            out.add(m);
            files.push(out.file(&self.root));
        }
        for a in vals(&e["actions"]) {
            let n = s(&a["name"]);
            let mut out = Source::new(self.contract(e, &format!("{}Action", cap(n))));
            out.kind = "interface";
            let description = a["description"]
                .as_str()
                .map(str::to_owned)
                .unwrap_or(format!("Backs {en}Mutator::{n}()."));
            out.comment(&format!("{description}\n\nThe context exposes only what {n} declares it writes, so this cannot\ntouch anything the spec does not say it touches."));
            let ty = out.import(&self.name(e, &format!("{}Context", cap(n))));
            let mut m = declaration("handle", "void").parameter(param("context", &ty, false));
            self.args(&mut out, &a["arguments"], &mut m)?;
            out.add(m);
            files.push(out.file(&self.root));
        }
        for t in vals(&e["sideEffects"]) {
            let n = s(&t["name"]);
            let mut out = Source::new(self.contract(e, &format!("{}SideEffect", cap(n))));
            out.kind = "interface";
            let description = t["description"]
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
                ));
            let phase = if t["phase"] == "postCommit" {
                "postCommit: the transaction is closed, so writes here are a new unit of\nwork and are not atomic with the commit that caused them. A throw is logged."
            } else {
                "preCommit: update pending fields and relationships before verification and storage.\nThrow to cancel the mutation. New entities still have pending IDs."
            };
            out.comment(&format!("{description}\n\n{phase}"));
            let ty = out.import(&self.name(
                e,
                if t["phase"] == "postCommit" {
                    "MutationContext"
                } else {
                    "PreCommitContext"
                },
            ));
            out.add(declaration("handle", "void").parameter(param("context", &ty, false)));
            files.push(out.file(&self.root));
        }
        for f in vals(&e["fields"]) {
            if !b(&f["verify"]) {
                continue;
            }
            let mut out = Source::new(self.contract(e, &format!("{}Verifier", cap(s(&f["name"])))));
            out.kind = "interface";
            let ctx = out.import(&self.name(e, "MutationContext"));
            let ty = out.import(&self.field_type(e, f)?);
            let ret = out.import(VERIFICATION);
            out.comment(&format!("Entity-specific rules for {en}::{}.\n\nRuns before the type processor, and both run even when this fails, so\nviolations from both tiers arrive together.",s(&f["name"])));
            out.add(
                declaration("verify", &ret)
                    .parameter(param("value", &ty, b(&f["nullable"])))
                    .parameter(param("context", &ctx, false)),
            );
            files.push(out.file(&self.root));
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
                files.push(self.policy(e, p, write, false));
            }
        }
        Ok(files)
    }
    fn policy(&self, e: &Value, p: &Value, write: bool, pattern: bool) -> Value {
        let n = s(&p["name"]);
        let en = s(&e["name"]);
        let suffix = format!("{}{}Policy", cap(n), if write { "Write" } else { "Read" });
        let fq = if pattern {
            format!("{}\\Pattern\\{en}\\Contract\\{en}{suffix}", self.root)
        } else {
            self.contract(e, &suffix)
        };
        let mut out = Source::new(fq);
        out.kind = "interface";
        out.comment(&format!(
            "Application policy {n} for {}{en}.",
            if pattern { "pattern " } else { "" }
        ));
        let ret = out.import(POLICY_DECISION);
        let viewer = out.import(VIEWER);
        let ty = out.import(&if pattern {
            self.pattern(en, "")
        } else {
            self.name(e, "")
        });
        let mut m = declaration("decide", &ret).parameter(param("entity", &ty, write));
        if write {
            let ctx = out.import(&if pattern {
                WRITE_CONTEXT.into()
            } else {
                self.name(e, "WriteContext")
            });
            m.parameters.push(param("context", &ctx, false));
        }
        m.parameters.push(param("viewer", &viewer, false));
        out.add(m);
        out.file(&self.root)
    }
    pub fn shared(&self) -> Result<Vec<Value>> {
        let mut files = vec![];
        for t in vals(&self.schema["types"]) {
            let n = s(&t["name"]);
            if !t["values"].is_null() {
                files.push(self.enumeration(
                    n,
                    &t["values"],
                    s(&t["primitive"]) == "int",
                    t["description"].as_str(),
                )?);
            }
            if !b(&t["hasProcessors"]) {
                continue;
            }
            let stored = match s(&t["primitive"]) {
                "int" => "int",
                "float" => "float",
                "bool" => "bool",
                _ => "string",
            };
            for write in [false, true] {
                let side = if write { "Write" } else { "Read" };
                let mut out = Source::new(format!("{}\\Type\\{n}{side}Processor", self.root));
                out.kind = "interface";
                let runtime = out.import(if write {
                    WRITE_PROCESSOR
                } else {
                    READ_PROCESSOR
                });
                let ty = out.import(&format!("{}\\{n}", self.types));
                out.class.extends = Some(runtime);
                out.comment(&format!(
                    "{}\n\n@extends {side}Processor<{stored}, {n}>",
                    if write {
                        format!("Checks a {n} and turns it back into a stored {stored}.")
                    } else {
                        format!("Turns a stored {stored} into a {n}.")
                    }
                ));
                out.add(
                    declaration(
                        if write { "write" } else { "read" },
                        if write { stored } else { &ty },
                    )
                    .parameter(param("value", "mixed", false)),
                );
                files.push(out.file(&self.root));
            }
        }
        for e in vals(&self.schema["entities"]) {
            for f in vals(&e["fields"]) {
                if !f["enum"]["inlineValues"].is_null() {
                    let en = s(&e["name"]);
                    let n = s(&f["name"]);
                    files.push(self.enumeration(
                        &format!("{en}{}", cap(n)),
                        &f["enum"]["inlineValues"],
                        false,
                        Some(&format!("Values of {en}::{n}.")),
                    )?);
                }
            }
        }
        for p in vals(&self.schema["patterns"]) {
            let n = s(&p["name"]);
            for write in [false, true] {
                for policy in vals(
                    &p[if write {
                        "writePolicies"
                    } else {
                        "readPolicies"
                    }],
                ) {
                    files.push(self.policy(p, policy, write, true));
                }
            }
            let mut out = Source::new(self.pattern(n, ""));
            out.kind = "interface";
            out.comment(&format!("The {n} fields and edges, for code that only needs those and not the whole entity.\n\nEvery entity using {n} already has these getters — this names the shape, it does\nnot add anything an application has to implement."));
            for f in vals(&p["fields"]) {
                let ty = out.import(&self.field_type(p, f)?);
                let sig = format!("{}{ty}", if b(&f["nullable"]) { "?" } else { "" });
                let mut m = declaration(&format!("get{}", cap(s(&f["name"]))), &sig);
                if let Some(d) = f["description"].as_str() {
                    m = m.document(doc(d));
                }
                out.add(m);
            }
            for edge in vals(&p["edges"]) {
                let target = self.entity(s(&edge["to"]));
                if target.is_null() {
                    continue;
                }
                let ty = out.import(&self.name(target, ""));
                let en = s(&edge["name"]);
                let m = if edge["cardinality"] == "one" {
                    declaration(&format!("get{}", cap(en)), &format!("?{ty}"))
                } else {
                    let ret = out.import(ENTITY_QUERY);
                    declaration(en, &ret).document(doc(&format!("@return EntityQuery<{ty}>")))
                };
                out.add(m);
            }
            files.push(out.file(&self.root));
            let mut out = Source::new(self.pattern(n, "MutatorTrait"));
            out.kind = "trait";
            out.comment(&format!("{n}'s own setters, mixed into every entity mutator that applies it.\n\nAssumes the using class has a private MutationBuffer $buffer — true of every\ngenerated {{Entity}}Mutator, and the only thing this trait requires of it."));
            for f in vals(&p["fields"]) {
                if b(&f["immutable"]) || !f["managed"].is_null() {
                    continue;
                }
                let fnm = s(&f["name"]);
                let ty = out.import(&self.field_type(p, f)?);
                out.add(
                    method(
                        &format!("set{}", cap(fnm)),
                        "self",
                        &format!(
                            "$this->buffer->set({}, ${fnm});\n\nreturn $this;",
                            quote(fnm)
                        ),
                    )
                    .parameter(param(fnm, &ty, b(&f["nullable"]))),
                );
            }
            for edge in vals(&p["edges"]) {
                let en = s(&edge["name"]);
                if edge["cardinality"] == "one" {
                    let ty = out.import(IDENTIFIER);
                    out.add(method(&format!("set{}",cap(en)),"self",&format!("$this->buffer->edge({})->set(null === ${en} ? [] : [${en}]);\n\nreturn $this;",quote(en))).parameter(param(en,&ty,true)));
                } else {
                    let ty = out.import(EDGE_MUTATION);
                    out.add(method(
                        en,
                        &ty,
                        &format!("return $this->buffer->edge({});", quote(en)),
                    ));
                }
            }
            files.push(out.file(&self.root));
        }
        Ok(files)
    }
    fn enumeration(
        &self,
        name: &str,
        values: &Value,
        int: bool,
        comment: Option<&str>,
    ) -> Result<Value> {
        use php_codegen::{
            enum_case::EnumCase,
            r#enum::{Enum, EnumBackingType},
            Generator, Indentation,
        };
        let mut e = Enum::new(name);
        e.backing_type = Some(if int {
            EnumBackingType::Int
        } else {
            EnumBackingType::String
        });
        if let Some(d) = comment {
            e.documentation = Some(doc(d));
        }
        let mut names = std::collections::BTreeMap::new();
        for (i, v) in list(values).iter().enumerate() {
            let case = s(v)
                .replace('_', " ")
                .split_whitespace()
                .map(cap)
                .collect::<String>();
            if let Some(previous) = names.insert(case.clone(), s(v)) {
                return Err(format!(
                    "PHP enum {name}: labels {previous:?} and {:?} both generate case {case:?}.",
                    s(v)
                ));
            }
            let mut c = EnumCase::new(case);
            c.value = Some(if int {
                php_codegen::literal::Value::Integer(i as i64)
            } else {
                php_codegen::literal::Value::String(s(v).into())
            });
            e.cases.push(c);
        }
        Ok(
            json!({"path":format!("Enum/{name}.php"),"body":format!("namespace {}\\Enum;\n\n{}",self.root,e.generate(Indentation::default(),0))}),
        )
    }
}
