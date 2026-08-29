<?php

namespace Yamaha\DreamFactory\NamedQuery\Http;

use DreamFactory\Core\Contracts\ServiceRequestInterface;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ConflictResourceException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Utility\ServiceResponse;

/**
 * RQ-044 — Tradutor de envelopes legado.
 *
 * Mapeia exceções DreamFactory (BadRequestException, UnauthorizedException,
 * ForbiddenException, NotFoundException, ConflictResourceException, RestException)
 * para envelope legado {erroCode, errorMessage, timestamp} com códigos corretos
 * preservados de api-query/src/main/java/com/querybuilder/dto/ErrorType.java:3-15.
 *
 * Códigos legados (ErrorType.java):
 *  400 -> 1400 BAD_REQUEST
 *  401 -> 1001 UNAUTHORIZED
 *  403 -> 1003 FORBIDDEN
 *  404 -> 1004 RESOURCE_NOT_FOUND
 *  409 -> 1409 CONFLICT
 *  500 -> 5000 INTERNAL_SERVER_ERROR
 *  504 -> 5504 GATEWAY_TIMEOUT
 *  (extras: 405->1405 METHOD_NOT_ALLOWED, 413->1413 PAYLOAD_TOO_LARGE)
 *
 * Sucesso legado: {elapsed_time, request_id, timestamp, result_count, result}
 * vs nativo DF: {resource:[]} — NamedQueryResource.php:107.
 *
 * Contrato nativo é preservado por padrão. Envelope legado é opt-in via:
 *  - header X-Legacy-Envelope (qualquer valor truthy: true/1/legacy)
 *  - query param ?envelope=legacy
 *  - header legado também aceita x-legacy-envelope (case-insensitive)
 *
 * Reutiliza RBAC/events nativos — sem autorização paralela.
 * RBAC via Session::checkServicePermission em NamedQueryResource::checkPermission
 * (BaseRestResource.php:checkPermission) e listAccessComponents.
 *
 * @see api-query/src/main/java/com/querybuilder/config/GlobalExceptionHandler.java:40-159
 * @see api-query/src/main/java/com/querybuilder/dto/ErrorMessageResponse.java:6-55
 * @see api-query/tests/blackbox/fixtures/goldens/error-envelope.json:1-103
 * @see api-query/tests/blackbox/fixtures/goldens/success-envelope.json:1-62
 * @see dreamfactory-fork/docs/architecture/inventory-api-query-contract.md:10 Envelopes
 */
class EnvelopeTranslator
{
    public const HEADER_LEGACY = 'X-Legacy-Envelope';

    /**
     * @var array<int,int> HTTP status -> erroCode legado
     * @see ErrorType.java:3-15
     */
    public const STATUS_TO_ERROCODE = [
        400 => 1400,
        401 => 1001,
        403 => 1003,
        404 => 1004,
        405 => 1405,
        409 => 1409,
        413 => 1413,
        500 => 5000,
        504 => 5504,
    ];

    /**
     * Mensagens padrão por erroCode — ErrorType.java:6-15
     */
    public const DEFAULT_MESSAGES = [
        1000 => 'O formato do JSON enviado é inválido.',
        1001 => 'Credenciais inválidas ou ausentes.',
        1003 => 'Você não tem permissão para acessar este recurso.',
        1004 => 'O recurso solicitado não foi encontrado.',
        1400 => 'Solicitação Inválida.',
        1405 => 'O método HTTP não é suportado para este recurso.',
        1409 => 'A operação conflita com o estado atual do recurso.',
        1413 => 'O arquivo enviado excede o tamanho máximo permitido.',
        5000 => 'Ocorreu um erro interno inesperado.',
        5504 => 'O tempo limite da consulta foi excedido.',
    ];

    /**
     * Verifica se a requisição optou por envelope legado.
     *
     * Opt-in via header X-Legacy-Envelope (ou x-legacy-envelope) OU
     * query param ?envelope=legacy.
     * Header com valor truthy (true/1/legacy/yes/on) ou qualquer valor não-falso
     * dispara legado. Presença sem valor também é legado.
     */
    public static function isLegacyRequested(?ServiceRequestInterface $request): bool
    {
        if ($request === null) {
            // Fallback globals para contexto estático (exceptionToServiceResponse)
            return self::isLegacyRequestedFromGlobals();
        }

        // Query param ?envelope=legacy
        try {
            $params = $request->getParameters();
            if (is_array($params) && isset($params['envelope'])) {
                if (strtolower(trim((string) $params['envelope'])) === 'legacy') {
                    return true;
                }
            }
            // Também tenta getParameter direto
            $env = $request->getParameter('envelope');
            if ($env !== null && strtolower(trim((string) $env)) === 'legacy') {
                return true;
            }
        } catch (\Throwable $ignored) {
        }

        // Header X-Legacy-Envelope via ServiceRequestInterface
        $headerValue = null;
        try {
            $headerValue = $request->getHeader(self::HEADER_LEGACY);
            if ($headerValue === null) {
                $headerValue = $request->getHeader(strtolower(self::HEADER_LEGACY));
            }
            if ($headerValue === null) {
                $headerValue = $request->getHeader('x-legacy-envelope');
            }
        } catch (\Throwable $ignored) {
        }

        if ($headerValue === null) {
            try {
                $headers = $request->getHeaders();
                if (is_array($headers)) {
                    foreach ($headers as $k => $v) {
                        if (strtolower((string) $k) === strtolower(self::HEADER_LEGACY)) {
                            $headerValue = $v;
                            break;
                        }
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }

        // Fallback: driver (Illuminate Request)
        if ($headerValue === null) {
            try {
                $driver = $request->getDriver();
                if ($driver !== null) {
                    if (method_exists($driver, 'header')) {
                        $h = $driver->header(self::HEADER_LEGACY);
                        if ($h !== null && $h !== '') {
                            $headerValue = $h;
                        } else {
                            $h = $driver->header('x-legacy-envelope');
                            if ($h !== null && $h !== '') {
                                $headerValue = $h;
                            }
                        }
                    }
                    if ($headerValue === null && method_exists($driver, 'headers')) {
                        // Symfony HeaderBag
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }

        if ($headerValue !== null) {
            // Se array (multi-value), pega primeiro
            if (is_array($headerValue)) {
                $headerValue = $headerValue[0] ?? null;
            }
            $normalized = strtolower(trim((string) $headerValue));
            // Valores explicitamente falsos não disparam legado
            if ($normalized === '' ) {
                // Header presente sem valor => considera legado (opt-in por presença)
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            // Qualquer outro valor truthy dispara legado
            return true;
        }

        // Último fallback: globals (para chamadas estáticas)
        return self::isLegacyRequestedFromGlobals();
    }

    /**
     * Fallback para detecção via globals quando ServiceRequest não está disponível
     * (ex.: exceptionToServiceResponse estático).
     */
    public static function isLegacyRequestedFromGlobals(): bool
    {
        // Query param via $_GET
        if (isset($_GET['envelope']) && strtolower(trim((string) $_GET['envelope'])) === 'legacy') {
            return true;
        }
        // Header via $_SERVER
        $legacyHeader = $_SERVER['HTTP_X_LEGACY_ENVELOPE'] ?? $_SERVER['HTTP_X_LEGACY_ENVELOPE_LOWER'] ?? null;
        if ($legacyHeader === null) {
            // Varre $_SERVER por X-Legacy-Envelope case-insensitive
            foreach ($_SERVER as $k => $v) {
                if (strtolower($k) === 'http_x_legacy_envelope') {
                    $legacyHeader = $v;
                    break;
                }
            }
        }
        if ($legacyHeader !== null && trim((string) $legacyHeader) !== '') {
            $norm = strtolower(trim((string) $legacyHeader));
            if (!in_array($norm, ['0', 'false', 'off', 'no'], true)) {
                return true;
            }
        }
        // getallheaders fallback
        if (function_exists('getallheaders')) {
            try {
                $headers = getallheaders();
                if (is_array($headers)) {
                    foreach ($headers as $k => $v) {
                        if (strtolower($k) === 'x-legacy-envelope' && trim((string) $v) !== '') {
                            $norm = strtolower(trim((string) $v));
                            if (!in_array($norm, ['0', 'false', 'off', 'no'], true)) {
                                return true;
                            }
                        }
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }
        return false;
    }

    /**
     * Mapeia HTTP status para erroCode legado.
     * @see ErrorType.java:3-15 + inventory-api-query-contract.md:298-306
     */
    public static function statusToErroCode(int $httpStatus): int
    {
        if (isset(self::STATUS_TO_ERROCODE[$httpStatus])) {
            return self::STATUS_TO_ERROCODE[$httpStatus];
        }
        // Fallback genérico: 4xx -> 1400, 5xx -> 5000
        if ($httpStatus >= 400 && $httpStatus < 500) {
            return 1400;
        }
        return 5000;
    }

    public static function defaultMessageForErroCode(int $erroCode): string
    {
        return self::DEFAULT_MESSAGES[$erroCode] ?? 'Ocorreu um erro interno inesperado.';
    }

    public static function defaultMessageForStatus(int $httpStatus): string
    {
        $code = self::statusToErroCode($httpStatus);
        return self::defaultMessageForErroCode($code);
    }

    /**
     * Resolve mensagem de erro preservando semântica de GlobalExceptionHandler:
     *  - 401 e 403 sempre genéricas (nunca expõe detalhe)
     *  - 500 sempre genérica (nunca expõe SQL/stack)
     *  - 400,404,409,504 preservam mensagem se presente, senão default
     */
    public static function resolveErrorMessage(int $httpStatus, string $rawMessage): string
    {
        $trimmed = trim(html_entity_decode((string) $rawMessage, ENT_COMPAT | ENT_HTML5));
        $hasMessage = $trimmed !== '';
        $erroCode = self::statusToErroCode($httpStatus);

        switch ($httpStatus) {
            case 401:
                return self::defaultMessageForErroCode(1001);
            case 403:
                return self::defaultMessageForErroCode(1003);
            case 500:
                return self::defaultMessageForErroCode(5000);
            case 400:
                return $hasMessage ? $trimmed : self::defaultMessageForErroCode(1400);
            case 404:
                return $hasMessage ? $trimmed : self::defaultMessageForErroCode(1004);
            case 409:
                return $hasMessage ? $trimmed : self::defaultMessageForErroCode(1409);
            case 504:
                return $hasMessage ? $trimmed : self::defaultMessageForErroCode(5504);
            default:
                return $hasMessage ? $trimmed : self::defaultMessageForErroCode($erroCode);
        }
    }

    /**
     * Extrai HTTP status de uma exceção DreamFactory.
     */
    public static function httpStatusForException(\Throwable $e): int
    {
        if ($e instanceof RestException) {
            return (int) $e->getStatusCode();
        }
        if ($e instanceof UnauthorizedException) {
            return 401;
        }
        if ($e instanceof ForbiddenException) {
            return 403;
        }
        if ($e instanceof NotFoundException) {
            return 404;
        }
        if ($e instanceof BadRequestException) {
            return 400;
        }
        if ($e instanceof ConflictResourceException) {
            return 409;
        }
        // Fallback via código da exceção se for HTTP status válido
        $code = (int) $e->getCode();
        if (isset(self::STATUS_TO_ERROCODE[$code])) {
            return $code;
        }
        // Tenta getStatusCode se existir
        if (method_exists($e, 'getStatusCode')) {
            try {
                $s = (int) $e->getStatusCode();
                if ($s >= 400 && $s <= 599) {
                    return $s;
                }
            } catch (\Throwable $ignored) {
            }
        }
        return 500;
    }

    /**
     * Constrói envelope legado de erro a partir de status + mensagem.
     * Timestamp no formato yyyy-MM-dd HH:mm:ss.SSS — ErrorMessageResponse.java:15-16.
     */
    public static function toLegacyErrorFromStatusAndMessage(int $httpStatus, string $message): array
    {
        $erroCode = self::statusToErroCode($httpStatus);
        $errorMessage = self::resolveErrorMessage($httpStatus, $message);
        return [
            'erroCode' => $erroCode,
            'errorMessage' => $errorMessage,
            'timestamp' => self::nowTimestamp(),
        ];
    }

    /**
     * Constrói envelope legado de erro a partir de Throwable.
     */
    public static function toLegacyError(\Throwable $e): array
    {
        $status = self::httpStatusForException($e);
        $message = $e->getMessage();
        // RestException já decodifica? Garantir html_entity_decode
        return self::toLegacyErrorFromStatusAndMessage($status, $message);
    }

    /**
     * Cria ServiceResponse legado para erro (preserva HTTP status).
     * Equivalente a GlobalExceptionHandler retornando ResponseEntity.status(...).body(envelope)
     */
    public static function toLegacyServiceResponse(\Throwable $e): ServiceResponse
    {
        $status = self::httpStatusForException($e);
        $legacy = self::toLegacyError($e);
        return new ServiceResponse($legacy, null, $status);
    }

    /**
     * Constrói envelope legado de sucesso.
     * Espelha QueryBuilderController.java:80-88 e QueryParameterController.java:63-74.
     *
     * @param array $resource Lista de rows já normalizadas (ResultNormalizer)
     * @param float $startedAt microtime(true) do início da execução
     */
    public static function toLegacySuccess(array $resource, float $startedAt): array
    {
        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        if ($elapsed < 0) {
            $elapsed = 0;
        }
        return [
            'elapsed_time' => $elapsed,
            'request_id' => self::generateUuidV4(),
            'timestamp' => self::nowTimestamp(),
            'result_count' => count($resource),
            'result' => $resource,
        ];
    }

    /**
     * Fallback para tradução pós-parent quando só temos content nativo {resource:[]}.
     */
    public static function toLegacySuccessFromNative(array $nativeContent, float $startedAt): array
    {
        $resource = $nativeContent['resource'] ?? [];
        if (!is_array($resource)) {
            $resource = [];
        }
        return self::toLegacySuccess($resource, $startedAt);
    }

    /**
     * Timestamp no formato yyyy-MM-dd HH:mm:ss.SSS — SimpleDateFormat em ErrorMessageResponse.java:15
     */
    public static function nowTimestamp(): string
    {
        $micro = microtime(true);
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $micro));
        if ($dt instanceof \DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s.v');
        }
        // Fallback
        return date('Y-m-d H:i:s') . '.' . sprintf('%03d', (int) (($micro - floor($micro)) * 1000));
    }

    /**
     * UUID v4 — equivalente a UUID.randomUUID() em QueryBuilderController.java:61,52
     */
    public static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
