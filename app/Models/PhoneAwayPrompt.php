<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneAwayPrompt extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'prompt_sent_at',
        'grace_period_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_sent_at' => 'datetime',
        ];
    }
}
