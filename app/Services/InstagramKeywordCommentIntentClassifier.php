<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dado un comentario y hasta 3 candidatos (keyword + regla + score embedding),
 * usa un modelo pequeño para decidir si el usuario pretendía activar una de esas keywords.
 */
final class InstagramKeywordCommentIntentClassifier
{
    /**
     * @param  array<int, array{rule_id: int, keyword: string, score: float}>  $top3
     * @return int|null id de regla a aplicar, o null si no aplica
     */
    public function classify(string $commentText, array $top3): ?int
    {
        if ($top3 === []) {
            return null;
        }

        $allowedRuleIds = array_values(array_unique(array_map(fn (array $c) => $c['rule_id'], $top3)));

        $model = (string) config('services.openrouter.keyword_intent_model', 'openai/gpt-4o-mini');

        $system = <<<'PROMPT'
Eres un clasificador estricto. Recibes el texto de un comentario en Instagram y hasta 3 candidatos de "keywords" de automatización (cada uno con rule_id, keyword y una puntuación de similitud semántica).

Decide si el autor del comentario pretendía activar la automatización asociada a alguna de esas keywords (incluye errores de tipeo, inglés vs español, sinónimos cercanos o formas coloquiales). Si no hay intención clara o el comentario es irrelevante, responde que no coincide.

Responde SOLO con un objeto JSON válido con exactamente estas claves:
- "matches_intent": boolean
- "rule_id": número entero o null (si matches_intent es true, debe ser el rule_id de uno de los candidatos; si es false, debe ser null)
PROMPT;

        $userPayload = [
            'comment_text' => $commentText,
            'candidates' => array_map(fn (array $c) => [
                'rule_id' => $c['rule_id'],
                'keyword' => $c['keyword'],
                'embedding_similarity' => round($c['score'], 6),
            ], $top3),
        ];

        try {
            $data = OpenRouter::chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
                ],
                $model,
                [
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ],
            );
        } catch (RuntimeException $e) {
            Log::warning('Instagram intent classifier: OpenRouter falló', ['message' => $e->getMessage()]);

            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/```\s*$/', '', $content) ?? $content;
            $content = trim($content);
        }

        $parsed = json_decode($content, true);
        if (! is_array($parsed)) {
            Log::warning('Instagram intent classifier: JSON inválido', ['raw' => mb_substr($content, 0, 300)]);

            return null;
        }

        $matches = $parsed['matches_intent'] ?? false;
        if ($matches !== true && $matches !== 1 && $matches !== 'true') {
            return null;
        }

        $ruleId = $parsed['rule_id'] ?? null;
        if ($ruleId === null || $ruleId === '') {
            return null;
        }
        $ruleId = is_numeric($ruleId) ? (int) $ruleId : null;
        if ($ruleId === null || ! in_array($ruleId, $allowedRuleIds, true)) {
            Log::warning('Instagram intent classifier: rule_id fuera de candidatos', ['rule_id' => $ruleId]);

            return null;
        }

        return $ruleId;
    }
}
