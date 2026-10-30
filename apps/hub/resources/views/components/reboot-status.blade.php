@props(['action'])
<div role="status" tabindex="-1" data-reboot-state="{{ $action['state'] }}">
    <p>{{ $action['result'] === 'unknown' && in_array($action['state'], ['authorized', 'attempted'], true) ? 'Reboot outcome unknown. This action will not be retried. Waiting for outstanding authority to expire.' : match($action['state']) {
        'queued' => 'Waiting for the next check-in.',
        'cancel_window' => 'Picked up. The 60-second cancel window is open.',
        'awaiting_primary' => 'Awaiting final authorization from Primary-Hub. You can still cancel.',
        'authorized' => 'Authorized. Too late to cancel. Waiting for the execution result.',
        'attempted' => 'Reboot attempted. Waiting for a new boot.',
        'canceled' => 'Reboot canceled.',
        'expired' => 'Reboot expired. Nothing will be attempted.',
        'boot_changed' => 'The target boot changed before authorization. Request a new reboot if needed.',
        'new_boot_observed' => 'A new boot was observed. This does not prove the queued reboot caused it.',
        default => 'Reboot outcome unknown. This action will not be retried automatically.',
    } }}</p>
    <p>Expires {{ $action['expires_at'] }}@if($action['cancel_until']) · Cancel window ends {{ $action['cancel_until'] }}@endif</p>
</div>
