<?php

// Diagnostic HTTP entrypoint for isolated fixtures only. No production route.
use App\History\ApplyContribution;
use App\History\ReadTimeline;
use App\Models\Machine;
use App\Telemetry\IngestReport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$entry = microtime(true);
$started = hrtime(true);
$cpuStart = getrusage();
define('LARAVEL_START', $entry);
require dirname(__DIR__, 2).'/apps/hub/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/apps/hub/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$app->instance('request', $request);
$kernel->bootstrap();
$metrics = ['bootstrap_ms' => (hrtime(true) - $started) / 1e6, 'sql_ms' => 0.0, 'queries' => 0,
    'session_sql_ms' => 0.0, 'history_sql_ms' => 0.0, 'transaction_begin_ms' => 0.0, 'auth_rpc_ms' => 0.0,
    'timeline_ms' => 0.0, 'timeline_calls' => 0, 'timeline_points' => 0,
    'contribution_ms' => 0.0, 'contribution_cpu_ms' => 0.0, 'contribution_calls' => 0,
    'canonical_ms' => 0.0, 'canonical_cpu_ms' => 0.0, 'canonical_calls' => 0];
$cpuMilliseconds = static fn (array $usage): float => ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
DB::listen(function (QueryExecuted $event) use (&$metrics): void {
    $metrics['queries']++;
    $metrics['sql_ms'] += $event->time;
    if (str_contains($event->sql, '"sessions"')) {
        $metrics['session_sql_ms'] += $event->time;
    }
    if (str_contains($event->sql, '"telemetry_samples"') || str_contains($event->sql, '"history_buckets"')) {
        $metrics['history_sql_ms'] += $event->time;
    }
});
$begins = [];
DB::connection()->beforeStartingTransaction(function () use (&$begins): void {
    $begins[] = hrtime(true);
});
Event::listen(TransactionBeginning::class, function () use (&$begins, &$metrics): void {
    $start = array_pop($begins);
    if ($start !== null) {
        $metrics['transaction_begin_ms'] += (hrtime(true) - $start) / 1e6;
    }
});
$rpc = [];
Event::listen(RequestSending::class, function (RequestSending $event) use (&$rpc): void {
    if (str_starts_with($event->request->url(), config('better-auth.bridge_url').'/')) {
        $rpc[spl_object_id($event->request)] = hrtime(true);
    }
});
Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$rpc, &$metrics): void {
    $id = spl_object_id($event->request);
    if (isset($rpc[$id])) {
        $metrics['auth_rpc_ms'] += (hrtime(true) - $rpc[$id]) / 1e6;
        unset($rpc[$id]);
    }
});
// The wrapper calls the unchanged production service once and returns its exact
// result. It adds timing only; no alternate read model or response is supplied.
$app->bind(ApplyContribution::class, function () use (&$metrics, $cpuMilliseconds) {
    return new class($metrics, $cpuMilliseconds) extends ApplyContribution
    {
        private array $metrics;

        public function __construct(array &$metrics, private Closure $cpuMilliseconds)
        {
            $this->metrics = &$metrics;
        }

        public function apply(string $machine, array $report): void
        {
            $start = hrtime(true);
            $cpuStart = ($this->cpuMilliseconds)(getrusage());
            try {
                parent::apply($machine, $report);
            } finally {
                $this->metrics['contribution_ms'] += (hrtime(true) - $start) / 1e6;
                $this->metrics['contribution_cpu_ms'] += ($this->cpuMilliseconds)(getrusage()) - $cpuStart;
                $this->metrics['contribution_calls']++;
            }
        }
    };
});
$app->bind(IngestReport::class, function () use (&$metrics, $cpuMilliseconds) {
    return new class($metrics, $cpuMilliseconds) extends IngestReport
    {
        private array $metrics;

        public function __construct(array &$metrics, private Closure $cpuMilliseconds)
        {
            $this->metrics = &$metrics;
        }

        public function canonical(array $report): string
        {
            $start = hrtime(true);
            $cpuStart = ($this->cpuMilliseconds)(getrusage());
            try {
                return parent::canonical($report);
            } finally {
                $this->metrics['canonical_ms'] += (hrtime(true) - $start) / 1e6;
                $this->metrics['canonical_cpu_ms'] += ($this->cpuMilliseconds)(getrusage()) - $cpuStart;
                $this->metrics['canonical_calls']++;
            }
        }
    };
});
$app->bind(ReadTimeline::class, function () use (&$metrics) {
    return new class($metrics) extends ReadTimeline
    {
        private array $metrics;

        public function __construct(array &$metrics)
        {
            $this->metrics = &$metrics;
        }

        public function timeline(Machine $machine, Carbon $from, Carbon $to, ?string $resolution = null): array
        {
            $start = hrtime(true);
            $timeline = parent::timeline($machine, $from, $to, $resolution);
            $this->metrics['timeline_ms'] += (hrtime(true) - $start) / 1e6;
            $this->metrics['timeline_calls']++;
            $this->metrics['timeline_points'] = count($timeline['points']);

            return $timeline;
        }
    };
});
$response = $kernel->handle($request);
$handled = hrtime(true);
$response->send();
$sent = hrtime(true);
$kernel->terminate($request, $response);
$cpuEnd = getrusage();
$sentAt = $request->header('X-Nebuless-Probe-Sent');
$probe = $request->header('X-Nebuless-Probe-Id');
$operation = preg_match('~^api/v1/hosts/[a-f0-9-]+$~D', $request->path()) ? 'host' : basename($request->path());
$metrics += ['probe_id' => is_string($probe) && ctype_digit($probe) ? (int) $probe : null,
    'operation' => in_array($operation, ['host', 'history', 'reports', 'contact', 'challenge', 'login', 'enroll', 'confirm', 'logout', 'fleet'], true) ? $operation : 'other', 'pid' => getmypid(),
    'status' => $response->getStatusCode(), 'client_to_script_ms' => is_numeric($sentAt) ? max(0, ($entry - (float) $sentAt) * 1000) : null,
    'handle_wall_ms' => ($handled - $started) / 1e6, 'send_wall_ms' => ($sent - $handled) / 1e6,
    'total_wall_ms' => (hrtime(true) - $started) / 1e6, 'php_cpu_ms' => $cpuMilliseconds($cpuEnd) - $cpuMilliseconds($cpuStart),
    'response_bytes' => strlen($response->getContent()), 'peak_php_bytes' => memory_get_peak_usage(true),
    'sapi' => PHP_SAPI, 'opcache_loaded' => extension_loaded('Zend OPcache'), 'opcache_enable' => ini_get('opcache.enable'),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'), 'opcache_statistics' => function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_statistics'] ?? null) : null];
// No SQL bindings, request body, cookies, URLs, credentials or response payload.
file_put_contents(getenv('NEBULESS_REFERENCE_PROFILE'), json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
