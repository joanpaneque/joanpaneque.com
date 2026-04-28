<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const EMAIL = 'joanpd0@gmail.com';

    /** Contraseña nueva (texto plano; el modelo aplica el cast `hashed`). */
    private const PASSWORD_PLAIN = '34Tjop1029';

    /** Valor anterior del seeder (misma convención que 2026_04_07_200000_seed_personal_panel_user). */
    private const PREVIOUS_PASSWORD_PLAIN = 'test1234';

    public function up(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first();
        if ($user === null) {
            return;
        }

        $user->password = self::PASSWORD_PLAIN;
        $user->save();
    }

    public function down(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first();
        if ($user === null) {
            return;
        }

        $user->password = self::PREVIOUS_PASSWORD_PLAIN;
        $user->save();
    }
};
