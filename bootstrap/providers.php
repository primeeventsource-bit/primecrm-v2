<?php

declare(strict_types=1);

/*
 * Auto-loaded service providers.
 *
 * Module providers are registered indirectly via ModuleServiceProvider, which
 * iterates config/modules.php and registers App\Modules\{Module}\{Module}ServiceProvider
 * for each entry. Only framework-level providers belong here.
 */
return [
    App\Providers\AppServiceProvider::class,
    App\Core\Shared\Providers\ModuleServiceProvider::class,
];
