<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $model = (string) config('services.openrouter.embedding_model', 'intfloat/multilingual-e5-large');
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
     * Chat Completions con stream SSE upstream. Devuelve el texto completo acumulado.
     *
     * @param  callable(string): void  $onToken
     */
    public static function streamChatCompletion(array $messages, ?string $model, array $options, callable $onToken): string
    {
        $startedAt = microtime(true);
        $body = array_merge(
            ['model' => config('services.openrouter.default_model')],
            $options,
            [
                'messages' => $messages,
                'stream' => true,
            ],
        );
        if ($model !== null) {
            $body['model'] = $model;
        }

        $response = self::streamPostJson('chat/completions', $body);
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, strlen('data:')));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }

                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if (is_string($delta) && $delta !== '') {
                    $content .= $delta;
                    $onToken($delta);
                }
            }
        }

        self::logAiEvent('stream_complete', [
            ...self::requestSummary('chat/completions', $body),
            'duration_ms' => self::elapsedMs($startedAt),
            'response_chars' => mb_strlen($content),
        ]);

        return $content;
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
        $startedAt = microtime(true);

        self::logAiEvent('request', self::requestSummary($path, $body));

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
            self::logAiEvent('network_error', [
                ...self::requestSummary($path, $body),
                'duration_ms' => self::elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('OpenRouter: error de red — '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            self::logAiEvent('response_error', [
                ...self::requestSummary($path, $body),
                'status' => $response->status(),
                'duration_ms' => self::elapsedMs($startedAt),
                'body_bytes' => strlen($response->body()),
            ]);

            self::throwApiError($response->status(), $response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('OpenRouter: respuesta JSON inválida.');
        }

        self::logAiEvent('response', [
            ...self::requestSummary($path, $body),
            ...self::responseSummary($json),
            'status' => $response->status(),
            'duration_ms' => self::elapsedMs($startedAt),
        ]);

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function streamPostJson(string $path, array $body): \Illuminate\Http\Client\Response
    {
        $path = ltrim($path, '/');
        $url = self::baseUrl().'/'.$path;
        $startedAt = microtime(true);

        self::logAiEvent('request', self::requestSummary($path, $body));

        $request = Http::accept('text/event-stream')
            ->timeout((int) config('services.openrouter.timeout', 120))
            ->withToken(self::apiKey())
            ->withOptions(['stream' => true])
            ->withHeaders(array_filter([
                'HTTP-Referer' => config('services.openrouter.http_referer'),
                'X-Title' => config('services.openrouter.app_title'),
            ]));

        try {
            $response = $request->post($url, $body);
        } catch (Throwable $e) {
            self::logAiEvent('network_error', [
                ...self::requestSummary($path, $body),
                'duration_ms' => self::elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('OpenRouter: error de red — '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            self::logAiEvent('response_error', [
                ...self::requestSummary($path, $body),
                'status' => $response->status(),
                'duration_ms' => self::elapsedMs($startedAt),
                'body_bytes' => strlen($response->body()),
            ]);

            self::throwApiError($response->status(), $response->body());
        }

        self::logAiEvent('response_stream_open', [
            ...self::requestSummary($path, $body),
            'status' => $response->status(),
            'duration_ms' => self::elapsedMs($startedAt),
        ]);

        return $response;
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

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function requestSummary(string $path, array $body): array
    {
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];

        return array_filter([
            'path' => $path,
            'model' => $body['model'] ?? null,
            'stream' => (bool) ($body['stream'] ?? false),
            'messages' => count($messages),
            'message_chars' => self::messagesCharacterCount($messages),
            'tools' => self::toolNames($tools),
            'tool_choice' => $body['tool_choice'] ?? null,
            'input_chars' => is_string($body['input'] ?? null) ? mb_strlen($body['input']) : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private static function responseSummary(array $json): array
    {
        $choices = is_array($json['choices'] ?? null) ? $json['choices'] : [];
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return array_filter([
            'response_id' => $json['id'] ?? null,
            'response_model' => $json['model'] ?? null,
            'choices' => count($choices) ?: null,
            'finish_reasons' => self::finishReasons($choices),
            'response_chars' => self::responseCharacterCount($choices),
            'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
            'embeddings' => count($data) ?: null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  list<mixed>  $messages
     */
    private static function messagesCharacterCount(array $messages): int
    {
        $count = 0;

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $count += mb_strlen($content);
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $count += mb_strlen($part);
                    } elseif (is_array($part) && is_string($part['text'] ?? null)) {
                        $count += mb_strlen($part['text']);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param  list<mixed>  $tools
     * @return list<string>
     */
    private static function toolNames(array $tools): array
    {
        $names = [];

        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = $tool['function']['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<mixed>  $choices
     * @return list<string>
     */
    private static function finishReasons(array $choices): array
    {
        $reasons = [];

        foreach ($choices as $choice) {
            if (is_array($choice) && is_string($choice['finish_reason'] ?? null)) {
                $reasons[] = $choice['finish_reason'];
            }
        }

        return $reasons;
    }

    /**
     * @param  list<mixed>  $choices
     */
    private static function responseCharacterCount(array $choices): int
    {
        $count = 0;

        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $content = $choice['message']['content'] ?? null;
            if (is_string($content)) {
                $count += mb_strlen($content);
            }
        }

        return $count;
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function logAiEvent(string $event, array $context): void
    {
        Log::info('[AI] '.$event, $context);
    }
}
