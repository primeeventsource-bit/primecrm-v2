<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API Routes (cross-module)
|--------------------------------------------------------------------------
| Module-owned API routes are registered by ModuleServiceProvider from each
| module's routes.php under the /api prefix. Add only cross-cutting routes
| here (e.g. /api/health, /api/version) — anything that belongs to a module
| stays in that module.
|
| Note: this file is NOT auto-registered by bootstrap/app.php (we omit `api:`
| in withRouting to avoid double-prefixing). To opt in, uncomment the include
| in App\Providers\AppServiceProvider::boot().
*/
