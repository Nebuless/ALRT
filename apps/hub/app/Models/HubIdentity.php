<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, string> $peers
 */
class HubIdentity extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['telegram_bot_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['peers' => 'array', 'telegram_bot_token' => 'encrypted'];
    }
}
