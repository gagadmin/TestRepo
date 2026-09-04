<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap administrator
    |--------------------------------------------------------------------------
    |
    | The first administrator account created by `DatabaseSeeder`. These were
    | previously read with `env()` inside the seeder, which is unsafe once
    | `php artisan config:cache` has run: `env()` returns null against a cached
    | configuration, so a seeded environment would silently fall back to the
    | built-in development password instead of the configured one. Reading them
    | through the config layer keeps them correct whether or not the cache is
    | warm.
    |
    | `password` has no default on purpose. The seeder supplies a development
    | password only in `local` and `testing`, and refuses to run anywhere else
    | without one.
    |
    */

    'admin' => [
        'name' => env('BI_ADMIN_NAME', 'Platform Administrator'),
        'email' => env('BI_ADMIN_EMAIL', 'admin@askgaholding.local'),
        'password' => env('BI_ADMIN_PASSWORD'),
    ],

    /*
    | Password used only when seeding a local or testing database with no
    | configured password. Never reachable in any other environment.
    */
    'development_password' => 'ChangeMe123!',

];
