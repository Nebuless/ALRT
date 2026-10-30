#!/usr/bin/env python3
"""Time a real fresh dependency installation with a preprovisioned PHP runtime.

This measures tools/bootstrap with empty dependency trees and private empty npm,
Composer and Go caches. PHP/Node/Go provisioning and source compilation are
explicitly excluded. Run serially after HTTP/warm measurements, inside the same
reference limits; never point this at an installed hub or the active source tree.
"""
import argparse
import hashlib
import json
import os
import signal
from pathlib import Path
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
from reference import cgroup

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bundle', type=Path, required=True)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', type=Path, required=True)
    parser.add_argument('--work-parent', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    assert args.output.is_absolute() and not args.output.exists()
    assert args.work_parent.is_absolute() and args.work_parent.is_dir()
    assert args.bundle.is_file() and args.php.is_absolute() and os.access(args.php, os.X_OK)
    before = cgroup()
    assert before['reference_enforced'], 'Enforced 2 CPU/4 GiB/no-swap cgroup required'
    directory = Path(tempfile.mkdtemp(prefix='nebuless-cold-setup-', dir=args.work_parent))
    source = directory / 'source'
    subprocess.run(['git', 'clone', '--no-checkout', str(args.bundle.resolve()), str(source)], check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['git', 'checkout', '--detach', args.revision], cwd=source, check=True, stdout=subprocess.DEVNULL)
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=source, text=True).strip()
    assert revision == args.revision, 'Use the full canonical revision'
    missing = [not (source / path).exists() for path in ['apps/hub/vendor', 'apps/hub/node_modules', 'apps/auth/node_modules', 'tools/runtime']]
    assert all(missing), 'Cold fixture must begin without dependencies or staged runtime'
    runtime = source / 'tools/runtime/bin'
    runtime.mkdir(parents=True)
    # Only PHP is preprovisioned. The pinned linter and every package must install.
    (runtime / 'php').symlink_to(args.php)
    env = {**os.environ, 'NEBULESS_PHP': str(args.php), 'COMPOSER_ALLOW_SUPERUSER': '1',
           'COMPOSER_CACHE_DIR': str(directory / 'composer-cache'), 'npm_config_cache': str(directory / 'npm-cache'),
           'GOCACHE': str(directory / 'go-build-cache'), 'GOMODCACHE': str(directory / 'go-module-cache'),
           'TMPDIR': str(directory)}
    result = {'profile': 'cold_dependency_setup_with_preprovisioned_compatible_php_node_go',
              'source_revision': revision, 'source_bundle_sha256': hashlib.sha256(args.bundle.read_bytes()).hexdigest(),
              'fixture': str(directory), 'cgroup_before': before, 'empty_dependency_trees': all(missing),
              'isolated_empty_caches': ['Composer', 'npm', 'Go build', 'Go modules'],
              'php': str(args.php), 'php_sha256': hashlib.sha256(args.php.read_bytes()).hexdigest(),
              'php_version': subprocess.check_output([str(source / 'bin/php'), '-v'], env=env, text=True).splitlines()[0],
              'runner_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'reference_helper_sha256': hashlib.sha256((Path(__file__).parent / 'reference.py').read_bytes()).hexdigest(),
              'node_version': subprocess.check_output(['node', '--version'], text=True).strip(),
              'go_version': subprocess.check_output(['go', 'version'], text=True).strip(),
              'excluded': ['PHP compilation/provisioning', 'Node and Go provisioning', 'quality gate', 'source clone time'],
              'bootstrap_log': str(directory / 'bootstrap.log')}
    started = time.monotonic()
    try:
        with (directory / 'bootstrap.log').open('wb') as log:
            install = subprocess.Popen([str(source / 'tools/bootstrap')], cwd=source, env=env, stdout=log, stderr=log, start_new_session=True)
            try:
                code = install.wait(timeout=1800)
            except BaseException:
                os.killpg(install.pid, signal.SIGTERM)
                try:
                    install.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(install.pid, signal.SIGKILL)
                    install.wait()
                raise
        result.update(exit_code=code, completed=code == 0)
    except BaseException as error:
        result.update(completed=False, failure_type=type(error).__name__)
        raise
    finally:
        result.update(bootstrap_seconds=time.monotonic() - started, cgroup_after=cgroup())
        args.output.write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps({'output': str(args.output), 'completed': result.get('completed')}))
    return 0 if result['completed'] else 1


if __name__ == '__main__':
    sys.exit(main())
