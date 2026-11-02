//! Small construction helpers; all PHP declarations are printed by php_codegen.
pub use php_codegen;
use php_codegen::{
    class::Class,
    comment::Document,
    data_type::DataType,
    method::Method,
    modifiers::{Modifier, VisibilityModifier},
    parameter::Parameter,
    Generator, Indentation,
};
use serde_json::{json, Value};
use std::collections::{BTreeMap, BTreeSet};
pub fn short(s: &str) -> &str {
    s.rsplit('\\').next().unwrap_or(s)
}
pub fn doc(s: &str) -> Document {
    let mut d = Document::new();
    for line in s.lines() {
        d = if line.is_empty() {
            d.empty_line()
        } else {
            d.text(line)
        };
    }
    d
}
pub fn param(name: &str, ty: &str, nullable: bool) -> Parameter {
    Parameter::new(name).typed(DataType::Named(format!(
        "{}{ty}",
        if nullable { "?" } else { "" }
    )))
}
pub fn promoted(name: &str, ty: &str, readonly: bool) -> Parameter {
    let p = param(name, ty, false).private();
    if readonly {
        p.modifier(Modifier::Readonly)
    } else {
        p
    }
}
pub fn method(name: &str, ty: &str, body: &str) -> Method {
    let m = Method::new(name).public().body(body);
    if ty.is_empty() {
        m
    } else {
        m.returns(DataType::Named(ty.into()))
    }
}
pub fn declaration(name: &str, ty: &str) -> Method {
    Method::new(name)
        .public()
        .returns(DataType::Named(ty.into()))
}
pub struct Source {
    pub fq: String,
    pub class: Class,
    pub imports: BTreeSet<String>,
    /// Imports whose short name another import already holds, and the alias each is used under.
    pub aliases: BTreeMap<String, String>,
    pub kind: &'static str,
}
impl Source {
    pub fn new(fq: String) -> Self {
        let class = Class::new(short(&fq)).modifier(Modifier::Final);
        Self {
            fq,
            class,
            imports: BTreeSet::new(),
            aliases: BTreeMap::new(),
            kind: "class",
        }
    }
    pub fn import(&mut self, name: &str) -> String {
        if name.contains('\\')
            || [
                "DateTimeImmutable",
                "InvalidArgumentException",
                "RuntimeException",
                "LogicException",
                "Closure",
            ]
            .contains(&name)
        {
            if let Some(alias) = self.aliases.get(name) {
                return alias.clone();
            }
            if !self.imports.contains(name) {
                let taken = |candidate: &str| {
                    candidate == short(&self.fq)
                        || self.imports.iter().any(|u| {
                            self.aliases.get(u).map_or(short(u), String::as_str) == candidate
                        })
                };
                if taken(short(name)) {
                    // Two classes with one short name (Page\Contract\PageItemKindVerifier and
                    // PageItem\Contract\PageItemKindVerifier): the later one is used under its
                    // namespace segments, nearest first and at least two of them, so the alias
                    // names its owner (PageItemContractPageItemKindVerifier), until it is free.
                    let segments: Vec<&str> = name.split('\\').collect();
                    let mut alias = short(name).to_owned();
                    for (depth, segment) in segments.iter().rev().skip(1).enumerate() {
                        alias = format!("{segment}{alias}");
                        if depth >= 1 && !taken(&alias) {
                            break;
                        }
                    }
                    self.aliases.insert(name.into(), alias.clone());
                    self.imports.insert(name.into());
                    return alias;
                }
            }
            self.imports.insert(name.into());
        }
        short(name).into()
    }
    pub fn add(&mut self, m: Method) {
        self.class.methods.push(m);
    }
    pub fn comment(&mut self, s: &str) {
        self.class.documentation = Some(doc(s));
    }
    pub fn readonly(&mut self) {
        self.class.modifiers.push(Modifier::Readonly);
    }
    pub fn implements(&mut self, s: &str) {
        let s = self.import(s);
        self.class.implements.push(s);
    }
    pub fn named_constructor(&mut self) {
        let ctor = &mut self.class.methods[0];
        ctor.visibility = Some(VisibilityModifier::Private);
        let mut m = method(
            "of",
            "self",
            &format!(
                "return new self({});",
                ctor.parameters
                    .iter()
                    .map(|p| format!("${}", p.name))
                    .collect::<Vec<_>>()
                    .join(", ")
            ),
        )
        .modifier(Modifier::Static);
        for p in &ctor.parameters {
            let ty = p
                .data_type
                .as_ref()
                .map(|t| t.generate(Indentation::default(), 0))
                .unwrap_or_default();
            let mut copy = param(&p.name, &ty, false);
            if p.default.is_some() {
                copy = copy.default(php_codegen::literal::Value::Null);
            }
            m.parameters.push(copy);
        }
        self.add(m);
    }
    pub fn file(self, root: &str) -> Value {
        let ns = self.fq.rsplit_once('\\').unwrap().0;
        let mut body = format!("namespace {ns};\n\n");
        for u in &self.imports {
            if let Some(alias) = self.aliases.get(u) {
                body.push_str(&format!("use {u} as {alias};\n"));
                continue;
            }
            if u.rsplit_once('\\').is_some_and(|(parent, _)| parent == ns) {
                continue;
            }
            body.push_str(&format!("use {u};\n"));
        }
        if body.ends_with(";\n") {
            body.push('\n');
        }
        let printed = match self.kind {
            "interface" => {
                let mut i = php_codegen::interface::Interface::new(self.class.name);
                i.documentation = self.class.documentation;
                i.extends = self.class.extends;
                i.methods = self.class.methods;
                i.generate(Indentation::default(), 0)
            }
            "trait" => {
                let mut t = php_codegen::r#trait::Trait::new(self.class.name);
                t.documentation = self.class.documentation;
                t.methods = self.class.methods;
                t.generate(Indentation::default(), 0)
            }
            _ => self.class.generate(Indentation::default(), 0),
        };
        body.push_str(&printed);
        let body = body
            .split('\n')
            .map(|line| line.trim_end_matches([' ', '\t']))
            .collect::<Vec<_>>()
            .join("\n");
        json!({"path": format!("{}.php", self.fq.strip_prefix(&format!("{}\\", root.trim_matches('\\'))).unwrap().replace('\\', "/")), "body": body})
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_second_class_with_a_taken_short_name_is_imported_under_an_alias() {
        let mut out = Source::new(r"App\Wiring".into());

        assert_eq!(
            out.import(r"App\Page\Contract\PageItemKindVerifier"),
            "PageItemKindVerifier"
        );
        assert_eq!(
            out.import(r"App\PageItem\Contract\PageItemKindVerifier"),
            "PageItemContractPageItemKindVerifier"
        );
        assert_eq!(
            out.import(r"App\PageItem\Contract\PageItemKindVerifier"),
            "PageItemContractPageItemKindVerifier"
        );
        assert_eq!(
            out.import(r"App\Page\Contract\PageItemKindVerifier"),
            "PageItemKindVerifier"
        );

        let body = out.file("App")["body"].as_str().unwrap().to_owned();

        assert!(body.contains("use App\\Page\\Contract\\PageItemKindVerifier;\n"));
        assert!(body.contains(
            "use App\\PageItem\\Contract\\PageItemKindVerifier as PageItemContractPageItemKindVerifier;\n"
        ));
    }
}
