<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeethCleaning extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'telegram_chat_id',
        'prompt_sent_at',
        'answered_at',
        'grace_period_minutes',
        'delayed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_sent_at' => 'datetime',
            'answered_at' => 'datetime',
            'delayed' => 'boolean',
        ];
    }
}
