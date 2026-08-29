<?php

namespace DreamFactory\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        // RQ-043 — alias nativo para middleware legado; fonte canônica em extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php
        // Registrado também em bootstrap/app.php:23 como 'legacy.headers'. Preserva underscore/hífen/x- aliases, par completo não-curto-circuito → 401, longest-prefix e RBAC nativo sem bypass.
        'legacy.headers' => \Yamaha\DreamFactory\NamedQuery\Http\Middleware\LegacyHeaderMiddleware::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \DreamFactory\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \DreamFactory\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        // ...
    ];
}
