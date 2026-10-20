#!/usr/bin/env python3
"""Compare the live Clog prototypes to the recorded imports; never write to either tree."""
import argparse
import hashlib
import json
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('clog', type=Path, help='Path to the Clog checkout')
args = parser.parse_args()
workspace = Path(__file__).resolve().parents[2]
changed = False
for name in ('sqlite', 'graphql'):
    package = workspace / ('elephentity-sqlite' if name == 'sqlite' else 'elephentity-graphql-php')
    baseline = json.loads((package / 'docs/clog-source.json').read_text())
    source = args.clog / baseline['path']
    if not source.is_dir():
        parser.error(f'Missing source: {source}')
    current = {str(p.relative_to(source)): hashlib.sha256(p.read_bytes()).hexdigest()
               for p in sorted(source.rglob('*')) if p.is_file()}
    changes = [path for path in sorted(current.keys() | baseline['files'].keys())
               if current.get(path) != baseline['files'].get(path)]
    print(f'{name}: {len(changes)} source file(s) changed since import')
    for path in changes:
        print('  ' + path)
    changed |= bool(changes)
raise SystemExit(1 if changed else 0)
