<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramInboundMessaging
{
    /**
     * Tras un webhook POST de Instagram, responde por DM si está activado en config.
     *
     * @param  array<string, mixed>  $payload
     */
    public function maybeAutoReplyToDirectMessages(array $payload): void
    {
        if (! config('services.instagram.auto_reply_dm')) {
            return;
        }

        $token = $this->normalizedAccessToken();
        if ($token === null) {
            Log::warning('Instagram auto-reply: INSTAGRAM_ACCESS_TOKEN vacío o no legible');

            return;
        }

        if (! $this->tokenIsInstagramApi($token)) {
            Log::warning('Instagram auto-reply: el token debe ser tipo Instagram API (prefijo IGAA… / IGQV…) para graph.instagram.com/me/messages');

            return;
        }

        $text = (string) config('services.instagram.auto_reply_dm_text', 'Hello world');
        if ($text === '') {
            return;
        }

        $entries = $payload['entry'] ?? null;
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $messaging = $entry['messaging'] ?? null;
            if (! is_array($messaging)) {
                continue;
            }
            foreach ($messaging as $event) {
                if (is_array($event)) {
                    $this->handleMessagingEvent($event, $token, $text);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleMessagingEvent(array $event, string $token, string $text): void
    {
        if (isset($event['read']) || isset($event['delivery'])) {
            return;
        }

        $message = $event['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        if (! empty($message['is_echo'])) {
            return;
        }

        $mid = $message['mid'] ?? null;
        if (is_string($mid) && $mid !== '') {
            if (! Cache::add('instagram:auto_reply:mid:'.$mid, 1, now()->addDay())) {
                return;
            }
        }

        $sender = $event['sender']['id'] ?? null;
        if (! is_string($sender) || $sender === '') {
            return;
        }

        $this->sendDirectMessage($token, $sender, $text);
    }

    private function sendDirectMessage(string $token, string $recipientIgsid, string $text): void
    {
        $version = ltrim((string) config('services.instagram.instagram_api_version', 'v21.0'), '/');
        $url = 'https://graph.instagram.com/'.$version.'/me/messages';

        $response = Http::acceptJson()
            ->timeout(20)
            ->withToken($token)
            ->post($url, [
                'recipient' => ['id' => $recipientIgsid],
                'message' => ['text' => $text],
            ]);

        if (! $response->successful()) {
            Log::warning('Instagram auto-reply: envío fallido', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return;
        }

        if (config('services.instagram.log_requests')) {
            Log::info('Instagram auto-reply: mensaje enviado', [
                'recipient_prefix' => mb_substr($recipientIgsid, 0, 6).'…',
            ]);
        }
    }

    private function normalizedAccessToken(): ?string
    {
        $raw = config('services.instagram.access_token');
        if (! is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        if (
            (str_starts_with($t, '"') && str_ends_with($t, '"'))
            || (str_starts_with($t, "'") && str_ends_with($t, "'"))
        ) {
            $t = trim(substr($t, 1, -1));
        }
        if ($t === '') {
            return null;
        }
        if (preg_match('/\s/', $t)) {
            $t = preg_replace('/\s+/u', '', $t) ?? $t;
        }

        return $t === '' ? null : $t;
    }

    private function tokenIsInstagramApi(string $token): bool
    {
        return str_starts_with($token, 'IGAA')
            || str_starts_with($token, 'IGQV');
    }
}
