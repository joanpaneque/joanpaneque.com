<?php

namespace App\Services;

use App\Models\InstagramDirectMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramInboundMessaging
{
    private const DM_AI_MODEL = 'openai/gpt-oss-120b';

    private const DM_HISTORY_LIMIT = 7;

    private const DM_SYSTEM_PROMPT = 'Eres el asistente de esta cuenta de Instagram. Responde en el mismo idioma que el usuario, de forma natural y breve.';

    /**
     * Webhook messaging: guarda mensajes (entrantes, eco salientes) y opcionalmente responde con IA.
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

        if ($sender === null || $text === '') {
            return;
        }

        if ($mid !== null && InstagramDirectMessage::query()->where('meta_message_id', $mid)->exists()) {
            return;
        }

        InstagramDirectMessage::query()->create([
            'peer_ig_user_id' => $sender,
            'direction' => InstagramDirectMessage::DIRECTION_INBOUND,
            'body' => $text,
            'meta_message_id' => $mid,
        ]);

        if (! config('services.instagram.auto_reply_dm')) {
            return;
        }

        $history = InstagramDirectMessage::recentForPeer($sender, self::DM_HISTORY_LIMIT);
        $openAiMessages = [['role' => 'system', 'content' => self::DM_SYSTEM_PROMPT]];
        foreach ($history as $row) {
            $openAiMessages[] = [
                'role' => $row->direction === InstagramDirectMessage::DIRECTION_INBOUND ? 'user' : 'assistant',
                'content' => $row->body,
            ];
        }

        $replyText = null;
        try {
            $data = OpenRouter::chatCompletion($openAiMessages, self::DM_AI_MODEL, [
                'reasoning' => ['effort' => 'low'],
            ]);
            $replyText = $data['choices'][0]['message']['content'] ?? null;
            $replyText = is_string($replyText) ? trim($replyText) : null;
        } catch (RuntimeException $e) {
            Log::warning('Instagram DM: OpenRouter falló', ['message' => $e->getMessage()]);
        }

        if ($replyText === null || $replyText === '') {
            return;
        }

        $outMid = $this->sendDirectMessage($token, $sender, $replyText);
        InstagramDirectMessage::query()->create([
            'peer_ig_user_id' => $sender,
            'direction' => InstagramDirectMessage::DIRECTION_OUTBOUND,
            'body' => $replyText,
            'meta_message_id' => $outMid,
        ]);
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
