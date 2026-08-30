<?php

namespace Yamaha\DreamFactory\NamedQuery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use Illuminate\Support\Facades\Cache;
use DreamFactory\Core\Events\ServiceModifiedEvent;

/**
 * RQ-043 — Middleware de headers legados e rotas legadas
 *
 * - Aceita underscore, hífen e x- aliases (client_secret ↔ client-secret ↔ x-client-secret e client_key ↔ client-key ↔ x-client-key)
 *   espelha api-query/src/main/java/com/querybuilder/config/RouteAuthorizationInterceptor.java:30-31
 * - Exige par completo (ambos não-vazios, AND não-curto-circuito) — se um presente sem o outro → 401
 *   espelha api-query/src/main/java/com/querybuilder/service/AuthorizationService.java:59-60 e credentialsMatch:168-174 (secretMatches & keyMatches)
 * - Preserva longest-prefix para rota (max length match) — espelha AuthorizationService.java:68-71 + 133-158
 * - Endpoints nativos não contornam autorização (reutilizar Session::checkServicePermission — via NamedQueryResource::checkPermission)
 *   sem criar guarda paralelo — ver dreamfactory-fork/docs/architecture/rbac.md:18-22 e legacy-headers.md
 */
class LegacyHeaderMiddleware
{
    public const CANONICAL_SECRET = 'client_secret';
    public const CANONICAL_KEY = 'client_key';

    /** @var string[] Aliases canônicos §8 inventory-api-query-contract.md:234-236 */
    public const SECRET_ALIASES = ['client_secret', 'client-secret', 'x-client-secret'];

    /** @var string[] Aliases canônicos §8 */
    public const KEY_ALIASES = ['client_key', 'client-key', 'x-client-key'];

    /**
     * Normaliza aliases para canônico client_secret/client_key antes de chegar ao NamedQueryResource ou ApiDocs.
     * Se legacy headers presentes, exige par completo — caso contrário lança 401.
     * Delega autorização efetiva para RBAC nativo (Session::checkServicePermission em BaseRestResource::checkPermission).
     */
    public function handle(Request $request, Closure $next)
    {
        $secret = $this->firstHeader($request, self::SECRET_ALIASES);
        $key = $this->firstHeader($request, self::KEY_ALIASES);

        // Detecta presença de qualquer alias (para decidir se validação de par se aplica).
        // Se nenhum legacy header presente, deixa passar — endpoints nativos usarão Session/token nativo.
        $hasAnySecretAlias = $this->hasAnyHeader($request, self::SECRET_ALIASES);
        $hasAnyKeyAlias = $this->hasAnyHeader($request, self::KEY_ALIASES);
        $legacyPresent = $hasAnySecretAlias || $hasAnyKeyAlias;

        // Normaliza aliases para canônico — fica disponível via $request->header('client_secret') downstream
        if ($this->hasText($secret)) {
            $request->headers->set(self::CANONICAL_SECRET, $secret);
        }
        if ($this->hasText($key)) {
            $request->headers->set(self::CANONICAL_KEY, $key);
        }

        // Exige par completo (ambos não-vazios, AND não-curto-circuito)
        // Preserva AuthorizationService.java:59-60 (!hasText(secret) || !hasText(key) → 401) e credentialsMatch & (não-curto-circuito)
        if ($legacyPresent) {
            $hasSecret = $this->hasText($secret);
            $hasKey = $this->hasText($key);
            // Avaliação não-curto-circuito: ambas as expressões são avaliadas antes do &
            $bothPresent = $hasSecret & $hasKey;
            if (!$bothPresent) {
                throw new UnauthorizedException('Credenciais inválidas ou ausentes.');
            }
        }

        // Preserva longest-prefix para rota — lógica AuthorizationService.java:68-71 (max length) + 148-158 (matchesRoute)
        // Este middleware não autoriza rota por si só; preserva semântica para eventual resolução de rota legada.
        // Autorização efetiva delega para RBAC nativo sem bypass:
        //   Session::checkServicePermission($action, $service, $component=_query/{name}, $requestor)
        //   via BaseRestResource::checkPermission em NamedQueryResource::execute():218
        //   ver vendor/dreamfactory/df-core/src/Utility/Session.php:35-64 e BaseRestResource.php:104-116

        return $next($request);
    }

    /**
     * Extrai primeiro header não-vazio entre aliases — espelha RouteAuthorizationInterceptor.java:40-48 firstHeader
     */
    private function firstHeader(Request $request, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            $value = $request->header($alias);
            if ($value === null) {
                $value = $request->headers->get($alias);
            }
            if ($this->hasText($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function hasAnyHeader(Request $request, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if ($request->headers->has($alias)) {
                return true;
            }
            // Symfony HeaderBag é case-insensitive mas underscore/hifen são chaves distintas
            if ($request->header($alias) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Normaliza rota removendo prefixo /api/v1 — espelha AuthorizationService.java:133-146
     */
    public function normalizeRoute(string $route): string
    {
        if (!$this->hasText($route)) {
            return '/';
        }
        $normalized = trim($route);
        // Strip query string and fragment — espelha AuthorizationService normalizeRoute + matchesRoute sem ?cma=x
        $normalized = explode('?', $normalized, 2)[0];
        $normalized = explode('#', $normalized, 2)[0];
        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }
        if (str_starts_with($normalized, '/api/v1')) {
            $normalized = substr($normalized, strlen('/api/v1'));
            if ($normalized === '' || $normalized === false) {
                $normalized = '/';
            }
        }

        return $normalized;
    }

    /**
     * Verifica se rota configurada casa com path normalizado — espelha AuthorizationService.java:148-158
     */
    public function matchesRoute(string $configuredRoute, string $normalizedRequestPath): bool
    {
        $normalizedRoute = $this->normalizeRoute($configuredRoute);
        if ($normalizedRoute === $normalizedRequestPath) {
            return true;
        }
        if (str_ends_with($normalizedRoute, '/') && strlen($normalizedRoute) > 1) {
            $normalizedRoute = substr($normalizedRoute, 0, -1);
        }

        return str_starts_with($normalizedRequestPath, $normalizedRoute . '/');
    }

    /**
     * Preserva longest-prefix (max length match) — espelha AuthorizationService.java:68-71
     * Seleciona rota com maior normalizeRoute(route).length() entre as que dão matchesRoute
     * @param string[] $configuredRoutes
     */
    public function findLongestMatchingRoute(array $configuredRoutes, string $requestPath): ?string
    {
        $normalizedPath = $this->normalizeRoute($requestPath);
        $best = null;
        $bestLen = -1;
        foreach ($configuredRoutes as $route) {
            if ($this->matchesRoute($route, $normalizedPath)) {
                $len = strlen($this->normalizeRoute($route));
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $route;
                }
            }
        }

        return $best;
    }

    /**
     * Invalidação via ServiceModifiedEvent + Cache::tags — RQ-054
     * @see NamedQueryRepository.php Cache::tags
     */
    public function invalidateViaCacheTags(string $serviceId): void
    {
        Cache::tags(['service:' . $serviceId])->flush();
        event(new ServiceModifiedEvent(['service_id' => $serviceId]));
    }
}
