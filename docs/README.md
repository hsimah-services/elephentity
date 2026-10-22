# Elephentity docs index

Start from the task. Each row is one hop; nothing here needs to be read in full.

## Using Elephentity

| I want to… | Read |
|---|---|
| install it and build a first project | [GETTING_STARTED.md](GETTING_STARTED.md) |
| know what a term means | [GLOSSARY.md](GLOSSARY.md) |
| understand the build cycle and the mutation path | [MUTATION-LIFECYCLE.md](MUTATION-LIFECYCLE.md) |
| run the gates in CI | [CI.md](CI.md) |
| choose and version the Composer packages | [PACKAGES.md](PACKAGES.md) |
| validate specs in my editor | [PUBLISHING-SCHEMAS.md](PUBLISHING-SCHEMAS.md) |
| use the C# / .NET side | [`../dotnet/README.md`](../dotnet/README.md), [`../dotnet/docs/`](../dotnet/docs) |
| find a reusable pattern | [`../patterns/README.md`](../patterns/README.md) |
| have an agent do any of this | [`../skills/README.md`](../skills/README.md) |

## Extending Elephentity

| I want to add… | Skill (the how) | Doc (the why) |
|---|---|---|
| an output generator for a language or file type | [`eleph-add-builder`](../skills/eleph-add-builder/SKILL.md) | [BUILDERS.md](BUILDERS.md), [protocol](../builders/codegen/docs/PROTOCOL.md) |
| a storage adaptor / `storage.driver` | [`eleph-add-adaptor`](../skills/eleph-add-adaptor/SKILL.md) | [PLAN.md](PLAN.md) storage sections |
| an API integration | [`eleph-add-integration`](../skills/eleph-add-integration/SKILL.md) | [`../builders/codegen-graphql-php/README.md`](../builders/codegen-graphql-php/README.md) |

## Working on the framework

| I need to… | Read |
|---|---|
| set up a dev environment and run tests | [DEVELOPMENT.md](DEVELOPMENT.md), [`../CLAUDE.md`](../CLAUDE.md) |
| cut a release or understand tags | [RELEASING.md](RELEASING.md) |
| know what a change must tell other directories | [`../.llms/cross-repo.md`](../.llms/cross-repo.md) |
| see why the PHP and Rust compilers differ | [divergence.md](divergence.md) |
| see every design decision and its reasoning | [PLAN.md](PLAN.md) (long: search it, do not read it) |
| see open questions | [DECISIONS-FOR-REVIEW.md](DECISIONS-FOR-REVIEW.md) |
