#!/usr/bin/env python3
"""Writes the manifest the builder generates for each fixture into Generated/<Name>/CosmosStorageManifest.cs.

Run from anywhere with the built binary at target/{release,debug}/eleph-gen-cosmos. The files are not committed: the
expected/ trees pin their bytes, and this proves they load into Elephentity.Cosmos.
"""
import json
import os
import shutil
import subprocess
import sys

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(os.path.dirname(here))
fixtures = ["clog", "relations", "one-to-one", "many-to-one", "one-to-many", "many-to-many"]

binary = next((p for p in (f"{root}/target/release/eleph-gen-cosmos", f"{root}/target/debug/eleph-gen-cosmos") if os.path.exists(p)), None)

if binary is None:
    sys.exit("materialize.py: build the builder first (cargo build).")

out = os.path.join(here, "Generated")
shutil.rmtree(out, ignore_errors=True)

for fixture in fixtures:
    request = open(f"{root}/tests/fixtures/{fixture}/request.json").read()
    result = subprocess.run([binary], input=request, capture_output=True, text=True)

    if result.returncode:
        sys.exit(f"{fixture}: {result.stderr}")

    response = json.loads(result.stdout)

    if response["errors"]:
        sys.exit(f"{fixture}: " + "\n".join(response["errors"]))

    for entry in response["files"]:
        path = os.path.join(out, "".join(p.capitalize() for p in fixture.split("-")), entry["path"])
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(entry["body"])

    print(f"{fixture}: {len(response['files'])} file")
