# Envelopes — Sucesso e Erro (RQ-044)

> Mapeia contrato legado `elapsed_time/request_id/timestamp/result_count/result` e
> `{erroCode, errorMessage, timestamp}` para envelope nativo DF `{resource:[]}` e
> `{error:{code,message}}`, com tradutor opt-in `X-Legacy-Envelope`/`?envelope=legacy`.

## 1. Contratos

### 1.1 Sucesso legado vs nativo

| Campo | Legado (QueryBuilderController.java:80-88) | Nativo DF (NamedQueryResource.php:107) |
|---|---|---|
| Outer | `elapsed_time: number >=0` (elapsed ms) | `resource: array` |
| ID | `request_id: string UUID v4` (UUID.randomUUID) | n/a |
| Tempo | `timestamp: "yyyy-MM-dd HH:mm:ss.SSS"` (SimpleDateFormat) | n/a |
| Contagem | `result_count: int == result.length` | `resource.length` |
| Dados | `result: []` (keys lowercased ResultSetUtil.java:64) | `resource: []` (mesmas keys) |

Fonte golden: `api-query/tests/blackbox/fixtures/goldens/success-envelope.json:1-62`.

### 1.2 Erro legado — ErrorMessageResponse.java:6-55

```json
{ "erroCode": 1400, "errorMessage": "Parametro(s) obrigatorio(s): cma", "timestamp": "2026-08-28 14:30:00.123" }
```

Mapeamento HTTP → erroCode — ErrorType.java:3-15 + inventory-api-query-contract.md:298-306:

| HTTP | erroCode | Tipo | Quando |
|---|---|---|---|
| 400 | 1400 | BAD_REQUEST | BadRequestException, InvalidRequestException, MissingServletRequestParameter, MethodArgumentTypeMismatch, MissingPathVariable, MaxUpload desvio (quando mapeado como 400), ConstraintViolation (GlobalExceptionHandler.java:46-51,53-58,103-144,154-159) |
| 401 | 1001 | UNAUTHORIZED | UnauthorizedException (GlobalExceptionHandler.java:67-72) — sempre genérica `"Credenciais inválidas ou ausentes."` |
| 403 | 1003 | FORBIDDEN | ForbiddenException (GlobalExceptionHandler.java:74-79) — genérica `"Você não tem permissão para acessar este recurso."` |
| 404 | 1004 | RESOURCE_NOT_FOUND | NotFoundException (GlobalExceptionHandler.java:40-44), NoResourceFoundException (GlobalExceptionHandler.java:146-152) |
| 405 | 1405 | METHOD_NOT_ALLOWED | HttpRequestMethodNotSupportedException (GlobalExceptionHandler.java:118-122) |
| 409 | 1409 | CONFLICT | ConflictException (GlobalExceptionHandler.java:60-65) → ConflictResourceException no DF |
| 413 | 1413 | PAYLOAD_TOO_LARGE | MaxUploadSizeExceededException (GlobalExceptionHandler.java:110-115) |
| 500 | 5000 | INTERNAL_SERVER_ERROR | RuntimeException (GlobalExceptionHandler.java:81-87) — genérica |
| 504 | 5504 | GATEWAY_TIMEOUT | QueryExecutionTimeoutException (GlobalExceptionHandler.java:89-94) — via RestException(504) no DF |

Golden canônico: `api-query/tests/blackbox/fixtures/goldens/error-envelope.json:1-103` (example_bodies 401/403/400/504 com timestamp `yyyy-MM-dd HH:mm:ss.SSS`).

Nativo DF para erro: `{error:{code, message, context, status_code}, status_code}` — ExceptionResponse.php:17-27 e DfException.php:49-51 (`code`, `message`, `context`, `trace` se `app.debug`).

## 2. Tradutor — extensions/df-named-query/src/Http/EnvelopeTranslator.php:1

### 2.1 Classe

`Yamaha\DreamFactory\NamedQuery\Http\EnvelopeTranslator`

- `isLegacyRequested(?ServiceRequestInterface)` — detecta opt-in via:
  - query param `?envelope=legacy` (case-insensitive) — ServiceRequest::getParameters()/getParameter() + fallback `$_GET`
  - header `X-Legacy-Envelope` (ou `x-legacy-envelope`, case-insensitive) — ServiceRequest::getHeader()/getHeaders() + driver headers + `$_SERVER['HTTP_X_LEGACY_ENVELOPE']` + `getallheaders()`
  - valores truthy (`true/1/legacy/yes/on` ou presença sem valor) disparam legado; `0/false/off/no` não disparam
- `statusToErroCode(int $status): int` + `STATUS_TO_ERROCODE` — mapeia 400→1400,401→1001,403→1003,404→1004,405→1405,409→1409,413→1413,500→5000,504→5504
- `defaultMessageForErroCode(int)` + `DEFAULT_MESSAGES` — mensagens de ErrorType.java:6-15
- `resolveErrorMessage(int $status, string $raw): string` — preserva semântica GlobalExceptionHandler: 401/403/500 sempre genéricas; 400/404/409/504 preservam mensagem se presente
- `httpStatusForException(Throwable): int` — extrai status de RestException/BadRequestException/UnauthorizedException/ForbiddenException/NotFoundException/ConflictResourceException/InternalServerErrorException/RestException(504)/DfException genérico→500
- `toLegacyError(Throwable): array` e `toLegacyErrorFromStatusAndMessage(int, string): array` → `{erroCode, errorMessage, timestamp}`
- `toLegacySuccess(array $resource, float $start): array` → `{elapsed_time, request_id, timestamp, result_count, result}` (elapsed via microtime, request_id UUID v4 via random_bytes, timestamp via DateTimeImmutable `Y-m-d H:i:s.v`)
- `nowTimestamp(): string` — `Y-m-d H:i:s.v` (milis com 3 dígitos)
- `generateUuidV4(): string`

### 2.2 Sucesso — handleGET/handlePOST em NamedQueryResource.php:82-134

Opt-in intercepta antes de `execute()`:

```php
if (EnvelopeTranslator::isLegacyRequested($this->request)) {
    $start = microtime(true);
    $native = $this->execute($values);
    return EnvelopeTranslator::toLegacySuccess($native['resource'] ?? [], $start);
}
return $this->execute($values);
```

Para `POST`, `envelope=legacy` também pode vir no body JSON (retirado antes do compile para não virar param SQL).

### 2.3 Erro — handleRequest em NamedQueryResource.php:142-194

Sobrescreve `RestHandler::handleRequest` (RestHandler.php:handleRequest) para, quando legado solicitado, traduzir `ServiceResponse` com `status >=400` e conteúdo `{error:{code,message}}` para `{erroCode, errorMessage, timestamp}` mantendo HTTP status:

```php
$response = parent::handleRequest($request, $resource);
if (EnvelopeTranslator::isLegacyRequested($request) && $status >= 400) {
    $legacy = EnvelopeTranslator::toLegacyErrorFromStatusAndMessage($status, $dfError['message']);
    $response->setContent($legacy);
}
```

Já-legacy (`erroCode` presente) não é re-traduzido. Falhas em `handleRequest` (exceções não capturadas como ServiceResponse) viajam como antes e são traduzidas no próximo nível (ExceptionResponse::exceptionToServiceResponse ainda nativo; o tradutor intercepta a resposta, não a exceção direta — para exceções que escapam, o pipeline DF ainda produz ServiceResponse que será traduzido se legado solicitado).

### 2.4 API nativa mantém contrato próprio

Sem `X-Legacy-Envelope` nem `?envelope=legacy`, todo o comportamento é idêntico a antes: `handleGET`/`handlePOST` retornam `{resource:[]}` e erros vêm como `{error:{code,message,status_code}}`. Nenhuma rota é adicionada; o header é opt-in e não interfere com RBAC/events nativos.

## 3. RBAC/events nativos — sem bypass

- `execute()` ainda chama `$this->checkPermission($this->getAction(), $this->resource)` (BaseRestResource.php:checkPermission → Session::checkServicePermission) antes de qualquer query — EnvelopeTranslator não cria autorização paralela.
- `listAccessComponents()` e `getEventMap()` permanecem inalterados; ServiceManager::handleServiceRequest + RestHandler::preProcess/processRequest/postProcess/respond continuam disparando PreProcessApiEvent/PostProcessApiEvent/ApiEvent nativos.

## 4. Exemplos

### 4.1 Sucesso legado

`GET /api/v2/py_ptg/_query/acasala?cma=TEST&envelope=legacy`
Header alternativo: `X-Legacy-Envelope: true`

Nativo:
```json
{"resource":[{"vin":"X","cma":"TEST"}]}
```

Legado (via tradutor):
```json
{"elapsed_time":42,"request_id":"550e8400-e29b-41d4-a716-446655440000","timestamp":"2026-08-28 14:30:00.123","result_count":1,"result":[{"vin":"X","cma":"TEST"}]}
```

### 4.2 Erro legado

`GET /api/v2/py_ptg/_query/acasala?envelope=legacy` sem `cma` → `400/1400`:
```json
{"erroCode":1400,"errorMessage":"Required parameter 'cma' is missing.","timestamp":"2026-08-28 14:30:00.123"}
```

`401/1001` sem credencial (quando autenticado via RBAC DF): `{"erroCode":1001,"errorMessage":"Credenciais inválidas ou ausentes.","timestamp":"..."}`

`504/5504` timeout: `{"erroCode":5504,"errorMessage":"Tempo limite total da consulta excedido","timestamp":"..."}`

## 5. Arquivos

- `dreamfactory-fork/extensions/df-named-query/src/Http/EnvelopeTranslator.php:1` — tradutor (novo)
- `dreamfactory-fork/extensions/df-named-query/src/Resources/NamedQueryResource.php:1,11,82,99,142` — integração legado opt-in (handleGET/handlePOST/handleRequest)
- `dreamfactory-fork/docs/architecture/envelopes.md:1` — este documento
- Golden: `api-query/tests/blackbox/fixtures/goldens/error-envelope.json:1`, `success-envelope.json:1`
- Contrato: `dreamfactory-fork/docs/architecture/inventory-api-query-contract.md:10`, `api-query/src/main/java/com/querybuilder/dto/ErrorType.java:3`, `api-query/src/main/java/com/querybuilder/config/GlobalExceptionHandler.java:40`, `api-query/src/main/java/com/querybuilder/dto/ErrorMessageResponse.java:6`

## 6. Notas sobre 413 e 405

413 (PAYLOAD_TOO_LARGE/1413) e 405 (METHOD_NOT_ALLOWED/1405) são mapeados no tradutor, mas não são gerados por `_query` em condições normais (max upload só em multipart, method not allowed só se rota não tiver handler). O mapeamento garante golden compat mesmo que o pipeline os gere.
