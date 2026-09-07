# Writing a builder

A **builder** turns an Elephentity spec into code in one language. Elephentity compiles
the spec; your builder decides what the output looks like. It can be written in anything
that can read JSON on stdin and write JSON on stdout.

**The contract lives with the program that implements it.** It is
[`docs/PROTOCOL.md` in elephentity-codegen](https://github.com/hsimah-services/elephentity-codegen/blob/main/docs/PROTOCOL.md),
which is what runs your builder, signs what it returns and writes the tree. Two copies of
a protocol description is one copy too many, and the one that drifts is always the copy
that lives away from the code.

The design and its reasoning are in [PLAN.md](PLAN.md) §15.

## The shape of it

```
eleph generate                       this repository — parses specs, resolves patterns,
  │ compiles specs → IR              produces the IR
  ▼
eleph-codegen generate               elephentity-codegen — resolves builders, runs them,
  ├→ eleph-gen-php   ─ files         signs, writes, diffs
  └→ your builder    ─ files
  ▼
generated/                           builders — IR in, source out
```

Three rules, and they are the whole of it:

1. **Read one JSON object from stdin. Write one JSON object to stdout. Exit 0.**
2. **Never touch the filesystem.** Return paths and bodies; `eleph-codegen` writes them.
   The signing that locks generated files happens after you return, so a builder that
   writes its own files is a builder whose output is not locked.
3. **Diagnostics go to stderr.** stdout is the response and nothing else.

## A reference implementation

[`elephentity-codegen-php`](https://github.com/hsimah-services/elephentity-codegen-php) is the PHP
builder. Two things about it are worth copying rather than just reading:

**It depends on nothing of Elephentity's.** The IR value objects are a copy, and the
runtime class names generated code refers to are strings rather than imports. A builder
that had to `composer require` the framework would be a builder no other language could
write — so the reference implementation does not, which is what proves the contract is a
contract.

**It commits its golden fixtures.** `tests/fixtures/golden/*/` holds a request and the
exact response it produces, asserted byte for byte through the real binary. That is the
form a specification has to take if another implementation is ever going to satisfy it.
