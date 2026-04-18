<?php

namespace App\Console\Commands;

use App\Services\OpenRouter;
use Illuminate\Console\Command;

class AppTestOpenRouterCommand extends Command
{
    protected $signature = 'app:test-command';

    protected $description = 'Prueba OpenRouter: gpt-oss-120b (reasoning effort low) cuenta un chiste';

    public function handle(): int
    {
        $out = OpenRouter::chatText('Cuenta un chiste.', null, 'openai/gpt-oss-120b', [
            'reasoning' => ['effort' => 'low'],
        ]);
        dd($out);
    }
}
