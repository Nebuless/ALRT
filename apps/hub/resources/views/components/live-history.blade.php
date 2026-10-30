@props(['timeline', 'detail' => false, 'label' => 'CPU history'])
@php
    $from = strtotime($timeline['from']);
    $span = max(1, strtotime($timeline['to']) - $from);
    $x = fn ($time) => round(max(0, min(300, (strtotime($time) - $from) / $span * 300)), 3);
    $pattern = 'gap-'.str_replace('.', '-', uniqid());
@endphp
<figure>
    <svg class="history-trace {{ $detail ? 'history-detail' : '' }}" viewBox="0 0 300 80" preserveAspectRatio="none" role="img" aria-label="{{ $label }}. Hatched spans have nothing recorded; shaded marks were received late.">
        <defs><pattern id="{{ $pattern }}" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="6" class="hatch-line" /></pattern></defs>
        @foreach($timeline['gaps'] as $gap)
            <rect x="{{ $x($gap['from']) }}" y="0" width="{{ max(0, $x($gap['to']) - $x($gap['from'])) }}" height="80" fill="url(#{{ $pattern }})"><title>Nothing recorded: {{ $gap['from'] }} to {{ $gap['to'] }}</title></rect>
        @endforeach
        @foreach($timeline['points'] as $point)
            @php
                $end = $x($point['sampled_at'] ?? $point['bucket_at']);
                $start = max(0, $end - min(10, $point['coverage_seconds']) / $span * 300);
                $cpu = $point['metrics']['cpu_percent']['average'] ?? null;
                $used = $point['metrics']['memory.used_bytes']['average'] ?? null;
                $total = $point['metrics']['memory.total_bytes']['average'] ?? null;
                $memory = $used !== null && $total > 0 ? $used / $total * 100 : null;
            @endphp
            @if($point['backfilled'] ?? false)<rect x="{{ $start }}" y="0" width="{{ max(.25, $end - $start) }}" height="80" class="black-box-span"><title>Black box, received late: {{ $point['sampled_at'] }}</title></rect>@endif
            @if($cpu !== null)<line class="cpu-line" x1="{{ $start }}" x2="{{ $end }}" y1="{{ 78 - min(100, $cpu) * .76 }}" y2="{{ 78 - min(100, $cpu) * .76 }}"><title>CPU {{ $cpu }}% at {{ $point['sampled_at'] ?? $point['bucket_at'] }}</title></line>@endif
            @if($detail && $memory !== null)<line class="memory-line" x1="{{ $start }}" x2="{{ $end }}" y1="{{ 78 - min(100, $memory) * .76 }}" y2="{{ 78 - min(100, $memory) * .76 }}"><title>Memory {{ round($memory, 2) }}%</title></line>@endif
        @endforeach
    </svg>
    @if($detail)
        <div class="history-axis"><span>{{ $timeline['from'] }}</span><span>{{ $timeline['to'] }}</span></div>
        <figcaption class="history-key"><span class="cpu-key">CPU, solid</span><span>Memory, dashed</span><span>Hatched: nothing recorded</span><span>Shaded: black box, received late</span></figcaption>
    @endif
</figure>
