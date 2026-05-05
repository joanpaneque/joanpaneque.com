<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventIndex extends Model
{
    protected $table = 'calendar_event_indexes';

    protected $fillable = [
        'user_id',
        'google_event_id',
        'google_recurring_event_id',
        'title',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'color',
        'is_all_day',
        'is_recurring',
        'recurrence',
        'recurrence_rule',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_by_day',
        'recurrence_until',
        'recurrence_count',
        'embedding_input',
        'embedding_model',
        'embedding_fingerprint',
        'embedding_generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_all_day' => 'boolean',
            'is_recurring' => 'boolean',
            'recurrence' => 'array',
            'recurrence_by_day' => 'array',
            'recurrence_until' => 'datetime',
            'embedding_generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
