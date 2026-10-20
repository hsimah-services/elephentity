# Pattern behavior and entity parameters

Status: accepted scope; compiler and generator implementation pending. The YAML
below is proposed syntax, not supported by Elephentity 0.11. Catalog entries that
need this feature remain `planned` and cannot be copied by the catalog tool.

## Contract ownership

`contract: abstract | extendable | shared` selects ownership of a pattern's
behavior contracts. It is independent of the generated read interface.

| Mode | Pattern author | Consuming application |
| --- | --- | --- |
| `abstract` | Declares the signatures | Implements each consuming entity's contracts |
| `extendable` | Supplies an abstract base with shared code and extension hooks | Extends an entity-specific generated subclass |
| `shared` | Supplies a single concrete implementation | Supplies dependencies, without per-entity handler overrides |

An extendable contract must be enforced by class inheritance and boot checks;
PHP interfaces cannot require a particular base class. The author can make the
entry point final to guarantee that extension hooks cannot bypass invariants.
Separate handler classes allow entities to apply multiple extendable patterns.

The default applies to behavior declared by that pattern, not behavior originating
in a dependency. Omitting the setting must preserve existing behavior, including
the existing shared pattern policy contracts. Explicit `abstract` makes consumer
ownership deliberate. This compatibility rule must be tested.

Handwritten code is application-owned after copying. Generated code must never
overwrite it. Implementation class mappings belong in target configuration, not
in the portable pattern spec. The final mapping syntax is still to be implemented.
`shared` means one implementation selection per pattern behavior, not a forced
singleton lifetime for dependency injection.

## HasCreator

```yaml
pattern: HasCreator
config:
  creatorType:
    type: entity
    default: User
edges:
  creator:
    to: { config: creatorType }
    cardinality: one
    required: true
contract: shared
```

```yaml
entity: Article
use: [HasCreator]
configure:
  HasCreator:
    creatorType: Account
```

`User` is an explicit default in this pattern, never a generator convention. An
override or default must name an entity in the compiled project. A missing value
without a default, an unknown entity, and a reference to a non-entity parameter
must each produce a compilation diagnostic with the pattern and consumer named.
An explicit invalid override must not silently fall back to `User`.

The compiler resolves each application before semantic edge validation and code
generation. One project can apply HasCreator to Article with Account and Comment
with User. Neither application may mutate the other's pattern definition.
Generated entity getters must retain their concrete target types.

Shared code must not assume one concrete target type. The shared read interface
needs a compatible common return type (with precise concrete entity getters and
static-analysis generics where useful), or explicit generated specializations.
This decision must be settled before emitting the new IR. Reusing whichever
consumer was compiled first is incorrect.

The invariant is: a creator is supplied on creation, and mutations cannot clear
it. Replacing it with another creator is permitted; immutable ownership would be
a separate rule. Untouched edges on update must remain valid: current
`pendingEdge()` reports changes, not the complete persisted relationship.

Validation must inspect final pending state after all pre-commit handlers, before
storage. A pre-commit side effect alone is insufficient because a later handler
could clear the edge. A pattern-level mutation verifier is therefore in scope,
including registration, aggregated violations, and create/update dispatch.
Delete should not require a creator. Deleting the creator entity must respect
the relationship's deletion policy; implicit nullification must not evade the rule.

## Implementation sequence and acceptance

1. Catalog, selective source copying, provenance, and compiler-checked basic patterns.
2. Entity parameters: schema, parsing, resolution, formatting, and diagnostics.
   Test the default, override, missing target, wrong parameter kind, and two consumers.
3. Pattern behavior IR and contract modes: update every independent decoder and
   version gate together. Older builders must reject unsupported semantics.
4. Shared contexts, generated inheritance and handler wiring, implementation
   mapping, and boot checks. Test all three modes with two consuming entities;
   reject shared overrides and extendable implementations without the base class.
5. Final-state pattern verification. Test missing creator, explicit clearing,
   untouched updates, replacement, later pre-commit clearing, and deletion.
6. Ship HasCreator source and tests, regenerate Clog, and run compiler, Rust,
   reference-generator, runtime, and application conformance checks.

Catalog updates are reviewable source diffs. Automatic updates, package publishing,
and a production dependency on this repository are not needed for this first version.
