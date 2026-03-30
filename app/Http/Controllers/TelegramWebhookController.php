<?php

namespace App\Http\Controllers;

use App\Services\PhoneAwayFairy;
use App\Services\ToothFairy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
