#!/usr/bin/env python3
"""Writes the C# the builder generates for each golden request into Generated/<Name>/, one namespace each.

Run from tests/dotnet with the built binary at ../../target/{release,debug}/eleph-gen-csharp. The files are not committed:
the golden responses pin their bytes, and this proves they compile.
"""
import json
import os
import shutil
import subprocess
import sys

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(os.path.dirname(here))
fixtures = {
    "valid": "Valid",
    "clog": "Clog",
    "edge-required": "EdgeRequired",
    "patterns": "Patterns",
    "runtime-contracts": "RuntimeContracts",
}

binary = next((p for p in (f"{root}/target/release/eleph-gen-csharp", f"{root}/target/debug/eleph-gen-csharp") if os.path.exists(p)), None)

if binary is None:
    sys.exit("materialize.py: build the builder first (cargo build).")

out = os.path.join(here, "Generated")
shutil.rmtree(out, ignore_errors=True)

for fixture, name in fixtures.items():
    request = json.load(open(f"{root}/tests/fixtures/golden/{fixture}/request.json"))
    request["target"] = "csharp"
    request["config"] = {"namespace": f"Golden.{name}", "typeNamespace": f"Golden.{name}.Declared"}
    result = subprocess.run([binary], input=json.dumps(request), capture_output=True, text=True)

    if result.returncode:
        sys.exit(f"{fixture}: {result.stderr}")

    response = json.loads(result.stdout)

    if response["errors"]:
        sys.exit(f"{fixture}: " + "\n".join(response["errors"]))

    for entry in response["files"]:
        path = os.path.join(out, name, entry["path"])
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(entry["body"])

    print(f"{fixture}: {len(response['files'])} files")
