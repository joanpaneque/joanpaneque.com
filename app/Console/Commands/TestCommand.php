<?php

namespace App\Console\Commands;

use App\Services\ToothFairy;
use Illuminate\Console\Command;
use Throwable;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-teeth {grace_period_minutes=15 : Minutos de periodo de gracia (a tiempo vs retraso)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ToothFairy: pregunta de lavado de dientes (Telegram + webhook)';

    /**
     * Execute the console command.
     */
    public function handle(ToothFairy $toothFairy): int
    {
        try {
            $grace = (int) $this->argument('grace_period_minutes');
            $prompt = $toothFairy->sendBrushingPrompt($grace);

            $this->info('Mensaje enviado con botones enlazados al webhook.');
            $this->line('  Prompt ID: '.$prompt->id);
            $this->line('  Periodo de gracia: '.$grace.' min (a tiempo si respondes antes).');
            $this->newLine();
            $this->line('Webhook para Telegram (HTTPS público):');
            $this->line('  '.route('telegram.webhook'));
            $this->newLine();
            $this->comment('Registra esa URL con setWebhook (y TELEGRAM_WEBHOOK_SECRET si lo usas). Los clics Sí/No llegan aquí y guardan en teeth_cleanings.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
