<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = ['start', 'end', 'email', 'event_id'];

    protected function casts(): array
    {
        return [
            'start' => 'datetime',
            'end' => 'datetime',
        ];
    }
}
