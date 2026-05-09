<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Browser-facing routes. The Vue 3 + Inertia dialer UI lands in Response 5;
| until then this file just exposes a JSON identity endpoint at the root so
| Cloud's health check has something to hit.
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'env' => config('app.env'),
    'status' => 'ok',
]));
