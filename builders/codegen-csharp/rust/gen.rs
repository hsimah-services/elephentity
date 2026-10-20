//! The state every emitter shares: the schema, the namespaces, and what has been claimed so far.

use crate::cs::File;
use crate::names::{pascal, TypeRegistry};
use crate::runtime::*;
use serde_json::Value;
use std::cell::RefCell;

pub type Result<T> = std::result::Result<T, String>;

pub fn s(v: &Value) -> &str {
    v.as_str().unwrap_or("")
}

pub fn b(v: &Value) -> bool {
    v.as_bool().unwrap_or(false)
}

/// The values of a JSON object, in order; an array (the empty map's other spelling) has none.
pub fn vals(v: &Value) -> Vec<&Value> {
    v.as_object()
        .map(|m| m.values().collect())
        .unwrap_or_default()
}

pub fn list(v: &Value) -> Vec<&Value> {
    v.as_array().map(|a| a.iter().collect()).unwrap_or_default()
}

/// How a value is held in C#, which decides how it is cast, decoded and written.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Kind {
    Text,
    Long,
    Double,
    Bool,
    Json,
    DateTime,
    Id,
    /// A generated enum.
    Enum,
    /// A type the application declares: a class or record in the type namespace, which it implements processors for.
    Declared,
}

/// A C# type for a field or an argument.
#[derive(Clone, Debug)]
pub struct Ty {
    pub name: String,
    pub kind: Kind,
}

impl Ty {
    pub fn with(&self, nullable: bool) -> String {
        if nullable {
            format!("{}?", self.name)
        } else {
            self.name.clone()
        }
    }
}

pub struct Gen<'a> {
    pub schema: &'a Value,
    pub root: String,
    pub types: String,
    pub project: String,
    errors: RefCell<Vec<String>>,
    registry: RefCell<TypeRegistry>,
}

impl<'a> Gen<'a> {
    pub fn new(schema: &'a Value, root: String, types: String) -> Self {
        let project = pascal(s(&schema["project"]["name"]));

        Self {
            schema,
            root,
            types,
            project,
            errors: RefCell::new(Vec::new()),
            registry: RefCell::new(TypeRegistry::default()),
        }
    }

    pub fn fail_all(&self, errors: Vec<String>) {
        self.errors.borrow_mut().extend(errors);
    }

    /// Everything that went wrong, in order: collisions first noticed, then the registry's.
    pub fn problems(&self) -> Vec<String> {
        let mut all = self.errors.borrow().clone();
        all.extend(self.registry.borrow().errors.clone());
        all
    }

    pub fn claim(&self, full_name: &str, what: &str) {
        self.registry.borrow_mut().claim(full_name, what);
    }

    /// A new file in `namespace`, whose type has been claimed.
    pub fn file(&self, path: String, namespace: &str, type_name: &str, what: &str) -> File {
        self.claim(&format!("{namespace}.{type_name}"), what);

        File::new(path, namespace)
    }

    // ----- namespaces ----------------------------------------------------------------------------------------

    pub fn contract_ns(&self) -> String {
        format!("{}.Contract", self.root)
    }

    pub fn enum_ns(&self) -> String {
        format!("{}.Enums", self.root)
    }

    pub fn processor_ns(&self) -> String {
        format!("{}.Types", self.root)
    }

    pub fn pattern_ns(&self) -> String {
        format!("{}.Pattern", self.root)
    }

    pub fn pattern_contract_ns(&self) -> String {
        format!("{}.Pattern.Contract", self.root)
    }

    // ----- names ---------------------------------------------------------------------------------------------

    pub fn entity(&self, name: &str) -> &Value {
        &self.schema["entities"][name]
    }

    pub fn entities(&self) -> Vec<&Value> {
        vals(&self.schema["entities"])
    }

    /// The entity's own name as a type: `Item`.
    pub fn type_name(&self, e: &Value) -> String {
        pascal(s(&e["name"]))
    }

    /// A class generated for an entity: `ItemInput`.
    pub fn class(&self, e: &Value, suffix: &str) -> String {
        format!("{}{suffix}", self.type_name(e))
    }

    /// An interface the application implements for an entity: `IItemSearchQuery`.
    pub fn contract(&self, e: &Value, suffix: &str) -> String {
        format!("I{}{suffix}", self.type_name(e))
    }

    /// Where an entity's file goes: `Item/ItemInput.cs`.
    pub fn path(&self, e: &Value, type_name: &str) -> String {
        format!("{}/{type_name}.cs", self.type_name(e))
    }

    pub fn contract_path(&self, e: &Value, type_name: &str) -> String {
        format!("{}/Contract/{type_name}.cs", self.type_name(e))
    }

    /// A generated class by its short name, which resolves because every entity artifact is in the root namespace.
    pub fn fq_contract(&self, e: &Value, suffix: &str) -> String {
        format!(
            "global::{}.{}",
            self.contract_ns(),
            self.contract(e, suffix)
        )
    }

    pub fn pattern_interface(&self, name: &str) -> String {
        format!("I{}", pascal(name))
    }

    pub fn fq_pattern(&self, name: &str) -> String {
        format!(
            "global::{}.{}",
            self.pattern_ns(),
            self.pattern_interface(name)
        )
    }

    pub fn fq_pattern_policy(&self, pattern: &str, policy: &Value, write: bool) -> String {
        format!(
            "global::{}.{}",
            self.pattern_contract_ns(),
            self.pattern_policy_name(pattern, policy, write)
        )
    }

    pub fn pattern_policy_name(&self, pattern: &str, policy: &Value, write: bool) -> String {
        format!(
            "I{}{}{}Policy",
            pascal(pattern),
            pascal(s(&policy["name"])),
            if write { "Write" } else { "Read" }
        )
    }

    pub fn policy_contract_name(&self, e: &Value, policy: &Value, write: bool) -> String {
        self.contract(
            e,
            &format!(
                "{}{}Policy",
                pascal(s(&policy["name"])),
                if write { "Write" } else { "Read" }
            ),
        )
    }

    /// The interface an entity's policy is implemented against: the entity's own, or its pattern's.
    pub fn fq_policy(&self, e: &Value, policy: &Value, write: bool) -> String {
        match policy["origin"]["pattern"].as_str() {
            Some(pattern) => self.fq_pattern_policy(pattern, policy, write),
            None => format!(
                "global::{}.{}",
                self.contract_ns(),
                self.policy_contract_name(e, policy, write)
            ),
        }
    }

    // ----- types ---------------------------------------------------------------------------------------------

    pub fn declared(&self, name: &str) -> &Value {
        &self.schema["types"][name]
    }

    pub fn is_enum_type(&self, name: &str) -> bool {
        !self.declared(name)["values"].is_null()
    }

    /// The C# type of an enum declared by the project or inline on a field.
    pub fn enum_type(&self, name: &str) -> String {
        format!("global::{}.{}", self.enum_ns(), pascal(name))
    }

    /// The enum's name for a field: the declared type's, or `EntityField` for inline values.
    pub fn field_enum_name(&self, e: &Value, f: &Value) -> Result<String> {
        let source = &f["enum"];

        if source.is_null() {
            return Err(format!(
                "Enum field {}.{} reached codegen without values; validation should have caught it.",
                s(&e["name"]),
                s(&f["name"])
            ));
        }

        Ok(if source["inlineValues"].is_null() {
            pascal(s(&source["declaredType"]))
        } else if let Some(pattern) = f["origin"]["pattern"].as_str() {
            // A pattern's field is one type for every entity that uses it, or the pattern's interface could not be satisfied.
            format!("{}{}", pascal(pattern), pascal(s(&f["name"])))
        } else {
            format!("{}{}", pascal(s(&e["name"])), pascal(s(&f["name"])))
        })
    }

    /// The type a type reference means: a primitive, an enum, or a declared type.
    pub fn reference(&self, r: &Value) -> Result<Ty> {
        let primitive = s(&r["primitive"]);

        Ok(match primitive {
            "string" | "text" => Ty {
                name: "string".into(),
                kind: Kind::Text,
            },
            "int" => Ty {
                name: "long".into(),
                kind: Kind::Long,
            },
            "float" => Ty {
                name: "double".into(),
                kind: Kind::Double,
            },
            "bool" => Ty {
                name: "bool".into(),
                kind: Kind::Bool,
            },
            "json" => Ty {
                name: JSON_ELEMENT.into(),
                kind: Kind::Json,
            },
            "datetime" => Ty {
                name: DATE_TIME_OFFSET.into(),
                kind: Kind::DateTime,
            },
            "id" => Ty {
                name: ENTITY_ID.into(),
                kind: Kind::Id,
            },
            "enum" => {
                return Err(
                    "Enums are resolved from their field, not from the primitive alone.".into(),
                );
            }
            _ => {
                let name = s(&r["declaredType"]);
                let declared = self.declared(name);

                if declared.is_null() {
                    return Err(format!(
                        "Unknown type \"{name}\" reached codegen; validation should have caught it."
                    ));
                }

                if !declared["values"].is_null() {
                    Ty {
                        name: self.enum_type(name),
                        kind: Kind::Enum,
                    }
                } else if b(&declared["hasProcessors"]) {
                    Ty {
                        name: format!("global::{}.{}", self.types, pascal(name)),
                        kind: Kind::Declared,
                    }
                } else {
                    // No processors: nobody can turn the stored value into a class, so it stays the stored kind.
                    self.reference(&serde_json::json!({"primitive": s(&declared["primitive"]), "declaredType": null}))?
                }
            }
        })
    }

    pub fn field_ty(&self, e: &Value, f: &Value) -> Result<Ty> {
        if f["type"]["primitive"] == "enum" {
            return Ok(Ty {
                name: self.enum_type(&self.field_enum_name(e, f)?),
                kind: Kind::Enum,
            });
        }

        self.reference(&f["type"])
    }

    /// What a stored value of a declared type is in the database: the kind a processor reads and writes.
    pub fn stored(&self, type_name: &str) -> (&'static str, &'static str) {
        match s(&self.declared(type_name)["primitive"]) {
            "int" => ("long", "Whole"),
            "float" => ("double", "Number"),
            "bool" => ("bool", "Bool"),
            _ => ("string", "Text"),
        }
    }

    /// The processor interfaces of a declared type that has them (and is not an enum).
    pub fn processor_types(&self) -> Vec<&Value> {
        vals(&self.schema["types"])
            .into_iter()
            .filter(|t| b(&t["hasProcessors"]) && t["values"].is_null())
            .collect()
    }

    pub fn read_processor(&self, type_name: &str) -> String {
        format!(
            "global::{}.I{}ReadProcessor",
            self.processor_ns(),
            pascal(type_name)
        )
    }

    pub fn write_processor(&self, type_name: &str) -> String {
        format!(
            "global::{}.I{}WriteProcessor",
            self.processor_ns(),
            pascal(type_name)
        )
    }

    /// Declared types, with processors, that an entity's hydrator (or input) must read.
    pub fn readers(&self, e: &Value, input: bool) -> Vec<String> {
        let mut types: Vec<String> = Vec::new();
        let mut fields = vals(&e["fields"]);

        if input {
            fields.retain(|f| f["managed"].is_null());

            for a in vals(&e["actions"]) {
                fields.extend(vals(&a["arguments"]));
            }

            for q in vals(&e["queries"]) {
                fields.extend(vals(&q["arguments"]));
            }
        }

        for f in fields {
            let name = s(&f["type"]["declaredType"]);
            let declared = self.declared(name);

            if !name.is_empty()
                && declared["values"].is_null()
                && b(&declared["hasProcessors"])
                && !types.iter().any(|t| t == name)
            {
                types.push(name.to_string());
            }
        }

        types
    }

    pub fn reader_field(&self, type_name: &str) -> String {
        format!("{}Reader", crate::names::camel(type_name))
    }

    /// An expression turning `value` (an `object?`) into the type `r` means. Raises with `label` when it cannot.
    pub fn decode(
        &self,
        r: &Value,
        ty: &Ty,
        value: &str,
        label: &str,
        enum_name: Option<&str>,
    ) -> String {
        let primitive = s(&r["primitive"]);
        let name = s(&r["declaredType"]);

        if primitive == "enum" || (!name.is_empty() && self.is_enum_type(name)) {
            let enum_name = enum_name
                .map(str::to_string)
                .unwrap_or_else(|| pascal(name));

            return format!(
                "{VALUE_DECODER}.Enum<{}>({value}, {label}, global::{}.{}Wire.FromWire)",
                ty.name,
                self.enum_ns(),
                enum_name
            );
        }

        if !name.is_empty() && b(&self.declared(name)["hasProcessors"]) {
            let (_, via) = self.stored(name);

            return format!(
                "{}.Read({VALUE_DECODER}.{via}({value}, {label}))",
                self.reader_field(name)
            );
        }

        let via = match ty.kind {
            Kind::Text => "Text",
            Kind::Long => "Whole",
            Kind::Double => "Number",
            Kind::Bool => "Bool",
            Kind::Json => "Json",
            Kind::DateTime => "DateTime",
            Kind::Id => "Id",
            Kind::Enum | Kind::Declared => "Text",
        };

        format!("{VALUE_DECODER}.{via}({value}, {label})")
    }

    /// `object?` to `T` (or `T?`): a cast, so a value of the wrong type is a loud error.
    pub fn unbox(&self, ty: &Ty, value: &str, nullable: bool) -> String {
        if nullable {
            format!("({}?){value}", ty.name)
        } else {
            format!("({}){value}!", ty.name)
        }
    }
}
