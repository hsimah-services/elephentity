# Elephentity
![Elephentity logo](./elephentity.svg)

An AI-native framework for building PHP business logic. Utilizes layers of agentic
rules and skills to turn human readable specs into consistent, scalable and reliable
underlying code.

The command is `eleph`; the PHP namespace is `Eleph\`.

## Start here

- **[docs/PLAN.md](docs/PLAN.md)** — every design decision and the reasoning behind it
- [docs/GLOSSARY.md](docs/GLOSSARY.md) — what every term means, and why
- [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) — laying out a project
- [docs/CI.md](docs/CI.md) — the four gates
- [docs/PUBLISHING-SCHEMAS.md](docs/PUBLISHING-SCHEMAS.md) — hosting the JSON Schemas for editor validation
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — working on the framework itself
- [examples/clog](examples/clog) — real post types spec'd, with the generated output

## Agent skills

Elephentity ships skills that teach an agent to use it, in [skills/](skills):

- `eleph` — the spec format, the build loop, finding what is left to implement
- `eleph-spec-author` — turning a written description into a spec, and what to ask first

```bash
cp -r vendor/elephentity/elephentity/skills/* .claude/skills/
```
