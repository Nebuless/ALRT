#!/usr/bin/env python3
"""Run the storage workload on two CPU affinities with bounded worker memory.

The process supervisor alone does not constrain filesystem page cache. If an
outer cgroup enforces the full reference limits, record its actual kernel values.
"""

import json
import os
from pathlib import Path
import resource
import signal
import subprocess
import sys
import time

root = Path(__file__).resolve().parents[2]
arguments = sys.argv[1:]
output_arguments = [value.split("=", 1)[1] for value in arguments if value.startswith("--output=")]
if len(output_arguments) != 1:
    raise SystemExit("Pass --output=/absolute/path/result.json plus optional --days=7 --backlog=360.")
if Path(output_arguments[0]).exists():
    raise SystemExit("Choose a fresh result path; existing evidence must not mask a failed run.")
initial_affinity = sorted(os.sched_getaffinity(0))
cpus = initial_affinity[:2]
if len(cpus) != 2:
    raise SystemExit("Two CPUs must be available.")
os.sched_setaffinity(0, cpus)


def cgroup_evidence():
    memberships = Path("/proc/self/cgroup").read_text().splitlines()
    unified = next((line.split(":", 2)[2] for line in memberships if line.startswith("0::")), None)
    result = {"memberships": memberships, "cpu_max": None, "memory_max": None, "cpuset_cpus_effective": None}
    if unified is not None:
        directory = Path("/sys/fs/cgroup") / unified.lstrip("/")
        for filename, key in [("cpu.max", "cpu_max"), ("memory.max", "memory_max"), ("cpuset.cpus.effective", "cpuset_cpus_effective")]:
            try:
                result[key] = (directory / filename).read_text().strip()
            except OSError:
                pass
    result["enforced_two_cpu_four_gib"] = False
    if result["cpu_max"] is not None and result["memory_max"] == str(4 * 1024**3):
        quota, period = result["cpu_max"].split()
        if quota != "max" and int(quota) == 2 * int(period):
            effective = set()
            for item in (result["cpuset_cpus_effective"] or "").split(","):
                if not item:
                    continue
                ends = item.split("-")
                effective.update(range(int(ends[0]), int(ends[-1]) + 1))
            result["enforced_two_cpu_four_gib"] = effective == set(cpus)
    return result


cgroup_before = cgroup_evidence()


def child_limits():
    # At most five PHP processes, each with a hard 768-MiB address-space ceiling.
    resource.setrlimit(resource.RLIMIT_AS, (768 * 1024**2, 768 * 1024**2))


process = subprocess.Popen(
    [str(root / "bin/php"), str(root / "tests/acceptance/history_load.php"), *arguments],
    cwd=root,
    start_new_session=True,
    preexec_fn=child_limits,
)
peak_rss = 0
peak_processes = 0
sample_count = 0
failure = None
started = time.monotonic()
try:
    while process.poll() is None:
        aggregate_rss = 0
        count = 0
        for entry in Path("/proc").iterdir():
            if not entry.name.isdigit():
                continue
            try:
                stat = (entry / "stat").read_text().rsplit(")", 1)[1].split()
                if int(stat[2]) != process.pid:  # process-group ID
                    continue
                affinity = sorted(os.sched_getaffinity(int(entry.name)))
                if affinity != cpus:
                    raise RuntimeError("A workload process escaped the two-CPU affinity.")
                aggregate_rss += int((entry / "statm").read_text().split()[1]) * os.sysconf("SC_PAGE_SIZE")
                count += 1
            except (FileNotFoundError, ProcessLookupError):
                continue
        sample_count += 1
        peak_rss = max(peak_rss, aggregate_rss)
        peak_processes = max(peak_processes, count)
        if aggregate_rss > 4 * 1024**3 or count > 5:
            raise RuntimeError("Workload exceeded four GiB aggregate RSS or five processes.")
        if time.monotonic() - started > 1800:
            raise RuntimeError("Storage benchmark exceeded its 30-minute execution bound.")
        time.sleep(0.1)
    if process.returncode != 0:
        failure = "Storage workload exited unsuccessfully."
    elif not Path(output_arguments[0]).is_file():
        failure = "Storage workload exited without its result."
    else:
        result = json.loads(Path(output_arguments[0]).read_text())
        if not isinstance(result, dict) or result.get("budgets_pass") is not True:
            failure = "Storage workload result is invalid or failed its budgets."
except BaseException as error:
    failure = str(error)
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
finally:
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    evidence = {
        "reference": "enforced_2cpu_4gib_cgroup" if cgroup_before["enforced_two_cpu_four_gib"] else "process_supervisor_limits_only",
        "logical_cpu_count": os.cpu_count(),
        "initial_cpu_affinity": initial_affinity,
        "cpu_affinity": cpus,
        "cgroup_before": cgroup_before,
        "cgroup_after": cgroup_evidence(),
        "hard_address_space_bytes_per_process": 768 * 1024**2,
        "maximum_processes_allowed": 5,
        "aggregate_rss_failure_limit_bytes": 4 * 1024**3,
        "peak_aggregate_rss_bytes": peak_rss,
        "peak_processes": peak_processes,
        "monitor_samples": sample_count,
        "monitor_interval_seconds": 0.1,
        "elapsed_seconds": time.monotonic() - started,
        "exit_code": process.returncode,
        "failure": failure,
        "measurement_boundary": "storage timing excludes HTTP parsing, validation and network",
        "resource_boundary": "outer memory cgroup includes workload file cache when enforced; RSS supervisor alone excludes file cache and unrelated host processes",
    }
    Path(output_arguments[0] + ".resources.json").write_text(json.dumps(evidence, indent=2) + "\n")
if failure:
    raise SystemExit(failure)
raise SystemExit(process.returncode)
