<?php

namespace App\Http\Controllers;

use App\Services\PhoneAwayFairy;
use App\Services\ToothFairy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ToothFairy $toothFairy,
        PhoneAwayFairy $phoneAwayFairy,
    ): SymfonyResponse {
        $secret = config('services.telegram.webhook_secret');
        if (is_string($secret) && $secret !== '') {
            $header = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if (! is_string($header) || ! hash_equals($secret, $header)) {
                abort(Response::HTTP_FORBIDDEN);
            }
        }

        $update = $request->all();

        if (isset($update['message']) && is_array($update['message'])) {
            $msg = $update['message'];
            $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
            $chatId = isset($chat['id']) ? (string) $chat['id'] : null;
            $chatType = is_string($chat['type'] ?? null) ? $chat['type'] : null;
            $messageId = $msg['message_id'] ?? null;
            $text = $msg['text'] ?? $msg['caption'] ?? null;
            $text = is_string($text) ? mb_substr($text, 0, 2000) : null;
            $fromId = isset($msg['from']['id']) ? (int) $msg['from']['id'] : null;

            Log::info('Telegram: mensaje recibido', [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'from_id' => $fromId,
                'text' => $text,
            ]);
        }

        if (isset($update['message_reaction']) && is_array($update['message_reaction'])) {
            $mr = $update['message_reaction'];
            $messageId = $mr['message_id'] ?? null;
            $chatId = isset($mr['chat']['id']) ? (string) $mr['chat']['id'] : null;
            Log::info('Telegram: reacción a mensaje', [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'message_reaction' => $mr,
            ]);
        }

        if (isset($update['message_reaction_count']) && is_array($update['message_reaction_count'])) {
            $mrc = $update['message_reaction_count'];
            $messageId = $mrc['message_id'] ?? null;
            $chatId = isset($mrc['chat']['id']) ? (string) $mrc['chat']['id'] : null;
            Log::info('Telegram: conteo de reacciones en mensaje', [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'message_reaction_count' => $mrc,
            ]);
        }

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $data = $update['callback_query']['data'] ?? '';
            $data = is_string($data) ? $data : '';

            if (preg_match('/^m(y|n):/', $data)) {
                $phoneAwayFairy->handleCallbackQuery($update['callback_query']);
            } else {
                $toothFairy->handleCallbackQuery($update['callback_query']);
            }
        }

        return response()->noContent();
    }
}
