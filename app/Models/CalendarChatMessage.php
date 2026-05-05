<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CalendarChatMessage extends Model
{
    protected $fillable = [
        'calendar_chat_id',
        'role',
        'content',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(CalendarChat::class, 'calendar_chat_id');
    }

    public function changeLog(): HasOne
    {
        return $this->hasOne(CalendarMessageChangeLog::class);
    }
}
