// Owner-only inventory. Keep all evidence in memory; never persist private state.
const FRESH_SECONDS = 10;
const finite = value => typeof value === 'number' && Number.isFinite(value) && value >= 0;
const time = value => typeof value === 'string' && Number.isFinite(Date.parse(value)) ? Date.parse(value) : null;
const title = value => String(value ?? 'unknown').replaceAll('_', ' ');
const toolName = value => value === 'omo' ? 'OmO' : value === 'pi' ? 'Pi' : 'Unknown tool';
const ageText = age => age === null ? 'Observation age unknown' : age < 60 ? Math.floor(age) + ' s ago' : age < 3600 ? Math.floor(age / 60) + ' min ago' : Math.floor(age / 3600) + ' h ago';

export function evidenceAge(observedAt, sampledAt, elapsed = 0) {
    const observed = time(observedAt), sampled = time(sampledAt);
    return observed === null || sampled === null || sampled > observed || !finite(elapsed) ? null : (observed - sampled) / 1000 + elapsed;
}
export function citizenCurrent(host, citizen, observedAt, elapsed = 0, connected = true) {
    const contact = evidenceAge(observedAt, host.last_contact_at, elapsed);
    const observation = evidenceAge(observedAt, citizen.observed_at, elapsed);
    return connected && host.coverage !== 'unavailable' && contact !== null && contact <= FRESH_SECONDS && observation !== null && observation <= FRESH_SECONDS;
}
export function citizenState(host, citizen, observedAt, elapsed = 0, connected = true) {
    return ['stopped', 'failed'].includes(citizen.state) || citizenCurrent(host, citizen, observedAt, elapsed, connected) ? citizen.state : 'unknown';
}
export function orderCitizens(rows, observedAt, elapsed = 0, connected = true) {
    const priority = { waiting: 0, working: 1, idle: 2, unknown: 3, failed: 4, stopped: 5 };
    return [...rows].sort((a, b) => priority[citizenState(a.host, a.citizen, observedAt, elapsed, connected)] - priority[citizenState(b.host, b.citizen, observedAt, elapsed, connected)] || a.host.name?.localeCompare(b.host.name ?? '') || a.key.localeCompare(b.key));
}
export function terminationReason(host, citizen, observedAt, elapsed, primary, connected) {
    if (!primary) return 'Standby hub: actions require the primary hub.';
    if (citizen.attribution === 'shared') return 'Shared runtime: individual sessions cannot be force killed.';
    if (!citizenCurrent(host, citizen, observedAt, elapsed, connected)) return 'Fresh connected evidence is required.';
    if (['stopped', 'failed'].includes(citizen.state)) return 'This citizen is stopped or failed; no active process action is available.';
    if (citizen.action && ['requested', 'unknown'].includes(citizen.action.status)) return 'Previous action outcome is pending or unknown. No automatic retry.';
    if (citizen.action && ['terminated', 'already_exited'].includes(citizen.action.status)) return 'Process outcome already recorded. Await a new process observation.';
    if (!citizen.process || !Number.isInteger(citizen.process.pid) || citizen.process.pid <= 0 || !citizen.process.boot_id || !finite(citizen.process.start_ticks) || !/^[a-f0-9]{64}$/.test(citizen.revision ?? '')) return 'Proven process identity is required.';
    if (citizen.attribution !== 'dedicated' || citizen.can_terminate !== true) return 'Termination capability is unavailable for this citizen.';
    return '';
}
export function resourceText(value, kind) {
    if (!finite(value)) return 'Unavailable';
    return new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(kind === 'rss' ? value / 1048576 : value) + (kind === 'rss' ? ' MiB' : '%');
}
export function avatarSeed(identity) {
    let seed = 2166136261;
    for (const character of identity) seed = Math.imul(seed ^ character.charCodeAt(0), 16777619) >>> 0;
    return { hue: seed % 3, eyes: (seed >>> 5) % 3, crown: (seed >>> 9) % 3, shift: (seed >>> 13) % 9 - 4 };
}
export function runtimeGroups(hosts, observedAt, elapsed = 0, connected = true) {
    const groups = new Map();
    for (const host of hosts) for (const citizen of host.citizens) {
        if (citizen.attribution !== 'shared') continue;
        const key = host.id + ':' + citizen.runtime_id;
        if (!groups.has(key)) groups.set(key, { key, host, runtime_id: citizen.runtime_id, members: [], cpu_percent: null, rss_bytes: null });
        const group = groups.get(key);
        group.members.push(citizen);
        if (citizenCurrent(host, citizen, observedAt, elapsed, connected)) {
            // Conflicting shared measurements are unavailable, not added or averaged.
            const prior = group.members.filter(row => citizenCurrent(host, row, observedAt, elapsed, connected));
            for (const field of ['cpu_percent', 'rss_bytes']) {
                const values = prior.map(row => row[field]).filter(finite);
                group[field] = values.length && values.every(value => value === values[0]) ? values[0] : null;
            }
        }
    }
    return [...groups.values()];
}

const $ = id => document.getElementById(id);
const make = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
};
const setText = (node, text) => { if (node.textContent !== text) node.textContent = text; };
function avatar(identity) {
    const seed = avatarSeed(identity), colors = ['#a6e6ff', '#ff8cc8', '#f6d589'];
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 80 80'); svg.setAttribute('class', 'avatar'); svg.setAttribute('aria-hidden', 'true');
    const shape = (tag, attributes) => {
        const node = document.createElementNS(svg.namespaceURI, tag);
        for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, String(value));
        svg.append(node);
    };
    shape('rect', { x: 1, y: 1, width: 78, height: 78, rx: 18, fill: '#30253f' });
    shape('path', { d: ['M22 23 L30 10 L40 22 L50 10 L58 23', 'M23 23 L23 12 L34 19 L46 19 L57 12 L57 23', 'M27 23 L27 13 L53 13 L53 23'][seed.crown], fill: 'none', stroke: colors[seed.hue], 'stroke-width': 4, 'stroke-linejoin': 'round' });
    shape('rect', { x: 18, y: 24, width: 44, height: 39, rx: 12 + seed.eyes * 2, fill: colors[seed.hue] });
    for (const x of [31, 49]) shape('rect', { x: x - 3 + seed.shift / 2, y: 35, width: 6, height: 7 + seed.eyes * 2, rx: 2, fill: '#151024' });
    shape('path', { d: 'M32 53 H48', stroke: '#151024', 'stroke-width': 3, 'stroke-linecap': 'round' });
    shape('path', { d: 'M26 69 H54', stroke: colors[(seed.hue + 1) % 3], 'stroke-width': 4, 'stroke-linecap': 'round' });
    return svg;
}
function metricList() {
    const dl = make('dl', undefined, 'metrics');
    for (const label of ['CPU / one core', 'Memory / RSS']) { const item = make('div'); item.append(make('dt', label), make('dd', 'Unavailable')); dl.append(item); }
    return dl;
}
function boot() {
    if (!$('citizen-list')) return;
    let inventory = null, received = 0, connected = false, denied = false, busy = false, posting = false;
    let timer = null, ageTimer = null, controller = null, actionController = null, selected = '', returnFocus = null, confirmation = null;
    let generation = 0, actionFeedback = '';
    const cards = new Map(), runtimes = new Map(), coverage = new Map(), localActions = new Map();
    const recovery = new URL('/citizens', location.origin);
    const recoveryHost = new URL(location.href).searchParams.get('host');
    if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(recoveryHost ?? '')) recovery.searchParams.set('host', recoveryHost);
    $('access-recovery').href = recovery.pathname + recovery.search;
    document.querySelectorAll('.owner-navigation a, #return-orbit').forEach(link => {
        const target = new URL(link.getAttribute('href'), location.origin);
        if (!['/', '/orbit', '/citizens'].includes(target.pathname)) return;
        if (recovery.searchParams.has('host')) target.searchParams.set('host', recovery.searchParams.get('host'));
        link.href = target.pathname + target.search;
    });
    const elapsed = () => inventory ? Math.max(0, (performance.now() - received) / 1000) : 0;
    const active = () => !document.hidden && navigator.onLine && !denied;
    const rows = () => (inventory?.hosts ?? []).flatMap(host => host.citizens.map(citizen => ({ host, citizen: { ...citizen, action: localActions.get(host.id + ':' + citizen.id) ?? citizen.action }, key: host.id + ':' + citizen.id })));
    const currentRow = () => rows().find(row => row.key === selected);
    const reason = row => row ? terminationReason(row.host, row.citizen, inventory.observed_at, elapsed(), inventory.primary, connected && active()) : 'Citizen is no longer in the current inventory.';
    const announce = text => setText($('citizen-announcement'), text);
    function resetConfirmation() { confirmation = null; $('kill-confirmation').hidden = true; }
    function closeDetail() {
        resetConfirmation(); $('citizen-detail').close(); selected = '';
        if (returnFocus?.isConnected && returnFocus.checkVisibility()) returnFocus.focus();
        else if (returnFocus?.closest('#citizen-history') && !$('recent-history').hidden) $('recent-history').querySelector('summary').focus();
        else $('citizen-search').focus();
        returnFocus = null;
    }
    function clearPrivate() {
        denied = true; connected = false; inventory = null; localActions.clear(); selected = ''; confirmation = null; actionFeedback = '';
        generation++; controller?.abort(); controller = null; busy = false;
        clearTimeout(timer); clearInterval(ageTimer); actionController?.abort();
        $('citizen-detail').close();
        for (const id of ['detail-title', 'detail-note', 'detail-readings', 'detail-evidence', 'detail-avatar', 'action-status', 'terminate-reason', 'confirmation-copy', 'citizen-list', 'citizen-history', 'shared-runtimes', 'host-coverage', 'citizen-announcement']) $(id).replaceChildren();
        $('recent-history').hidden = true;
        $('recent-history').open = false;
        $('history-count').textContent = '';
        $('filter-count').textContent = '';
        $('coverage-summary').textContent = 'Machine coverage unavailable';
        for (const state of ['working', 'waiting', 'idle', 'unknown']) $('count-' + state).textContent = '—';
        $('citizen-host').replaceChildren(make('option', 'All machines')); $('citizen-host').firstChild.value = '';
        $('shared-section').hidden = true; $('citizen-empty').hidden = true; $('kill-confirmation').hidden = true;
        cards.clear(); runtimes.clear(); coverage.clear(); returnFocus = null;
        setText($('inventory-count'), 'Private inventory cleared'); setText($('hub-role'), 'Access lost');
        setText($('inventory-note'), 'Sign in again through Orbit to restore owner access.');
        setText($('connection-note'), 'Owner access lost. Private inventory cleared. Return to Orbit to sign in.');
        $('connection-note').parentElement.dataset.state = 'denied';
        $('access-recovery').hidden = false;
        $('citizen-filters').reset();
        for (const node of $('citizen-filters').elements) node.disabled = true;
        $('refresh-citizens').disabled = true;
    }
    function renderDetail() {
        if (!selected) return;
        const row = currentRow();
        if (!row) { closeDetail(); announce('Citizen no longer reported. Detail closed.'); return; }
        const { host, citizen } = row;
        const blocked = reason(row), current = citizenCurrent(host, citizen, inventory.observed_at, elapsed(), connected && active());
        setText($('detail-title'), toolName(citizen.tool) + ' · ' + citizen.id.slice(0, 8));
        const displayState = citizenState(host, citizen, inventory.observed_at, elapsed(), connected && active());
        setText($('detail-note'), host.name + ' / ' + title(displayState) + '. ' + (current ? 'Fresh observation.' : 'Recorded evidence; not current activity.'));
        const readings = [['CPU / one core', citizen.attribution === 'shared' ? 'Shared runtime' : resourceText(current ? citizen.cpu_percent : null, 'cpu')], ['Memory / RSS', citizen.attribution === 'shared' ? 'Shared runtime' : resourceText(current ? citizen.rss_bytes : null, 'rss')]];
        readings.forEach(([label, value], index) => {
            if (!$('detail-readings').children[index]) { const item = make('div'); item.append(make('dt', label), make('dd')); $('detail-readings').append(item); }
            setText($('detail-readings').children[index].lastChild, value);
        });
        const values = [
            ['Machine', host.name + ' (' + host.id + ')'], ['Citizen', citizen.id], ['Tool', toolName(citizen.tool)],
            ['Native state', title(citizen.state)], ['Observed', citizen.observed_at ?? 'Unknown'],
            ['Freshness', ageText(evidenceAge(inventory.observed_at, citizen.observed_at, elapsed()))],
            ['Source contact', ageText(evidenceAge(inventory.observed_at, host.last_contact_at, elapsed()))],
            ['Coverage', title(host.coverage)], ['Attribution', title(citizen.attribution)], ['Runtime', citizen.runtime_id],
            ['PID', citizen.process ? String(citizen.process.pid) : 'Unavailable'],
            ['Parent', citizen.parent_id ?? 'None reported'],
        ];
        values.forEach(([label, value], index) => {
            if (!$('detail-evidence').children[index]) { const item = make('div'); item.append(make('dt', label), make('dd')); $('detail-evidence').append(item); }
            setText($('detail-evidence').children[index].lastChild, String(value));
        });
        setText($('action-status'), citizen.action ? 'Action ' + title(citizen.action.status) + '. ' + (citizen.action.status === 'requested' ? 'Awaiting collector result; not yet terminated.' : citizen.action.status === 'unknown' ? 'Outcome uncertain. No automatic retry.' : 'Reported process result.') : actionFeedback);
        $('request-terminate').disabled = !!blocked || posting;
        setText($('terminate-reason'), posting ? 'Submitting confirmed process action.' : blocked || 'Only this dedicated process can be targeted. Confirmation required.');
        if (confirmation && (blocked || confirmation.revision !== citizen.revision)) {
            resetConfirmation(); setText($('terminate-reason'), 'Evidence changed or expired. Review current process before confirming again.');
            if (!posting) $('close-detail').focus();
        }
        $('confirm-terminate').disabled = !confirmation || !!blocked || posting;
        $('cancel-terminate').disabled = posting;
    }
    function render() {
        if (!inventory || denied) return;
        const all = orderCitizens(rows(), inventory.observed_at, elapsed(), connected && active()), visibleKeys = new Set();
        const focused = document.activeElement;
        const counts = { working: 0, waiting: 0, idle: 0, unknown: 0 };
        let shownCurrent = 0, shownHistory = 0;
        const query = $('citizen-search').value.trim().toLowerCase();
        const hostFilter = $('citizen-host').value, tool = $('citizen-tool').value, state = $('citizen-state').value;
        for (const row of all) {
            const { host, citizen, key } = row;
            const displayState = citizenState(host, citizen, inventory.observed_at, elapsed(), connected && active());
            const historical = ['stopped', 'failed'].includes(displayState);
            if (Object.hasOwn(counts, displayState)) counts[displayState]++;
            const show = (!hostFilter || host.id === hostFilter) && (!tool || citizen.tool === tool) && (!state || displayState === state) && (!query || [citizen.id, host.id, host.name, toolName(citizen.tool)].some(value => value.toLowerCase().includes(query)));
            if (show) { if (historical) shownHistory++; else shownCurrent++; }
            if (show) visibleKeys.add(key);
            let card = cards.get(key);
            if (!card) {
                card = make('li', undefined, 'citizen-card'); card.dataset.citizen = key;
                const heading = make('div', undefined, 'citizen-heading'), identity = make('div');
                identity.append(make('h3'), make('p')); heading.append(avatar(key), identity);
                const native = make('div', undefined, 'native-state'); native.append(make('strong'), make('span', '', 'freshness'));
                const inspect = make('button', '', 'inspect'); inspect.type = 'button'; inspect.dataset.inspect = key;
                inspect.addEventListener('click', () => {
                    selected = key; returnFocus = inspect; actionFeedback = ''; resetConfirmation();
                    $('detail-avatar').replaceChildren(avatar(key)); $('citizen-detail').querySelector('.technical-details').open = false; renderDetail(); $('citizen-detail').showModal(); $('close-detail').focus();
                });
                card.append(heading, native, metricList(), make('p', '', 'attribution'), make('p', '', 'row-action'), inspect);
                cards.set(key, card); $('citizen-list').append(card);
            }
            const list = historical ? $('citizen-history') : $('citizen-list');
            if (card.parentElement !== list) list.append(card);
            card.hidden = !show; card.dataset.state = displayState;
            const current = citizenCurrent(host, citizen, inventory.observed_at, elapsed(), connected && active());
            setText(card.querySelector('h3'), toolName(citizen.tool));
            setText(card.querySelector('.citizen-heading p'), host.name + ' · ' + citizen.id.slice(0, 8));
            setText(card.querySelector('.native-state strong'), title(displayState));
            setText(card.querySelector('.freshness'), (current ? 'Fresh · ' : 'Recorded · ') + ageText(evidenceAge(inventory.observed_at, citizen.observed_at, elapsed())));
            const readings = card.querySelectorAll('dd');
        card.querySelector('.metrics').hidden = citizen.attribution === 'shared';
        setText(readings[0], resourceText(current && citizen.attribution === 'dedicated' ? citizen.cpu_percent : null, 'cpu'));
        setText(readings[1], resourceText(current && citizen.attribution === 'dedicated' ? citizen.rss_bytes : null, 'rss'));
        const attribution = card.querySelector('.attribution');
        if (citizen.attribution === 'shared') {
            if (attribution.dataset.sharedIdentity !== citizen.runtime_id) {
                attribution.replaceChildren(make('a', 'Shared runtime ' + citizen.runtime_id.slice(0, 8) + ' · no individual resource share'));
                attribution.querySelector('a').href = '#shared-title';
                attribution.dataset.sharedIdentity = citizen.runtime_id;
            }
        } else {
            delete attribution.dataset.sharedIdentity;
            setText(attribution, citizen.attribution === 'dedicated' ? 'Dedicated process' : 'Process attribution unavailable');
        }
            setText(card.querySelector('.row-action'), citizen.action ? 'Action: ' + title(citizen.action.status) : '');
            setText(card.querySelector('button'), 'View agent');
            card.querySelector('button').setAttribute('aria-label', 'View agent ' + toolName(citizen.tool) + ' ' + citizen.id + ' on ' + host.name);
        }
        const existing = new Set(all.map(row => row.key));
        for (const [key, card] of cards) if (!existing.has(key)) { const focused = card.contains(document.activeElement); card.remove(); cards.delete(key); if (focused) $('citizen-list').focus(); }
        for (const list of [$('citizen-list'), $('citizen-history')]) {
            const wanted = all.map(row => cards.get(row.key)).filter(card => card.parentElement === list);
            wanted.forEach((card, index) => { if (list.children[index] !== card) list.insertBefore(card, list.children[index] ?? null); });
        }
        for (const [state, count] of Object.entries(counts)) setText($('count-' + state), String(count));
        const filterCount = [hostFilter, tool, state].filter(Boolean).length;
        setText($('filter-count'), filterCount ? '(' + filterCount + ')' : '');
        $('reset-citizens').hidden = !filterCount && !query;
        $('recent-history').hidden = shownHistory === 0;
        setText($('history-count'), '(' + shownHistory + ')');
        if (focused?.isConnected && focused.closest('#recent-history') && $('recent-history').hidden) $('citizen-search').focus();
        if (focused?.isConnected && focused.closest('.citizen-card')) {
            if (!focused.checkVisibility()) {
                if (focused.closest('#citizen-history') && !$('recent-history').hidden) $('recent-history').querySelector('summary').focus(); else $('citizen-search').focus();
            } else if (document.activeElement !== focused) focused.focus({ preventScroll: true });
        }
        setText($('inventory-count'), visibleKeys.size + ' of ' + all.length + ' citizens');
        setText($('hub-role'), inventory.primary ? 'Primary hub' : 'Standby · read only');
        $('citizen-empty').hidden = shownCurrent !== 0;
        setText($('empty-title'), shownHistory ? 'No current agents in this view' : all.length ? 'No matching citizens' : 'No citizens reported');
        setText($('empty-copy'), shownHistory ? 'Stopped and failed agents are available in Recent history below.' : all.length ? 'Change filters or reset to see reported citizens.' : 'Check machine coverage for missing integrations. An incomplete inventory cannot prove no agents are running.');
        const hostIds = new Set(inventory.hosts.map(host => host.id));
        for (const option of [...$('citizen-host').options].slice(1)) if (!hostIds.has(option.value)) option.remove();
        for (const host of inventory.hosts) {
            let option = [...$('citizen-host').options].find(item => item.value === host.id);
            if (!option) { option = make('option'); option.value = host.id; $('citizen-host').append(option); }
            setText(option, host.name);
            if (!coverage.has(host.id)) { const node = make('p'); coverage.set(host.id, node); $('host-coverage').append(node); }
            setText(coverage.get(host.id), host.name + ' · ' + title(host.coverage) + ' coverage · contact ' + ageText(evidenceAge(inventory.observed_at, host.last_contact_at, elapsed())));
        }
        for (const [key, node] of coverage) if (!hostIds.has(key)) { node.remove(); coverage.delete(key); }
        const incomplete = inventory.hosts.filter(host => host.coverage !== 'complete' || !citizenCurrent(host, { observed_at: host.last_contact_at }, inventory.observed_at, elapsed(), connected && active())).length;
        const machineCount = inventory.hosts.length;
        setText($('coverage-summary'), incomplete ? incomplete + ' of ' + machineCount + ' machines need coverage review' : machineCount + (machineCount === 1 ? ' machine reporting' : ' machines reporting'));
        const groups = runtimeGroups(inventory.hosts, inventory.observed_at, elapsed(), connected && active());
        const groupKeys = new Set(groups.map(group => group.key));
        for (const group of groups) {
            let node = runtimes.get(group.key);
            if (!node) { node = make('li'); node.dataset.runtime = group.key; node.append(make('h3'), make('p'), metricList()); runtimes.set(group.key, node); $('shared-runtimes').append(node); }
            node.hidden = !group.members.some(citizen => visibleKeys.has(group.host.id + ':' + citizen.id));
            setText(node.querySelector('h3'), group.host.name + ' / Runtime ' + group.runtime_id.slice(0, 8));
            setText(node.querySelector('p'), group.members.length + ' citizens share this process. Individual force kill unavailable.');
            const metrics = node.querySelectorAll('dd'); setText(metrics[0], resourceText(group.cpu_percent, 'cpu')); setText(metrics[1], resourceText(group.rss_bytes, 'rss'));
        }
        for (const [key, node] of runtimes) if (!groupKeys.has(key)) { node.remove(); runtimes.delete(key); }
        $('shared-section').hidden = ![...runtimes.values()].some(node => !node.hidden);
        renderDetail();
    }
    async function refresh() {
        if (!active() || busy) return;
        clearTimeout(timer); busy = true; $('refresh-citizens').disabled = true;
        const run = ++generation, started = performance.now(), request = new AbortController(); controller = request;
        const deadline = setTimeout(() => request.abort(), 8000);
        try {
            const response = await fetch('/api/v1/citizens', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: request.signal });
            if (run !== generation) return;
            if ([401, 403].includes(response.status)) { clearPrivate(); return; }
            if (!response.ok) throw new Error('Inventory unavailable');
            const data = await response.json();
            if (run !== generation) return;
            if (!data || !Array.isArray(data.hosts) || !data.hosts.every(host => Array.isArray(host.citizens)) || time(data.observed_at) === null || typeof data.primary !== 'boolean') throw new Error('Invalid inventory');
            inventory = data; received = started; connected = true;
            for (const host of data.hosts) for (const citizen of host.citizens) {
                const key = host.id + ':' + citizen.id, local = localActions.get(key);
                if (citizen.action && (!local || (local.id ? citizen.action.id === local.id : citizen.action.id !== local.prior_id))) localActions.delete(key);
            }
            $('access-recovery').hidden = true;
            setText($('connection-note'), 'Live · updates every 2 seconds');
            $('connection-note').parentElement.dataset.state = 'connected';
        } catch {
            if (run !== generation || denied) return;
            connected = false;
            setText($('connection-note'), 'Refresh failed. Last received evidence is retained; live readings and actions are unavailable. Retry refresh.'); $('connection-note').parentElement.dataset.state = 'error';
        } finally {
            clearTimeout(deadline);
            if (run === generation) { busy = false; controller = null; $('refresh-citizens').disabled = denied; render(); if (active()) timer = setTimeout(refresh, 2000); }
        }
    }
    async function terminate() {
        const row = currentRow();
        if (posting || !confirmation || reason(row) || confirmation.revision !== row.citizen.revision) { resetConfirmation(); renderDetail(); return; }
        const target = { ...confirmation }, csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrf) { resetConfirmation(); setText($('terminate-reason'), 'CSRF token unavailable. Reload before taking action.'); return; }
        posting = true; actionFeedback = ''; const request = new AbortController(); actionController = request; renderDetail();
        const deadline = setTimeout(() => request.abort(), 8000);
        try {
            const response = await fetch('/api/v1/citizens/' + encodeURIComponent(row.host.id) + '/' + encodeURIComponent(row.citizen.id) + '/terminate', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ revision: target.revision, confirmation: true }), signal: request.signal,
            });
            if (denied) return;
            if ([401, 403].includes(response.status)) { clearPrivate(); return; }
            if (response.status >= 500) throw new Error('Action outcome uncertain');
            if (response.status !== 201) {
                actionFeedback = 'Request not accepted (HTTP ' + response.status + '). Refresh and review current process before retrying.';
                connected = false; resetConfirmation(); return;
            }
            const data = await response.json();
            if (denied) return;
            if (!data.action || data.action.status !== 'requested' || typeof data.action.id !== 'string') throw new Error('Invalid action receipt');
            localActions.set(target.key, data.action); resetConfirmation();
            announce('Force kill requested. Awaiting collector result; not yet terminated.');
        } catch {
            if (!denied) { localActions.set(target.key, { status: 'unknown', prior_id: row.citizen.action?.id }); resetConfirmation(); announce('Action outcome unknown. No automatic retry.'); }
        } finally {
            clearTimeout(deadline); posting = false; actionController = null;
            if (!denied) { render(); $('close-detail').focus(); void refresh(); }
        }
    }
    $('citizen-filters').addEventListener('submit', event => event.preventDefault());
    for (const event of ['input', 'change']) $('citizen-filters').addEventListener(event, render);
    $('citizen-state').addEventListener('change', () => {
        if (['stopped', 'failed'].includes($('citizen-state').value)) $('recent-history').open = true;
    });
    $('citizen-filters').addEventListener('reset', event => {
        event.preventDefault();
        for (const control of $('citizen-filters').elements) if (control.matches('input, select')) control.value = '';
        render();
    });
    $('refresh-citizens').addEventListener('click', refresh);
    $('close-detail').addEventListener('click', closeDetail);
    $('citizen-detail').addEventListener('cancel', event => { event.preventDefault(); if (confirmation && !posting) { resetConfirmation(); renderDetail(); $('request-terminate').focus(); } else closeDetail(); });
    $('request-terminate').addEventListener('click', () => {
        const row = currentRow(); if (!row || reason(row) || posting) return;
        confirmation = { key: row.key, revision: row.citizen.revision };
        setText($('confirmation-copy'), 'Force kill ' + toolName(row.citizen.tool) + ' ' + row.citizen.id.slice(0, 8) + ' on ' + row.host.name + ' (' + row.host.id + '), PID ' + row.citizen.process.pid + '?');
        $('kill-confirmation').hidden = false; renderDetail(); $('cancel-terminate').focus();
    });
    $('cancel-terminate').addEventListener('click', () => { resetConfirmation(); renderDetail(); $('request-terminate').focus(); });
    $('confirm-terminate').addEventListener('click', terminate);
    function suspend() {
        generation++; controller?.abort(); controller = null; busy = false; connected = false;
        clearTimeout(timer); clearInterval(ageTimer); ageTimer = null; $('refresh-citizens').disabled = denied;
        if (!denied) { setText($('connection-note'), navigator.onLine ? 'Paused while page is hidden. Revalidating when visible.' : 'Offline. Polling paused; no actions are queued.'); render(); }
    }
    function resume() {
        if (!active()) { suspend(); return; }
        connected = false; render();
        clearInterval(ageTimer); ageTimer = setInterval(render, 1000); void refresh();
    }
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('offline', suspend); window.addEventListener('online', resume);
    window.addEventListener('pagehide', () => { suspend(); actionController?.abort(); });
    window.addEventListener('pageshow', event => { if (event.persisted) resume(); });
    resume();
}
if (typeof document !== 'undefined') {
    boot();
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});
}
