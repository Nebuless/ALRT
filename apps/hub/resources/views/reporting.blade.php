<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><link rel="icon" href="/icons/orbit.svg" type="image/svg+xml"><title>Reporting · Orbit</title>@vite(['resources/css/citizens.css'])</head>
<body><a class="skip" href="#paths">Skip to reporting paths</a><div class="citizens-wrap">
<header class="masthead"><div><h1>Reporting</h1><p>Direct receipts and collector claims are separate evidence.</p></div></header>
<x-live-nav active="reporting" variant="sky" />
<main id="paths"><h2>Reporting paths</h2><p class="muted">Snapshot at page load. Reload to check new receipts; this page does not claim live health.</p>
<ul class="runtime-list reporting-list">
@foreach($paths as $path)
<li><h3>{{ $path['source_name'] }} → {{ $path['target_name'] }} · {{ $path['target_role'] }}</h3>
<p>{{ $path['state'] }} · @if($path['provenance'] === 'direct_receipt')Direct receipt {{ $path['age_seconds'] ?? 'unknown' }} s ago; fresh contact {{ $path['contact_age_seconds'] ?? 'unknown' }} s ago.@elseif($path['provenance'] === 'collector_claim')Collector claim: ACK {{ $path['claim_age_seconds'] ?? 'unknown' }} s old; this hub observed the claim {{ $path['observation_age_seconds'] ?? 'unknown' }} s ago.@else Unknown: no receipt or collector claim.@endif</p></li>
@endforeach
</ul>@if($paths === [])<p class="empty">No reporting paths yet. Enroll a collector through local setup.</p>@endif
</main></div></body></html>
