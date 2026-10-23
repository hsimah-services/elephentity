#!/usr/bin/env python3
"""Writes the manifest the builder generates for each fixture into Generated/<Name>/SqliteStorageManifest.cs.

Run from anywhere with the built binary at target/{release,debug}/eleph-gen-sqlite. The files are not committed: the
tests/fixtures/csharp trees pin their bytes, and this proves they load into Elephentity.Sqlite and make a database that works.

Besides the fixtures, it generates the variants tests/runtime.php builds for PHP (constraints, defaults, integer enums), so the
two languages are held to the same cases.
"""
import copy
import json
import os
import shutil
import subprocess
import sys

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(os.path.dirname(here))
fixtures = ["clog", "relations", "one-to-one", "many-to-one", "one-to-many", "many-to-many"]

binary = next((p for p in (f"{root}/target/release/eleph-gen-sqlite", f"{root}/target/debug/eleph-gen-sqlite") if os.path.exists(p)), None)

if binary is None:
    sys.exit("materialize.py: build the builder first (cargo build).")


def load(name):
    return json.load(open(f"{root}/tests/fixtures/{name}.json"))


def constraints():
    request = load("many-to-one")
    owner = request["schema"]["entities"]["Owner"]["fields"]
    owner["name"]["unique"] = True
    owner["name"]["maxLength"] = 5
    owner["rank"]["type"]["primitive"] = "bool"
    name = request["schema"]["entities"]["Target"]["fields"]["name"]
    name["type"]["primitive"] = "enum"
    name["enum"] = {"inlineValues": ["plain", "quote'\"{prefix}\\"], "declaredType": None}
    return request


def defaults():
    request = load("many-to-one")
    owner = request["schema"]["entities"]["Owner"]["fields"]
    owner["checked"] = {"name": "checked", "type": {"primitive": "bool", "declaredType": None}, "origin": {"pattern": None, "file": "fixture.yml"}, "nullable": False, "hasDefault": True, "default": False}
    owner["rank"].update({"hasDefault": True, "default": 7})
    owner["name"].update({"hasDefault": True, "default": "it's {prefix}\\"})
    return request


def enums(primitive):
    request = load("many-to-one")
    request["schema"]["types"] = {"PostStatus": {"name": "PostStatus", "primitive": "int", "values": ["draft", "scheduled", "published"], "sourceFile": "status.yml"}}
    name = request["schema"]["entities"]["Target"]["fields"]["name"]
    name["type"] = {"primitive": primitive, "declaredType": "PostStatus"}
    name["enum"] = {"inlineValues": None, "declaredType": "PostStatus"}
    return request


requests = {name: load(name) for name in fixtures}
requests.update({"constraints": constraints(), "defaults": defaults(), "enums": enums("enum"), "enums-inferred": enums(None)})

out = os.path.join(here, "Generated")
shutil.rmtree(out, ignore_errors=True)

for name, request in requests.items():
    request = copy.deepcopy(request)
    type_name = "".join(p.capitalize() for p in name.split("-"))
    request["config"] = {"language": "csharp", "namespace": f"Golden.{type_name}"}
    result = subprocess.run([binary], input=json.dumps(request), capture_output=True, text=True)

    if result.returncode:
        sys.exit(f"{name}: {result.stderr}")

    response = json.loads(result.stdout)

    if response["errors"]:
        sys.exit(f"{name}: " + "\n".join(response["errors"]))

    for entry in response["files"]:
        path = os.path.join(out, type_name, entry["path"])
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, "w").write(entry["body"])

    print(f"{name}: {len(response['files'])} file")
