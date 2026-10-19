#!/usr/bin/env python3
"""Development-only catalog discovery and source copying; standard library only."""

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[1]
LOCK = ".eleph-patterns.json"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def local_path(root, name):
    """Require literal relative paths, without following catalog or app symlinks."""
    path = PurePosixPath(name)
    if not name or path.is_absolute() or ".." in path.parts or "\\" in name:
        raise ValueError(f"Unsafe path: {name}")
    if path.as_posix() != name or ".git" in path.parts:
        raise ValueError(f"Unsafe path: {name}")
    result = root
    for part in path.parts:
        result = result / part
        if result.is_symlink():
            raise ValueError(f"Symlink not supported: {result}")
    return result


def read_catalog(root):
    catalog = json.loads((root / "catalog.json").read_text())
    if catalog.get("format") != 1 or not isinstance(catalog.get("patterns"), dict):
        raise ValueError("Expected catalog format 1 and a patterns object")
    for name, entry in catalog["patterns"].items():
        if not name.isidentifier() or not name[0].isupper():
            raise ValueError(f"Invalid pattern name: {name}")
        if entry["status"] not in ("ready", "planned"):
            raise ValueError(f"Invalid status for {name}")
        if entry["contract"] not in (None, "abstract", "extendable", "shared"):
            raise ValueError(f"Invalid contract mode for {name}")
        for dependency in entry["dependencies"]:
            if dependency not in catalog["patterns"]:
                raise ValueError(f"Unknown dependency {dependency} of {name}")
        if entry["status"] == "ready" and not entry["files"]:
            raise ValueError(f"Ready pattern {name} has no files")
        for source, target in entry["files"].items():
            if not local_path(root, source).is_file():
                raise ValueError(f"Missing source: {source}")
            local_path(root, target)
            if target == LOCK:
                raise ValueError(f"Reserved destination: {target}")
    for name, entry in catalog["patterns"].items():
        if entry["status"] == "ready":
            select(catalog, name)
    return catalog


def select(catalog, name):
    ordered, active = [], set()

    def visit(current):
        if current in active:
            raise ValueError(f"Pattern dependency cycle at {current}")
        if current in ordered:
            return
        entry = catalog["patterns"].get(current)
        if entry is None:
            raise ValueError(f"Unknown pattern: {current}")
        if entry["status"] != "ready":
            raise ValueError(f"{current} is planned, not yet installable")
        active.add(current)
        for dependency in entry["dependencies"]:
            visit(dependency)
        active.remove(current)
        ordered.append(current)

    visit(name)
    return ordered


def source_info(root):
    def git(*args):
        return subprocess.check_output(
            ["git", "-C", str(root), *args], stderr=subprocess.DEVNULL, text=True
        ).strip()

    try:
        revision = git("rev-parse", "HEAD")
        modified = bool(git("status", "--porcelain"))
    except (OSError, subprocess.CalledProcessError):
        revision, modified = None, True
    return {"catalog": str(root), "revision": revision, "modified": modified}


def copy_pattern(root, destination, name):
    catalog = read_catalog(root)
    names = select(catalog, name)
    destination = destination.absolute()
    # Reject symlink components before creating any files.
    local_path(Path(destination.anchor), destination.relative_to(destination.anchor).as_posix())
    lock_path = local_path(destination, LOCK)
    original_lock = lock_path.read_bytes() if lock_path.exists() else None
    lock = json.loads(original_lock) if original_lock is not None else {"format": 1, "patterns": {}}
    if lock.get("format") != 1 or not isinstance(lock.get("patterns"), dict):
        raise ValueError("Unsupported provenance file")
    pending = {}
    installed = []
    source = source_info(root)
    for current in names:
        entry = catalog["patterns"][current]
        if current in lock["patterns"]:
            if current == name:
                raise ValueError(f"{current} is already installed; review updates manually")
            # Reuse only an unchanged copy of the same dependency.
            recorded = lock["patterns"][current]["files"]
            expected = {target: digest(local_path(root, src).read_bytes()) for src, target in entry["files"].items()}
            if {target: info["sha256"] for target, info in recorded.items()} != expected:
                raise ValueError(f"Dependency {current} differs from this catalog; reconcile it manually")
            for target, sha in expected.items():
                if digest(local_path(destination, target).read_bytes()) != sha:
                    raise ValueError(f"Dependency {current} has local edits; reconcile it manually")
            continue
        files = {}
        for src, target in entry["files"].items():
            path = local_path(destination, target)
            if path.exists() or target in pending:
                raise ValueError(f"Destination already exists or collides: {target}")
            data = local_path(root, src).read_bytes()
            pending[target] = data
            files[target] = {"source": src, "sha256": digest(data)}
        lock["patterns"][current] = {**source, "files": files}
        installed.append(current)
    license_target = "docs/patterns/LICENSE"
    license_data = (root / "LICENSE").read_bytes()
    license_path = local_path(destination, license_target)
    if license_target in pending:
        raise ValueError("Pattern files collide with the catalog license")
    if license_path.exists():
        if license_path.read_bytes() != license_data:
            raise ValueError("Existing pattern license differs; reconcile it manually")
    else:
        pending[license_target] = license_data
    # Preflight all parent paths too, so ordinary collisions cause no partial copy.
    for target in pending:
        for parent in local_path(destination, target).parents:
            if parent.exists() and not parent.is_dir():
                raise ValueError(f"Destination parent is not a directory: {parent}")
    created = []
    try:
        for target, data in pending.items():
            path = local_path(destination, target)
            path.parent.mkdir(parents=True, exist_ok=True)
            with path.open("xb") as stream:
                created.append(path)
                stream.write(data)
        # Compare before replacing provenance; this is a single-writer dev tool.
        if (lock_path.read_bytes() if lock_path.exists() else None) != original_lock:
            raise ValueError("Provenance changed during copying; retry")
        temporary = local_path(destination, LOCK + ".tmp")
        with temporary.open("x") as stream:
            created.append(temporary)
            stream.write(json.dumps(lock, indent=2) + "\n")
        temporary.replace(lock_path)
    except BaseException:
        for path in reversed(created):
            path.unlink(missing_ok=True)
        raise
    return installed


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    search = commands.add_parser("search")
    search.add_argument("query", nargs="?", default="")
    commands.add_parser("check")
    copy = commands.add_parser("copy")
    copy.add_argument("pattern")
    copy.add_argument("--into", type=Path, required=True)
    args = parser.parse_args()
    try:
        catalog = read_catalog(ROOT)
        if args.command == "search":
            for name, entry in catalog["patterns"].items():
                if args.query.casefold() in json.dumps([name, entry["description"], entry["tags"]]).casefold():
                    print(f"{name} [{entry['status']}]: {entry['description']}")
        elif args.command == "check":
            print(f"Catalog valid: {len(catalog['patterns'])} entries")
        else:
            print("Copied: " + ", ".join(copy_pattern(ROOT, args.into, args.pattern)))
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(f"catalog: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
