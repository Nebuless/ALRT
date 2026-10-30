// Orbit is a read-only owner API adapter. No telemetry is persisted in the browser.
const STALE_SECONDS = 30;
const PROCESS_WINDOW = 4 * 60 * 60;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const finite = value => typeof value === 'number' && Number.isFinite(value) && value >= 0;
const timestamp = value => typeof value === 'string' && Number.isFinite(Date.parse(value)) ? Date.parse(value) : null;
const title = value => String(value ?? 'unknown').replaceAll('_', ' ');
const number = value => finite(value) ? new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(value) : 'Unavailable';
const datetime = value => timestamp(value) === null ? 'Unknown' : new Date(value).toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');
const clock = value => timestamp(value) === null ? 'Unknown' : new Date(value).toISOString().slice(11, 19);
const ageText = value => value === null ? 'Unknown' : value < 60 ? Math.floor(value) + ' s ago' : value < 3600 ? Math.floor(value / 60) + ' min ago' : Math.floor(value / 3600) + ' h ' + Math.floor(value % 3600 / 60) + ' min ago';

export function metricValue(metric) {
    return metric?.status === 'available' && finite(metric.value) ? metric.value : null;
}
export function percent(used, total) {
    const numerator = metricValue(used), denominator = metricValue(total);
    return numerator !== null && denominator !== null && denominator > 0 ? numerator / denominator * 100 : null;
}
export function evidenceAge(host, field, elapsed = 0) {
    const supplied = host?.[field + '_age_seconds'];
    const observed = timestamp(host?.observed_at);
    const measured = timestamp(host?.[field === 'sample' ? 'sampled_at' : 'contact_at']);
    if (!finite(supplied) || observed === null || measured === null || measured > observed) return null;
    return Math.max(supplied, (observed - measured) / 1000) + Math.max(0, elapsed);
}
export function compareProcesses(a, b, sort) {
    if (sort === 'name') return String(a.name).localeCompare(String(b.name), 'en');
    const key = sort === 'memory' ? 'rss_bytes' : 'cpu_percent';
    const av = finite(a[key]) ? a[key] : null, bv = finite(b[key]) ? b[key] : null;
    if (av === null) return bv === null ? 0 : 1;
    if (bv === null) return -1;
    return sort === 'cpu-asc' ? av - bv : bv - av;
}
export function observationGroup(host, elapsed = 0, connected = true, responseAge = 0) {
    const state = host?.observation?.state;
    if (state === 'silent' || state === 'suspect') return 'offline';
    const sample = evidenceAge(host, 'sample', elapsed), contact = evidenceAge(host, 'contact', elapsed);
    if (sample === null || contact === null) return 'unknown';
    if (!connected || sample > STALE_SECONDS || contact > STALE_SECONDS || responseAge > STALE_SECONDS || state === 'stale_metrics') return 'stale';
    if (['partial_data', 'pressure', 'recovering'].includes(state)) return 'partial';
    return state === 'healthy' ? 'fresh' : 'unknown';
}
// Ring emphasis describes a fresh sample, not the sustained incident evaluator.
export function resourceRings(host, current) {
    const metrics = host?.metrics;
    const disks = Array.isArray(metrics?.disks) ? metrics.disks : [];
    const percentage = value => finite(value) && value <= 100 ? value : null;
    const storage = disks.map(disk => percentage(percent(disk.used_bytes, disk.total_bytes))).filter(value => value !== null);
    const values = { cpu: percentage(metricValue(metrics?.cpu_percent)), memory: percentage(percent(metrics?.memory?.used_bytes, metrics?.memory?.total_bytes)), storage: storage.length ? Math.max(...storage) : null };
    return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, { value: current ? value : null, high: current && value !== null && value >= 75 }]));
}
export function nodeAppearance(id) {
    let seed = 2166136261;
    for (const character of id) seed = Math.imul(seed ^ character.charCodeAt(0), 16777619) >>> 0;
    seed = Math.imul(seed ^ (seed >>> 16), 2246822507) >>> 0;
    seed = (seed ^ (seed >>> 13)) >>> 0;
    return { material: seed % 5, phase: -(seed % 360) / 10, tint: (seed >>> 8) % 25 - 12 };
}
export function historyRange(observedAt, minutes) {
    const end = timestamp(observedAt);
    if (end === null || ![30, 60, 240].includes(minutes)) throw new Error('A hub observation is required for history.');
    // ponytail: the four-hour option leaves 15 seconds for transit at the dirty retention boundary.
    return { from: new Date(end - minutes * 60000 + (minutes === 240 ? 15000 : 0)).toISOString(), to: new Date(end).toISOString(), resolution: 'minute' };
}

const $ = id => document.getElementById(id);
const make = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
};
const svg = (tag, attributes = {}, text) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, String(value));
    if (text !== undefined) node.textContent = text;
    return node;
};

function boot() {
    if (!$('sky')) return;
    let fleet = null, detail = null, timeline = null, incidents = null;
    let selected = new URL(location.href).searchParams.get('host') || '';
    let range = 60, connected = false, refreshing = false, denied = false;
    let error = '', timer = null, ageTimer = null, controller = null, running = Promise.resolve(), generation = 0;
    let focusReturn = null, focusedSystem = '', selectionMotion = null;
    let gaugeRecord = null, gaugeAvailability = null, processItems = null, processQuery = null, processSort = null;
    const elapsed = record => record ? Math.max(0, (performance.now() - record.at) / 1000) : 0;
    const observedAge = record => record ? elapsed(record) : Infinity;
    const host = () => detail?.data?.id === selected ? detail : null;
    const hosts = () => fleet?.data.hosts || [];
    const stamp = (data, at) => ({ data, at });
    const announce = text => { $('announcement').textContent = text; };
    function settleSelectionMotion() {
        const motion = selectionMotion;
        selectionMotion = null;
        if (!motion) return;
        try { if (motion.playState === 'running' || motion.playState === 'paused') motion.finish(); }
        finally { motion.cancel(); }
    }
    function syncCitizensLink() {
        document.querySelectorAll('.owner-navigation a').forEach(link => {
            const target = new URL(link.getAttribute('href'), location.origin);
            if (!['/', '/orbit', '/citizens'].includes(target.pathname)) return;
            if (UUID.test(selected)) target.searchParams.set('host', selected); else target.searchParams.delete('host');
            link.href = target.pathname + target.search;
        });
        $('host-instruments').href = UUID.test(selected) ? '/hosts/' + selected : '#instruments';
        $('host-instruments').hidden = !UUID.test(selected);
    }
    const currentMetrics = record => {
        if (!record || !connected || document.hidden) return false;
        const sample = evidenceAge(record.data, 'sample', elapsed(record));
        const contact = evidenceAge(record.data, 'contact', elapsed(record));
        return sample !== null && sample <= STALE_SECONDS && contact !== null && contact <= STALE_SECONDS
            && observedAge(record) <= STALE_SECONDS && !['silent', 'suspect', 'awaiting_report', 'stale_metrics'].includes(record.data.observation?.state);
    };
    const group = (h, at = fleet?.at) => {
        const record = stamp(h, at);
        return observationGroup(h, elapsed(record), connected, observedAge(record));
    };
    function writeURL(id, push) {
        const url = new URL(location.href);
        if (id) url.searchParams.set('host', id); else url.searchParams.delete('host');
        if (url.href !== location.href) history[push ? 'pushState' : 'replaceState'](null, '', url);
    }
    function closeDrawer() {
        if ($('signal-drawer').open) $('signal-drawer').close();
        $('drawer-evidence').replaceChildren();
        for (const id of ['drawer-title', 'drawer-copy', 'drawer-kicker']) $(id).textContent = '';
    }
    function selectHost(id, push = true) {
        if (!UUID.test(id)) return;
        const node = [...$('sky').querySelectorAll('.planet')].find(button => button.dataset.host === id);
        const before = node?.querySelector('.system-body').getBoundingClientRect();
        const changed = id !== selected;
        selected = id; focusedSystem = id;
        if (changed) detail = timeline = incidents = null;
        closeDrawer(); writeURL(id, push); render();
        settleSelectionMotion();
        const graphic = node?.querySelector('.system-body');
        if (graphic && before && !sky.classList.contains('motion-paused') && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const after = graphic.getBoundingClientRect();
            selectionMotion = graphic.animate([
                { transform: `translate(${before.x + before.width / 2 - after.x - after.width / 2}px, ${before.y + before.height / 2 - after.y - after.height / 2}px) scale(${before.width / after.width})`, opacity: .65 },
                { transform: 'translate(0, 0) scale(1.04)', opacity: 1, offset: .8 },
                { transform: 'translate(0, 0) scale(1)', opacity: 1 },
            ], { duration: 650, easing: 'cubic-bezier(.16,1,.3,1)' });
        }
        if (changed) refresh();
        announce('System selected. Close-up and instruments updated.');
    }
    function renderConnection() {
        const old = fleet && observedAge(fleet) > STALE_SECONDS;
        let state = 'connected', label = 'CONNECTED';
        let message = 'Connected to owner API. Polling every 10 seconds while visible. Hub read time is not sample time.';
        if (denied) {
            state = 'access-error'; label = 'ACCESS REQUIRED'; message = error;
        } else if (error) {
            state = 'error';
            label = 'DISCONNECTED';
            if (fleet && connected) label = old ? 'OBSERVATION OLD' : 'CONNECTED';
            message = error + (fleet ? ' Recorded evidence only. Last fleet observation ' + datetime(fleet.data.observed_at) + '.' : ' No observations available.');
        } else if (!fleet) {
            state = 'loading'; label = 'LOADING'; message = 'Loading owner telemetry. No observations received.';
        } else if (!connected) {
            state = 'stale'; label = 'DISCONNECTED'; message = 'Rechecking connection. Recorded evidence is stale until refresh succeeds.';
        } else if (old) {
            state = 'stale'; label = 'OBSERVATION OLD'; message = 'Recorded hub observation is old. Current measurements are hidden. Refresh to reconnect.';
        } else if (refreshing) {
            state = 'loading'; message = 'Refreshing owner telemetry. Sample times remain unchanged.';
        }
        $('connection').dataset.state = state;
        $('connection-state').dataset.state = state;
        $('connection-state').textContent = label;
        if ($('connection-note').textContent !== message) $('connection-note').textContent = message;
        $('retry').disabled = refreshing;
        $('retry').textContent = denied ? 'Retry access' : refreshing ? 'Refreshing…' : 'Refresh now';
        $('observation-time').textContent = fleet ? datetime(fleet.data.observed_at) : 'Not yet received';
        if (fleet) $('observation-time').dateTime = fleet.data.observed_at; else $('observation-time').removeAttribute('datetime');
    }
    const sky = $('sky');
    const stars = svg('svg', { class: 'star-field', viewBox: '0 0 600 420', preserveAspectRatio: 'xMidYMid slice', 'aria-hidden': 'true' });
    for (let index = 0; index < 96; index++) {
        stars.append(svg('circle', { cx: (index * 167 + 23) % 600, cy: (index * 113 + 41) % 420, r: index % 11 === 0 ? 1.4 : .6, opacity: index % 3 === 0 ? .65 : .25 }));
    }
    sky.append(stars);
    const returnToSky = $('return-to-sky');
    returnToSky.addEventListener('click', () => {
        settleSelectionMotion(); focusedSystem = ''; renderFleet();
        ([...sky.querySelectorAll('.planet')].find(button => !button.hidden && button.dataset.host === selected) || document.querySelector('.system-chooser summary')).focus({ preventScroll: true });
    });
    const constellationObserver = new IntersectionObserver(([entry]) => {
        sky.classList.toggle('in-view', entry.isIntersecting && !document.hidden);
    });
    constellationObserver.observe(sky);
    const pauseConstellation = $('pause-constellation');
    pauseConstellation.hidden = false;
    pauseConstellation.addEventListener('click', () => {
        const paused = sky.classList.toggle('motion-paused');
        if (paused) settleSelectionMotion();
        pauseConstellation.setAttribute('aria-pressed', String(paused));
        pauseConstellation.textContent = paused ? 'Resume motion' : 'Pause motion';
    });
    function renderFleet() {
        const active = document.activeElement;
        const managedFocus = active?.closest('#sky .planet, #return-to-sky, #node-card');
        const all = hosts(), query = $('host-search').value.trim().toLowerCase(), filter = $('state-filter').value;
        const shown = all.filter(h => (!query || (h.name + ' ' + h.id).toLowerCase().includes(query)) && (filter === 'all' || group(h) === filter));
        const summary = [make('span', fleet ? 'SECTOR 01 / ' + all.length + ' HOSTS' : 'Waiting for fleet observation.', 'sector')];
        if (fleet) for (const [key, label] of [['fresh', 'recent'], ['partial', 'attention / partial'], ['stale', 'stale'], ['offline', 'silent / suspect'], ['unknown', 'unknown']]) {
            const item = make('span', undefined, key);
            const dot = make('i', undefined, 'state-dot'); dot.setAttribute('aria-hidden', 'true');
            item.append(dot, make('strong', all.filter(h => group(h) === key).length), document.createTextNode(label)); summary.push(item);
        }
        $('fleet-summary').replaceChildren(...summary);
        const sky = $('sky');
        // Keep node elements stable during polling so keyboard focus is never discarded.
        const existing = new Map([...sky.querySelectorAll('.planet')].map(node => [node.dataset.host, node]));
        all.forEach((h, index) => {
            let button = existing.get(h.id);
            if (!button) {
                button = make('button', undefined, 'planet'); button.type = 'button'; button.dataset.host = h.id;
                const star = svg('svg', { class: 'system-star', viewBox: '0 0 112 112', 'aria-hidden': 'true' });
                for (const [key, angle, radius] of [['cpu', -32, 43], ['memory', 38, 36], ['storage', 90, 29]]) {
                    const ring = svg('g', { class: 'resource-ring ' + key });
                    const plane = svg('g', { class: 'ring-plane' });
                    plane.style.setProperty('--ring-angle', angle + 'deg');
                    plane.append(svg('ellipse', { cx: 56, cy: 56, rx: radius, ry: radius * .46 }));
                    ring.append(plane);
                    star.append(ring);
                }
                const surface = make('span', undefined, 'planet-sphere'); surface.setAttribute('aria-hidden', 'true');
                surface.append(make('span', undefined, 'planet-terrain'), make('span', undefined, 'planet-shadow'));
                const body = make('span', undefined, 'system-body'); body.append(star, surface);
                button.append(body, make('span', '', 'planet-name'));
                button.addEventListener('click', () => selectHost(h.id)); sky.append(button);
            }
            existing.delete(h.id);
            button.dataset.slot = index;
            const appearance = nodeAppearance(h.id);
            button.dataset.material = String(appearance.material);
            button.style.setProperty('--surface-phase', appearance.phase + 's');
            button.style.setProperty('--surface-tint', appearance.tint + 'deg');
            button.style.setProperty('--star-color', ['#b7dfff', '#ffd5a8', '#a9e8db', '#dfbdff', '#9fcdf7'][appearance.material]);
            button.hidden = !shown.some(item => item.id === h.id);
            button.setAttribute('aria-pressed', String(h.id === selected));
            const rings = resourceRings(h, currentMetrics(stamp(h, fleet.at)));
            const summaries = [];
            for (const [key, ring] of Object.entries(rings)) {
                const graphic = button.querySelector('.resource-ring.' + key);
                graphic.classList.toggle('high', ring.high);
                graphic.dataset.state = ring.value === null ? 'unavailable' : ring.high ? 'high' : 'normal';
                summaries.push(({ cpu: 'CPU', memory: 'RAM', storage: 'Storage' }[key]) + ' ' + (ring.value === null ? 'unavailable' : number(ring.value) + '%' + (ring.high ? ' high' : '')));
            }
            button.querySelector('.planet-name').textContent = h.name;
            button.setAttribute('aria-label', 'Select ' + h.name + ', ' + title(h.observation?.state) + '. ' + summaries.join(', '));
        });
        for (const node of existing.values()) node.remove();
        if (focusedSystem && !shown.some(h => h.id === focusedSystem)) focusedSystem = '';
        sky.classList.toggle('close-up', !!focusedSystem);
        sky.parentElement.classList.toggle('has-selection', !!focusedSystem);
        const selectedHost = all.find(item => item.id === focusedSystem);
        const card = $('node-card'); card.hidden = !selectedHost;
        if (!selectedHost) {
            for (const key of ['title', 'age', 'cpu', 'memory', 'storage', 'state']) $('node-card-' + key).textContent = '';
        }
        if (selectedHost) {
            const record = stamp(selectedHost, fleet.at), current = currentMetrics(record);
            const readings = resourceRings(selectedHost, current);
            $('node-card-title').textContent = selectedHost.name;
            $('node-card-age').textContent = 'Sample ' + ageText(evidenceAge(selectedHost, 'sample', elapsed(record)));
            for (const [key, reading] of Object.entries(readings)) $('node-card-' + key).textContent = reading.value === null ? 'Unavailable' : number(reading.value) + '%';
            $('node-card-state').textContent = current ? 'Fresh sample · rings glow at 75%. Storage shows the highest available disk usage.' : 'Current readings unavailable. Recorded state: ' + title(selectedHost.observation?.state) + '.';
        }
        returnToSky.hidden = !focusedSystem;
        for (const button of sky.querySelectorAll('.planet')) button.classList.toggle('is-focused', button.dataset.host === focusedSystem);
        sky.classList.toggle('many', all.length > 4);
        sky.hidden = !shown.length;
        $('fleet-empty').hidden = !fleet || shown.length > 0;
        $('fleet-empty').querySelector('h3').textContent = all.length ? 'No hosts in this orbit.' : 'No enrolled hosts reported.';
        $('fleet-empty').querySelector('p').textContent = all.length ? 'Try another name or signal state. Selected instruments remain visible.' : 'The owner API returned an empty fleet.';
        $('reset-hosts').hidden = !all.length;
        const pending = all.filter(h => h.actions?.latest && ['queued', 'cancel_window', 'awaiting_primary', 'authorized', 'attempted'].includes(h.actions.latest.state));
        const actions = $('pending-host-actions');
        actions.hidden = !pending.length;
        const signature = pending.map(h => [h.id, h.actions.latest.id, h.actions.latest.state].join(':')).join('|');
        if (actions.dataset.signature !== signature) {
            const focused = document.activeElement?.closest('#pending-host-actions a')?.dataset.host;
            actions.dataset.signature = signature;
            actions.replaceChildren(...pending.map(h => {
                const link = make('a', h.name + ' · ' + title(h.actions.latest.state) + ' reboot · inspect or cancel');
                link.dataset.host = h.id;
                link.href = '/hosts/' + h.id + '#capabilities';
                return link;
            }));
            if (focused) ([...actions.querySelectorAll('a')].find(link => link.dataset.host === focused) || document.querySelector('.system-chooser summary')).focus({ preventScroll: true });
        }
        $('host-count').textContent = fleet ? shown.length + ' / ' + all.length + ' hosts' : 'Loading hosts';
        const options = shown.map(h => { const option = make('option', h.name + ' · ' + title(h.observation?.state)); option.value = h.id; return option; });
        if (all.length && !shown.some(h => h.id === selected)) { const option = make('option', selected ? 'Selected host outside this list' : 'Select a host'); option.value = ''; options.unshift(option); }
        const list = $('host-list');
        if (list.options.length !== options.length || options.some((option, index) => list.options[index].value !== option.value || list.options[index].textContent !== option.textContent)) list.replaceChildren(...options);
        list.disabled = !shown.length;
        $('host-list').value = shown.some(h => h.id === selected) ? selected : '';
        if (managedFocus && (!active.isConnected || !active.checkVisibility())) document.querySelector('.system-chooser summary').focus({ preventScroll: true });
    }
    function gauge(key, label, value, note) {
        const figure = make('figure', undefined, 'gauge ' + key);
        const graphic = svg('svg', { viewBox: '0 0 180 160', role: 'img', 'aria-labelledby': key + '-title ' + key + '-desc' });
        graphic.append(svg('title', { id: key + '-title' }, label + ': ' + (value === null ? 'unavailable' : number(value) + '%')), svg('desc', { id: key + '-desc' }, note));
        for (let i = 0; i < 25; i++) { const angle = (135 + i * 11.25) * Math.PI / 180; graphic.append(svg('line', { x1: 90 + 73 * Math.cos(angle), y1: 79 + 73 * Math.sin(angle), x2: 90 + 78 * Math.cos(angle), y2: 79 + 78 * Math.sin(angle), class: 'ticks' })); }
        graphic.append(svg('circle', { cx: 90, cy: 79, r: 59, class: 'dial-track', pathLength: 100, 'stroke-dasharray': '75 25', transform: 'rotate(135 90 79)' }));
        if (value !== null) graphic.append(svg('circle', { cx: 90, cy: 79, r: 59, class: 'dial-value', pathLength: 100, 'stroke-dasharray': Math.min(100, value) * .75 + ' 100', transform: 'rotate(135 90 79)' }));
        graphic.append(svg('text', { x: 90, y: 83, class: 'gauge-value' }, value === null ? 'N/A' : number(value)), svg('text', { x: 90, y: 105, class: 'gauge-unit' }, value === null ? 'unavailable' : 'percent'));
        figure.append(graphic, make('figcaption', label), make('div', note, 'subunit'));
        return figure;
    }
    function renderDock() {
        const record = host(), h = record?.data, available = currentMetrics(record), metrics = h?.metrics;
        $('dock-title').textContent = denied ? 'Access required' : h?.name || (selected ? 'Loading selected host' : 'Select a host');
        $('dock-sub').textContent = h ? [h.id, h.scope ? title(h.scope) + ' scope' : 'Scope unavailable', h.capabilities?.os || 'OS unavailable'].join(' / ') : selected && !UUID.test(selected) ? 'Invalid host ID in URL.' : 'Waiting for owner API evidence.';
        $('dock-state').textContent = h ? (!currentMetrics(record) ? 'Recorded assessment · ' : '') + title(h.observation?.state) : 'Unavailable';
        $('dock-state').className = 'status-pill ' + (h ? group(h, record.at) : 'offline');
        $('contact-age').textContent = ageText(evidenceAge(h, 'contact', elapsed(record)));
        $('sample-age').textContent = ageText(evidenceAge(h, 'sample', elapsed(record)));
        const cpu = metricValue(metrics?.cpu_percent), memory = percent(metrics?.memory?.used_bytes, metrics?.memory?.total_bytes);
        const disks = Array.isArray(metrics?.disks) ? metrics.disks : [];
        const diskValues = disks.map(d => ({ disk: d, value: percent(d.used_bytes, d.total_bytes) }));
        const count = Number(cpu !== null) + Number(memory !== null) + diskValues.filter(d => d.value !== null).length;
        $('coverage').textContent = h ? count + ' / ' + (2 + disks.length) + (available ? ' reported' : ' recorded') : 'Unknown';
        const reasons = [h?.unavailable_reason, ...(h?.observation?.unavailable_reasons || [])].filter(Boolean).map(title);
        $('state-note').textContent = h ? [h.observation?.sentence, available ? 'Current values from sample ' + datetime(h.sampled_at) + '.' : 'Current metrics unavailable. Recorded sample ' + datetime(h.sampled_at) + '; contact is not sample freshness.', ...reasons].filter(Boolean).join(' ') : error || (selected && fleet && !hosts().some(item => item.id === selected) ? 'Selected host is not in the returned fleet. Choose another host or retry.' : 'Waiting for a host observation.');
        if (gaugeRecord === record && gaugeAvailability === available) return;
        gaugeRecord = record; gaugeAvailability = available;
        const byteDetail = metric => metricValue(metric) === null ? 'Total unavailable' : number(metricValue(metric) / 1024 ** 3) + ' GiB total';
        const unknown = available ? 'Not reported' : 'Current unknown';
        const gauges = [gauge('cpu', 'CPU load', available ? cpu : null, available && cpu !== null ? 'Host aggregate busy %' : unknown), gauge('memory', 'Memory', available ? memory : null, available && memory !== null ? byteDetail(metrics?.memory?.total_bytes) : unknown)];
        if (!disks.length) gauges.push(gauge('disk', 'Disk', null, 'No disk evidence reported'));
        diskValues.forEach(({ disk, value }, index) => gauges.push(gauge('disk-' + index, 'Disk · ' + disk.label, available ? value : null, available && value !== null ? byteDetail(disk.total_bytes) : unknown)));
        $('gauges').replaceChildren(...gauges);
    }
    function renderRibbon() {
        const chart = $('heat-ribbon'), data = timeline?.data;
        chart.replaceChildren(svg('title', { id: 'heat-title' }, 'Recorded CPU minute averages'), svg('desc', { id: 'heat-description' }, 'Bars show bucket CPU averages. Stripes mark unknown intervals. Bars are not continuous coverage.'));
        const defs = svg('defs'), pattern = svg('pattern', { id: 'gap-hatch', width: 8, height: 8, patternUnits: 'userSpaceOnUse' });
        pattern.append(svg('path', { d: 'M-2 2L2 -2M0 8L8 0M6 10L10 6', stroke: '#635373', 'stroke-width': 1 })); defs.append(pattern);
        chart.append(defs, svg('rect', { x: 0, y: 4, width: 600, height: 60, fill: 'url(#gap-hatch)', rx: 5 }));
        $('range-start').textContent = data ? clock(data.from) : '—'; $('range-end').textContent = data ? clock(data.to) : '—';
        for (const button of document.querySelectorAll('[data-range]')) button.setAttribute('aria-pressed', String(Number(button.dataset.range) === range));
        if (!data) { $('ribbon-note').textContent = selected ? 'History unavailable or loading. Unknown intervals remain gaps.' : 'Select a host for recorded history.'; return; }
        const start = timestamp(data.from), end = timestamp(data.to), width = end - start;
        let shown = 0;
        for (const point of data.points) {
            const t = timestamp(point.bucket_at), value = point.metrics?.cpu_percent?.average;
            if (t === null || !finite(value) || !(point.coverage_seconds > 0) || t >= end || t + point.bucket_seconds * 1000 <= start) continue;
            const x = Math.max(0, (t - start) / width * 600), right = Math.min(600, (t + point.bucket_seconds * 1000 - start) / width * 600);
            const height = 10 + Math.min(100, value) * .5;
            const mark = svg('rect', { x, y: 64 - height, width: Math.max(.2, right - x - 1), height, rx: 2, fill: value > 70 ? '#ff8cc8' : '#a6e6ff', class: 'sample', 'data-time': point.bucket_at, 'data-value': value });
            mark.append(svg('title', {}, datetime(point.bucket_at) + ' · CPU average ' + number(value) + '% · observed coverage ' + number(point.coverage_seconds) + ' s'));
            chart.append(mark); shown++;
        }
        // Draw supplied gaps over buckets: an aggregate is never proof of continuous sampling.
        for (const gap of data.gaps) {
            const a = timestamp(gap.from), b = timestamp(gap.to);
            if (a === null || b === null || b <= a) continue;
            const left = Math.max(start, a), right = Math.min(end, b);
            if (right > left) {
                const attributes = { x: (left - start) / width * 600, y: 4, width: (right - left) / width * 600, height: 60 };
                chart.append(svg('rect', { ...attributes, fill: '#211931' }), svg('rect', { ...attributes, fill: 'url(#gap-hatch)', class: 'history-gap' }));
            }
        }
        chart.dataset.range = range;
        $('ribbon-note').textContent = (!connected || timeline.error ? 'Stale recorded history. ' : 'Recorded history. ') + shown + ' CPU buckets · height = average % · pink >70% · stripes = unknown gaps. ' + datetime(data.from) + ' to ' + datetime(data.to) + '. Edge bucket statistics may include samples outside the selected edge.' + (range === 240 ? ' Four-hour view leaves a 15-second retention margin.' : '') + (timeline.error ? ' ' + timeline.error : '');
    }
    function renderProcesses() {
        const record = host(), h = record?.data, sampleAge = evidenceAge(h, 'sample', elapsed(record));
        const key = $('process-category').value, category = h?.processes?.[key], scan = h?.coverage?.processes;
        const retained = sampleAge !== null && sampleAge <= PROCESS_WINDOW;
        const items = retained && Array.isArray(category?.items) ? category.items : null;
        const query = $('process-filter').value.trim().toLowerCase();
        const sort = $('process-sort').value;
        if (processItems !== items || processQuery !== query || processSort !== sort) {
            processItems = items; processQuery = query; processSort = sort;
            const previous = [...$('process-body').querySelectorAll('tr')];
            const open = new Set(previous.filter(row => row.querySelector('details')?.open).map(row => row.dataset.identity));
            const focused = document.activeElement?.closest('#process-body tr')?.dataset.identity;
            const rows = (items || []).filter(p => (p.name + ' ' + p.identity?.pid).toLowerCase().includes(query)).sort((a, b) => compareProcesses(a, b, sort));
            $('process-body').replaceChildren(...rows.map(p => {
                const tr = make('tr'), identity = p.identity || {};
                tr.dataset.identity = [identity.boot_id, identity.pid, identity.start_ticks].join(':');
                const name = make('td', p.name), processIdentity = make('details', undefined, 'process-identity');
                processIdentity.open = open.has(tr.dataset.identity);
                processIdentity.append(make('summary', 'PID ' + String(identity.pid ?? 'unknown') + ' · identity'), make('p', 'Boot ' + String(identity.boot_id ?? 'unknown') + ' · start ticks ' + String(identity.start_ticks ?? 'unknown')));
                name.append(processIdentity);
                tr.append(name, make('td', identity.pid ?? 'Unknown', 'pid-column'), make('td', number(p.cpu_percent)), make('td', finite(p.rss_bytes) ? number(p.rss_bytes / 1024 ** 2) : 'Unavailable'), make('td', p.state || 'Unknown'));
                return tr;
            }));
            if (focused) {
                const row = [...$('process-body').querySelectorAll('tr')].find(row => row.dataset.identity === focused);
                (row?.querySelector('summary') || $('process-filter')).focus({ preventScroll: true });
            }
        }
        const rowCount = $('process-body').children.length;
        const note = !h ? 'Process evidence unavailable or loading.' : !retained ? 'Process evidence unavailable: sample age unknown or older than four hours.' : [currentMetrics(record) ? 'Latest available ranking' : 'Historical / stale recorded ranking', key === 'top_cpu' ? 'top CPU' : 'top memory (RSS)', h.name, datetime(h.sampled_at), ageText(sampleAge), 'category ' + title(category?.completeness) + (category?.reason ? ' (' + title(category.reason) + ')' : ''), (items?.length || 0) + ' supplied of ' + number(category?.total_observed) + ' eligible observed', 'scan ' + title(scan?.status) + ': ' + number(scan?.sampled) + ' sampled / ' + number(scan?.discovered) + ' discovered' + (scan?.reason ? ' (' + title(scan.reason) + ')' : ''), 'Not a full process inventory. CPU is one-core % and may exceed 100. Memory is resident MiB.'].join(' · ');
        $('snapshot-note').textContent = note; $('process-caption').textContent = note;
        $('process-count').textContent = retained && category ? rowCount + ' / ' + (items?.length || 0) + ' supplied' : 'Unavailable';
        $('process-empty').hidden = !!rowCount;
        $('process-empty').querySelector('h3').textContent = query && items?.length ? 'No matching flights.' : 'No process evidence to display.';
        $('process-empty').querySelector('p').textContent = query && items?.length ? 'Nothing matches this process filter.' : category?.completeness === 'complete' && retained ? 'No entries in this reported ranking.' : 'Missing or expired evidence does not mean no processes.';
        $('reset-process').hidden = !query;
    }
    function signalRows() {
        const active = (fleet?.data.incidents || []).filter(item => item.target === selected || item.kind === 'hub');
        const today = incidents?.data || [];
        const combined = new Map(today.map(item => [String(item.id), item]));
        for (const item of active) combined.set(String(item.id), { ...combined.get(String(item.id)), ...item });
        return [...combined.values()];
    }
    function renderSignals() {
        const events = signalRows();
        $('signal-count').textContent = fleet ? events.length + ' recorded' : 'Unavailable';
        $('signal-note').textContent = !fleet ? 'Incident evidence not yet loaded.' : (!connected ? 'Stale recorded evidence. ' : '') + 'Selected host: today UTC and unresolved. Hub-wide active incidents are identified separately.' + (incidents?.error ? ' Timeline unavailable: ' + incidents.error : '');
        const focusedSignal = document.activeElement?.closest?.('[data-incident]')?.dataset.incident;
        $('signal-list').replaceChildren(...events.map(event => {
            const li = make('li'), button = make('button', undefined, 'signal-button'), copy = make('span');
            button.type = 'button'; button.dataset.incident = String(event.id);
            const beacon = make('span', undefined, 'beacon ' + (event.resolved_at ? 'info' : '')); beacon.setAttribute('aria-hidden', 'true');
            copy.append(make('strong', (event.target_name || event.target || 'Unknown target') + ' · ' + title(event.rule)), make('small', (event.kind === 'hub' ? 'Hub · ' : 'Host · ') + (event.resolved_at ? 'Resolved ' + datetime(event.resolved_at) : 'Unresolved record') + ' · opened ' + datetime(event.opened_at)));
            const arrow = make('span', '↗', 'signal-arrow'); arrow.setAttribute('aria-hidden', 'true');
            button.append(beacon, copy, arrow); button.addEventListener('click', () => inspectEvent(event, button)); li.append(button); return li;
        }));
        if (focusedSignal && !$('signal-drawer').open) [...$('signal-list').querySelectorAll('button')].find(button => button.dataset.incident === focusedSignal)?.focus();
        if (fleet && !events.length) $('signal-list').append(make('li', incidents ? 'No incident records returned for this host or active hubs. This is not a health guarantee.' : 'No active incidents returned. Host timeline unavailable or loading.', 'snapshot-note'));
    }
    function inspectEvent(event, button) {
        focusReturn = String(event.id);
        $('drawer-kicker').textContent = (event.kind === 'hub' ? 'HUB' : 'HOST') + ' / RECORDED EVIDENCE';
        $('drawer-title').textContent = (event.target_name || event.target) + ' · ' + title(event.rule);
        $('drawer-copy').textContent = 'This API supplies an incident rule and lifecycle timestamps, not threshold-time measurements or a severity. Latest host metrics are not incident-time evidence.';
        const evidence = [['Target', event.target_name || event.target], ['Target ID', event.target], ['Kind', event.kind], ['Rule', event.rule], ['Severity', event.severity || 'Not supplied by API'], ['Measured incident evidence', 'Not supplied by API'], ['Opened', datetime(event.opened_at)], ['Resolved', event.resolved_at ? datetime(event.resolved_at) : 'No resolution supplied (unresolved record)'], ['API observation', datetime(event.observed_at || fleet?.data.observed_at)], ['Provenance', event.provenance], ['Connection', connected ? 'Recorded evidence; not a current diagnosis' : 'Disconnected; stale recorded evidence']];
        $('drawer-evidence').replaceChildren(...evidence.flatMap(([key, value]) => [make('dt', key), make('dd', value ?? 'Unavailable')]));
        $('signal-drawer').showModal();
    }
    function render() { syncCitizensLink(); syncAccessRecovery(); renderConnection(); renderFleet(); renderDock(); renderRibbon(); renderProcesses(); renderSignals(); }
    function clearPrivate(message) {
        denied = true; connected = false; error = message;
        fleet = detail = timeline = incidents = null; focusedSystem = '';
        $('host-search').value = ''; $('process-filter').value = '';
        closeDrawer(); focusReturn = null; render();
        $('dock-title').textContent = 'Access required'; $('dock-sub').textContent = '';
        $('access-recovery').hidden = false;
        $('host-search').value = ''; $('process-filter').value = '';
        announce(message);
    }
    async function request(path, signal, version) {
        const at = performance.now();
        const response = await fetch('/api/v1' + path, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal, redirect: 'error' });
        if (version !== generation || signal.aborted) throw new DOMException('Superseded', 'AbortError');
        if (response.status === 401 || response.status === 403) {
            clearPrivate(response.status === 401 ? 'Sign-in required. Restore your owner session, then retry.' : 'Access denied. Check owner enrollment and access, then retry.');
            controller.abort();
            throw new Error('Access required');
        }
        if (!response.ok) throw new Error(response.status === 422 ? 'History range rejected (422). Choose a shorter range or retry.' : response.status === 404 ? 'Host or evidence no longer available (404). Select another host or retry.' : 'Owner API request failed (' + response.status + '). Retry.');
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Owner API did not return JSON. Check sign-in and retry.');
        const data = await response.json();
        if (version !== generation || signal.aborted) throw new DOMException('Superseded', 'AbortError');
        return stamp(data, at);
    }
    function validHost(value) { return value && UUID.test(value.id) && typeof value.name === 'string' && timestamp(value.observed_at) !== null && value.observation && typeof value.observation.state === 'string'; }
    async function load(version) {
        controller = new AbortController();
        const active = controller;
        const timeout = setTimeout(() => active.abort(), 8000);
        refreshing = true; error = ''; denied = false; renderConnection();
        const errors = [];
        try {
            const result = await request('/fleet', active.signal, version);
            if (version !== generation || active.signal.aborted) return;
            if (!Array.isArray(result.data?.hosts) || !result.data.hosts.every(validHost) || !Array.isArray(result.data.incidents) || timestamp(result.data.observed_at) === null) throw new Error('Invalid fleet response. Retry.');
            fleet = result;
            if (!selected && hosts().length) { selected = hosts()[0].id; writeURL(selected, false); syncCitizensLink(); syncAccessRecovery(); }
            if (!selected) { detail = timeline = incidents = null; connected = true; render(); return; }
            if (!UUID.test(selected) || !hosts().some(item => item.id === selected)) {
                detail = timeline = incidents = null;
                throw new Error('Selected host is unavailable. Choose a host from the fleet.');
            }
            const id = selected, minutes = range, path = '/hosts/' + encodeURIComponent(id);
            detail = stamp(hosts().find(item => item.id === id), result.at);
            const window = historyRange(result.data.observed_at, minutes);
            // A single shared eight-second budget bounds the entire refresh, including fleet and both evidence reads.
            const results = await Promise.allSettled([
                request(path + '/history?' + new URLSearchParams(window), active.signal, version),
                request(path + '/incidents', active.signal, version),
            ]);
            if (version !== generation || denied) return;
            for (let index = 0; index < results.length; index++) {
                const outcome = results[index];
                if (outcome.status === 'rejected') { errors.push(outcome.reason?.name === 'AbortError' ? 'Refresh timed out. Retry.' : outcome.reason?.message || 'Evidence request failed. Retry.'); continue; }
                const value = outcome.value;
                if (index === 0) {
                    const h = value.data;
                    if (h?.machine_id !== id || h.resolution !== 'minute' || !Array.isArray(h.points) || !Array.isArray(h.gaps) || timestamp(h.from) === null || timestamp(h.to) === null || timestamp(h.to) <= timestamp(h.from)) { errors.push('Invalid history response. Retry.'); continue; }
                    timeline = value;
                } else {
                    if (!Array.isArray(value.data?.data) || value.data.data.some(event => event.target !== id)) { errors.push('Invalid incident response. Retry.'); continue; }
                    incidents = stamp(value.data.data, value.at);
                }
            }
            connected = errors.length === 0;
            error = [...new Set(errors)].join(' ');
            if (timeline) timeline.error = results[0].status === 'rejected' ? 'Last successful range retained.' : '';
            if (incidents) incidents.error = results[1].status === 'rejected' ? 'Last successful timeline retained.' : '';
        } catch (failure) {
            if (version !== generation || denied) return;
            connected = false;
            error = failure.name === 'AbortError' ? 'Refresh timed out. Retry.' : failure.message;
        } finally {
            clearTimeout(timeout);
            if (version === generation) { refreshing = false; if (!connected) closeDrawer(); render(); }
        }
    }
    function refresh() {
        const version = ++generation;
        clearTimeout(timer);
        controller?.abort();
        running = running.catch(() => {}).then(async () => {
            if (version !== generation || document.hidden) return;
            await load(version);
        }).finally(() => {
            if (version === generation && !document.hidden && !denied) timer = setTimeout(refresh, 10000);
        });
    }
    $('host-search').addEventListener('input', () => { renderFleet(); announce($('host-count').textContent + ' match.'); });
    $('state-filter').addEventListener('change', () => { renderFleet(); announce($('host-count').textContent + ' match.'); });
    $('host-list').addEventListener('change', event => selectHost(event.target.value));
    $('reset-hosts').addEventListener('click', () => { $('host-search').value = ''; $('state-filter').value = 'all'; renderFleet(); $('host-search').focus(); });
    for (const id of ['process-filter', 'process-sort', 'process-category']) $(id).addEventListener(id === 'process-filter' ? 'input' : 'change', () => { renderProcesses(); announce($('process-count').textContent + ' processes.'); });
    $('reset-process').addEventListener('click', () => { $('process-filter').value = ''; renderProcesses(); $('process-filter').focus(); });
    for (const button of document.querySelectorAll('[data-range]')) button.addEventListener('click', () => { const next = Number(button.dataset.range); if (range === next) return; range = next; timeline = null; renderRibbon(); refresh(); announce('Loading recorded CPU history for ' + range + ' minutes.'); });
    $('retry').addEventListener('click', refresh);
    const accessRecovery = $('access-recovery');
    if (!accessRecovery) throw new Error('Orbit recovery link is missing from the page.');
    function syncAccessRecovery() {
        const url = new URL('/orbit', location.origin);
        if (UUID.test(selected)) url.searchParams.set('host', selected);
        accessRecovery.href = url.pathname + url.search;
    }
    syncAccessRecovery();
    $('close-drawer').addEventListener('click', closeDrawer);
    $('signal-drawer').addEventListener('close', () => { const trigger = [...$('signal-list').querySelectorAll('[data-incident]')].find(node => node.dataset.incident === focusReturn); if (trigger) trigger.focus(); else $('signals-title').focus(); focusReturn = null; });
    window.addEventListener('popstate', () => { const next = new URL(location.href).searchParams.get('host') || ''; if (next === selected) return; selected = next; focusedSystem = ''; detail = timeline = incidents = null; closeDrawer(); render(); refresh(); });
    function stop() { settleSelectionMotion(); sky.classList.remove('in-view'); generation++; clearTimeout(timer); clearInterval(ageTimer); controller?.abort(); connected = false; refreshing = false; closeDrawer(); render(); }
    function tickAges() { clearInterval(ageTimer); ageTimer = setInterval(() => { if (!document.hidden) { renderConnection(); renderFleet(); renderDock(); renderProcesses(); } }, 1000); }
    function resume() { const bounds = sky.getBoundingClientRect(); sky.classList.toggle('in-view', bounds.bottom > 0 && bounds.top < innerHeight); connected = false; render(); tickAges(); refresh(); }
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : resume());
    const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
    motionPreference.addEventListener('change', settleSelectionMotion);
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', event => { if (event.persisted) resume(); });
    window.addEventListener('offline', () => { stop(); error = 'Network offline. Restore connectivity, then retry.'; render(); if (!document.hidden) tickAges(); });
    window.addEventListener('online', resume);
    render(); if (!document.hidden) resume();
}
if (typeof document !== 'undefined') {
    boot();
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});
}
