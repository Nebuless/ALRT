@props(['seconds' => null])
<span data-evidence-age data-age-seconds="{{ $seconds }}" data-age-at="{{ now()->toIso8601String() }}">{{ App\ReadModels\Observation::age($seconds) }}</span>
