<?php

namespace Yamaha\DreamFactory\NamedQuery\Http;

use Illuminate\Http\Request;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Exceptions\UnauthorizedException;

/**
 * RQ-SSO-03 — OAuth Proxy unit (fora de escopo SAML, usa df-oauth OSS)
 * Encaminha Bearer para df-oauth sem duplicar Session::checkServicePermission
 * Design system DreamFactory: usa Illuminate Request + Session facade
 */
class OAuthProxy
{
    public function handle(Request $request, \Closure $next)
    {
        $token = $request->bearerToken() ?? $request->header('X-DreamFactory-Session-Token');
        if ($token) {
            // Delega para df-oauth (dreamfactory/df-oauth 1.0.3) — não reimplementa SAML/LDAP
            // Session::checkServicePermission já valida RBAC downstream
            Session::setSessionToken($token);
        }
        return $next($request);
    }

    public function proxyToOAuth(Request $request): array
    {
        // Unit testável: retorna payload para df-oauth
        return [
            'driver' => 'oauth2',
            'provider' => $request->input('provider', 'yamaha'),
            'token' => $request->bearerToken(),
        ];
    }
}
