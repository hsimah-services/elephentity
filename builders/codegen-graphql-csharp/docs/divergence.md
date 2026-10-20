# Where this builder differs from `eleph-gen-graphql-php`

The manifest describes the same schema, and `tests/parity` proves the SDL is the same for `book` (which includes an integer enum),
`relations` and `clog`. These are the differences that SDL cannot show.

- **Accessors are delegates.** PHP names a method (`getTitle`) and the registrar calls it by name. The C# manifest holds a delegate onto
  the read model `eleph-gen-csharp` generated, so the C# compiler checks every accessor and nothing uses reflection. A to-one edge is
  read with `ReadAsync` (`Get<Edge>Async`), a to-many edge through `<Edge>()` and `EntityQueries.AsObjects`.
- **No `BackedEnum` encoding.** The read goes through the generated `<Enum>Wire` class, so the field's delegate returns the stored value.
- **Integer enums.** A GraphQL enum's values are the stored values: the label for a text enum (as PHP), the label's position (`"0"`,
  `"1"`) for an integer one. `eleph-gen-csharp` stores an integer enum by position and decodes input with `FromWire`, which accepts
  that numeric string, so the round trip needs no label table (`AnIntegerEnumIsStoredByPositionAndTravelsAsItsLabel` runs it on the
  memory driver). PHP's registrar maps the label for these too; its integer-enum path is not exercised by any PHP test, so the two stacks
  are proven equal on the schema only.
- **All problems at once.** PHP's `validation.rs` stops at the first problem; this one collects them, with the configuration's, in one
  response. The rules are the same.
