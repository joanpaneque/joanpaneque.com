<?php

namespace App\Services;

use App\Models\TeethCleaning;
use App\Models\TeethPrompt;
use RuntimeException;

class ToothFairy
{
    /**
     * Envía la pregunta con botones Sí/No y guarda el prompt para el webhook.
     *
     * @return TeethPrompt Incluye telegram_message_id cuando la API lo devuelve.
     */
    public function sendBrushingPrompt(int $gracePeriodMinutes): TeethPrompt
    {
        $gracePeriodMinutes = max(1, $gracePeriodMinutes);

        $chatId = config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Telegram chat_id is not configured (services.telegram.chat_id).');
        }

        $chatId = (string) $chatId;

        $prompt = TeethPrompt::query()->create([
            'telegram_chat_id' => $chatId,
            'prompt_sent_at' => now(),
            'grace_period_minutes' => $gracePeriodMinutes,
        ]);

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Sí', 'callback_data' => 'y:'.$prompt->id],
                    ['text' => 'No', 'callback_data' => 'n:'.$prompt->id],
                ],
            ],
        ];

        $response = TelegramBotService::sendMessage(
            'Te has lavado los dientes?',
            $chatId,
            ['reply_markup' => json_encode($replyMarkup, JSON_THROW_ON_ERROR)]
        );

        $messageId = data_get($response, 'result.message.message_id');
        if (is_int($messageId) || is_numeric($messageId)) {
            $prompt->update(['telegram_message_id' => (int) $messageId]);
        }

        return $prompt->fresh();
    }

    /**
     * Procesa un callback_query del webhook de Telegram.
     *
     * @param  array<string, mixed>  $callbackQuery
     */
    public function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = is_string($callbackQuery['id'] ?? null) ? $callbackQuery['id'] : '';
        $data = is_string($callbackQuery['data'] ?? null) ? $callbackQuery['data'] : '';

        if ($callbackQueryId === '' || ! preg_match('/^([yn]):(\d+)$/', $data, $matches)) {
            if ($callbackQueryId !== '') {
                TelegramBotService::answerCallbackQuery($callbackQueryId, [
                    'text' => 'Acción no reconocida.',
                ]);
            }

            return;
        }

        $action = $matches[1];
        $promptId = (int) $matches[2];

        $prompt = TeethPrompt::query()->find($promptId);
        if ($prompt === null) {
            TelegramBotService::answerCallbackQuery($callbackQueryId, [
                'text' => 'Este mensaje ya no está activo.',
            ]);

            return;
        }

        $messageChatId = isset($callbackQuery['message']['chat']['id'])
            ? (string) $callbackQuery['message']['chat']['id']
            : null;

        if ($messageChatId !== $prompt->telegram_chat_id) {
            TelegramBotService::answerCallbackQuery($callbackQueryId, [
                'text' => 'No coincide el chat.',
            ]);

            return;
        }

        if ($action === 'n') {
            $prompt->delete();
            TelegramBotService::answerCallbackQuery($callbackQueryId, [
                'text' => 'Entendido.',
            ]);

            return;
        }

        $telegramUserId = isset($callbackQuery['from']['id']) ? (int) $callbackQuery['from']['id'] : null;
        if ($telegramUserId === null) {
            TelegramBotService::answerCallbackQuery($callbackQueryId, [
                'text' => 'No se pudo identificar al usuario.',
            ]);

            return;
        }

        $answeredAt = now();
        $elapsedMinutes = $prompt->prompt_sent_at->diffInMinutes($answeredAt);

        if ($elapsedMinutes > 24 * 60) {
            $prompt->delete();
            TelegramBotService::answerCallbackQuery($callbackQueryId, [
                'text' => 'Han pasado más de 24 horas; no se registra.',
                'show_alert' => true,
            ]);

            return;
        }

        $delayed = $elapsedMinutes > $prompt->grace_period_minutes;

        TeethCleaning::query()->create([
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $prompt->telegram_chat_id,
            'prompt_sent_at' => $prompt->prompt_sent_at,
            'answered_at' => $answeredAt,
            'grace_period_minutes' => $prompt->grace_period_minutes,
            'delayed' => $delayed,
        ]);

        $prompt->delete();

        TelegramBotService::answerCallbackQuery($callbackQueryId, [
            'text' => $delayed
                ? 'Registrado con retraso.'
                : 'Registrado a tiempo.',
        ]);
    }
}
