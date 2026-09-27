#!/usr/bin/env python3
"""Run Clog's standalone suites on core runtime source without its deletion workaround."""
import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('clog', type=Path)
parser.add_argument('--image', default='localhost/clog-php:8.3-rust')
args = parser.parse_args()
core = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='eleph-clog-runtime-') as tmp:
    fixture = Path(tmp)
    for directory in ('server/src', 'server/generated', 'server/standalone',
                      'server/vendor', 'client/dist'):
        shutil.copytree(args.clog / directory, fixture / directory)
    runtime = fixture / 'server/vendor/elephentity/runtime/src'
    shutil.rmtree(runtime)
    shutil.copytree(core / 'packages/runtime/src', runtime)
    factory = fixture / 'server/src/Runtime/RuntimeFactory.php'
    source = factory.read_text()
    workaround = 'new DependentReadStorage($storage)'
    if source.count(workaround) != 1:
        raise RuntimeError('Expected one deletion workaround in the Clog fixture; review its current wiring.')
    factory.write_text(source.replace(workaround, '$storage'))
    (fixture / 'server/src/Runtime/DependentReadStorage.php').unlink()
    for test in ('conformance', 'schema-upgrade', 'integration', 'http'):
        subprocess.run([
            'podman', 'run', '--rm', '--network=none', '--security-opt=label=disable',
            '-v', f'{fixture}:/fixture:ro', '-w', '/fixture/server', args.image,
            'php', f'standalone/tests/{test}.php',
        ], check=True)
