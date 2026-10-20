use super::*;
use php_codegen::modifiers::Modifier;
impl Gen<'_> {
    pub(crate) fn readers(&self, e: &Value, input: bool) -> Vec<String> {
        let mut types = vec![];
        let mut fields = vals(&e["fields"]);
        if input {
            fields.retain(|f| f["managed"].is_null());
            for a in vals(&e["actions"]) {
                fields.extend(vals(&a["arguments"]));
            }
        }
        for f in fields {
            let n = s(&f["type"]["declaredType"]);
            let t = &self.schema["types"][n];
            if !n.is_empty()
                && t["values"].is_null()
                && b(&t["hasProcessors"])
                && !types.iter().any(|v| v == n)
            {
                types.push(n.into());
            }
        }
        types
    }
    fn decode(&self, r: &Value, ty: &str, value: &str, label: &str) -> String {
        let primitive = s(&r["primitive"]);
        if primitive.is_empty() {
            let n = s(&r["declaredType"]);
            let declared = &self.schema["types"][n];
            let backing = s(&declared["primitive"]);
            if !declared["values"].is_null() {
                decode_primitive("enum", ty, value, label)
            } else if b(&declared["hasProcessors"]) {
                format!(
                    "$this->{}Reader->read({})",
                    low(n),
                    decode_primitive(
                        if matches!(backing, "json" | "datetime") {
                            "string"
                        } else {
                            backing
                        },
                        ty,
                        value,
                        label
                    )
                )
            } else {
                decode_primitive(backing, ty, value, label)
            }
        } else {
            decode_primitive(primitive, ty, value, label)
        }
    }
    pub fn hydrate_input(&self, e: &Value, input: bool) -> Result<Value> {
        let en = s(&e["name"]);
        let mut out = Source::new(self.name(e, if input { "Input" } else { "Hydrator" }));
        out.readonly();
        out.import(VALUE_DECODER);
        if input {
            out.import(MUTATION_BUFFER);
            out.import("InvalidArgumentException");
            out.comment(&format!("Turns raw input into pending {en} changes."));
        } else {
            out.implements(HYDRATOR);
            out.import(EDGE_LOADER);
            out.import(RECORD);
            out.import(&self.name(e, ""));
            out.comment(&format!(
                "Builds a {en} from a stored row.\n\n@implements Hydrator<{en}>"
            ));
        }
        let mut ctor =
            method("__construct", "", "").parameter(promoted("decode", "ValueDecoder", false));
        for n in self.readers(e, input) {
            let ty = out.import(&format!("{}\\Type\\{n}ReadProcessor", self.root));
            ctor.parameters
                .push(promoted(&format!("{}Reader", low(&n)), &ty, false));
        }
        out.add(ctor);
        if !input {
            let mut args = vec!["$record->id".to_owned()];
            if self.has_edges(e) {
                args.push("$edges".into());
            }
            for f in vals(&e["fields"]) {
                args.push(format!("$this->{}($record)", s(&f["name"])));
            }
            out.add(
                method(
                    "hydrate",
                    en,
                    &format!("return {en}::of(\n    {},\n);", args.join(",\n    ")),
                )
                .parameter(param("record", "Record", false))
                .parameter(param("edges", "EdgeLoader", false)),
            );
        }
        let mut lines = vec![];
        for f in vals(&e["fields"]) {
            if input && !f["managed"].is_null() {
                continue;
            }
            let n = s(&f["name"]);
            let ty = out.import(&self.field_type(e, f)?);
            let label = quote(&format!("{en}.{n}"));
            let conversion = self.decode(&f["type"], &ty, "$value", &label);
            let body = if input {
                if b(&f["hasDefault"]) {
                    let value = if f["default"].is_null() {
                        "null".into()
                    } else if f["type"]["primitive"] == "json"
                        || self.schema["types"][s(&f["type"]["declaredType"])]["primitive"]
                            == "json"
                    {
                        quote(&serde_json::to_string(&f["default"]).unwrap())
                    } else if let Some(label) = f["default"].as_str().filter(|_| {
                        !f["enum"].is_null()
                            || !self.schema["types"][s(&f["type"]["declaredType"])]["values"]
                                .is_null()
                    }) {
                        let case = label
                            .replace('_', " ")
                            .split_whitespace()
                            .map(cap)
                            .collect::<String>();
                        format!("{ty}::{case}")
                    } else {
                        php_value(&f["default"])
                    };
                    lines.extend([
                        format!("if (!$buffer->target()->isPersisted() && !$buffer->isChanged({}) && !array_key_exists({}, $input)) {{", quote(n), quote(n)),
                        format!("    $buffer->set({}, $this->{n}({value}));", quote(n)),
                        "}".into(), "".into(),
                    ]);
                }
                lines.extend([
                    format!("if (array_key_exists({}, $input)) {{", quote(n)),
                    format!(
                        "    $buffer->set({}, $this->{n}($input[{}]));",
                        quote(n),
                        quote(n)
                    ),
                    "}".into(),
                    "".into(),
                ]);
                format!("if (null === $value) {{\n    return null;\n}}\n\nreturn {conversion};")
            } else {
                format!(
                    "$value = $record->value({});\n\nreturn {}{conversion};",
                    quote(n),
                    if b(&f["nullable"]) {
                        "null === $value ? null : "
                    } else {
                        ""
                    }
                )
            };
            out.add(
                method(
                    n,
                    &format!("{}{ty}", if input || b(&f["nullable"]) { "?" } else { "" }),
                    &body,
                )
                .private()
                .parameter(param(
                    if input { "value" } else { "record" },
                    if input { "mixed" } else { "Record" },
                    false,
                )),
            );
        }
        if input {
            for edge in vals(&e["edges"]) {
                let n = s(&edge["name"]);
                let key = quote(n);
                let label = quote(&format!("{en}.{n}"));
                out.import(IDENTIFIER);
                lines.extend([
                    format!("if (array_key_exists({key}, $input)) {{"),
                    format!("    $buffer->edge({key})->set($this->{n}($input[{key}]));"),
                    "}".into(),
                    "".into(),
                ]);
                let body = if edge["cardinality"] == "one" {
                    format!("if (null === $value) {{\n    return [];\n}}\n\nreturn [$this->decode->id($value, {label})];")
                } else {
                    format!("if (null === $value) {{\n    return [];\n}}\n\nif (!is_array($value)) {{\n    throw new InvalidArgumentException(sprintf('%s takes a list of ids.', {label}));\n}}\n\n$ids = [];\n\nforeach ($value as $id) {{\n    $ids[] = $this->decode->id($id, {label});\n}}\n\nreturn $ids;")
                };
                out.add(
                    method(n, "array", &body)
                        .private()
                        .parameter(param("value", "mixed", false))
                        .document(doc("@return list<Identifier>")),
                );
            }
            out.add(method("apply","void",lines.join("\n").trim_end()).parameter(param("buffer","MutationBuffer",false)).parameter(param("input","array",false)).document(doc("Seeds declared defaults on create and applies explicit input.\nAbsent update keys and existing pending values are not reset by defaults.\n\n@param array<string, mixed> $input")));
            let mut arms = vec![];
            for a in vals(&e["actions"]) {
                let an = s(&a["name"]);
                let mut values = vec![];
                for arg in vals(&a["arguments"]) {
                    let key = quote(s(&arg["name"]));
                    let value = format!("$args[{key}]");
                    let label = quote(&format!("{en}.{an}.{}", s(&arg["name"])));
                    let ty = self.reference(&arg["type"])?;
                    let decoded = self.decode(&arg["type"], short(&ty), &value, &label);
                    values.push(format!(
                        "{key} => {}{decoded}",
                        if b(&arg["nullable"]) {
                            format!("null === {value} ? null : ")
                        } else {
                            String::new()
                        }
                    ));
                }
                arms.push(format!("    {} => [{}],", quote(an), values.join(", ")));
            }
            out.add(method("decodeAction","array",&format!("return match ($action) {{\n{}\n    default => throw new InvalidArgumentException(sprintf('Unknown action %s.', $action)),\n}};",arms.join("\n"))).parameter(param("action","string",false)).parameter(param("args","array",false)).document(doc("@param array<string, mixed> $args\n@return array<string, mixed>")));
        }
        Ok(out.file(&self.root))
    }
    pub fn deleter(&self, e: &Value) -> Value {
        let en = s(&e["name"]);
        let mut out = Source::new(self.name(e, "Deleter"));
        for ty in [ENTITY_ID, DELETION, UNIT_OF_WORK, DELETION_RULE] {
            out.import(ty);
        }
        out.comment(&format!(
            "Removes a {en}, and whatever its edges say goes with it."
        ));
        out.add(method("__construct", "", "").parameter(promoted("work", "UnitOfWork", true)));
        out.add(
            method(
                "delete",
                "void",
                &format!("$this->work->delete(new Deletion({}, $id));", quote(en)),
            )
            .parameter(param("id", "EntityId", false))
            .document(doc(
                "Registers the removal. Nothing happens until the unit of work commits.",
            )),
        );
        let mut rules = vec![];
        for other in vals(&self.schema["entities"]) {
            for edge in vals(&other["edges"]) {
                let on = s(&other["name"]);
                let to = s(&edge["to"]);
                let local = edge["cardinality"] == "one";
                let join = !local && (edge["inverse"].is_null() || !b(&edge["inverse"]["unique"]));
                let (dependent, referenced) = if join {
                    if on == en {
                        (to, on)
                    } else {
                        (on, to)
                    }
                } else if local {
                    (on, to)
                } else {
                    (to, on)
                };
                if referenced != en {
                    continue;
                }
                rules.push(format!(
                    "    new DeletionRule({}, {}, {}, DeletionPolicy::{}, {}),",
                    quote(dependent),
                    quote(s(&edge["name"])),
                    quote(on),
                    cap(edge["onDelete"].as_str().unwrap_or("restrict")),
                    join
                ));
            }
        }
        if !rules.is_empty() {
            out.import(DELETION_POLICY);
        }
        let body = if rules.is_empty() {
            "return [];".into()
        } else {
            format!("return [\n{}\n];", rules.join("\n"))
        };
        out.add(
            method("rules", "array", &body)
                .modifier(Modifier::Static)
                .document(doc("@return list<DeletionRule>")),
        );
        out.file(&self.root)
    }
    pub fn bridges(&self, e: &Value) -> Result<Vec<Value>> {
        let en = s(&e["name"]);
        let mut files = vec![];
        for write in [false, true] {
            let side = if write { "Write" } else { "Read" };
            let mut out = Source::new(self.name(e, &format!("{side}Policies")));
            out.readonly();
            out.implements(if write {
                ENTITY_WRITE_POLICIES
            } else {
                ENTITY_READ_POLICIES
            });
            for t in [POLICY_DECISION, POLICY_OUTCOME, VIEWER] {
                out.import(t);
            }
            if write {
                out.import(WRITE_CONTEXT);
            }
            let policies = vals(
                &e[if write {
                    "writePolicies"
                } else {
                    "readPolicies"
                }],
            );
            let mut ctor = method("__construct", "", "");
            for p in &policies {
                let ty = out.import(&self.policy_handler(e, p, write));
                ctor.parameters
                    .push(promoted(&format!("{}Policy", s(&p["name"])), &ty, false));
            }
            out.add(ctor);
            out.add(method(
                "isEmpty",
                "bool",
                if policies.is_empty() {
                    "return true;"
                } else {
                    "return false;"
                },
            ));
            let body = if policies.is_empty() {
                "return PolicyDecision::allow();".into()
            } else {
                out.import(&self.name(e, ""));
                let mut lines = vec![format!(
                    "assert({}$entity instanceof {en});",
                    if write { "null === $entity || " } else { "" }
                )];
                for p in policies {
                    let n = s(&p["name"]);
                    let context = if write {
                        if !p["origin"]["pattern"].is_null() {
                            ", $context".into()
                        } else {
                            let ty = out.import(&self.name(e, "WriteContext"));
                            format!(", {ty}::of($context)")
                        }
                    } else {
                        String::new()
                    };
                    lines.extend([
                        format!("$decision = $this->{n}Policy->decide($entity{context}, $viewer);"),
                        "if (PolicyOutcome::Skip !== $decision->outcome) {".into(),
                        format!("    return $decision->withPolicy({});", quote(n)),
                        "}".into(),
                        "".into(),
                    ]);
                }
                let allow = e[if write {
                    "terminalWrite"
                } else {
                    "terminalRead"
                }] == "allow";
                lines.push(format!(
                    "return PolicyDecision::{}({})->withPolicy('terminal');",
                    if allow { "allow" } else { "deny" },
                    quote(&if allow {
                        String::new()
                    } else {
                        format!(
                            "No policy allowed this {}.",
                            if write { "write" } else { "read" }
                        )
                    })
                ));
                lines.join("\n")
            };
            let mut m = method("decide", "PolicyDecision", &body)
                .parameter(param("entity", "object", write));
            if write {
                m.parameters.push(param("context", "WriteContext", false));
            }
            m.parameters.push(param("viewer", "Viewer", false));
            out.add(m);
            files.push(out.file(&self.root));
        }
        let mut out = Source::new(self.name(e, "Verifiers"));
        out.readonly();
        out.implements(ENTITY_VERIFIERS);
        out.import(MUTATION_CONTEXT);
        out.import(VERIFICATION);
        let context = out.import(&self.name(e, "MutationContext"));
        out.comment(&format!("Dispatches to {en}'s field verifiers."));
        let verified = vals(&e["fields"])
            .into_iter()
            .filter(|f| b(&f["verify"]))
            .collect::<Vec<_>>();
        let mut ctor = method("__construct", "", "");
        for f in &verified {
            let n = s(&f["name"]);
            let ty = out.import(&self.contract(e, &format!("{}Verifier", cap(n))));
            ctor.parameters
                .push(promoted(&format!("{n}Verifier"), &ty, false));
        }
        out.add(ctor);
        out.add(
            method(
                "verifiedFields",
                "array",
                &format!(
                    "return [{}];",
                    verified
                        .iter()
                        .map(|f| quote(s(&f["name"])))
                        .collect::<Vec<_>>()
                        .join(", ")
                ),
            )
            .document(doc("@return list<string>")),
        );
        let body = if verified.is_empty() {
            "return Verification::ok();".into()
        } else {
            format!(
                "return match ($field) {{\n{}\n    default => Verification::ok(),\n}};",
                verified
                    .iter()
                    .map(|f| format!(
                        "    {} => $this->verify{}($value, $context),",
                        quote(s(&f["name"])),
                        cap(s(&f["name"]))
                    ))
                    .collect::<Vec<_>>()
                    .join("\n")
            )
        };
        out.add(
            method("verify", "Verification", &body)
                .parameter(param("field", "string", false))
                .parameter(param("value", "mixed", false))
                .parameter(param("context", "MutationContext", false)),
        );
        for f in verified {
            let n = s(&f["name"]);
            let ty = out.import(&self.field_type(e, f)?);
            let mut check = if scalar(&ty) {
                format!("is_{ty}($value)")
            } else {
                format!("$value instanceof {ty}")
            };
            if b(&f["nullable"]) {
                check = format!("null === $value || {check}");
            }
            out.add(method(&format!("verify{}",cap(n)),"Verification",&format!("assert({check});\n\nreturn $this->{n}Verifier->verify($value, {context}::of($context));")).private().parameter(param("value","mixed",false)).parameter(param("context","MutationContext",false)));
        }
        files.push(out.file(&self.root));
        let mut out = Source::new(self.name(e, "SideEffects"));
        out.readonly();
        out.implements(ENTITY_SIDE_EFFECTS);
        for ty in [
            MUTABLE_MUTATION_CONTEXT,
            SIDE_EFFECT_EVENT,
            SIDE_EFFECT_PHASE,
        ] {
            out.import(ty);
        }
        let context = out.import(&self.name(e, "MutationContext"));
        let pre_context = out.import(&self.name(e, "PreCommitContext"));
        out.comment(&format!(
            "Runs {en}'s sideEffects, in the order the spec declares them."
        ));
        let mut ctor = method("__construct", "", "");
        for t in vals(&e["sideEffects"]) {
            let n = s(&t["name"]);
            let ty = out.import(&self.contract(e, &format!("{}SideEffect", cap(n))));
            ctor.parameters
                .push(promoted(&format!("{n}SideEffect"), &ty, false));
        }
        out.add(ctor);
        let mut lines = vec!["$handlers = [];".to_owned()];
        if !vals(&e["sideEffects"]).is_empty() {
            for t in vals(&e["sideEffects"]) {
                let events = list(&t["events"])
                    .iter()
                    .map(|v| format!("SideEffectEvent::{}", cap(s(v))))
                    .collect::<Vec<_>>()
                    .join(", ");
                lines.extend([
                    format!(
                        "if (SideEffectPhase::{} === $phase && in_array($event, [{events}], true)) {{",
                        cap(s(&t["phase"]))
                    ),
                    format!("    $handlers[] = fn () => $this->{}SideEffect->handle({}::of($context));", s(&t["name"]), if t["phase"] == "postCommit" { &context } else { &pre_context }),
                    "}".into(),
                    "".into(),
                ]);
            }
        }
        lines.push("return $handlers;".to_owned());
        out.add(
            method("handlers", "iterable", lines.join("\n").trim_end())
                .parameter(param("phase", "SideEffectPhase", false))
                .parameter(param("event", "SideEffectEvent", false))
                .parameter(param("context", "MutableMutationContext", false))
                .document(doc("@return iterable<callable(): void>")),
        );
        files.push(out.file(&self.root));
        Ok(files)
    }
    pub(crate) fn policy_handler(&self, e: &Value, p: &Value, write: bool) -> String {
        let suffix = format!(
            "{}{}Policy",
            cap(s(&p["name"])),
            if write { "Write" } else { "Read" }
        );
        if let Some(pattern) = p["origin"]["pattern"].as_str() {
            format!(
                "{}\\Pattern\\{pattern}\\Contract\\{pattern}{suffix}",
                self.root
            )
        } else {
            self.contract(e, &suffix)
        }
    }
}
fn decode_primitive(p: &str, ty: &str, value: &str, label: &str) -> String {
    if p == "enum" {
        format!("$this->decode->enum({ty}::class, {value}, {label})")
    } else {
        format!(
            "$this->decode->{}({value}, {label})",
            if p == "text" || p.is_empty() {
                "string"
            } else {
                p
            }
        )
    }
}

fn php_value(value: &Value) -> String {
    match value {
        Value::Null => "null".into(),
        Value::Bool(v) => v.to_string(),
        Value::Number(v) => v.to_string(),
        Value::String(v) => quote(v),
        Value::Array(values) => format!(
            "[{}]",
            values.iter().map(php_value).collect::<Vec<_>>().join(", ")
        ),
        Value::Object(values) => format!(
            "[{}]",
            values
                .iter()
                .map(|(k, v)| format!("{} => {}", quote(k), php_value(v)))
                .collect::<Vec<_>>()
                .join(", ")
        ),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn declared_enums_decode_without_a_processor() {
        let schema = json!({"types":{"Status":{"primitive":"string","values":["draft","published"],"hasProcessors":false}}});
        let generator = Gen {
            schema: &schema,
            root: "Example".into(),
            types: "ExampleType".into(),
        };
        let reference = json!({"primitive":null,"declaredType":"Status"});
        assert_eq!(
            generator.decode(&reference, "Status", "$value", "'Item.status'"),
            "$this->decode->enum(Status::class, $value, 'Item.status')"
        );
    }
}
