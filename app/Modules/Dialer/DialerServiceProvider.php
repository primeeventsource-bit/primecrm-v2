<?php

declare(strict_types=1);

namespace App\Modules\Dialer;

use Illuminate\Support\ServiceProvider;

final class DialerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Concrete services resolve via autoloader; no explicit bindings yet.
    }

    public function boot(): void
    {
        //
    }
}
