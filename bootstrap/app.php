<?php

use App\Http\Middleware\EnsureIsStudent;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->alias([
            'ensure.student' => EnsureIsStudent::class,
            'ensure.gate' => \App\Http\Middleware\EnsureGateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
