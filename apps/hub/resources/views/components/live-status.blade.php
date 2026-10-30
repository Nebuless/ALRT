@props(['fleet'])
<div class="live-status" wire:ignore data-live-status data-observed-at="{{ $fleet['observed_at'] }}">
    <p class="muted" role="status" aria-live="polite" data-connection>Connected to {{ $fleet['hub']['name'] }} · {{ $fleet['hub']['role'] }} hub</p>
    <p class="muted">Screen received <span data-screen-age>just now</span>.</p>
    <button type="button" data-refresh hidden>Try again</button>
</div>
