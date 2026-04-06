<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Throwable;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook
                            {--url= : URL HTTPS del webhook (por defecto TELEGRAM_WEBHOOK_URL o route telegram.webhook)}';

    protected $description = 'Registra setWebhook con callback_query + reacciones (necesario para logs de message_id al reaccionar)';

    public function handle(): int
    {
        $url = $this->option('url');
        if (! is_string($url) || $url === '') {
            $url = config('services.telegram.webhook_url');
        }
        if (! is_string($url) || $url === '') {
            $url = route('telegram.webhook', [], true);
        }

        $secret = config('services.telegram.webhook_secret');
        $secret = is_string($secret) && $secret !== '' ? $secret : null;

        try {
            $result = TelegramBotService::setWebhookWithReactions($url, $secret);

            if (($result['ok'] ?? false) === true) {
                $this->info('Webhook registrado con allowed_updates: callback_query, message_reaction, message_reaction_count.');
                $this->line('URL: '.$url);

                return self::SUCCESS;
            }

            $this->error('Respuesta inesperada de la API de Telegram.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
