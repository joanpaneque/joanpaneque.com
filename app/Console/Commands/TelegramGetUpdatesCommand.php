<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Throwable;

class TelegramGetUpdatesCommand extends Command
{
    protected $signature = 'telegram:get-updates
                            {--limit=100 : Máximo de updates (1-100)}
                            {--offset= : offset para paginar}
                            {--timeout=0 : Long polling en segundos (0-50; 0 = sin espera)}';

    protected $description = 'Llama a getUpdates de Telegram y muestra la respuesta JSON (usa TELEGRAM_API_TOKEN del .env)';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $timeout = max(0, min(50, (int) $this->option('timeout')));

        $params = [
            'limit' => $limit,
            'timeout' => $timeout,
        ];

        $offset = $this->option('offset');
        if ($offset !== null && $offset !== '') {
            $params['offset'] = (int) $offset;
        }

        try {
            $result = TelegramBotService::getUpdates($params);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if (($result['ok'] ?? false) === true && empty($result['result'])) {
                $this->newLine();
                $this->comment('result está vacío: si tienes webhook activo, los updates no se acumulan para getUpdates.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
