<?php

namespace App\Services;

use App\Models\InstagramDirectMessage;
use App\Models\InstagramKeywordRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramInboundMessaging
{
    /**
     * Webhook messaging: guarda mensajes (entrantes, eco salientes) y respuestas de fase 2 (Nebula / quick replies).
     *
     * @param  array<string, mixed>  $payload
     */
    public function maybeAutoReplyToDirectMessages(array $payload): void
    {
        $token = $this->normalizedAccessToken();
        if ($token === null) {
            return;
        }

        if (! $this->tokenIsInstagramApi($token)) {
            Log::warning('Instagram DM: hace falta token Instagram API (IGAA…) para mensajes');

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
                    $this->handleMessagingEvent($event, $token);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleMessagingEvent(array $event, string $token): void
    {
        if (isset($event['read']) || isset($event['delivery'])) {
            return;
        }

        $message = $event['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $recipient = isset($event['recipient']['id']) && is_string($event['recipient']['id'])
            ? $event['recipient']['id']
            : null;
        $sender = isset($event['sender']['id']) && is_string($event['sender']['id'])
            ? $event['sender']['id']
            : null;

        $text = isset($message['text']) && is_string($message['text']) ? trim($message['text']) : '';
        $mid = isset($message['mid']) && is_string($message['mid']) ? $message['mid'] : null;
        $quickReplyPayload = null;
        if (isset($message['quick_reply']) && is_array($message['quick_reply'])) {
            $p = $message['quick_reply']['payload'] ?? null;
            if (is_string($p) && trim($p) !== '') {
                $quickReplyPayload = trim($p);
            }
        }

        if (! empty($message['is_echo'])) {
            if ($recipient !== null && $text !== '' && ($mid === null || ! InstagramDirectMessage::query()->where('meta_message_id', $mid)->exists())) {
                InstagramDirectMessage::query()->create([
                    'peer_ig_user_id' => $recipient,
                    'direction' => InstagramDirectMessage::DIRECTION_OUTBOUND,
                    'body' => $text,
                    'meta_message_id' => $mid,
                ]);
            }

            return;
        }

        if ($sender === null) {
            return;
        }

        if ($text === '' && $quickReplyPayload === null) {
            return;
        }

        $storedInboundBody = $text !== '' ? $text : (string) $quickReplyPayload;

        if ($mid !== null && InstagramDirectMessage::query()->where('meta_message_id', $mid)->exists()) {
            return;
        }

        InstagramDirectMessage::query()->create([
            'peer_ig_user_id' => $sender,
            'direction' => InstagramDirectMessage::DIRECTION_INBOUND,
            'body' => $storedInboundBody,
            'meta_message_id' => $mid,
        ]);

        if ($this->tryPhase2ConfiguredReply($sender, $text, $quickReplyPayload, $token)) {
            return;
        }
    }

    /**
     * Respuesta fase 2 (Nebula): quick reply o texto coincidente con botón/payload de una regla.
     */
    private function tryPhase2ConfiguredReply(
        string $senderIgsid,
        string $text,
        ?string $quickReplyPayload,
        string $token,
    ): bool {
        $rules = InstagramKeywordRule::query()->active()->ordered()->get();
        foreach ($rules as $rule) {
            if (! $rule->hasDmAutomation()) {
                continue;
            }
            $qrs = $rule->dm_quick_replies;
            if (! is_array($qrs)) {
                continue;
            }
            foreach ($qrs as $qr) {
                if (! is_array($qr)) {
                    continue;
                }
                $title = isset($qr['title']) && is_string($qr['title']) ? trim($qr['title']) : '';
                $payload = isset($qr['payload']) && is_string($qr['payload']) ? trim($qr['payload']) : '';
                if ($payload === '' && $title !== '') {
                    $payload = $title;
                }
                $matched = false;
                if ($quickReplyPayload !== null && $payload !== '' && $quickReplyPayload === $payload) {
                    $matched = true;
                }
                if (! $matched && $text !== '' && $title !== '' && mb_strtolower($text) === mb_strtolower($title)) {
                    $matched = true;
                }
                if (! $matched && $text !== '' && $payload !== '' && $text === $payload) {
                    $matched = true;
                }
                if (! $matched) {
                    continue;
                }
                $variants = array_values(array_filter(
                    $rule->dm_phase2_reply_variants ?? [],
                    fn ($v) => is_string($v) && trim($v) !== '',
                ));
                if ($variants === []) {
                    continue;
                }
                $replyText = $variants[array_rand($variants)];
                $outMid = $this->sendDirectMessage($token, $senderIgsid, $replyText);
                InstagramDirectMessage::query()->create([
                    'peer_ig_user_id' => $senderIgsid,
                    'direction' => InstagramDirectMessage::DIRECTION_OUTBOUND,
                    'body' => $replyText,
                    'meta_message_id' => $outMid,
                ]);

                return true;
            }
        }

        return false;
    }

    private function sendDirectMessage(string $token, string $recipientIgsid, string $text): ?string
    {
        $version = ltrim((string) config('services.instagram.instagram_api_version', 'v21.0'), '/');
        $url = 'https://graph.instagram.com/'.$version.'/me/messages';

        $response = Http::acceptJson()
            ->timeout(60)
            ->withToken($token)
            ->post($url, [
                'recipient' => ['id' => $recipientIgsid],
                'message' => ['text' => $text],
            ]);

        if (! $response->successful()) {
            Log::warning('Instagram DM: envío fallido', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        if (config('services.instagram.log_requests')) {
            Log::info('Instagram DM: mensaje enviado', [
                'recipient_prefix' => mb_substr($recipientIgsid, 0, 6).'…',
            ]);
        }

        $json = $response->json();

        return is_array($json) && isset($json['message_id']) && is_string($json['message_id'])
            ? $json['message_id']
            : null;
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
