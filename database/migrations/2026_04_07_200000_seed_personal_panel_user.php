<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'joanpd0@gmail.com'],
            [
                'name' => 'Joan',
                'password' => Hash::make('test1234'),
            ]
        );
    }

    public function down(): void
    {
        User::query()->where('email', 'joanpd0@gmail.com')->delete();
    }
};
