<?php

namespace App\Services;

use App\Models\InstagramDirectMessage;
use App\Models\InstagramKeywordRule;
use App\Models\InstagramKeywordRuleEmbedding;
use App\Support\VectorSimilarity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramCommentAutomation
{
    private const INSTAGRAM_API_VERSION = 'v21.0';

    private const FACEBOOK_GRAPH_VERSION = 'v21.0';

    private const TOP_KEYWORD_CANDIDATES = 3;

    public function __construct(
        private readonly InstagramKeywordCommentIntentClassifier $intentClassifier,
    ) {}

    /**
     * Comentarios → embedding del texto, top-3 keywords por similitud, LLM confirma intención, luego flujo habitual.
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
            Log::warning('Instagram comentario: sin INSTAGRAM_ACCESS_TOKEN');

            return;
        }

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
                $this->processCommentChange($value, $token, $ourIgId, $payload);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $fullWebhookPayload
     */
    private function processCommentChange(
        array $value,
        string $token,
        string $ourIgId,
        array $fullWebhookPayload,
    ): void {
        $commentId = $value['id'] ?? null;
        if (! is_string($commentId) || $commentId === '') {
            return;
        }

        if (! Cache::add('instagram:comment_rule:'.$commentId, 1, now()->addDay())) {
            return;
        }

        $text = isset($value['text']) && is_string($value['text']) ? trim($value['text']) : '';

        if ($text === '') {
            Cache::forget('instagram:comment_rule:'.$commentId);
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        $rows = InstagramKeywordRuleEmbedding::query()
            ->whereHas('rule', fn ($q) => $q->where('is_active', true))
            ->with('rule')
            ->get();

        if ($rows->isEmpty()) {
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        try {
            $commentEmbedding = OpenRouter::createEmbedding($text);
        } catch (RuntimeException $e) {
            Log::warning('Instagram comentario: embedding falló', ['message' => $e->getMessage()]);
            Cache::forget('instagram:comment_rule:'.$commentId);
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        $scored = [];
        foreach ($rows as $row) {
            $emb = $row->embedding;
            if (! is_array($emb) || $emb === []) {
                continue;
            }
            /** @var list<float> $emb */
            $score = VectorSimilarity::cosineSimilarity($commentEmbedding, $emb);
            $rid = $row->instagram_keyword_rule_id;
            if (! is_int($rid)) {
                continue;
            }
            $scored[] = [
                'rule_id' => $rid,
                'keyword' => (string) $row->keyword,
                'score' => $score,
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top3 = array_slice($scored, 0, self::TOP_KEYWORD_CANDIDATES);

        if ($top3 === []) {
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        $ruleId = $this->intentClassifier->classify($text, $top3);

        if ($ruleId === null) {
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        $rule = InstagramKeywordRule::query()->active()->whereKey($ruleId)->first();
        if ($rule === null) {
            Log::warning('Instagram comentario: regla no activa o inexistente', ['rule_id' => $ruleId]);
            InstagramCommentContinueProcess::continue_process($fullWebhookPayload);

            return;
        }

        $from = $value['from'] ?? null;
        $senderId = is_array($from) && isset($from['id']) && is_string($from['id']) ? $from['id'] : null;
        if ($senderId === null || $senderId === '') {
            Log::warning('Instagram comentario: sin from.id', ['comment_id' => $commentId]);
            Cache::forget('instagram:comment_rule:'.$commentId);

            return;
        }

        if ($ourIgId !== '' && $senderId === $ourIgId) {
            Cache::forget('instagram:comment_rule:'.$commentId);

            return;
        }

        $this->applyRule($rule, $commentId, $token, $senderId);
    }

    private function applyRule(
        InstagramKeywordRule $rule,
        string $commentId,
        string $token,
        string $senderId,
    ): void {
        $variants = array_values(array_filter($rule->comment_reply_variants, fn ($v) => is_string($v) && trim($v) !== ''));
        if ($variants === []) {
            Cache::forget('instagram:comment_rule:'.$commentId);

            return;
        }

        $publicReply = $variants[array_rand($variants)];

        if (! $this->replyToComment($token, $commentId, $publicReply)) {
            Log::warning('Instagram comentario: falló respuesta pública', ['comment_id' => $commentId, 'rule_id' => $rule->id]);
        }

        if (! $rule->hasDmAutomation()) {
            Log::info('Instagram comentario: regla sin DM', ['comment_id' => $commentId, 'rule_id' => $rule->id]);

            return;
        }

        if (! $this->tokenIsInstagramApi($token)) {
            Log::warning('Instagram comentario: quick replies requieren token Instagram API (IGAA…)', ['rule_id' => $rule->id]);

            return;
        }

        $dmSend = $this->sendDmWithQuickReplies(
            $token,
            $senderId,
            (string) $rule->dm_phase1_text,
            $rule->dm_quick_replies ?? [],
        );
        if (! $dmSend['ok']) {
            Log::warning('Instagram comentario: falló DM fase 1', [
                'comment_id' => $commentId,
                'rule_id' => $rule->id,
                'recipient_prefix' => mb_substr($senderId, 0, 8).'…',
            ]);
        } else {
            InstagramDirectMessage::query()->create([
                'peer_ig_user_id' => $senderId,
                'direction' => InstagramDirectMessage::DIRECTION_OUTBOUND,
                'body' => '[Fase 1] '.mb_substr((string) $rule->dm_phase1_text, 0, 2000),
                'meta_message_id' => $dmSend['message_id'],
            ]);
            Log::info('Instagram comentario: DM fase 1 enviado', ['comment_id' => $commentId, 'rule_id' => $rule->id]);
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
     * @param  array<int, array{title?: mixed, payload?: mixed}>  $quickReplies
     * @return array{ok: bool, message_id: ?string}
     */
    private function sendDmWithQuickReplies(string $token, string $recipientIgsid, string $bodyText, array $quickReplies): array
    {
        $built = [];
        foreach ($quickReplies as $qr) {
            if (! is_array($qr)) {
                continue;
            }
            $title = isset($qr['title']) && is_string($qr['title']) ? trim($qr['title']) : '';
            if ($title === '') {
                continue;
            }
            $payload = isset($qr['payload']) && is_string($qr['payload']) ? trim($qr['payload']) : $title;
            if ($payload === '') {
                $payload = $title;
            }
            $built[] = [
                'content_type' => 'text',
                'title' => mb_substr($title, 0, 20),
                'payload' => mb_substr($payload, 0, 1000),
            ];
            if (count($built) >= 13) {
                break;
            }
        }

        if ($built === []) {
            return ['ok' => false, 'message_id' => null];
        }

        $v = ltrim(self::INSTAGRAM_API_VERSION, '/');
        $url = 'https://graph.instagram.com/'.$v.'/me/messages';

        $payload = [
            'recipient' => ['id' => $recipientIgsid],
            'message' => [
                'text' => $bodyText,
                'quick_replies' => $built,
            ],
        ];

        $response = Http::acceptJson()
            ->timeout(25)
            ->withToken($token)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('Instagram comentario: API DM quick_replies', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
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
