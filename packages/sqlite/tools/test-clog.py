#!/usr/bin/env python3
"""Resolve dependencies and run Clog's suites against each supported runtime."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('clog', type=Path)
parser.add_argument('--image', default='localhost/clog-php:8.3-rust')
parser.add_argument('--runtime', action='append', help='Exact runtime version; repeat for a matrix (default: 0.11.1 and 0.12.0)')
parser.add_argument('--package-version', help='Test published integration packages at this version instead of local checkouts')
args = parser.parse_args()
workspace = Path(__file__).resolve().parents[2]

for runtime in args.runtime or ['0.11.1', '0.12.0']:
    print(f'=== Runtime {runtime} ===', flush=True)
    with tempfile.TemporaryDirectory(prefix='eleph-clog-tests-') as tmp:
        fixture = Path(tmp)
        for directory in ('server/src', 'server/generated', 'server/standalone', 'client/dist'):
            shutil.copytree(args.clog / directory, fixture / directory)
        original = json.loads((args.clog / 'server/composer.json').read_text())
        manifest = {
            'name': 'elephentity/compatibility-fixture',
            'type': 'project',
            'license': 'MIT',
            'require': dict(original['require']),
            'autoload': original['autoload'],
        }
        manifest['require']['elephentity/runtime'] = runtime
        version = args.package_version or '0.1.0-alpha.3'
        for name in ('sqlite', 'graphql'):
            manifest['require'][f'elephentity/{name}'] = version
        if not args.package_version:
            manifest['repositories'] = []
            for name, repo in [('sqlite', 'elephentity-sqlite'), ('graphql', 'elephentity-graphql-php')]:
                package = fixture / 'packages' / name
                shutil.copytree(workspace / repo / 'src', package / 'src')
                shutil.copy2(workspace / repo / 'composer.json', package / 'composer.json')
                manifest['repositories'].append({
                    'type': 'path', 'url': f'/fixture/packages/{name}',
                    'options': {'symlink': False, 'versions': {f'elephentity/{name}': version}},
                })
        (fixture / 'server/composer.json').write_text(json.dumps(manifest, indent=4) + chr(10))

        def run(command, *, network=False):
            subprocess.run([
                'podman', 'run', '--rm',
                *([] if network else ['--network=none']),
                '--security-opt=label=disable',
                '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
                '-v', f'{fixture}:/fixture' + ('' if network else ':ro'),
                '-w', '/fixture/server', args.image, *command,
            ], check=True)

        # Resolve real published runtimes, with no aliases or copied vendor tree.
        run(['composer', 'update', '--dry-run', '--no-interaction', '--no-plugins', '--no-scripts'], network=True)
        run(['composer', 'update', '--no-interaction', '--no-plugins', '--no-scripts', '--prefer-dist'], network=True)
        lock = json.loads((fixture / 'server/composer.lock').read_text())
        installed = {package['name']: package['version'].removeprefix('v') for package in lock['packages']}
        for name, expected in [('runtime', runtime), ('sqlite', version), ('graphql', version)]:
            if installed[f'elephentity/{name}'] != expected:
                raise RuntimeError(f'Unexpected {name} version: {installed[f"elephentity/{name}"]}')
        for test in ('conformance', 'schema-upgrade', 'integration', 'http'):
            run(['php', f'standalone/tests/{test}.php'])
