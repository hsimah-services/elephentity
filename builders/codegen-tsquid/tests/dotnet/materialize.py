#!/usr/bin/env python3
"""Writes the C# the builder makes for each test manifest into Generated/<Name>/TsquidManifest.cs.

Run with the builder built (cargo build). The files are not committed: tests/fixtures/golden/csharp pins their bytes, and this proves
they load into Elephentity.Tsquid and answer every vector.
"""
import json
import os
import shutil
import subprocess
import sys

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(os.path.dirname(here))
manifests = {"vectors": "Vectors", "starter": "Starter"}

binary = next((p for p in (f"{root}/target/release/eleph-gen-tsquid", f"{root}/target/debug/eleph-gen-tsquid") if os.path.exists(p)), None)

if binary is None:
    sys.exit("materialize.py: build the builder first (cargo build).")

out = os.path.join(here, "Generated")
shutil.rmtree(out, ignore_errors=True)

for manifest, name in manifests.items():
    request = {
        "elephentity": 1,
        "irVersion": "1.2",
        "request": "generate",
        "target": "tsquid",
        "config": {"language": "csharp", "namespace": f"Golden.{name}", "manifest": f"../{manifest}.manifest.json"},
        "outputDirectory": f"{root}/tests/fixtures/out",
        "schema": {"project": {"integrations": {"tsquid": None}}},
    }
    result = subprocess.run([binary], input=json.dumps(request), capture_output=True, text=True)

    if result.returncode:
        sys.exit(f"{manifest}: {result.stderr}")

    response = json.loads(result.stdout)

    if response["errors"]:
        sys.exit(f"{manifest}: " + "\n".join(response["errors"]))

    for entry in response["files"]:
        path = os.path.join(out, name, entry["path"])
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(entry["body"])

    print(f"{manifest}: {len(response['files'])} file")
