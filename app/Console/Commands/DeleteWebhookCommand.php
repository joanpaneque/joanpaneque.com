<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Throwable;

class DeleteWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:delete-webhook {--drop-pending : Descarta actualizaciones pendientes en Telegram}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina el webhook del bot de Telegram (deleteWebhook)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $result = TelegramBotService::deleteWebhook($this->option('drop-pending'));

            if (($result['ok'] ?? false) === true) {
                $this->info('Webhook eliminado correctamente.');
                if ($this->option('drop-pending')) {
                    $this->comment('Se solicitaron drop_pending_updates.');
                }

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
