<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminAuthController extends Controller
{
    public function showLogin(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('admin_authenticated') === true
            && $request->session()->has('admin_user_id')) {
            return redirect()->route('admin.automations.index');
        }

        return Inertia::render('Admin/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => __('Credenciales incorrectas.'),
            ]);
        }

        $request->session()->regenerate();

        $request->session()->put('admin_authenticated', true);
        $request->session()->put('admin_user_id', $user->id);

        return redirect()->intended(route('admin.automations.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['admin_authenticated', 'admin_user_id']);
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
