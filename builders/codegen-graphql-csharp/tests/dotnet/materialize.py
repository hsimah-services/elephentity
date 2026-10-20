#!/usr/bin/env python3
"""Writes, for each fixture, the C# entities `eleph-gen-csharp` makes and the GraphQL manifest this builder makes from the same IR.

    Generated/<Name>/Entities/...   the read models, contracts, catalogue and wiring (the C# target)
    Generated/<Name>/GraphQL/...    GraphQLManifest.cs (this target)

Run from anywhere with this builder built (cargo build) and the C# builder's binary named by ELEPH_GEN_CSHARP (default:
../codegen-csharp/target/debug/eleph-gen-csharp). Neither is committed: the expected/ trees pin the
manifest's bytes, and this proves they compile against the entities and run.
"""
import json
import os
import shutil
import subprocess
import sys

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(os.path.dirname(here))
fixtures = {"book": "Book", "relations": "Relations", "clog": "Clog"}


def binary(name, override=None):
    candidates = [override] if override else []
    candidates += [f"{root}/target/release/{name}", f"{root}/target/debug/{name}"]
    found = next((p for p in candidates if p and os.path.exists(p)), None)

    if found is None:
        sys.exit(f"materialize.py: cannot find {name}; build it first.")

    return found


def run(program, request):
    result = subprocess.run([program], input=json.dumps(request), capture_output=True, text=True)

    if result.returncode:
        sys.exit(f"{program}: {result.stderr}")

    response = json.loads(result.stdout)

    if response["errors"]:
        sys.exit("\n".join(response["errors"]))

    return response["files"]


csharp = os.environ.get("ELEPH_GEN_CSHARP") or f"{root}/../codegen-csharp/target/debug/eleph-gen-csharp"

if not os.path.exists(csharp):
    sys.exit(f"materialize.py: the C# builder is not at {csharp}; set ELEPH_GEN_CSHARP.")

graphql = binary("eleph-gen-graphql-csharp")
out = os.path.join(here, "Generated")
shutil.rmtree(out, ignore_errors=True)

for fixture, name in fixtures.items():
    request = json.load(open(f"{root}/tests/fixtures/{fixture}/request.json"))

    entities = dict(request, target="csharp", config={"namespace": f"Golden.{name}", "typeNamespace": f"Golden.{name}.Declared"})
    plans = [("Entities", csharp, entities), ("GraphQL", graphql, request)]

    for folder, program, body in plans:
        files = run(program, body)

        for entry in files:
            path = os.path.join(out, name, folder, entry["path"])
            os.makedirs(os.path.dirname(path), exist_ok=True)
            open(path, "w").write(entry["body"])

        print(f"{fixture}/{folder}: {len(files)} file(s)")
