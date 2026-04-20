<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class NebulaController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Nebula/Dashboard');
    }
}
