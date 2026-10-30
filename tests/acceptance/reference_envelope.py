"""Optional resource separation for the isolated HTTP reference fixture only."""
import os
from pathlib import Path
import secrets
import time

MOUNT = Path('/sys/fs/cgroup')
GIB = 1024**3


def cpus(value):
    result = set()
    for part in value.strip().split(','):
        if not part:
            continue
        bounds = part.split('-')
        assert len(bounds) in (1, 2) and all(item.isdigit() for item in bounds), 'Invalid CPU list'
        first, last = int(bounds[0]), int(bounds[-1])
        assert first <= last, 'Invalid CPU range'
        result.update(range(first, last + 1))
    return result


def current_directory(pid=None):
    path = Path('/proc') / (str(pid) if pid is not None else 'self') / 'cgroup'
    membership = next((line.split(':', 2)[2] for line in path.read_text().splitlines() if line.startswith('0::')), None)
    assert membership is not None, 'The reference requires cgroup v2'
    directory = MOUNT / membership.lstrip('/')
    assert directory.is_dir() and directory.resolve().is_relative_to(MOUNT), 'Unified cgroup directory unavailable'
    return directory


def quota(value):
    maximum, period = value.split()
    return float('inf') if maximum == 'max' else int(maximum) / int(period)


def cgroup(directory=None):
    directory = current_directory() if directory is None else Path(directory)
    assert directory.resolve().is_relative_to(MOUNT), 'Inspect only the unified cgroup hierarchy'
    relative = str(directory.relative_to(MOUNT))
    result = {'membership': '/' + ('' if relative == '.' else relative)}
    for name in ['cpu.max', 'cpu.stat', 'cpu.pressure', 'cpu.weight', 'memory.max', 'memory.swap.max',
                 'cpuset.cpus.effective', 'memory.current', 'memory.peak', 'memory.events', 'cgroup.controllers', 'cgroup.subtree_control']:
        try:
            result[name] = (directory / name).read_text().strip()
        except OSError:
            result[name] = None
    result['reference_enforced'] = (result['cpu.max'] is not None and quota(result['cpu.max']) == 2
                                    and result['memory.max'] == str(4 * GIB) and result['memory.swap.max'] == '0'
                                    and len(cpus(result['cpuset.cpus.effective'] or '')) == 2)
    return result


def topology(selected):
    result = {}
    for cpu in sorted(selected):
        directory = Path('/sys/devices/system/cpu') / ('cpu' + str(cpu)) / 'topology'
        result[str(cpu)] = {key: (directory / key).read_text().strip()
                            for key in ['physical_package_id', 'core_id', 'thread_siblings_list']}
    return result


class SeparateLoadGenerator:
    def __init__(self, server_cpus, client_cpu):
        self.server_cpus = cpus(server_cpus)
        self.client_cpus = {client_cpu}
        selected = self.server_cpus | self.client_cpus
        assert len(self.server_cpus) == 2 and len(selected) == 3 and client_cpu >= 0, 'Select two server CPUs and one separate generator CPU'
        self.outer = current_directory()
        assert self.outer.name.startswith('nebuless-u12-') and self.outer.name.endswith('.service'), 'Use an owned nebuless-u12-* delegated transient service'
        assert self.outer.stat().st_uid == os.getuid(), 'Delegated cgroup must belong to the fixture user'
        assert {int(pid) for pid in (self.outer / 'cgroup.procs').read_text().split()} == {os.getpid()}, 'Outer service must contain only the Python launcher'
        assert all(os.access(self.outer / name, os.W_OK) for name in ['cgroup.procs', 'cgroup.subtree_control']), 'Actual writable cgroup delegation required'
        assert {'cpu', 'memory', 'cpuset'} <= set((self.outer / 'cgroup.controllers').read_text().split()), 'Delegate cpu, memory and cpuset controllers'
        outer = cgroup(self.outer)
        assert quota(outer['cpu.max']) == 3 and outer['memory.max'] == str(5 * GIB) and outer['memory.swap.max'] == '0', 'Outer fixture must enforce exactly 3 CPU/5 GiB/no swap'
        assert cpus(outer['cpuset.cpus.effective']) == selected, 'Outer effective CPUs must equal the selected three CPUs'
        self.topology = topology(selected)
        assert len({(item['physical_package_id'], item['core_id']) for item in self.topology.values()}) == 3, 'Use three distinct physical cores, not SMT siblings'
        self.ancestors = []
        for ancestor in [self.outer.parent, *self.outer.parent.parents]:
            if not ancestor.is_relative_to(MOUNT):
                break
            facts = cgroup(ancestor)
            assert facts['cpu.max'] is None or quota(facts['cpu.max']) >= 3, 'Ancestor CPU quota is insufficient for server plus generator'
            assert facts['memory.max'] in (None, 'max') or int(facts['memory.max']) >= 5 * GIB, 'Ancestor memory limit is insufficient'
            assert selected <= cpus(facts['cpuset.cpus.effective'] or ''), 'Ancestor excludes selected CPUs'
            self.ancestors.append(facts)
        token = secrets.token_hex(6)
        self.server = self.outer / ('reference-server-' + token)
        self.client = self.outer / ('reference-generator-' + token)
        self.server.mkdir()
        self.client.mkdir()
        # Empty our own delegated service before enabling domain controllers.
        # No ancestor controller or service configuration is changed.
        (self.server / 'cgroup.procs').write_text(str(os.getpid()))
        assert not (self.outer / 'cgroup.procs').read_text().strip(), 'Delegated parent must be empty'
        (self.outer / 'cgroup.subtree_control').write_text('+cpu +memory +cpuset')
        for directory, selected_cpus, maximum_cpu, maximum_memory in [
                (self.server, self.server_cpus, 2, 4 * GIB), (self.client, self.client_cpus, 1, GIB)]:
            (directory / 'cpuset.cpus').write_text(','.join(map(str, sorted(selected_cpus))))
            (directory / 'cpu.max').write_text(str(maximum_cpu * 100000) + ' 100000')
            (directory / 'memory.max').write_text(str(maximum_memory))
            (directory / 'memory.swap.max').write_text('0')
        os.sched_setaffinity(0, self.server_cpus)
        self.verify()
        self.initial = {'outer': outer, 'server': cgroup(self.server), 'generator': cgroup(self.client)}
        self.server_pids = []
        self.generator_started = None

    def verify(self):
        server, client, outer = cgroup(self.server), cgroup(self.client), cgroup(self.outer)
        assert server['reference_enforced'] and cpus(server['cpuset.cpus.effective']) == self.server_cpus, 'Actual server cgroup lost its 2 CPU/4 GiB envelope'
        assert quota(client['cpu.max']) == 1 and client['memory.max'] == str(GIB) and client['memory.swap.max'] == '0', 'Actual generator must enforce 1 CPU/1 GiB/no swap'
        assert cpus(client['cpuset.cpus.effective']) == self.client_cpus, 'Generator must use only its separate CPU'
        assert quota(outer['cpu.max']) == 3 and outer['memory.max'] == str(5 * GIB) and outer['memory.swap.max'] == '0', 'Outer fixture limits changed'
        assert cpus(outer['cpuset.cpus.effective']) == self.server_cpus | self.client_cpus, 'Outer CPU envelope changed'

    def process(self, pid, expected_directory, allowed_cpus):
        assert current_directory(pid) == expected_directory, 'Fixture process escaped its expected cgroup'
        affinity = os.sched_getaffinity(pid)
        assert affinity == allowed_cpus, 'Fixture process affinity differs from its cgroup CPUs'
        start = (Path('/proc') / str(pid) / 'stat').read_text().rsplit(')', 1)[1].split()[19]
        return {'pid': pid, 'start_ticks': int(start), 'membership': '/' + str(expected_directory.relative_to(MOUNT)), 'affinity': sorted(affinity)}

    def start_generator(self, processes):
        assert self.generator_started is None and len(processes) == 3, 'Separate mode requires actual auth, FPM and Caddy server processes'
        self.server_pids = [self.process(process.pid, self.server, self.server_cpus) for process in processes]
        (self.client / 'cgroup.procs').write_text(str(os.getpid()))
        os.sched_setaffinity(0, self.client_cpus)
        self.generator_before = self.process(os.getpid(), self.client, self.client_cpus)
        self.generator_started = (time.monotonic(), time.process_time())
        self.verify()

    def evidence(self):
        self.verify()
        descendants = [self.process(int(pid), self.server, self.server_cpus)
                       for pid in (self.server / 'cgroup.procs').read_text().split()]
        for expected in self.server_pids:
            current = self.process(expected['pid'], self.server, self.server_cpus)
            assert current['start_ticks'] == expected['start_ticks'], 'Server process identity changed'
        generator = self.process(os.getpid(), self.client, self.client_cpus)
        return {'mode': 'separate_load_generator', 'initial': self.initial,
                'final': {'outer': cgroup(self.outer), 'server': cgroup(self.server), 'generator': cgroup(self.client)},
                'ancestors': self.ancestors, 'topology': self.topology, 'spawned_server_processes': self.server_pids,
                'server_processes_after_http': descendants, 'generator_before_http': self.generator_before,
                'generator_after_http': generator, 'generator_cpu_seconds_http': time.process_time() - self.generator_started[1],
                'http_wall_seconds': time.monotonic() - self.generator_started[0],
                'scope': 'All setup, database copy/migrations and server descendants charged to server 2CPU/4GiB; only Python load generator moves to separate 1CPU/1GiB before first HTTP use. Cgroups remain inside this owned transient service for inspection and unit cleanup.'}
