# Decisions made without you

Written 2026-09-05, overnight, against the instruction to assume where necessary and
flag it. Reviewed 2026-09-06: two changed, two stood, three parked until real use
supplies the evidence to decide them.

---

## 1. Named constructors ~~on every generated class~~ ✅ RESOLVED 2026-09-06

**Settled:** split by who instantiates. Entities, action contexts and mutation contexts
are sealed behind `of()`; mutators, finders, hydrators and bridges keep public
constructors so containers autowire them without per-entity service definitions.

---

## 2. Enums stayed outside the entity folders ✅ RESOLVED 2026-09-06

**Settled:** keep them in `generated/Enum/` and `generated/Type/`. The free-promotion
property is worth more than a tidier `Item/` folder, and jump-to-definition makes the
split invisible in practice.

The one cost: `Enum` and `Type` are now reserved entity names, which the compiler
rejects rather than letting the collision surface as a confusing tree.

---

## 3. `Contract/` nests inside each entity

**Done:** `generated/Item/Contract/ItemPriceVerifier.php`, rather than a top-level
`Contract/` tree.

**Why.** It makes "what do I owe this entity?" answerable by listing one directory,
which is exactly the question the `eleph` skill teaches an agent to ask. The cost is
that a project-wide "what is unimplemented?" needs a glob — `ls generated/*/Contract/`
— rather than one listing. That felt like the better trade, but it is a trade.

---

## 4. Skills ship in `skills/`, symlinked into `.claude/skills/` ⏸ DEFERRED 2026-09-06

**Settled for now:** leave the arrangement as it is. How skills should be distributed
and kept current is not answerable in the abstract — real use will show where the
friction is, and that is when to decide.

Two things to watch when it does: whether a copied skill going stale actually bites,
and whether both skills reliably load (one turn listed only `eleph`).

---

## 5. The interview has six blocking questions ⏸ DEFERRED 2026-09-06

**Settled for now:** leave the tiers as written. Which questions genuinely block a spec
is a call that needs evidence from writing specs, not from reasoning about writing
them.

The two things to watch: whether `indexed` belongs in the blocking tier (adding an
index later is easy; realising you needed one is not), and whether "raise model changes
rather than absorb them" interrupts too often to be worth it.

---

## 6. "Evaluated" read as "gradeable by the gates" ⏸ PARKED 2026-09-06

**Settled for now:** the four gates stay the grader. An eval suite is wanted long term
— this is a deferral, not a rejection — but a corpus built before the interview has met
real specs would enshrine our current guesses about modelling as the expected answers,
and then defend them.

Revisit once enough real specs exist that a corpus can be drawn from experience.

---

## 7. Not done ✅ REVIEWED 2026-09-06

- ~~**No changes to `PostTypeRegistrar`.**~~ **Resolved.** Registration arguments now
  come from pattern configuration, and `supports` defaults to empty — so the post row
  is a projection by default rather than an editable copy.
- ~~**No changes to the clog spec.**~~ **Resolved:** one barcode per item.
- **No GraphQL root query fields.** Stands, and is the largest remaining hole in the
  vertical slice: entities can be mutated through the generated API but not fetched.
  Tracked in [PLAN.md](PLAN.md) §16 as a gap rather than a judgement call.
