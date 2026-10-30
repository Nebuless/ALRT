<?php

namespace App\Jobs;

use App\Citizens\CitizensState;
use App\History\Retention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PruneHistory implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        app(CitizensState::class)->prune();
        $retention = new Retention;
        $limit = min(128, max(1, (int) config('history.prune_chunk')));
        $deadline = $this->monotonicTime() + 2_000_000_000;
        for ($pass = 0; $pass < 16; $pass++) {
            $work = 0;
            // Give every bounded stage one opportunity before applying the cooperative deadline.
            for ($offset = 0; $offset < 3; $offset++) {
                if ($pass > 0 && $this->monotonicTime() >= $deadline) {
                    return;
                }
                $work += match (($pass + $offset) % 3) {
                    0 => $this->raw($retention, $limit),
                    1 => $this->buckets($limit),
                    2 => $this->contacts($retention, $limit),
                };
            }
            if ($work === 0) {
                return;
            }
        }
    }

    protected function monotonicTime(): int
    {
        return hrtime(true);
    }

    private function ineligible(Builder $query, Retention $retention): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('sampled_at', '<', $retention->cutoff->toIso8601String())
            ->orWhere('sampled_at', '>', $retention->now->toIso8601String()));
    }

    private function raw(Retention $retention, int $limit): int
    {
        return DB::transaction(function () use ($retention, $limit): int {
            $identities = $this->ineligible(DB::table('telemetry_accepted'), $retention)->orderBy('sampled_at')->orderBy('id')->limit($limit)->get();
            $machine = $stream = '';
            foreach ($identities->groupBy(fn (object $row) => $row->machine_id.'|'.$row->stream_id) as $group) {
                $first = $last = null;
                foreach ($group->sortBy('sequence') as $identity) {
                    $machine = $identity->machine_id;
                    $stream = $identity->stream_id;
                    if ($last !== null && $identity->sequence - $last !== 1) {
                        $this->remember($machine, $stream, $first, $last);
                        $first = null;
                    }
                    $first ??= $identity->sequence;
                    $last = $identity->sequence;
                }
                $this->remember($machine, $stream, $first, $last);
            }

            return $identities->isEmpty() ? 0 : DB::table('telemetry_accepted')->whereIn('id', $identities->pluck('id'))->delete();
        });
    }

    private function remember(string $machine, string $stream, int $first, int $last): void
    {
        $key = ['machine_id' => $machine, 'stream_id' => $stream];
        $adjacent = DB::table('history_accepted_intervals')->where($key)
            ->where('first_sequence', '<=', $last === PHP_INT_MAX ? $last : $last + 1)
            ->where('last_sequence', '>=', $first - 1)->get();
        foreach ($adjacent as $interval) {
            $first = min($first, $interval->first_sequence);
            $last = max($last, $interval->last_sequence);
        }
        DB::table('history_accepted_intervals')->whereIn('id', $adjacent->pluck('id'))->delete();
        DB::table('history_accepted_intervals')->insert($key + ['first_sequence' => $first, 'last_sequence' => $last]);
    }

    private function buckets(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            $ids = DB::table('history_buckets')->orderBy('id')->limit($limit)->pluck('id');

            return $ids->isEmpty() ? 0 : DB::table('history_buckets')->whereIn('id', $ids)->delete();
        });
    }

    private function contacts(Retention $retention, int $limit): int
    {
        return DB::transaction(function () use ($retention, $limit): int {
            $contacts = $this->ineligible(DB::table('telemetry_contacts'), $retention)
                ->whereNull('payload->unavailable_reason')->orderBy('machine_id')->limit($limit)->get();
            $changed = 0;
            foreach ($contacts as $contact) {
                $identity = array_intersect_key((array) $contact, array_flip(['machine_id', 'generation', 'boot_id', 'stream_id', 'sequence', 'sampled_at']));
                $changed += $this->ineligible(DB::table('telemetry_contacts')->where($identity), $retention)
                    ->whereNull('payload->unavailable_reason')->update(['payload' => json_encode(Retention::unavailable(), JSON_THROW_ON_ERROR)]);
            }

            return $changed;
        });
    }
}
