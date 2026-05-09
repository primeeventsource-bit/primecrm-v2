<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest test bootstrap
|--------------------------------------------------------------------------
| Pest is the configured test runner (see composer.json scripts.test).
| Test suites that need the full app should `uses(Tests\TestCase::class)`.
*/

uses(Tests\TestCase::class)->in('Feature');
