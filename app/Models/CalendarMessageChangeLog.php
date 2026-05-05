<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarMessageChangeLog extends Model
{
    protected $fillable = [
        'calendar_chat_message_id',
        'changes',
        'reverted_at',
        'revert_result',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'reverted_at' => 'datetime',
            'revert_result' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CalendarChatMessage::class, 'calendar_chat_message_id');
    }
}
