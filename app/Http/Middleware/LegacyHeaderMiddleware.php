<?php

namespace DreamFactory\Http\Middleware;

/**
 * RQ-043 — Wrapper nativo para LegacyHeaderMiddleware da extensão
 *
 * DreamFactory 4.x/Laravel 13 resolve aliases via bootstrap/app.php ->withMiddleware(alias).
 * Este arquivo existe para satisfazer o contrato TddUltraSprint3Test que aceita
 * app/Http/Middleware/LegacyHeaderMiddleware.php como localização válida
 * (extensions/df-named-query/src/Http/Middleware/LegacyHeaderMiddleware.php é a fonte canônica).
 *
 * Registro: bootstrap/app.php:23 alias 'legacy.headers' => Yamaha\DreamFactory\NamedQuery\Http\Middleware\LegacyHeaderMiddleware::class
 * Alternativa Kernel: app/Http/Kernel.php:16-18 routeMiddleware['legacy.headers']
 *
 * Sem parallelAuth — delega para Session::checkServicePermission via NamedQueryResource::checkPermission.
 * Ver docs/architecture/legacy-headers.md e rbac.md:§ RQ-043
 */
class LegacyHeaderMiddleware extends \Yamaha\DreamFactory\NamedQuery\Http\Middleware\LegacyHeaderMiddleware
{
}
