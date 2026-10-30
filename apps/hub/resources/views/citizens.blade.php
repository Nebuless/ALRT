<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/icons/orbit.svg" type="image/svg+xml"><title>Citizens · Nebuless observatory</title>
    @vite(['resources/css/citizens.css', 'resources/js/citizens.js'])
</head>
<body>
<a class="skip" href="#citizen-list">Skip to citizens</a>
<div class="citizens-wrap">
    <header class="masthead">
<div><a id="return-orbit" class="return-orbit" href="/orbit">Return to Orbit</a><h1>Citizens</h1><p>Your agents, across your machines.</p></div>
        <span id="hub-role" class="badge">Connecting</span>
    </header>
    <x-live-nav active="citizens" variant="sky" />
    <main>
        <section class="connection" aria-label="Connection status">
            <p id="connection-note" role="status">Loading owner inventory. No observations received.</p>
            <a id="access-recovery" class="access-recovery" href="{{ route('citizens') }}" hidden>Sign in / Restore access</a>
            <button id="refresh-citizens" type="button">Refresh now</button>
        </section>
        <dl id="activity-summary" class="activity-summary" aria-label="Current agent activity">
            <div><dt>Working</dt><dd id="count-working">—</dd></div>
            <div><dt>Waiting</dt><dd id="count-waiting">—</dd></div>
            <div><dt>Idle</dt><dd id="count-idle">—</dd></div>
            <div><dt>Unknown</dt><dd id="count-unknown">—</dd></div>
        </dl>
        <form id="citizen-filters" class="filters" aria-label="Filter citizens">
            <label class="search-field">Find citizen<input id="citizen-search" type="search" placeholder="Machine, tool or identity" autocomplete="off"></label>
            <details id="filter-disclosure" class="filter-disclosure"><summary>Filters <span id="filter-count"></span></summary><div class="filter-fields">
                <label>Machine<select id="citizen-host"><option value="">All machines</option></select></label>
                <label>Tool<select id="citizen-tool"><option value="">All tools</option><option value="omo">OmO</option><option value="pi">Pi</option></select></label>
                <label>Activity<select id="citizen-state"><option value="">All states</option><option value="working">Working</option><option value="waiting">Waiting</option><option value="idle">Idle</option><option value="stopped">Stopped</option><option value="failed">Failed</option><option value="unknown">Unknown</option></select></label>
            </div></details>
            <button id="reset-citizens" type="reset" hidden>Reset filters</button>
        </form>
        <details id="coverage-disclosure" class="coverage-disclosure"><summary><span id="coverage-summary">Checking machine coverage</span></summary><p class="muted">Only instrumented agents appear. Missing integrations do not mean idle machines. Existing sessions may need a supported reload or a new launch.</p><div id="host-coverage" class="host-coverage" aria-label="Machine coverage"></div></details>
        <div class="inventory-heading"><h2>Current agents</h2><p id="inventory-count">Waiting for inventory</p></div>
        <p id="inventory-note" class="muted">Native activity, not inferred progress.</p>
        <ul id="citizen-list" class="citizen-list" tabindex="-1" aria-label="Citizens"></ul>
        <section id="citizen-empty" class="empty" hidden><h3 id="empty-title">No citizens reported</h3><p id="empty-copy"></p></section>
        <details id="recent-history" class="recent-history" hidden><summary>Recent history <span id="history-count"></span><span class="history-window">Last 4 hours</span></summary><ul id="citizen-history" class="citizen-list" aria-label="Stopped agent history"></ul></details>
        <section id="shared-section" class="shared-section" aria-labelledby="shared-title" hidden>
            <h2 id="shared-title">Shared runtimes</h2>
            <p class="muted">One measurement per runtime. Never added to individual sessions or counted twice. Runtime totals include all reported members, regardless of filters.</p>
            <ul id="shared-runtimes" class="runtime-list"></ul>
        </section>
    </main>
    <footer>Citizens / Nebuless observatory <span>No prompts, transcripts or task content. Missing readings are never zero.</span></footer>
</div>
<dialog id="citizen-detail" aria-labelledby="detail-title" aria-describedby="detail-note">
    <div class="dialog-top"><h2 id="detail-title">Citizen detail</h2><button id="close-detail" type="button">Close detail</button></div>
    <div id="detail-avatar" class="detail-avatar"></div>
    <p id="detail-note"></p>
    <dl id="detail-readings" class="metrics"></dl>
    <details class="technical-details"><summary>Process details</summary><dl id="detail-evidence"></dl></details>
    <p id="action-status" role="status"></p>
    <section class="process-control" aria-label="Process control"><button id="request-terminate" class="danger" type="button" disabled>Force kill process</button><p id="terminate-reason" class="muted"></p></section>
    <section id="kill-confirmation" aria-labelledby="confirmation-title" hidden>
        <h3 id="confirmation-title">Confirm force kill</h3>
        <p id="confirmation-copy"></p>
        <p>Force kill sends SIGKILL. Unsaved work can be lost. No graceful shutdown.</p>
        <div class="confirmation-actions"><button id="cancel-terminate" type="button">Keep running</button><button id="confirm-terminate" class="danger" type="button">Confirm force kill</button></div>
    </section>
</dialog>
<p id="citizen-announcement" class="sr-only" aria-live="polite" aria-atomic="true"></p>
<noscript><p class="noscript">JavaScript is required to load private agent inventory. Return to <a href="/orbit">Orbit</a>.</p></noscript>
</body>
</html>
