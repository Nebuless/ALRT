<?php

namespace App\Telemetry;

use Closure;
use Illuminate\Support\Facades\DB;
use PDOException;

class CurrentTransaction
{
    /**
     * Retry only acquisition: the callback and its effects are never replayed.
     * SQLite's long busy-handler sleeps otherwise let replay writers overtake
     * a waiting current contact repeatedly.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction($callback);
        }
        $pdo = $connection->getPdo();
        $timeout = (int) $connection->selectOne('PRAGMA busy_timeout')->timeout;
        $deadline = hrtime(true) + 5_000_000_000;
        try {
            $pdo->exec('PRAGMA busy_timeout=5');
            while (true) {
                $entered = false;
                try {
                    return $connection->transaction(function () use ($callback, &$entered, $pdo, $timeout) {
                        $entered = true;
                        $pdo->exec('PRAGMA busy_timeout='.$timeout);

                        return $callback();
                    });
                } catch (PDOException $error) {
                    if ($entered || ($error->errorInfo[1] ?? null) !== 5) {
                        throw $error;
                    }
                    if (hrtime(true) >= $deadline) {
                        throw new TelemetryException('ingest_unavailable', 503);
                    }
                    usleep(random_int(1000, 5000));
                }
            }
        } finally {
            $pdo->exec('PRAGMA busy_timeout='.$timeout);
        }
    }
}
