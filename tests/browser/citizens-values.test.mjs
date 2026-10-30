import test from 'node:test';
import assert from 'node:assert/strict';
import { evidenceAge, citizenCurrent, terminationReason, runtimeGroups, avatarSeed, resourceText, citizenState, orderCitizens } from '../../apps/hub/resources/js/citizens.js';

const observed_at = '2026-10-28T12:00:00Z';
const host = { id: 'host', coverage: 'complete', last_contact_at: observed_at };
const citizen = { id: 'a'.repeat(64), revision: 'b'.repeat(64), tool: 'omo', state: 'working', observed_at, attribution: 'dedicated', runtime_id: 'runtime', process: { pid: 123, start_ticks: 1, boot_id: 'boot' }, can_terminate: true, cpu_percent: 150, rss_bytes: 0 };

test('presentation orders fresh attention first and never labels expired work as current', () => {
    const row = state => ({ host, citizen: { ...citizen, id: state, state }, key: 'host:' + state });
    const rows = ['stopped', 'unknown', 'idle', 'failed', 'working', 'waiting'].map(row);
    assert.deepEqual(orderCitizens(rows, observed_at).map(row => row.citizen.state), ['waiting', 'working', 'idle', 'unknown', 'failed', 'stopped']);
    assert.equal(citizenState(host, citizen, observed_at, 11), 'unknown');
    assert.equal(citizenState(host, { ...citizen, state: 'stopped' }, observed_at, 11), 'stopped');
    assert.equal(citizenState(host, citizen, observed_at, 0, false), 'unknown');
    assert.deepEqual(rows.map(row => row.citizen.state), ['stopped', 'unknown', 'idle', 'failed', 'working', 'waiting']);
});

test('freshness uses server time and elapsed time with inclusive ten-second boundary', () => {
    assert.equal(evidenceAge(observed_at, observed_at, 10), 10);
    assert.equal(evidenceAge(observed_at, '2026-10-28T12:00:01Z'), null);
    assert.equal(evidenceAge(null, observed_at), null);
    assert.equal(citizenCurrent(host, citizen, observed_at, 10), true);
    assert.equal(citizenCurrent(host, citizen, observed_at, 10.001), false);
    assert.equal(citizenCurrent({ ...host, coverage: 'unavailable' }, citizen, observed_at), false);
    assert.equal(citizenCurrent(host, citizen, observed_at, 0, false), false);
});

test('kill gate rejects standby shared stale unsupported and pending records', () => {
    const reason = (row = citizen, primary = true, elapsed = 0) => terminationReason(host, row, observed_at, elapsed, primary, true);
    assert.equal(reason(), '');
    assert.match(reason(citizen, false), /standby/i);
    assert.match(reason({ ...citizen, attribution: 'shared' }), /shared/i);
    assert.match(reason(citizen, true, 11), /fresh/i);
    assert.match(reason({ ...citizen, can_terminate: false }), /capability/i);
    assert.match(reason({ ...citizen, process: null }), /identity/i);
    assert.match(reason({ ...citizen, state: 'stopped' }), /stopped/i);
    for (const status of ['requested', 'unknown', 'terminated', 'already_exited']) assert.notEqual(reason({ ...citizen, action: { id: 'action', status } }), '');
});

test('shared measurements are represented once per host runtime, never summed per session', () => {
    const shared = { ...citizen, attribution: 'shared', cpu_percent: 48, rss_bytes: 1024 };
    const hosts = [{ ...host, citizens: [shared, { ...shared, id: 'other' }] }, { ...host, id: 'host-2', citizens: [shared] }];
    const groups = runtimeGroups(hosts, observed_at);
    assert.equal(groups.length, 2);
    assert.equal(groups[0].members.length, 2);
    assert.equal(groups[0].cpu_percent, 48);
    assert.equal(groups[0].rss_bytes, 1024);
    hosts[0].citizens[1].cpu_percent = null;
    hosts[0].citizens[1].rss_bytes = null;
    assert.equal(runtimeGroups(hosts, observed_at)[0].cpu_percent, 48);
    assert.equal(runtimeGroups(hosts, observed_at)[0].rss_bytes, 1024);
    assert.equal(runtimeGroups(hosts, observed_at, 11)[0].cpu_percent, null);
    hosts[0].citizens[1].cpu_percent = 49;
    assert.equal(runtimeGroups(hosts, observed_at)[0].cpu_percent, null);
});

test('missing resources stay unavailable and avatar identity is stable', () => {
    assert.equal(resourceText(null, 'cpu'), 'Unavailable');
    assert.equal(resourceText(undefined, 'rss'), 'Unavailable');
    assert.equal(resourceText(0, 'cpu'), '0%');
    assert.equal(resourceText(150, 'cpu'), '150%');
    assert.equal(resourceText(0, 'rss'), '0 MiB');
    assert.equal(resourceText(-1, 'cpu'), 'Unavailable');
    assert.deepEqual(avatarSeed('host:a'), avatarSeed('host:a'));
    assert.notDeepEqual(avatarSeed('host:a'), avatarSeed('host:b'));
});
