<?php

namespace App\Services;

use App\Models\PhoneAwayPrompt;
use App\Models\PhoneAwayRecord;
use RuntimeException;

class PhoneAwayFairy
{
    public const PROMPT_MESSAGE = 'He dejado el móvil lejos';

    /**
     * Misma ventana que dientes por la noche: margen 4 h para delayed (en minutos).
     */
    public function sendPrompt(int $gracePeriodMinutes): PhoneAwayPrompt
    {
        $gracePeriodMinutes = max(1, $gracePeriodMinutes);

        $chatId = config('services.telegram.chat_id');
        if ($chatId === null || $chatId === '') {
            throw new RuntimeException('Telegram chat_id is not configured (services.telegram.chat_id).');
        }

        $chatId = (string) $chatId;

        $prompt = PhoneAwayPrompt::query()->create([
            'telegram_chat_id' => $chatId,
            'prompt_sent_at' => now(),
            'grace_period_minutes' => $gracePeriodMinutes,
        ]);

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Sí', 'callback_data' => 'my:'.$prompt->id],
                    ['text' => 'No', 'callback_data' => 'mn:'.$prompt->id],
                ],
            ],
        ];

        $response = TelegramBotService::sendMessage(
            self::PROMPT_MESSAGE,
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
     * @param  array<string, mixed>  $callbackQuery
     */
    public function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = is_string($callbackQuery['id'] ?? null) ? $callbackQuery['id'] : '';
        $data = is_string($callbackQuery['data'] ?? null) ? $callbackQuery['data'] : '';

        if ($callbackQueryId === '' || ! preg_match('/^m(y|n):(\d+)$/', $data, $matches)) {
            if ($callbackQueryId !== '') {
                TelegramBotService::answerCallbackQuery($callbackQueryId, [
                    'text' => 'Acción no reconocida.',
                ]);
            }

            return;
        }

        $letter = $matches[1];
        $promptId = (int) $matches[2];

        $prompt = PhoneAwayPrompt::query()->whereNull('closed_at')->find($promptId);
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

        $messageId = isset($callbackQuery['message']['message_id'])
            ? (int) $callbackQuery['message']['message_id']
            : null;

        if ($letter === 'n') {
            if ($messageChatId !== null && $messageId !== null) {
                TelegramBotService::editMessageText(
                    $this->noAnswerLine($prompt),
                    $messageChatId,
                    $messageId,
                    [
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                    ]
                );
            }

            $prompt->update(['closed_at' => now()]);
            TelegramBotService::answerCallbackQuery($callbackQueryId);

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
        $delayed = $elapsedMinutes > $prompt->grace_period_minutes;

        PhoneAwayRecord::query()->create([
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $prompt->telegram_chat_id,
            'prompt_sent_at' => $prompt->prompt_sent_at,
            'answered_at' => $answeredAt,
            'grace_period_minutes' => $prompt->grace_period_minutes,
            'delayed' => $delayed,
        ]);

        $resolvedMessageId = $messageId ?? $prompt->telegram_message_id;

        if ($messageChatId !== null && $resolvedMessageId !== null) {
            $dateStr = $answeredAt->format('d/m/Y');
            $line = $delayed
                ? '⏰ '.$dateStr.' confirmado: móvil lejos (con retraso) 📵'
                : '✅ '.$dateStr.' confirmado: móvil lejos 📵';

            TelegramBotService::editMessageText(
                $line,
                $messageChatId,
                $resolvedMessageId,
                [
                    'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                ]
            );
        }

        $prompt->update(['closed_at' => now()]);

        TelegramBotService::answerCallbackQuery($callbackQueryId);
    }

    private function noAnswerLine(PhoneAwayPrompt $prompt): string
    {
        return '❌ '.$prompt->prompt_sent_at->format('d/m/Y').' no dejaste el móvil lejos';
    }
}
