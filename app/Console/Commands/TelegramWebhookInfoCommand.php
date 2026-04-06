<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Throwable;

class TelegramWebhookInfoCommand extends Command
{
    protected $signature = 'telegram:webhook-info';

    protected $description = 'Muestra getWebhookInfo (URL, allowed_updates, errores pendientes, etc.)';

    public function handle(): int
    {
        try {
            $data = TelegramBotService::getWebhookInfo();
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $result = $data['result'] ?? [];
            if (is_array($result) && array_key_exists('allowed_updates', $result)) {
                $allowed = $result['allowed_updates'];
                $this->newLine();
                if ($allowed === null) {
                    $this->comment('allowed_updates es null (Telegram usa su lista por defecto; message_reaction puede NO estar incluido).');
                } else {
                    $this->info('allowed_updates en servidor: '.json_encode($allowed, JSON_UNESCAPED_UNICODE));
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
