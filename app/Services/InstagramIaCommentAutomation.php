<?php

namespace App\Services;

use App\Models\InstagramDirectMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramIaCommentAutomation
{
    /** Comentario exacto (sin distinguir mayúsculas). */
    private const KEYWORD = 'IA';

    private const PUBLIC_THREAD_REPLY = 'Te ha llegado mi mensaje?';

    private const DM_TITLE = 'Hola mundo';

    private const DM_BUTTON_TITLE = 'Obtener enlace';

    private const DM_BUTTON_URL = 'https://amuletvoice.com';

    private const INSTAGRAM_API_VERSION = 'v21.0';

    private const FACEBOOK_GRAPH_VERSION = 'v21.0';

    /**
     * Comentario "IA" → respuesta en hilo + DM con botón al enlace.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'instagram') {
            return;
        }

        $token = $this->normalizedAccessToken();
        if ($token === null) {
            Log::warning('Instagram IA comentario: sin INSTAGRAM_ACCESS_TOKEN');

            return;
        }

        $keywordLower = mb_strtolower(self::KEYWORD);
        $ourIgId = config('services.instagram.business_account_id');
        $ourIgId = is_string($ourIgId) ? trim($ourIgId) : '';

        $entries = $payload['entry'] ?? null;
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = $entry['changes'] ?? null;
            if (! is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'comments') {
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }
                $this->processCommentChange($value, $keywordLower, $token, $ourIgId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function processCommentChange(
        array $value,
        string $keywordLower,
        string $token,
        string $ourIgId,
    ): void {
        $commentId = $value['id'] ?? null;
        if (! is_string($commentId) || $commentId === '') {
            return;
        }

        if (! Cache::add('instagram:ia_comment:'.$commentId, 1, now()->addDay())) {
            return;
        }

        $text = isset($value['text']) && is_string($value['text']) ? trim($value['text']) : '';
        if (mb_strtolower($text) !== $keywordLower) {
            Cache::forget('instagram:ia_comment:'.$commentId);

            return;
        }

        $from = $value['from'] ?? null;
        $senderId = is_array($from) && isset($from['id']) && is_string($from['id']) ? $from['id'] : null;
        if ($senderId === null || $senderId === '') {
            Log::warning('Instagram IA comentario: sin from.id', ['comment_id' => $commentId]);
            Cache::forget('instagram:ia_comment:'.$commentId);

            return;
        }

        if ($ourIgId !== '' && $senderId === $ourIgId) {
            Cache::forget('instagram:ia_comment:'.$commentId);

            return;
        }

        if (! $this->replyToComment($token, $commentId, self::PUBLIC_THREAD_REPLY)) {
            Log::warning('Instagram IA comentario: falló respuesta pública', ['comment_id' => $commentId]);
        }

        $dmSend = $this->sendDmGenericWithWebUrlButton($token, $senderId);
        if (! $dmSend['ok']) {
            Log::warning('Instagram IA comentario: falló DM con botón', [
                'comment_id' => $commentId,
                'recipient_prefix' => mb_substr($senderId, 0, 8).'…',
            ]);
        } else {
            InstagramDirectMessage::query()->create([
                'peer_ig_user_id' => $senderId,
                'direction' => InstagramDirectMessage::DIRECTION_OUTBOUND,
                'body' => self::DM_TITLE,
                'meta_message_id' => $dmSend['message_id'],
            ]);
            Log::info('Instagram IA comentario: flujo completado', ['comment_id' => $commentId]);
        }
    }

    private function replyToComment(string $token, string $commentId, string $message): bool
    {
        if ($this->tokenIsInstagramApi($token)) {
            $v = ltrim(self::INSTAGRAM_API_VERSION, '/');
            $url = 'https://graph.instagram.com/'.$v.'/'.$commentId.'/replies';
            $response = Http::asForm()
                ->timeout(25)
                ->withToken($token)
                ->post($url, ['message' => $message]);
        } else {
            $v = ltrim(self::FACEBOOK_GRAPH_VERSION, '/');
            $url = 'https://graph.facebook.com/'.$v.'/'.$commentId.'/replies';
            $response = Http::asForm()
                ->timeout(25)
                ->post($url, [
                    'message' => $message,
                    'access_token' => $token,
                ]);
        }

        return $response->successful();
    }

    /**
     * @return array{ok: bool, message_id: ?string}
     */
    private function sendDmGenericWithWebUrlButton(string $token, string $recipientIgsid): array
    {
        if (! $this->tokenIsInstagramApi($token)) {
            Log::warning('Instagram IA comentario: la plantilla con botón requiere token Instagram API (IGAA…)');

            return ['ok' => false, 'message_id' => null];
        }

        $v = ltrim(self::INSTAGRAM_API_VERSION, '/');
        $url = 'https://graph.instagram.com/'.$v.'/me/messages';

        $payload = [
            'recipient' => ['id' => $recipientIgsid],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => [
                            [
                                'title' => mb_substr(self::DM_TITLE, 0, 80),
                                'buttons' => [
                                    [
                                        'type' => 'web_url',
                                        'url' => self::DM_BUTTON_URL,
                                        'title' => mb_substr(self::DM_BUTTON_TITLE, 0, 20),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::acceptJson()
            ->timeout(25)
            ->withToken($token)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('Instagram IA comentario: respuesta DM', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return ['ok' => false, 'message_id' => null];
        }

        $json = $response->json();
        $messageId = is_array($json) && isset($json['message_id']) && is_string($json['message_id'])
            ? $json['message_id']
            : null;

        return ['ok' => true, 'message_id' => $messageId];
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
