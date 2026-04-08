<?php

namespace App\Services;

use App\Models\TeethCleaning;
use App\Models\TeethPrompt;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

class ToothFairy
{
    /**
     * Envía la pregunta con botones Sí/No y guarda el prompt para el webhook.
     *
     * @param  string  $message  Texto de la pregunta (p. ej. mañana / mediodía / noche).
     * @param  string|null  $phase  morning|midday|night para los mensajes editados al responder.
     * @return TeethPrompt Incluye telegram_message_id cuando la API lo devuelve.
     */
    public function sendBrushingPrompt(int $gracePeriodMinutes, string $message = 'Te has lavado los dientes?', ?string $phase = null): TeethPrompt
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
            'phase' => $phase,
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
            $message,
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

        $prompt = TeethPrompt::query()->whereNull('closed_at')->find($promptId);
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

        $phaseSuffix = $this->phaseSuffix($prompt->phase);

        $messageId = isset($callbackQuery['message']['message_id'])
            ? (int) $callbackQuery['message']['message_id']
            : null;

        if ($action === 'n') {
            $telegramUserId = isset($callbackQuery['from']['id']) ? (int) $callbackQuery['from']['id'] : null;
            if ($telegramUserId === null) {
                TelegramBotService::answerCallbackQuery($callbackQueryId, [
                    'text' => 'No se pudo identificar al usuario.',
                ]);

                return;
            }

            $answeredAt = now();
            $resolvedMessageId = $messageId ?? $prompt->telegram_message_id;

            TeethCleaning::query()->create([
                'telegram_user_id' => $telegramUserId,
                'telegram_chat_id' => $prompt->telegram_chat_id,
                'telegram_message_id' => $resolvedMessageId,
                'phase' => $prompt->phase,
                'prompt_sent_at' => $prompt->prompt_sent_at,
                'answered_at' => $answeredAt,
                'grace_period_minutes' => $prompt->grace_period_minutes,
                'delayed' => false,
                'completed' => false,
            ]);

            if ($messageChatId !== null && $resolvedMessageId !== null) {
                $line = $this->noAnswerLineWithPencil($prompt, $phaseSuffix);
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

        $resolvedMessageId = $messageId ?? $prompt->telegram_message_id;

        TeethCleaning::query()->create([
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $prompt->telegram_chat_id,
            'telegram_message_id' => $resolvedMessageId,
            'phase' => $prompt->phase,
            'prompt_sent_at' => $prompt->prompt_sent_at,
            'answered_at' => $answeredAt,
            'grace_period_minutes' => $prompt->grace_period_minutes,
            'delayed' => $delayed,
            'completed' => true,
        ]);

        if ($messageChatId !== null && $resolvedMessageId !== null) {
            $line = $this->teethYesLine($answeredAt, $phaseSuffix, $delayed, true);

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

    /**
     * Respuesta citando ✏️⏰ (retraso) o ✏️❌ (no): guarda motivo en response_note, edita sin lápiz y borra el mensaje del usuario.
     *
     * @param  array<string, mixed>  $message
     */
    public function handleTeethResponseNoteIfApplicable(array $message): void
    {
        $from = $message['from'] ?? null;
        if (! is_array($from) || ($from['is_bot'] ?? false) === true) {
            return;
        }

        if (! isset($message['reply_to_message']) || ! is_array($message['reply_to_message'])) {
            return;
        }

        $rawText = $message['text'] ?? $message['caption'] ?? null;
        if (! is_string($rawText)) {
            return;
        }

        $text = trim($rawText);
        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }

        $text = mb_substr($text, 0, 1000);

        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;
        $userMessageId = isset($message['message_id']) ? (int) $message['message_id'] : null;
        $fromId = isset($message['from']['id']) ? (int) $message['from']['id'] : null;

        if ($chatId === null || $userMessageId === null || $fromId === null) {
            return;
        }

        $replyTo = $message['reply_to_message'];
        $replyMessageId = isset($replyTo['message_id']) ? (int) $replyTo['message_id'] : null;
        if ($replyMessageId === null) {
            return;
        }

        $record = TeethCleaning::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $replyMessageId)
            ->whereNull('response_note')
            ->where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->where('completed', true)->where('delayed', true);
                })->orWhere('completed', false);
            })
            ->first();

        if ($record === null || $record->telegram_user_id !== $fromId) {
            return;
        }

        $record->update(['response_note' => $text]);

        if ($record->completed) {
            $line = $this->teethDelayedLineWithReason($record->answered_at, $this->phaseSuffix($record->phase), $text);
        } else {
            $line = $this->teethNoLineWithReasonFromRecord($record, $text);
        }

        try {
            TelegramBotService::editMessageText(
                $line,
                $chatId,
                $replyMessageId,
                [
                    'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                ]
            );
        } catch (Throwable) {
            //
        }

        try {
            TelegramBotService::deleteMessage($chatId, $userMessageId);
        } catch (Throwable) {
            //
        }
    }

    private function teethYesLine(Carbon $answeredAt, string $phaseSuffix, bool $delayed, bool $withPencilOnDelayed): string
    {
        $dateStr = $answeredAt->format('d/m/Y');
        if (! $delayed) {
            return '✅ '.$dateStr.' te has lavado los dientes'.$phaseSuffix.' 🦷';
        }

        $prefix = $withPencilOnDelayed ? '✏️⏰ ' : '⏰ ';

        return $prefix.$dateStr.' te has lavado los dientes'.$phaseSuffix.' (con retraso) 🦷';
    }

    private function teethDelayedLineWithReason(Carbon $answeredAt, string $phaseSuffix, string $reason): string
    {
        $dateStr = $answeredAt->format('d/m/Y');
        $base = '⏰ '.$dateStr.' te has lavado los dientes'.$phaseSuffix.' (con retraso) 🦷';

        return $base.' — '.$reason;
    }

    private function noAnswerLineWithPencil(TeethPrompt $prompt, string $phaseSuffix): string
    {
        return '✏️❌ '.$prompt->prompt_sent_at->format('d/m/Y').' no te has lavado los dientes'.$phaseSuffix;
    }

    private function teethNoLineWithReasonFromRecord(TeethCleaning $record, string $reason): string
    {
        $phaseSuffix = $this->phaseSuffix($record->phase);
        $base = '❌ '.$record->prompt_sent_at->format('d/m/Y').' no te has lavado los dientes'.$phaseSuffix;

        return $base.' — '.$reason;
    }

    private function phaseSuffix(?string $phase): string
    {
        return match ($phase) {
            'morning' => ' por la mañana',
            'midday' => ' al mediodía',
            'night' => ' por la noche',
            default => '',
        };
    }
}
