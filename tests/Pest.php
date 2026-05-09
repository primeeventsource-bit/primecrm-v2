<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest test bootstrap
|--------------------------------------------------------------------------
| Tests in /tests/Feature inherit Tests\TestCase (Laravel's full app
| bootstrapping) plus RefreshDatabase. Tests in /tests/Unit are pure
| PHPUnit — no Laravel app, no database.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');
