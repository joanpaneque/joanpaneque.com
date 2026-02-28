<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.google.admin_secret');
        if (empty($secret)) {
            abort(500, 'Admin secret not configured. Set GOOGLE_ADMIN_SECRET in .env');
        }

        if ($request->session()->get('admin_authenticated') === true) {
            return $next($request);
        }

        if ($request->isMethod('POST') && $request->input('admin_secret') === $secret) {
            $request->session()->put('admin_authenticated', true);

            return redirect()->intended(route('admin.google-calendar.index'));
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('admin.login');
    }
}
