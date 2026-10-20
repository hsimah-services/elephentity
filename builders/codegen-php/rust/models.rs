use super::*;
use php_codegen::modifiers::Modifier;
impl Gen<'_> {
    pub fn read_model(&self, e: &Value) -> Result<Value> {
        let name = s(&e["name"]);
        let mut out = Source::new(self.name(e, ""));
        out.comment(
            e["description"]
                .as_str()
                .unwrap_or(&format!("{name}, as stored.")),
        );
        let id = out.import(ENTITY_ID);
        let mut ctor = method("__construct", "", "").parameter(promoted("id", &id, true));
        if self.has_edges(e) {
            let ty = out.import(EDGE_LOADER);
            ctor.parameters.push(promoted("edges", &ty, true));
        }
        for p in list(&e["appliedPatterns"]) {
            let p = s(p);
            if !self.schema["patterns"][p].is_null() {
                out.implements(&self.pattern(p, ""));
            }
        }
        out.add(ctor);
        out.add(method("getId", &id, "return $this->id;"));
        for f in vals(&e["fields"]) {
            let n = s(&f["name"]);
            let ty = out.import(&self.field_type(e, f)?);
            let sig = format!("{}{ty}", if b(&f["nullable"]) { "?" } else { "" });
            out.class.methods[0]
                .parameters
                .push(promoted(n, &sig, true));
            let mut m = method(
                &format!("get{}", cap(n)),
                &sig,
                &format!("return $this->{n};"),
            );
            if let Some(d) = f["description"].as_str() {
                m = m.document(doc(d));
            }
            out.add(m);
        }
        for edge in vals(&e["edges"]) {
            let target = self.entity(s(&edge["to"]));
            if target.is_null() {
                continue;
            }
            let ty = out.import(&self.name(target, ""));
            let one = edge["cardinality"] == "one";
            let n = s(&edge["name"]);
            let call = format!(
                "$this->edges->to{}({}, $this->id, {})",
                if one { "One" } else { "Many" },
                quote(name),
                quote(n)
            );
            self.edge_read(&mut out, n, &ty, one, &call, None);
        }
        for (declaring, edge, n) in self.inverses(e) {
            let ty = out.import(&self.name(declaring, ""));
            let one = b(&edge["inverse"]["unique"]);
            let dn = s(&declaring["name"]);
            let en = s(&edge["name"]);
            let call = format!(
                "$this->edges->inverseTo{}({}, {}, $this->id)",
                if one { "One" } else { "Many" },
                quote(dn),
                quote(en)
            );
            let comment = format!(
                "{} {dn} whose \"{en}\" points here.",
                if one { "The" } else { "Every" }
            );
            self.edge_read(&mut out, &n, &ty, one, &call, Some(comment));
        }
        out.named_constructor();
        Ok(out.file(&self.root))
    }
    fn edge_read(
        &self,
        out: &mut Source,
        n: &str,
        ty: &str,
        one: bool,
        call: &str,
        comment: Option<String>,
    ) {
        let (name, ret, body, annotation) = if one {
            (format!("get{}",cap(n)),format!("?{ty}"),format!("$related = {call};\nassert(null === $related || $related instanceof {ty});\n\nreturn $related;"),format!("@return {ty}|null"))
        } else {
            let q = out.import(ENTITY_QUERY);
            (n.into(),q,format!("/** @var EntityQuery<{ty}> $related */\n$related = {call};\n\nreturn $related;"),format!("@return EntityQuery<{ty}>"))
        };
        let comment = comment
            .map(|c| format!("{c}\n\n{annotation}"))
            .unwrap_or(annotation);
        out.add(method(&name, &ret, &body).document(doc(&comment)));
    }
    fn setter(&self, out: &mut Source, e: &Value, f: &Value) -> Result<()> {
        let n = s(&f["name"]);
        let ty = out.import(&self.field_type(e, f)?);
        out.add(
            method(
                &format!("set{}", cap(n)),
                "self",
                &format!("$this->buffer->set({}, ${n});\n\nreturn $this;", quote(n)),
            )
            .parameter(param(n, &ty, b(&f["nullable"]))),
        );
        Ok(())
    }
    pub fn mutator(&self, e: &Value) -> Result<Value> {
        let name = s(&e["name"]);
        let mut out = Source::new(self.name(e, "Mutator"));
        out.comment(&format!("Pending changes to a {name}."));
        let ty = out.import(MUTATION_BUFFER);
        let mut ctor = method("__construct", "", "").parameter(promoted("buffer", &ty, true));
        for p in list(&e["appliedPatterns"]) {
            let p = s(p);
            if !self.schema["patterns"][p].is_null() {
                let ty = out.import(&self.pattern(p, "MutatorTrait"));
                out.class.usages.push(ty.into());
            }
        }
        for a in vals(&e["actions"]) {
            let n = s(&a["name"]);
            let ty = out.import(&self.contract(e, &format!("{}Action", cap(n))));
            ctor.parameters
                .push(promoted(&format!("{n}Action"), &ty, true));
        }
        out.add(ctor);
        for f in vals(&e["fields"]) {
            if !b(&f["immutable"]) && f["managed"].is_null() {
                self.setter(&mut out, e, f)?;
            }
        }
        for edge in vals(&e["edges"]) {
            let n = s(&edge["name"]);
            let target = s(&edge["to"]);
            if edge["cardinality"] == "one" {
                let ty = out.import(IDENTIFIER);
                out.add(method(&format!("set{}",cap(n)),"self",&format!("$this->buffer->edge({})->set(null === ${n} ? [] : [${n}]);\n\nreturn $this;",quote(n))).parameter(param(n,&ty,true)).document(doc(&format!("Point this at one {target}, or at nothing."))));
            } else {
                let ty = out.import(EDGE_MUTATION);
                out.add(
                    method(
                        n,
                        &ty,
                        &format!("return $this->buffer->edge({});", quote(n)),
                    )
                    .document(doc(&format!(
                        "Add, remove or replace the {target} this links to."
                    ))),
                );
            }
        }
        for a in vals(&e["actions"]) {
            let n = s(&a["name"]);
            let ty = out.import(&self.name(e, &format!("{}Context", cap(n))));
            let args = vals(&a["arguments"])
                .iter()
                .map(|v| format!(", ${}", s(&v["name"])))
                .collect::<String>();
            let mut m = method(
                n,
                "void",
                &format!("$this->{n}Action->handle({ty}::of($this->buffer){args});"),
            );
            self.args(&mut out, &a["arguments"], &mut m)?;
            if let Some(d) = a["description"].as_str() {
                m = m.document(doc(d));
            }
            out.add(m);
        }
        Ok(out.file(&self.root))
    }
    pub fn finder(&self, e: &Value) -> Result<Value> {
        let mut out = Source::new(self.name(e, "Finder"));
        out.comment(&format!("Collection-level queries over {}.", s(&e["name"])));
        let mut ctor = method("__construct", "", "");
        for q in vals(&e["queries"]) {
            let n = s(&q["name"]);
            let ty = out.import(&self.contract(e, &format!("{}Query", cap(n))));
            ctor.parameters
                .push(promoted(&format!("{n}Query"), &ty, true));
        }
        out.add(ctor);
        for q in vals(&e["queries"]) {
            let e = self.entity(s(&q["returns"]["type"]));
            if e.is_null() {
                continue;
            }
            let ty = out.import(&self.name(e, ""));
            let n = s(&q["name"]);
            let args = vals(&q["arguments"])
                .iter()
                .map(|v| format!("${}", s(&v["name"])))
                .collect::<Vec<_>>()
                .join(", ");
            let mut m = if q["returns"]["cardinality"] == "one" {
                method(
                    n,
                    &format!("?{ty}"),
                    &format!("return $this->{n}Query->find({args});"),
                )
            } else {
                let rt = out.import(ENTITY_QUERY);
                method(n, &rt, &format!("return $this->{n}Query->find({args});"))
                    .document(doc(&format!("@return EntityQuery<{ty}>")))
            };
            self.args(&mut out, &q["arguments"], &mut m)?;
            out.add(m);
        }
        Ok(out.file(&self.root))
    }
    pub fn contexts(&self, e: &Value) -> Result<Vec<Value>> {
        let mut files = vec![];
        let en = s(&e["name"]);
        for a in vals(&e["actions"]) {
            let n = s(&a["name"]);
            let mut out = Source::new(self.name(e, &format!("{}Context", cap(n))));
            out.comment(&format!(
                "Everything {en}::{n}() is allowed to write, and nothing else."
            ));
            let ty = out.import(MUTATION_BUFFER);
            out.add(method("__construct", "", "").parameter(promoted("buffer", &ty, true)));
            let read = out.import(MUTATION_CONTEXT);
            let context = out.import(&self.name(e, "MutationContext"));
            let error = out.import("LogicException");
            out.add(method("context", &context, &format!("if (!$this->buffer instanceof {read}) {{\n    throw new {error}('Action buffer must expose mutation state.');\n}}\n\nreturn {context}::of($this->buffer);")).document(doc("Read original and pending state without widening the action's writes.")));
            for f in list(&a["writes"]["fields"]) {
                let f = &e["fields"][s(f)];
                if !f.is_null() {
                    self.setter(&mut out, e, f)?;
                }
            }
            for edge in list(&a["writes"]["edges"]) {
                let ty = out.import(EDGE_MUTATION);
                out.add(method(
                    s(edge),
                    &ty,
                    &format!("return $this->buffer->edge({});", quote(s(edge))),
                ));
            }
            out.named_constructor();
            files.push(out.file(&self.root));
            let mut out = Source::new(self.name(e, &format!("{}Arguments", cap(n))));
            out.readonly();
            let mut ctor = method("__construct", "", "");
            let mut lines = vec![];
            let mut values = vec![];
            for a in vals(&a["arguments"]) {
                let n = s(&a["name"]);
                let ty = out.import(&self.reference(&a["type"])?);
                ctor.parameters
                    .push(param(n, &ty, b(&a["nullable"])).public());
                let value = format!("$arguments[{}]", quote(n));
                let test = narrow(&ty, &value);
                lines.push(format!(
                    "assert({}{test});",
                    if b(&a["nullable"]) {
                        format!("null === {value} || ")
                    } else {
                        String::new()
                    }
                ));
                values.push(value);
            }
            lines.push(format!("return new self({});", values.join(", ")));
            out.add(ctor);
            out.add(
                method("of", "self", &lines.join("\n"))
                    .modifier(Modifier::Static)
                    .parameter(param("arguments", "array", false))
                    .document(doc("@param array<string, mixed> $arguments")),
            );
            files.push(out.file(&self.root));
        }
        for (write, mutable) in [(false, false), (true, false), (false, true)] {
            let mut out = Source::new(self.name(
                e,
                if write {
                    "WriteContext"
                } else if mutable {
                    "PreCommitContext"
                } else {
                    "MutationContext"
                },
            ));
            out.readonly();
            let runtime = if write {
                WRITE_CONTEXT
            } else if mutable {
                MUTABLE_MUTATION_CONTEXT
            } else {
                MUTATION_CONTEXT
            };
            out.implements(runtime);
            let ty = out.import(runtime);
            out.add(method("__construct", "", "").parameter(promoted("context", &ty, false)));
            out.import(ACTION_CALL);
            out.add(
                method("actions", "array", "return $this->context->actions();")
                    .document(doc("@return list<ActionCall>")),
            );
            if !write {
                out.add(method(
                    "originalEntity",
                    "?object",
                    "return $this->context->originalEntity();",
                ));
            }
            if mutable {
                out.import(IDENTIFIER);
                out.import(EDGE_MUTATION);
                out.add(method(
                    "target",
                    "Identifier",
                    "return $this->context->target();",
                ));
                out.add(
                    method("set", "void", "$this->context->set($field, $value);")
                        .parameter(param("field", "string", false))
                        .parameter(param("value", "mixed", false)),
                );
                out.add(
                    method(
                        "edge",
                        "EdgeMutation",
                        "return $this->context->edge($edge);",
                    )
                    .parameter(param("edge", "string", false)),
                );
                for f in vals(&e["fields"]) {
                    let n = s(&f["name"]);
                    let ft = out.import(&self.field_type(e, f)?);
                    out.add(
                        method(
                            &format!("set{}", cap(n)),
                            "void",
                            &format!("$this->context->set({}, $value);", quote(n)),
                        )
                        .parameter(param("value", &ft, b(&f["nullable"]))),
                    );
                }
                for edge in vals(&e["edges"]) {
                    let n = s(&edge["name"]);
                    out.add(method(
                        n,
                        "EdgeMutation",
                        &format!("return $this->context->edge({});", quote(n)),
                    ));
                }
            }
            if write {
                out.import(WRITE_OPERATION);
                out.import(MUTATION_CONTEXT);
                out.import(&self.name(e, ""));
                out.add(
                    method("of", "self", "return new self($context);")
                        .modifier(Modifier::Static)
                        .parameter(param("context", &ty, false)),
                );
                for (n, t) in [
                    ("entity", "string"),
                    ("operation", "WriteOperation"),
                    ("action", "?string"),
                    ("arguments", "array"),
                    ("mutation", "?MutationContext"),
                ] {
                    out.add(method(n, t, &format!("return $this->context->{n}();")));
                }
            } else {
                out.comment(&format!("A pending {en} mutation, with exact types."));
                out.import(IDENTIFIER);
                for (n, t) in [
                    ("id", "Identifier"),
                    ("entity", "string"),
                    ("isCreate", "bool"),
                ] {
                    out.add(method(n, t, &format!("return $this->context->{n}();")));
                }
                for (n, t) in [
                    ("original", "mixed"),
                    ("pending", "mixed"),
                    ("isChanged", "bool"),
                ] {
                    out.add(
                        method(n, t, &format!("return $this->context->{n}($field);"))
                            .parameter(param("field", "string", false)),
                    );
                }
                out.add(
                    method("changes", "array", "return $this->context->changes();")
                        .document(doc("@return array<string, mixed>")),
                );
                out.add(
                    method(
                        "pendingEdge",
                        "array",
                        "return $this->context->pendingEdge($edge);",
                    )
                    .parameter(param("edge", "string", false))
                    .document(doc("@return list<Identifier>")),
                );
                out.add(
                    method(
                        "isEdgeChanged",
                        "bool",
                        "return $this->context->isEdgeChanged($edge);",
                    )
                    .parameter(param("edge", "string", false)),
                );
            }
            for f in vals(&e["fields"]) {
                let ty = out.import(&self.field_type(e, f)?);
                let n = s(&f["name"]);
                for side in ["original", "pending"] {
                    out.add(method(&format!("{side}{}",cap(n)),&format!("?{ty}"),&format!("$value = $this->context->{}{side}({});\nassert(null === $value || {});\n\nreturn $value;",if write{"mutation()?->"}else{""},quote(n),narrow(&ty,"$value"))));
                }
            }
            if write {
                for a in vals(&e["actions"]) {
                    let n = s(&a["name"]);
                    let ty = out.import(&self.name(e, &format!("{}Arguments", cap(n))));
                    out.add(method(n,&format!("?{ty}"),&format!("return {} === $this->context->action() ? {ty}::of($this->context->arguments()) : null;",quote(n))));
                }
            } else {
                for edge in vals(&e["edges"]) {
                    let n = s(&edge["name"]);
                    let one = edge["cardinality"] == "one";
                    let mut m = method(
                        &format!("pending{}", cap(n)),
                        if one { "?Identifier" } else { "array" },
                        &format!(
                            "return $this->context->pendingEdge({}){};",
                            quote(n),
                            if one { "[0] ?? null" } else { "" }
                        ),
                    );
                    if !one {
                        m = m.document(doc("@return list<Identifier>"));
                    }
                    out.add(m);
                    out.add(method(
                        &format!("is{}Changed", cap(n)),
                        "bool",
                        &format!("return $this->context->isEdgeChanged({});", quote(n)),
                    ));
                }
                out.named_constructor();
            }
            files.push(out.file(&self.root));
        }
        Ok(files)
    }
}
fn narrow(ty: &str, value: &str) -> String {
    if scalar(ty) {
        format!("is_{ty}({value})")
    } else {
        format!("{value} instanceof {ty}")
    }
}
