# Lessons from the adaptors already written

## SQLite
- `INTEGER PRIMARY KEY` is special; copied `AUTO_INCREMENT` SQL is invalid.
- Table, index and column collisions must be checked case-insensitively.
- SQL values and PHP literals need separate escaping.
- Prefix tables, references and indexes together; `project.tablePrefix` is already in IR names.
- Runtime inserts entities before linking edges; required-edge foreign keys may need a
  temporary NULL.
- Runtime deletion policy is authoritative; SQL foreign keys sit underneath.
- Initial schema checks use application-independent collations.
- A database-specific installer can be emitted without changing the runtime metadata classes.

## Cosmos / document stores
- Batch limits are hard; the adaptor throws a dedicated exception rather than splitting
  silently.
- Unique keys and keyset paging need their own tests.
- The installer refuses a populated container; backup exports into an empty one.
- Use the emulator, or document the real-account cases a test needs.

## Any new store
- Do not copy a platform-owning generator wholesale. Emit only what the spec defines.
- Do not advertise project options unless the emitted artifacts and runtime honour them.
- A manifest the builder emits and the adaptor has never loaded is not tested.
