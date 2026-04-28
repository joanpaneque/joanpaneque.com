<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated') !== true) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('admin.login');
        }

        $userId = $request->session()->get('admin_user_id');
        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            $request->session()->forget(['admin_authenticated', 'admin_user_id']);

            return redirect()->route('admin.login');
        }

        if (! User::query()->whereKey((int) $userId)->exists()) {
            $request->session()->forget(['admin_authenticated', 'admin_user_id']);

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
