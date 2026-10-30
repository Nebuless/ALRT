<?php

namespace App\Notifications;

use App\Models\HubIdentity;
use Illuminate\Support\Facades\Http;

class TelegramTransport
{
    /** @return array{delivered: bool, error: ?string, retry_after: ?int} */
    public function send(string $machine, string $condition, string $observedAt): array
    {
        $hub = HubIdentity::find(1);
        if ($hub === null || ! $hub->telegram_bot_token || ! $hub->telegram_chat_id) {
            return ['delivered' => false, 'error' => 'not_configured', 'retry_after' => null];
        }
        $text = $machine.' — '.$condition."\nSeen by ".$hub->name.' at '.$observedAt."\n".config('incidents.public_url');
        try {
            $response = Http::asJson()->timeout(3)->connectTimeout(2)->withOptions(['allow_redirects' => false])
                ->post('https://api.telegram.org/bot'.$hub->telegram_bot_token.'/sendMessage', [
                    'chat_id' => $hub->telegram_chat_id,
                    'text' => $text,
                    'link_preview_options' => ['is_disabled' => true],
                ]);
            if ($response->successful() && $response->json('ok') === true) {
                return ['delivered' => true, 'error' => null, 'retry_after' => null];
            }
            if ($response->status() === 429) {
                $retry = $response->json('parameters.retry_after') ?? $response->header('Retry-After');

                return ['delivered' => false, 'error' => 'rate_limited', 'retry_after' => is_numeric($retry) ? max(1, min(3600, (int) $retry)) : 30];
            }

            return ['delivered' => false, 'error' => 'telegram_rejected', 'retry_after' => null];
        } catch (\Throwable) {
            return ['delivered' => false, 'error' => 'transport_failed', 'retry_after' => null];
        }
    }
}
