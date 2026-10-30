import test from 'node:test';
import assert from 'node:assert/strict';
import { metricValue, percent, evidenceAge, compareProcesses, observationGroup, historyRange, resourceRings, nodeAppearance } from '../../apps/hub/resources/js/orbit.js';

test('available zero is a value; unavailable, invalid and missing are not', () => {
    assert.equal(metricValue({ status: 'available', value: 0 }), 0);
    for (const value of [null, undefined, { status: 'unavailable', value: 0 }, { status: 'available', value: null }, { status: 'available', value: Infinity }]) assert.equal(metricValue(value), null);
    assert.equal(percent({ status: 'available', value: 0 }, { status: 'available', value: 10 }), 0);
    assert.equal(percent({ status: 'available', value: 1 }, { status: 'available', value: 0 }), null);
});

test('evidence ages retain server clock and reject future source measurements', () => {
    const h = { observed_at: '2026-10-19T14:00:00Z', sampled_at: '2026-10-19T13:59:40Z', contact_at: '2026-10-19T13:59:55Z', sample_age_seconds: 20, contact_age_seconds: 5 };
    assert.equal(evidenceAge(h, 'sample', 7), 27);
    assert.equal(evidenceAge(h, 'contact', 7), 12);
    assert.equal(evidenceAge({ ...h, sampled_at: '2026-10-19T14:00:01Z', sample_age_seconds: 0 }, 'sample'), null);
    assert.equal(evidenceAge({ ...h, sample_age_seconds: null }, 'sample'), null);
});

test('unknown processes sort last in both numeric directions without clamping one-core CPU', () => {
    const rows = [{ name: 'unknown', cpu_percent: null, rss_bytes: null }, { name: 'busy', cpu_percent: 150, rss_bytes: 100 }, { name: 'idle', cpu_percent: 0, rss_bytes: 0 }];
    assert.deepEqual([...rows].sort((a,b) => compareProcesses(a,b,'cpu-desc')).map(p=>p.name), ['busy','idle','unknown']);
    assert.deepEqual([...rows].sort((a,b) => compareProcesses(a,b,'cpu-asc')).map(p=>p.name), ['idle','busy','unknown']);
    assert.deepEqual([...rows].sort((a,b) => compareProcesses(a,b,'memory')).map(p=>p.name), ['busy','idle','unknown']);
});

test('history windows anchor to hub observation rather than client clock', () => {
    const range = historyRange('2026-10-19T14:00:00Z', 60);
    assert.equal(range.to, '2026-10-19T14:00:00.000Z');
    assert.equal(range.from, '2026-10-19T13:00:00.000Z');
    assert.equal(range.resolution, 'minute');
    const long = historyRange('2026-10-19T14:00:00Z', 240);
    assert.ok(Date.parse(long.to) - Date.parse(long.from) <= 4 * 3600000);
    assert.throws(() => historyRange(null, 60));
});

test('fleet assessment uses both contact and sample freshness at elapsed boundaries', () => {
    const host = { observed_at: '2026-10-19T14:00:00Z', sampled_at: '2026-10-19T13:59:55Z', contact_at: '2026-10-19T13:59:30Z', sample_age_seconds: 5, contact_age_seconds: 30, observation: { state: 'healthy' } };
    assert.equal(observationGroup(host), 'fresh');
    assert.equal(observationGroup(host, 1), 'stale');
    assert.equal(observationGroup({ ...host, contact_age_seconds: 35 }), 'stale');
    assert.equal(observationGroup({ ...host, observation: { state: 'silent' } }), 'offline');
});


test('resource rings independently signal fresh high readings and never treat unavailable as zero', () => {
    const available = value => ({ status: 'available', value });
    const host = { metrics: { cpu_percent: available(75), memory: { used_bytes: available(74), total_bytes: available(100) }, disks: [{ used_bytes: available(75), total_bytes: available(100) }, { used_bytes: available(2), total_bytes: available(100) }] } };
    assert.deepEqual(resourceRings(host, true), { cpu: { value: 75, high: true }, memory: { value: 74, high: false }, storage: { value: 75, high: true } });
    assert.deepEqual(resourceRings(host, false), { cpu: { value: null, high: false }, memory: { value: null, high: false }, storage: { value: null, high: false } });
    host.metrics.cpu_percent = { status: 'unavailable', value: 99 };
    host.metrics.memory.total_bytes = available(0);
    host.metrics.disks = [];
    assert.deepEqual(resourceRings(host, true), { cpu: { value: null, high: false }, memory: { value: null, high: false }, storage: { value: null, high: false } });
});


test('resource rings reject out-of-range percentages and overflowing ratios', () => {
    const available = value => ({ status: 'available', value });
    const host = { metrics: { cpu_percent: available(101), memory: { used_bytes: available(101), total_bytes: available(100) }, disks: [{ used_bytes: available(Number.MAX_VALUE), total_bytes: available(Number.MIN_VALUE) }] } };
    assert.deepEqual(resourceRings(host, true), { cpu: { value: null, high: false }, memory: { value: null, high: false }, storage: { value: null, high: false } });
    host.metrics.cpu_percent = available(100);
    host.metrics.memory.used_bytes = available(0);
    host.metrics.disks.push({ used_bytes: available(90), total_bytes: available(100) });
    assert.deepEqual(resourceRings(host, true), { cpu: { value: 100, high: true }, memory: { value: 0, high: false }, storage: { value: 90, high: true } });
});


test('node materials vary by stable server identity without changing across refreshes', () => {
    const ids = Array.from({ length: 40 }, (_, index) => 'server-' + index);
    const first = ids.map(nodeAppearance);
    assert.deepEqual(ids.map(nodeAppearance), first);
    assert.equal(new Set(first.map(value => value.material)).size, 5);
    assert.ok(first.every(value => value.material >= 0 && value.material < 5 && value.tint >= -12 && value.tint <= 12));
});


test('each resource ring lights at inclusive 75 percent and stays muted just below', () => {
    const available = value => ({ status: 'available', value });
    for (const value of [74, 74.999, 75, 76]) {
        const host = { metrics: { cpu_percent: available(value), memory: { used_bytes: available(value), total_bytes: available(100) }, disks: [{ used_bytes: available(value), total_bytes: available(100) }] } };
        for (const ring of Object.values(resourceRings(host, true))) assert.equal(ring.high, value >= 75);
        for (const ring of Object.values(resourceRings(host, false))) assert.equal(ring.high, false);
    }
});
