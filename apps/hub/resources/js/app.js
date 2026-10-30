// Native dialog supplies modal semantics and Escape dismissal. Explicit Tab
// wrapping keeps Chromium's reverse traversal inside the document dialog.
for (const trigger of document.querySelectorAll('[data-dialog-open]')) {
    const dialog = document.getElementById(trigger.dataset.dialogOpen);
    if (!(dialog instanceof HTMLDialogElement)) continue;

    trigger.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;

        const controls = [...dialog.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]')]
            .filter((control) => control.tabIndex >= 0 && control.getClientRects().length > 0);
        const first = controls[0];
        const last = controls.at(-1);
        const boundary = event.shiftKey ? first : last;

        if (document.activeElement === boundary) {
            event.preventDefault();
            (event.shiftKey ? last : first)?.focus();
        }
    });
    dialog.addEventListener('close', () => {
        if (dialog.returnValue === 'preview') {
            const feedback = document.getElementById('action-feedback');
            if (feedback) feedback.textContent = 'Preview complete. No machine action was sent.';
        }
        dialog.returnValue = '';
        requestAnimationFrame(() => {
            (trigger.disabled ? document.querySelector('[data-reboot-state]') : trigger)?.focus();
        });
    });
}

if (document.body.hasAttribute('data-live-fleet')) {
    let pending = false;
    let denied = false;
    let pollTimer;
    let receivedAt = performance.now();
    let lastAttempt = performance.now();
    let failures = 0;
    let connectionState = navigator.onLine ? 'connected' : 'offline';
    const age = (seconds) => {
        if (seconds < 60) return `${seconds} s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
        return `${Math.floor(seconds / 3600)} h ${Math.floor(seconds % 3600 / 60)} min`;
    };
    const screen = () => document.querySelector('[data-live-screen]');
    const updateText = (element, text) => {
        if (element.textContent !== text) element.textContent = text;
    };
    // Charge transport time from the request start. Phone wall-clock changes
    // cannot extend the server-derived remaining observation lifetime.
    let evidenceStarted = performance.getEntriesByType('navigation')[0]?.requestStart ?? 0;
    let requestStarted = evidenceStarted;
    let suspension = 0;
    let requestSuspension = suspension;
    let expiryTimer;
    const expireEvidence = (root = screen(), started = evidenceStarted, force = false) => {
        if (!root || denied) return;
        const position = root === screen() ? { x: scrollX, y: scrollY } : null;
        if (position) root.style.overflowAnchor = 'none';
        let changed = false;
        for (const element of root?.querySelectorAll('[data-evidence-lifetime]') ?? []) {
            const lifetime = Number(element.dataset.evidenceLifetime);
            if (lifetime < 0 || element.dataset.evidenceExpired || (!force && performance.now() - started <= lifetime * 1000)) continue;
            changed = true;
            element.dataset.evidenceExpired = 'true';
            delete element.dataset.receivedText;
            if (element instanceof HTMLOptionElement) {
                // Retain the selected ID/URL but not its old evidence label.
                element.textContent = `Selected #${element.value} unavailable (expired).`;
                element.disabled = true;
            } else {
                // Preserve the current scroll position when large panels expire.
                if (getComputedStyle(element).display === 'block') element.style.minHeight = `${element.getBoundingClientRect().height}px`;
                element.replaceChildren(document.createTextNode(element.dataset.expiredText ?? 'Observation evidence unavailable (expired).'));
            }
        }
        if (changed && position) window.scrollTo(position.x, position.y);
        if (root === screen()) {
            clearTimeout(expiryTimer);
            const remaining = [...root?.querySelectorAll('[data-evidence-lifetime]') ?? []]
                .filter((element) => !element.dataset.evidenceExpired && Number(element.dataset.evidenceLifetime) >= 0)
                .map((element) => Number(element.dataset.evidenceLifetime) * 1000 - (performance.now() - started));
            if (remaining.length) expiryTimer = setTimeout(() => showState(), Math.max(1, Math.min(...remaining) + 1));
        }
    };
    const showState = () => {
        if (denied) return;
        expireEvidence();
        const status = document.querySelector('[data-live-status]');
        if (!status) return;
        const state = connectionState === 'connected' && performance.now() - receivedAt > 30000 ? 'stale' : connectionState;
        document.body.dataset.connectionState = state;
        const message = status.querySelector('[data-connection]');
        if (!message.dataset.connectedText) message.dataset.connectedText = message.textContent;
        let connectionText = message.dataset.connectedText;
        if (state === 'offline') connectionText = 'Offline. Showing retained received evidence; current health is unknown.';
        else if (state !== 'connected') connectionText = 'Refresh failed. Showing retained received evidence; current health is unknown.';
        updateText(message, connectionText);
        updateText(status.querySelector('[data-screen-age]'), `${age(Math.floor((performance.now() - receivedAt) / 1000))} ago`);
        status.querySelector('[data-refresh]').hidden = state === 'connected';
        for (const button of document.querySelectorAll('[data-reboot-mutation]')) {
            button.disabled = state !== 'connected' || button.dataset.rebootDisabled === 'true' || button.dataset.rebootBusy === 'true';
        }
        const verdict = document.querySelector('[data-current-verdict]');
        if (verdict && !verdict.dataset.evidenceExpired) {
            if (!verdict.dataset.receivedText) verdict.dataset.receivedText = verdict.textContent;
            updateText(verdict, state === 'connected' ? verdict.dataset.receivedText : 'Current status is unknown.');
        }
        for (const element of document.querySelectorAll('[data-evidence-age]')) {
            if (element.dataset.ageSeconds === '') continue;
            const elapsed = Math.max(0, Math.floor((performance.now() - evidenceStarted) / 1000));
            updateText(element, age(Number(element.dataset.ageSeconds) + elapsed));
        }
    };
    const clearPrivate = () => {
        denied = true;
        clearTimeout(expiryTimer);
        clearInterval(pollTimer);
        document.body.dataset.connectionState = 'denied';
        for (const dialog of document.querySelectorAll('dialog[open]')) dialog.close();
        const heading = document.createElement('h1');
        heading.textContent = 'Access required';
        const message = document.createElement('p');
        message.textContent = 'Private host evidence cleared. Restore access to continue.';
        const recovery = document.createElement('a');
        // A fresh GET revalidates the selected auth mode and enrollment on the
        // server, including Tailscale, without an automatic retry or fallback.
        recovery.href = location.pathname;
        recovery.textContent = 'Restore access';
        // Remove the component itself, including its private wire:snapshot,
        // rather than only hiding or emptying the visible evidence panels.
        document.getElementById('main').replaceChildren(heading, message, recovery);
        recovery.focus();
    };
    const refresh = async () => {
        expireEvidence();
        if (denied || pending || document.visibilityState !== 'visible') return;
        if (!navigator.onLine) {
            connectionState = 'offline';
            showState();
            return;
        }
        const id = screen()?.getAttribute('wire:id');
        if (!id || !window.Livewire) return;
        showState();
        pending = true;
        const previousFailures = failures;
        lastAttempt = performance.now();
        try {
            await window.Livewire.find(id).$refresh();
            if (!denied && failures === previousFailures) {
                connectionState = 'connected';
                receivedAt = performance.now();
                const verdict = document.querySelector('[data-current-verdict]');
                if (verdict && !verdict.dataset.evidenceExpired) verdict.dataset.receivedText = verdict.textContent;
            }
        } catch {
            if (denied) return;
            connectionState = navigator.onLine ? 'failed' : 'offline';
        } finally {
            pending = false;
            showState();
        }
    };
    const registerHooks = () => {
        // Scrub expired incoming HTML before Alpine can morph it into view.
        window.Livewire.hook('morph', ({ toEl }) => {
            evidenceStarted = requestStarted;
            expireEvidence(toEl, evidenceStarted, requestSuspension !== suspension || document.visibilityState !== 'visible');
        });
        window.Livewire.hook('morphed', () => {
            if (denied) return;
            const verdict = document.querySelector('[data-current-verdict]');
            if (verdict && !verdict.dataset.evidenceExpired) verdict.dataset.receivedText = verdict.textContent;
            connectionState = 'connected';
            receivedAt = performance.now();
            showState();
        });
        window.Livewire.hook('request', ({ fail, succeed }) => {
            const started = performance.now();
            const generation = suspension;
            succeed(() => {
                requestStarted = started;
                requestSuspension = generation;
            });
            fail(({ status, preventDefault }) => {
                failures++;
                if (status === 401 || status === 403) {
                    preventDefault();
                    clearPrivate();
                    return;
                }
                if (denied) { preventDefault(); return; }
                connectionState = navigator.onLine ? 'failed' : 'offline';
                showState();
                if (pending) preventDefault();
            });
        });
    };
    if (window.Livewire) registerHooks();
    else document.addEventListener('livewire:init', registerHooks, { once: true });
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-refresh]')) refresh();
    });
    document.addEventListener('visibilitychange', () => {
        // Mobile monotonic clocks may stop in suspension. Fail closed before
        // the browser can expose the old page or begin a resume refresh.
        if (document.visibilityState !== 'visible') suspension++;
        expireEvidence(screen(), evidenceStarted, true);
        if (document.visibilityState === 'visible') refresh();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        suspension++;
        expireEvidence(screen(), evidenceStarted, true);
        refresh();
    });
    window.addEventListener('online', refresh);
    window.addEventListener('offline', () => { connectionState = 'offline'; showState(); });
    pollTimer = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        showState();
        if (performance.now() - lastAttempt >= 10000) refresh();
    }, 1000);
    showState();
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});
}
