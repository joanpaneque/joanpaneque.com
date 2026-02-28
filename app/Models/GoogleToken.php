<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleToken extends Model
{
    protected $fillable = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }

    public static function getRefreshToken(): ?string
    {
        $token = self::latest()->first();

        return $token?->refresh_token;
    }
}
