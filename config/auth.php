<?php

use App\Models\Admin;
use App\Models\Student;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The default guard is set to 'web' which uses the admin provider.
    | Filament uses this guard for admin session authentication.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admins'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | web    → Session-based, used by Filament for Admin login.
    | sanctum → Used automatically by Sanctum for API token auth (Student).
    |
    | Note: Laravel Sanctum registers its own guard internally.
    | The 'student' provider below is referenced via Sanctum config
    | so that API tokens resolve to the Student model.
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | admins   → Eloquent provider using App\Models\Admin (for Filament).
    | students → Eloquent provider using App\Models\Student (for Sanctum API tokens).
    |
    */

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],

        'students' => [
            'driver' => 'eloquent',
            'model' => Student::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
