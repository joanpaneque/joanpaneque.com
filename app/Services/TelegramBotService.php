<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotService
{
    private static function baseUrl(): string
    {
        $token = config('services.telegram.api_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram API token is not configured (services.telegram.api_token).');
        }

        return 'https://api.telegram.org/bot'.$token.'/';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function get(string $method, array $params = []): array
    {
        $response = Http::timeout(30)->get(self::baseUrl().$method, $params);

        return self::decodeResponse($response);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function post(string $method, array $params = []): array
    {
        $response = Http::timeout(30)->asForm()->post(self::baseUrl().$method, $params);

        return self::decodeResponse($response);
    }

    /**
     * Información del bot (@username, id, etc.).
     *
     * @return array<string, mixed>
     */
    public static function getMe(): array
    {
        return self::get('getMe');
    }

    /**
     * Actualizaciones pendientes (polling). Útil en desarrollo o sin webhook.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function getUpdates(array $params = []): array
    {
        return self::get('getUpdates', $params);
    }

    /**
     * Envía un mensaje de texto. Si no pasas chat_id, usa TELEGRAM_CHAT_ID.
     *
     * @param  array<string, mixed>  $options  parse_mode, reply_markup, disable_web_page_preview, etc.
     * @return array<string, mixed>
     */
    public static function sendMessage(string $text, ?string $chatId = null, array $options = []): array
    {
        $chatId ??= config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Telegram chat_id is not configured (services.telegram.chat_id).');
        }

        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options);

        return self::post('sendMessage', $params);
    }

    /**
     * Edita el texto de un mensaje existente (p. ej. quitar botones con reply_markup vacío).
     *
     * @param  array<string, mixed>  $options  parse_mode, reply_markup, link_preview_options, etc.
     * @return array<string, mixed>
     */
    public static function editMessageText(string $text, string $chatId, int $messageId, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options);

        return self::post('editMessageText', $params);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function sendPhoto(string $photo, ?string $chatId = null, ?string $caption = null, array $options = []): array
    {
        $chatId ??= config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Telegram chat_id is not configured (services.telegram.chat_id).');
        }

        $params = array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
        ], $options);

        if ($caption !== null) {
            $params['caption'] = $caption;
        }

        return self::post('sendPhoto', $params);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function sendDocument(string $document, ?string $chatId = null, ?string $caption = null, array $options = []): array
    {
        $chatId ??= config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Telegram chat_id is not configured (services.telegram.chat_id).');
        }

        $params = array_merge([
            'chat_id' => $chatId,
            'document' => $document,
        ], $options);

        if ($caption !== null) {
            $params['caption'] = $caption;
        }

        return self::post('sendDocument', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function setWebhook(string $url, array $params = []): array
    {
        return self::post('setWebhook', array_merge(['url' => $url], $params));
    }

    /**
     * Webhook con callback_query + reacciones (message_reaction / message_reaction_count).
     * Telegram solo envía reacciones si están en allowed_updates.
     *
     * @return array<string, mixed>
     */
    public static function setWebhookWithReactions(string $url, ?string $secretToken = null): array
    {
        $params = [
            'url' => $url,
            'allowed_updates' => json_encode(['callback_query', 'message_reaction', 'message_reaction_count'], JSON_THROW_ON_ERROR),
        ];

        if (is_string($secretToken) && $secretToken !== '') {
            $params['secret_token'] = $secretToken;
        }

        return self::post('setWebhook', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public static function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return self::post('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getWebhookInfo(): array
    {
        return self::get('getWebhookInfo');
    }

    /**
     * Responde a un botón inline (obligatorio en muchos clientes para quitar el “loading”).
     *
     * @param  array<string, mixed>  $params  text, show_alert, url, cache_time
     * @return array<string, mixed>
     */
    public static function answerCallbackQuery(string $callbackQueryId, array $params = []): array
    {
        return self::post('answerCallbackQuery', array_merge([
            'callback_query_id' => $callbackQueryId,
        ], $params));
    }

    /**
     * @return array{ok: bool, result: mixed}
     */
    private static function decodeResponse(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Telegram API returned invalid JSON.');
        }

        if (! ($data['ok'] ?? false)) {
            $description = is_string($data['description'] ?? null)
                ? $data['description']
                : 'Unknown error';

            throw new RuntimeException('Telegram API: '.$description);
        }

        return $data;
    }
}
