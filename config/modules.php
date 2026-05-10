<?php

declare(strict_types=1);

/**
 * Module registry. The ModuleServiceProvider iterates this list at boot
 * to register routes, migrations (already in /database), config, and
 * dependency bindings per module.
 *
 * Order matters for dependency resolution at boot time. Tenant first,
 * then everything that depends on it.
 */
return [
    'modules' => [
        'Tenant',
        'Lead',
        'Compliance',
        'CallCenter',
        'Dialer',
        'Sales',
        'Customer',
        'Note',
        'Listing',
        'Booking',
        'Payment',
        'Commission',
        'Reporting',
    ],

    'namespace' => 'App\\Modules',
    'path' => app_path('Modules'),
];
