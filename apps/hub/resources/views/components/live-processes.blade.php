@props(['processes', 'coverage'])
@php($display = App\ReadModels\Observation::class)
<p class="muted">Process coverage: {{ $coverage['status'] ?? 'unavailable' }}. {{ $coverage['sampled'] ?? 'Unknown' }} of {{ $coverage['discovered'] ?? 'unknown' }} discovered processes sampled. {{ $coverage['reason'] ?? '' }}</p>
@if($coverage)<p class="muted">Permission denied {{ $coverage['permission_denied'] }}, vanished {{ $coverage['vanished'] }}, malformed {{ $coverage['malformed'] }}. Scan limited: {{ $coverage['scan_limited'] ? 'yes' : 'no' }}. Enumeration limited: {{ $coverage['enumeration_limited'] ? 'yes' : 'no' }}.</p>@endif
@foreach(['top_cpu' => 'Top by CPU', 'top_rss' => 'Top by memory', 'orphans' => 'Orphans', 'zombies' => 'Zombies', 'longest_running' => '10 longest running'] as $category => $label)
    @php($list = $processes[$category] ?? null)
    <details class="process-list" wire:key="category-{{ $category }}" @if($category === 'top_cpu') open @endif>
        <summary>{{ $label }} <span>{{ $list['completeness'] ?? 'unavailable' }}</span></summary>
        @if($list)
            <p class="muted">{{ count($list['items']) }} received of {{ $list['total_observed'] }} observed; chunk {{ $list['chunk_index'] + 1 }} of {{ $list['chunk_count'] }}. {{ $list['reason'] ?? '' }}</p>
            @if($category === 'orphans')<p class="muted">Only an observed parent exit proves an orphan. First-seen adoption stays uncertain.</p>@endif
            <ul class="process-rows">
                @foreach($list['items'] as $process)
                    <li wire:key="{{ $category }}-{{ $process['identity']['boot_id'] }}-{{ $process['identity']['pid'] }}-{{ $process['identity']['start_ticks'] }}">
                        <strong>{{ $process['name'] }}</strong><span>{{ $display::number($process['cpu_percent'], '%') }} CPU · {{ $display::bytes($process['rss_bytes']) }}</span>
                        <small>PID {{ $process['identity']['pid'] }} · running {{ $display::age($process['age_seconds']) }} · state {{ $process['state'] }}</small>
                        <small>@if($process['parent']['identity'])Parent PID {{ $process['parent']['identity']['pid'] }}@else Parent unavailable ({{ $process['parent']['status'] }})@endif · orphan {{ $process['orphan_status'] }}@if($process['former_parent']) · former parent PID {{ $process['former_parent']['pid'] }}@endif</small>
                        <details class="process-identity"><summary>Identity evidence</summary>
                            <small>Boot {{ $process['identity']['boot_id'] }} · start ticks {{ $process['identity']['start_ticks'] }}
                            @if($process['parent']['identity'])<br>Parent boot {{ $process['parent']['identity']['boot_id'] }} · start ticks {{ $process['parent']['identity']['start_ticks'] }}@endif
                            @if($process['former_parent'])<br>Former parent boot {{ $process['former_parent']['boot_id'] }} · start ticks {{ $process['former_parent']['start_ticks'] }}@endif</small>
                        </details>
                    </li>
                @endforeach
            </ul>
            @if($list['items'] === [])<p class="empty-message">No entries received in this {{ $list['completeness'] }} category.</p>@endif
        @else<p class="empty-message">No process category received.</p>@endif
    </details>
@endforeach
