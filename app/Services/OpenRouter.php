<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente estático compatible con la API OpenAI (Chat Completions) vía OpenRouter.
 *
 * @see https://openrouter.ai/docs
 */
final class OpenRouter
{
    /**
     * Embeddings (OpenAI-compatible POST /v1/embeddings).
     *
     * @return list<float>
     */
    public static function createEmbedding(string $input): array
    {
        $model = (string) config('services.openrouter.embedding_model', 'openai/text-embedding-3-small');
        $body = [
            'model' => $model,
            'input' => $input,
        ];

        $json = self::postJson('embeddings', $body);
        $data = $json['data'] ?? null;
        if (! is_array($data) || ! isset($data[0]) || ! is_array($data[0])) {
            throw new RuntimeException('OpenRouter embeddings: respuesta sin data[0].');
        }
        $embedding = $data[0]['embedding'] ?? null;
        if (! is_array($embedding)) {
            throw new RuntimeException('OpenRouter embeddings: falta el vector embedding.');
        }
        $out = [];
        foreach ($embedding as $x) {
            if (is_int($x) || is_float($x)) {
                $out[] = (float) $x;
            }
        }
        if ($out === []) {
            throw new RuntimeException('OpenRouter embeddings: vector vacío o inválido.');
        }

        return $out;
    }

    public static function chatCompletion(array $messages, ?string $model = null, array $options = []): array
    {
        $body = array_merge(
            ['model' => config('services.openrouter.default_model')],
            $options,
            ['messages' => $messages],
        );
        if ($model !== null) {
            $body['model'] = $model;
        }

        return self::postJson('chat/completions', $body);
    }

    /**
     * Una petición user (+ system opcional); devuelve solo el texto del asistente.
     */
    public static function chatText(
        string $userMessage,
        ?string $systemPrompt = null,
        ?string $model = null,
        array $options = [],
    ): string {
        $messages = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $data = self::chatCompletion($messages, $model, $options);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (! is_string($content)) {
            throw new RuntimeException('OpenRouter: la respuesta no incluye texto del asistente.');
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function postJson(string $path, array $body): array
    {
        $path = ltrim($path, '/');
        $url = self::baseUrl().'/'.$path;

        $request = Http::acceptJson()
            ->timeout((int) config('services.openrouter.timeout', 120))
            ->withToken(self::apiKey())
            ->withHeaders(array_filter([
                'HTTP-Referer' => config('services.openrouter.http_referer'),
                'X-Title' => config('services.openrouter.app_title'),
            ]));

        try {
            $response = $request->post($url, $body);
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRouter: error de red — '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            self::throwApiError($response->status(), $response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('OpenRouter: respuesta JSON inválida.');
        }

        return $json;
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
    }

    private static function apiKey(): string
    {
        $key = config('services.openrouter.api_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Define OPENROUTER_API_KEY en .env');
        }

        return trim($key);
    }

    private static function throwApiError(int $status, string $body): never
    {
        $snippet = mb_substr($body, 0, 800);
        $decoded = json_decode($body, true);
        $message = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $snippet)
            : $snippet;

        throw new RuntimeException("OpenRouter HTTP {$status}: {$message}");
    }
}
