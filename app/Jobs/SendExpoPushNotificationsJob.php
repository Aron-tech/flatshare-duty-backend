<?php

namespace App\Jobs;

use App\Models\PushToken;
use App\Services\Expo\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendExpoPushNotificationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $tokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(ExpoPushClient $client): void
    {
        foreach (array_chunk($this->tokens, ExpoPushClient::MAX_MESSAGES_PER_REQUEST) as $chunk) {
            $messages = array_map(fn (string $token) => [
                'to' => $token,
                'title' => $this->title,
                'body' => $this->body,
                'data' => (object) $this->data,
                'sound' => 'default',
                'channelId' => 'default',
            ], $chunk);

            $tickets = $client->send($messages);

            $this->removeUnregisteredTokens($chunk, $tickets);
        }
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function removeUnregisteredTokens(array $tokens, array $tickets): void
    {
        $invalid = [];

        foreach (array_values($tokens) as $index => $token) {
            $ticket = $tickets[$index] ?? [];

            if (($ticket['status'] ?? null) === 'error' && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $invalid[] = $token;
            }
        }

        if ($invalid !== []) {
            PushToken::whereIn('token', $invalid)->delete();
        }
    }
}
