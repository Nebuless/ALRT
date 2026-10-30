<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** @property bool $reboot_enabled */
class Machine extends Model
{
    use HasUuids;

    public const HEALTH_PROCESSES = 'health_processes';

    public const TITLES = 'titles';

    protected $fillable = ['id', 'name', 'data_policy'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['token_revoked_at' => 'immutable_datetime', 'reboot_enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Machine $machine): void {
            if (! in_array($machine->data_policy, [self::HEALTH_PROCESSES, self::TITLES], true) ||
                (strcasecmp($machine->name, 'Beta-Restricted') === 0 && $machine->data_policy !== self::HEALTH_PROCESSES)) {
                throw new InvalidArgumentException('Invalid machine data policy.');
            }
        });
    }

    /** Return this credential only during explicit local enrollment. */
    public function rotateToken(): string
    {
        $token = Str::random(64);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'token_revoked_at' => null])->save();

        return $token;
    }

    public function revokeToken(): void
    {
        $this->forceFill(['token_revoked_at' => now()])->save();
    }

    public function allowsTitles(): bool
    {
        return $this->data_policy === self::TITLES;
    }
}
