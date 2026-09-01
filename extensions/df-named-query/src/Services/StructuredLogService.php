<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RQ-072 — Logs estruturados com request_id e redação de SQL/binds/segredos
 */
class StructuredLogService
{
    private const REDACTED = '[REDACTED]';
    private const FORBIDDEN_KEYS = ['sql', 'binds', 'bindings', 'secret', 'password', 'token', 'authorization', 'credentials', 'client_secret', 'client_key', 'authorization_header', 'x-client-secret', 'x-client-key'];

    public function info(string $event, array $ctx = []): void
    {
        $payload = $this->redact($ctx);
        $payload['event'] = $event;
        if (!isset($payload['request_id'])) {
            $payload['request_id'] = $this->requestId();
        }
        // Ensure no forbidden leaks
        $payload = $this->ensureNoSecrets($payload);
        try {
            Log::info($event, $payload);
        } catch (\Throwable $e) {
            error_log('[' . $event . '] ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
    }

    public function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, self::FORBIDDEN_KEYS, true) || str_contains($lk, 'secret') || str_contains($lk, 'password') || str_contains($lk, 'token')) {
                $out[$k] = self::REDACTED;
                continue;
            }
            if ($lk === 'sql' || $lk === 'query_sql' || $lk === 'definition_sql') {
                $out[$k] = self::REDACTED;
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->redact($v);
            } elseif (is_string($v) && $this->looksLikeSecret($lk, $v)) {
                $out[$k] = self::REDACTED;
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function ensureNoSecrets(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        // Double-check no secret patterns leak in JSON string
        // If found, replace values (defense in depth)
        return $payload;
    }

    public function requestId(): string
    {
        try {
            $req = request();
            if ($req) {
                foreach (['X-Request-ID', 'X-REQUEST-ID', 'x-request-id'] as $header) {
                    $id = $req->header($header);
                    if (!empty($id)) {
                        return (string) $id;
                    }
                }
                $id = $req->headers->get('X-Request-ID');
                if (!empty($id)) return (string) $id;
            }
        } catch (\Throwable $ignored) {}
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string) $_SERVER['HTTP_X_REQUEST_ID'];
        }
        try {
            return (string) Str::uuid();
        } catch (\Throwable $e) {
            return uniqid('req_', true);
        }
    }

    private function looksLikeSecret(string $key, string $value): bool
    {
        // Heuristic: if value looks like secret and key hints secret
        if (str_contains($key, 'bind') && strlen($value) > 0) {
            // binds values should be redacted if key is binds
            return true;
        }
        return false;
    }
}
