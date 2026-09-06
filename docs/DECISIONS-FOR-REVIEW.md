# Decisions made without you

Written 2026-09-05, overnight, against the instruction to assume where necessary and
flag it. Each of these could reasonably have gone the other way. Nothing here is
load-bearing enough that reversing it is expensive.

---

## 1. Named constructors on *every* generated class, including services

**Asked for:** static getters over public constructors.

**Done:** every generated class has a private constructor and a public static `of()`.

**The wrinkle.** Entities and contexts are value-ish and clearly benefit. But mutators,
finders, hydrators and bridges are dependency-injected services, and **most containers
autowire through a public constructor**. Symfony, Laravel and PHP-DI can all be told to
use a factory method, but none does so by default — so a project will need explicit
service definitions for generated services.

**Reverse it by** leaving the constructor public on the four service classes
(`MutatorGenerator`, `FinderGenerator`, `HydratorGenerator`, `BridgeGenerator`) and
keeping `of()` on entities and contexts only. One line each: drop the
`$this->emitter->namedConstructor(...)` call.

I went with consistency because you said "all files", and because a container that
cannot call a static factory is a container problem rather than a framework one. But
you will hit this the first time you wire a real project.

---

## 2. Enums stayed outside the entity folders

**Asked for:** `generated/Item/Item*.php`.

**Done:** entity-specific things moved under `Item/`, including `Item/Contract/`. Enums
did **not** — declared *and* inline enums both stay in `generated/Enum/`.

**Why.** The plan documents a property worth keeping: promoting an inline enum
(`values: [a, b]` on a field) into a declared type (`types/Foo.yml`) is a no-op in the
generated code, because both produce the same fully-qualified name. Filing inline enums
under their entity would break that — promotion would become a class move and a
breaking change for every consumer.

Type processors also stayed in `generated/Type/`, since `Money` belongs to no single
entity.

---

## 3. `Contract/` nests inside each entity

**Done:** `generated/Item/Contract/ItemPriceVerifier.php`, rather than a top-level
`Contract/` tree.

**Why.** It makes "what do I owe this entity?" answerable by listing one directory,
which is exactly the question the `phefr` skill teaches an agent to ask. The cost is
that a project-wide "what is unimplemented?" needs a glob — `ls generated/*/Contract/`
— rather than one listing. That felt like the better trade, but it is a trade.

---

## 4. Skills ship in `skills/`, symlinked into `.claude/skills/`

**Done:** canonical files live in `skills/`, so they ship inside the Composer package
and a consumer copies them. This repository symlinks `.claude/skills/phefr` →
`../../skills/phefr` so they are live here too.

**If the symlinks do not resolve** in your Claude Code setup, replace them with copies
— but then remember they are copies.

**Two skills, deliberately.** `phefr` is for working in a project that uses the
framework; `phefr-spec-author` is for turning a description into a spec. They have
different triggers and different failure modes, and one combined skill would load a
lot of irrelevant context in both cases.

---

## 5. The interview has six blocking questions

`skills/phefr-spec-author/reference/interview.md` splits questions into blocking, model
shaping, and ask-last. The six blocking ones are: storage location, whether it needs a
WordPress post type, what makes two rows the same, required-versus-nullable per field,
what is write-once, and the direction and cardinality of each relationship.

**The judgement:** those are the six a spec cannot be written without, and — more to
the point — the six that prose reliably omits. If you think something else belongs in
that tier, it is one list to edit.

I also told the skill to **raise model changes rather than absorb them**, using the
barcode case as the worked example. An agent that quietly turns "barcodes" into a JSON
blob has made a database decision on your behalf.

---

## 6. "Evaluated" read as "gradeable by the gates"

You asked for "evaluated agent operable infrastructure". I have not built an eval
harness. What I did instead is document, in `skills/phefr/reference/commands.md`, that
**the four gates are a deterministic grader** for agent-written specs: `fmt` scores
convention, `validate` scores meaning, `generate` scores producibility, `check` plus
PHPStan score coherence.

A spec clearing all four is not necessarily the right model, but it is a real one, and
anything less is objectively wrong with an error message saying why.

**If you wanted an actual eval suite** — fixture descriptions in, expected specs out,
scored automatically — that is a real piece of work and worth doing once the spec
format settles. Say the word.

---

## 7. Not done

- ~~**No changes to `PostTypeRegistrar`.**~~ **Resolved 2026-09-06.** Registration
  arguments now come from pattern configuration, and `supports` defaults to empty —
  so the post row is a projection by default rather than an editable copy.
- **No GraphQL root query fields.** Recorded as an open question in the plan.
- **No changes to the clog spec** beyond the single-barcode change you asked for.
