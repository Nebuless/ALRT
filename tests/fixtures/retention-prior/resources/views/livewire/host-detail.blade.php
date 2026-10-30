@php
    $display = App\ReadModels\Observation::class;
    $metrics = $host['metrics'];
    $old = $host['sample_age_seconds'] === null || $host['sample_age_seconds'] > 30 || $host['contact_age_seconds'] === null || $host['contact_age_seconds'] > 30;
    $processes = $this->sample === null ? $host['processes'] : ($snapshot['processes'] ?? null);
    $coverage = $this->sample === null ? ($host['coverage']['processes'] ?? null) : ($snapshot['coverage'] ?? null);
    $tailscale = config('nebuless.auth_mode') === 'tailscale';
@endphp
<div class="host-page" data-live-screen data-observed-at="{{ $fleet['observed_at'] }}">
    <x-live-status :fleet="$fleet" />
    <a class="back-link" href="{{ route('home') }}">← Home</a>
    <header class="host-heading"><span class="host-ring"><span class="state-point state-{{ $host['observation']['state'] }}" aria-hidden="true"></span></span><div><h1>{{ $host['name'] }}</h1><p>{{ $host['scope'] ?? 'Scope unavailable' }} · {{ $host['provenance']['kind'] }} at {{ $fleet['hub']['name'] }}</p></div></header>
    <p class="host-verdict" data-current-verdict>{{ $host['observation']['sentence'] }}</p>
    <p class="evidence-note">Last received <x-observation-age :seconds="$host['contact_age_seconds']" /> ago; measurements <x-observation-age :seconds="$host['sample_age_seconds']" /> old. Records still on {{ $host['name'] }} appear after replay.</p>
    <p class="muted">Assessment: {{ $host['health']['state'] }}, evaluated <x-observation-age :seconds="$host['health']['evaluation_age_seconds']" /> ago. {{ $host['health']['unavailable_reason'] }}</p>
    @if($host['observation']['detail'])<p class="muted">{{ $host['observation']['detail'] }}</p>@endif
    @if($host['unavailable_reason'])<p class="empty-message">{{ str_replace('_', ' ', $host['unavailable_reason']) }}. Waiting for this hub to receive evidence.</p>@endif
    <section class="host-section" aria-labelledby="vitals-title">
        <div class="section-heading"><h2 id="vitals-title">{{ $old ? 'Last received vitals' : 'Live vitals' }}</h2><span>{{ $host['sampled_at'] ?? 'No measurements received' }}</span></div>
        <dl class="vitals">
            <div><dt>CPU</dt><dd>{{ $display::number($metrics['cpu_percent']['value'] ?? null, '%') }}</dd><small class="muted">{{ $metrics['cpu_percent']['status'] ?? 'no report' }}</small></div>
            <div><dt>Memory</dt><dd>{{ $display::bytes($metrics['memory']['used_bytes']['value'] ?? null) }} / {{ $display::bytes($metrics['memory']['total_bytes']['value'] ?? null) }}</dd><small class="muted">Available {{ $display::bytes($metrics['memory']['available_bytes']['value'] ?? null) }}</small></div>
            <div><dt>Disk</dt><dd>@forelse($metrics['disks'] ?? [] as $disk){{ $disk['label'] }}: {{ $display::bytes($disk['used_bytes']['value']) }} / {{ $display::bytes($disk['total_bytes']['value']) }}<br>@empty Unavailable @endforelse</dd></div>
            <div><dt>Network</dt><dd>@forelse($metrics['network'] ?? [] as $network){{ $network['label'] }}: ↓ {{ $display::bytes($network['receive_bytes_per_second']['value']) }}/s · ↑ {{ $display::bytes($network['transmit_bytes_per_second']['value']) }}/s<br>@empty Unavailable @endforelse</dd></div>
            <div><dt>Pressure, avg10</dt><dd>@foreach($metrics['pressure'] ?? [] as $resource => $signals){{ $resource === 'io' ? 'I/O' : ucfirst($resource) }}: @if($signals['some']['status'] === $signals['full']['status'] && $signals['some']['status'] !== 'available'){{ str_replace('_', ' ', $signals['some']['status']) }}@else @foreach($signals as $kind => $signal){{ $kind }} {{ $signal['status'] === 'available' ? $display::number($signal['avg10'], '%') : str_replace('_', ' ', $signal['status']) }} @endforeach @endif<br>@endforeach @if(!$metrics)Unavailable @endif</dd></div>
            <div><dt>Load, 1 / 5 / 15 min</dt><dd>@foreach(['one', 'five', 'fifteen'] as $period){{ $display::number($metrics['load'][$period]['value'] ?? null) }}{{ $loop->last ? '' : ' / ' }}@endforeach</dd></div>
        </dl>
        @if($host['observation']['unavailable_reasons'])<p class="evidence-note">Unavailable measurements: {{ str_replace('_', ' ', implode('; ', $host['observation']['unavailable_reasons'])) }}.</p>@endif
    </section>
    <section class="host-section" aria-labelledby="history-title">
        <div class="section-heading"><h2 id="history-title">History</h2><span>Last 6 hours · UTC</span></div>
        <x-live-history :timeline="$timeline" :detail="true" :label="'Six hours of CPU and memory for '.$host['name']" />
        <details class="history-gaps"><summary>Nothing recorded: {{ count($timeline['gaps']) }} spans</summary><ul>@foreach($timeline['gaps'] as $gap)<li>{{ $gap['from'] }} to {{ $gap['to'] }}: unknown</li>@endforeach</ul></details>
        <p class="muted">Only received records are shown. CPU and memory use a 0–100% scale; gaps are unknown.</p>
    </section>
    <section class="host-section" aria-labelledby="incidents-title">
        <div class="section-heading"><h2 id="incidents-title">What happened</h2><span>Today · UTC</span></div>
        <ol class="incident-list">@foreach($incidents as $incident)<li wire:key="incident-{{ $incident['id'] }}"><time datetime="{{ $incident['opened_at'] }}">{{ $incident['opened_at'] }}</time><span>{{ str_replace('_', ' ', $incident['rule']) }} signal<br>{{ $incident['resolved_at'] ? 'Resolved '.$incident['resolved_at'] : 'Open' }}<br><small>{{ $incident['provenance'] }}</small></span></li>@endforeach</ol>
        @if($incidents === [])<p class="empty-message">No incidents recorded today.</p>@endif
    </section>
    <section class="host-section" aria-labelledby="processes-title">
        <h2 id="processes-title">Processes</h2>
        <label for="process-sample">Inspect received sample</label>
        <select id="process-sample" wire:model.live="sample">
            <option value="">Latest live contact</option>
            @foreach($timeline['points'] as $point)@if($point['snapshot'] !== null)<option value="{{ $point['snapshot']['id'] }}">{{ $point['sampled_at'] }} · #{{ $point['snapshot']['id'] }}{{ $point['backfilled'] ? ' · black box, received late' : '' }}</option>@endif @endforeach
            @if($this->sample !== null && !collect($timeline['points'])->contains(fn ($point) => ($point['snapshot']['id'] ?? null) == $this->sample))<option value="{{ $this->sample }}">Selected #{{ $this->sample }}</option>@endif
        </select>
        @if($this->sample !== null)<p class="evidence-note">{{ $snapshot ? 'Historical snapshot #'.$snapshot['id'].' · sampled '.$snapshot['sampled_at'].' · received '.$snapshot['received_at'].' · '.$snapshot['scope'] : 'Selected snapshot unavailable. It may have expired or belong to another machine.' }}</p>@else<p class="muted">Sample <x-observation-age :seconds="$host['sample_age_seconds']" /> old. CPU is a share of one core and can exceed 100%.</p>@endif
        <x-live-processes :processes="$processes" :coverage="$coverage" />
    </section>
    <section id="capabilities" class="host-section" aria-labelledby="capabilities-title">
        <h2 id="capabilities-title">Can do here</h2>
        @php($reboot = $host['actions'])
        @php($queueDisabled = !$reboot['capability']['available'] || ($reboot['latest'] && in_array($reboot['latest']['state'], ['queued', 'cancel_window', 'awaiting_primary', 'authorized', 'attempted'], true)))
        <div class="capability-row"><div><strong>{{ $reboot['capability']['available'] ? 'Reboot on next check-in' : 'Reboot unavailable' }}</strong><p>{{ $reboot['capability']['reason'] ?? 'Expires six hours after queuing. You can cancel before final authorization.' }}</p></div><button type="button" data-reboot-mutation data-dialog-open="confirm-reboot" data-reboot-disabled="{{ $queueDisabled ? 'true' : 'false' }}" @disabled($queueDisabled)>Queue reboot</button></div>
        @if($reboot['latest'])
            <x-reboot-status :action="$reboot['latest']" />
            @if($reboot['latest']['cancelable'] && $fleet['hub']['role'] === 'primary')<button type="button" data-reboot-mutation wire:click="cancelReboot('{{ $reboot['latest']['id'] }}')" wire:loading.attr="disabled">Cancel reboot</button>@endif
        @endif
        @error('reboot')<p role="alert">{{ $message }}</p>@enderror
        <dialog id="confirm-reboot" role="alertdialog" aria-labelledby="reboot-title" aria-describedby="reboot-description" wire:ignore.self x-data="{ submitting: false }" x-on:reboot-queued.window="$el.close()" @if(!$tailscale) x-on:close="$refs.password.value = ''; $refs.code.value = ''" @endif>
            <h2 id="reboot-title">Confirm reboot</h2>
            <p id="reboot-description">Queue {{ $host['name'] }} to reboot at its next check-in. The queue expires in six hours. Pickup starts a 60-second cancel window. {{ $tailscale ? 'Confirm this action using your current Tailscale identity.' : 'Confirm with your password and a fresh authenticator code.' }}</p>
            @if($tailscale)
            <form x-on:submit.prevent="submitting = true; $wire.queueReboot().finally(() => { submitting = false; })">
            @else
            <form x-on:submit.prevent="submitting = true; $wire.queueReboot($refs.password.value, $refs.code.value).finally(() => { submitting = false; $refs.password.value = ''; $refs.code.value = ''; })">
            @endif
                @if(!$tailscale)
                <label for="reboot-password">Password</label><input id="reboot-password" x-ref="password" type="password" autocomplete="current-password" required maxlength="1024">
                <label for="reboot-code">Authenticator code</label><input id="reboot-code" x-ref="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                @endif
                @error('reboot')<p role="alert">{{ $message }}</p>@enderror
                @error('confirmation')<p role="alert">{{ $message }}</p>@enderror
                <div class="dialog-actions"><button type="button" autofocus x-on:click="$el.closest('dialog').close()">Keep as is</button><button type="submit" data-reboot-mutation x-bind:disabled="submitting" x-bind:data-reboot-busy="submitting" wire:loading.attr="disabled">Confirm and queue</button></div>
            </form>
        </dialog>
        <div class="capability-row"><div><strong>Collector capabilities</strong><p>Scope: {{ $host['scope'] ?? 'unknown' }}. Last contact <x-observation-age :seconds="$host['contact_age_seconds']" /> ago.</p></div></div>
        <dl class="vitals">@foreach($host['capabilities'] ?? [] as $name => $value)<div><dt>{{ str_replace('_', ' ', $name) }}</dt><dd>{{ is_bool($value) ? ($value ? 'Supported' : 'Unsupported') : $value }}</dd></div>@endforeach</dl>
        @if($host['delivery'])<p class="evidence-note">Collector claim observed <x-observation-age :seconds="$host['delivery']['observation_age_seconds']" /> ago. Recording: {{ $host['delivery']['recording'] }}. Possible last-interval loss: {{ $host['delivery']['possible_loss_seconds'] }} s. Coverage {{ $host['delivery']['unknown_coverage'] ? 'unknown' : 'as reported' }}. {{ count($host['delivery']['lost_ranges']) }} reported lost ranges. Unsent journal contents are unavailable.</p>@else<p class="muted">Collector recording and delivery claims unavailable.</p>@endif
    </section>
    <x-live-nav :attention="$fleet['attention_count']" />
</div>
