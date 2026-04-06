<?php

namespace App\Services;

use App\Models\TeethCleaning;
use App\Models\TeethPrompt;
use RuntimeException;
use Throwable;

class ToothFairy
{
    /** Tras esto, el prompt no admite registro (ni delayed) y se muestra como “no lavado”. */
    private const PROMPT_TTL_HOURS = 5;

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
     * Prompts con más de {@see PROMPT_TTL_HOURS} horas: edita el mensaje a “no lavado” y borra el prompt.
     */
    public function expireStalePrompts(): void
    {
        $cutoff = now()->subHours(self::PROMPT_TTL_HOURS);

        TeethPrompt::query()
            ->whereNull('closed_at')
            ->where('prompt_sent_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($prompts): void {
                foreach ($prompts as $prompt) {
                    $this->editPromptToNoAnswerAndClose($prompt);
                }
            });
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

        if ($this->isPromptExpired($prompt)) {
            $this->editPromptToNoAnswerAndClose($prompt, $messageChatId, $messageId, $callbackQueryId, $action === 'y');

            return;
        }

        if ($action === 'n') {
            if ($messageChatId !== null && $messageId !== null) {
                $line = $this->noAnswerLine($prompt, $phaseSuffix);
                TelegramBotService::editMessageText(
                    $line,
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

        TeethCleaning::query()->create([
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $prompt->telegram_chat_id,
            'prompt_sent_at' => $prompt->prompt_sent_at,
            'answered_at' => $answeredAt,
            'grace_period_minutes' => $prompt->grace_period_minutes,
            'delayed' => $delayed,
        ]);

        $yesMessageId = isset($callbackQuery['message']['message_id'])
            ? (int) $callbackQuery['message']['message_id']
            : null;

        if ($messageChatId !== null && $yesMessageId !== null) {
            $dateStr = $answeredAt->format('d/m/Y');
            $line = $delayed
                ? '⏰ '.$dateStr.' te has lavado los dientes'.$phaseSuffix.' (con retraso) 🦷'
                : '✅ '.$dateStr.' te has lavado los dientes'.$phaseSuffix.' 🦷';

            TelegramBotService::editMessageText(
                $line,
                $messageChatId,
                $yesMessageId,
                [
                    'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                ]
            );
        }

        $prompt->update(['closed_at' => now()]);

        TelegramBotService::answerCallbackQuery($callbackQueryId);
    }

    private function isPromptExpired(TeethPrompt $prompt): bool
    {
        return $prompt->prompt_sent_at->lt(now()->subHours(self::PROMPT_TTL_HOURS));
    }

    /**
     * Texto unificado: día del recordatorio + frase “no te has lavado…” + mañana/mediodía/noche.
     */
    private function noAnswerLine(TeethPrompt $prompt, string $phaseSuffix): string
    {
        return '❌ '.$prompt->prompt_sent_at->format('d/m/Y').' no te has lavado los dientes'.$phaseSuffix;
    }

    /**
     * Edita Telegram (si hay datos), marca el prompt cerrado y opcionalmente alerta (Sí caducado).
     */
    private function editPromptToNoAnswerAndClose(
        TeethPrompt $prompt,
        ?string $messageChatId = null,
        ?int $messageMessageId = null,
        ?string $callbackQueryId = null,
        bool $alertBecauseYesAfterExpiry = false
    ): void {
        $phaseSuffix = $this->phaseSuffix($prompt->phase);
        $line = $this->noAnswerLine($prompt, $phaseSuffix);

        $chatId = $messageChatId ?? $prompt->telegram_chat_id;
        $msgId = $messageMessageId ?? $prompt->telegram_message_id;

        if ($msgId !== null) {
            try {
                TelegramBotService::editMessageText(
                    $line,
                    $chatId,
                    (int) $msgId,
                    [
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                    ]
                );
            } catch (Throwable) {
                // Mensaje borrado o API: seguimos y cerramos el prompt.
            }
        }

        $prompt->update(['closed_at' => now()]);

        if ($callbackQueryId !== null && $callbackQueryId !== '') {
            $params = [];
            if ($alertBecauseYesAfterExpiry) {
                $params['text'] = 'Han pasado más de 5 horas; no se puede registrar.';
                $params['show_alert'] = true;
            }
            TelegramBotService::answerCallbackQuery($callbackQueryId, $params);
        }
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
