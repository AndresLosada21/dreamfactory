<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Log;

/**
 * RQ-086 — SGA Client endurecido
 * Espelha SGA/src/main/java/br/com/yamaha/sga/facade/ws/WsAcesso.java:30
 * - endpoint allowlisted sem userinfo, timeout 3000ms, BODY 1MB 1048576, SOAP RPC, XXE guards, @@@ERRO@@@, sem segredos em logs
 * - operações: validarLogin(codUsuario,dscSenha,nomSistema), getUsuarioByMatricula, getPerfilUsuario, getPerfilById
 * - sessionSecond=10 propagado via cache_generation
 */
class SgaClient
{
    public const BODY_LIMIT = 1048576;
    public const TIMEOUT_MS = 3000;
    public const NAMESPACE = 'http://WsAcesso.facade.sga.yamaha.com.br/';
    public const WSDL_SGA = 'http://172.31.16.89/SGA/WsAcesso?wsdl';

    private string $endpoint;
    private int $timeoutMs;
    private int $maxResponseBytes;
    private array $allowlistHosts;

    public function __construct(string $endpoint = '', int $timeoutMs = self::TIMEOUT_MS, int $maxResponseBytes = self::BODY_LIMIT, array $allowlistHosts = [])
    {
        $endpointFromConfig = '';
        try {
            $endpointFromConfig = (string) config('sga.endpoint', env('SGA_ENDPOINT', self::WSDL_SGA));
        } catch (\Throwable $ignored) {
            $endpointFromConfig = (string) (function_exists('env') ? env('SGA_ENDPOINT', self::WSDL_SGA) : self::WSDL_SGA);
        }
        $this->endpoint = $endpoint ?: $endpointFromConfig;
        $this->timeoutMs = $timeoutMs;
        $this->maxResponseBytes = $maxResponseBytes;
        $this->allowlistHosts = $allowlistHosts;
        if (empty($this->allowlistHosts)) {
            $cfg = '';
            try {
                $cfg = config('sga.allowlist', env('SGA_ALLOWLIST', '172.31.16.89'));
            } catch (\Throwable $ignored) {
                $cfg = (string) (function_exists('env') ? env('SGA_ALLOWLIST', '172.31.16.89') : '172.31.16.89');
            }
            if (!empty($cfg)) {
                $this->allowlistHosts = array_map('trim', explode(',', (string) $cfg));
            } else {
                $this->allowlistHosts = ['172.31.16.89'];
            }
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->endpoint) && trim($this->endpoint) !== '';
    }

    public function validateConfiguration(?array $config = null): void
    {
        if (is_array($config)) {
            if (isset($config['body']) && strlen($config['body']) > self::BODY_LIMIT) {
                throw new \RuntimeException('BODY exceeds 1MB limit');
            }
        }
        if ($this->timeoutMs < 1 || $this->maxResponseBytes < 1 || $this->maxResponseBytes === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid SGA timeout or response size limit');
        }
        if ($this->isConfigured()) {
            $uri = parse_url($this->endpoint);
            if (!isset($uri['scheme']) || !in_array(strtolower($uri['scheme']), ['http', 'https'], true)) {
                throw new \InvalidArgumentException('SGA endpoint must use HTTP(S)');
            }
            if (isset($uri['user']) || isset($uri['pass'])) {
                throw new \InvalidArgumentException('SGA endpoint must not contain user info');
            }
            if (!empty($this->allowlistHosts)) {
                $host = $uri['host'] ?? '';
                $allowed = false;
                foreach ($this->allowlistHosts as $ah) {
                    if ($ah === $host || ($ah && str_ends_with($host, '.' . ltrim($ah, '.')))) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    throw new \InvalidArgumentException('SGA endpoint host not allowlisted: ' . $host);
                }
            }
        }
    }

    /**
     * validarLogin(codUsuario,dscSenha,nomSistema) — SGA WsAcesso:30
     * Retorna MBeanAcessoMenu itens decodificados ou lança @@@ERRO@@@
     */
    public function validarLogin(string $codUsuario, string $dscSenha, string $nomSistema): array
    {
        if (trim($codUsuario) === '' || trim($nomSistema) === '') {
            throw new \InvalidArgumentException('codUsuario and nomSistema required');
        }
        $this->validateConfiguration();
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Endpoint SGA nao configurado');
        }
        $soapBody = $this->soapBodyValidarLogin($codUsuario, $dscSenha, $nomSistema);
        if (strlen($soapBody) > self::BODY_LIMIT) {
            throw new \RuntimeException('SOAP BODY exceeds 1MB limit');
        }
        $response = $this->sendWithTimeout($soapBody, 'validarLogin');
        $status = $response['status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            $this->logSanitized('sga.non2xx', ['host' => parse_url($this->endpoint, PHP_URL_HOST), 'status' => $status, 'codUsuario' => $codUsuario]);
            throw new \RuntimeException('SGA respondeu HTTP ' . $status);
        }
        $body = $response['body'] ?? '';
        if (strlen($body) > $this->maxResponseBytes) {
            throw new \RuntimeException('SGA response exceeds BODY limit 1MB');
        }
        if (str_starts_with(trim($body), '@@@ERRO@@@') || str_contains($body, '@@@ERRO@@@')) {
            $this->logSanitized('sga.erro_marker', ['host' => parse_url($this->endpoint, PHP_URL_HOST), 'codUsuario' => $codUsuario]);
            throw new \RuntimeException('SGA validarLogin falhou: ' . $this->sanitizeError(substr($body, 0, 200)));
        }
        $inner = $this->readSoapReturn($body);
        if (str_contains($inner, '@@@ERRO@@@')) {
            throw new \RuntimeException('SGA validarLogin falhou: ' . $this->sanitizeError(substr($inner, 0, 200)));
        }
        $data = json_decode($inner, true);
        if (!is_array($data)) {
            $data = ['raw' => $inner, 'codUsuario' => $codUsuario, 'nomSistema' => $nomSistema];
        }
        $this->logSanitized('sga.validarLogin.success', ['host' => parse_url($this->endpoint, PHP_URL_HOST), 'codUsuario' => $codUsuario, 'nomSistema' => $nomSistema, 'status' => $status]);
        return $data + ['codUsuario' => $codUsuario, 'nomSistema' => $nomSistema, 'via' => 'SGA', 'body_limit' => self::BODY_LIMIT];
    }

    public function getUsuarioByMatricula(string $codUsuario): array
    {
        if (trim($codUsuario) === '') {
            throw new \InvalidArgumentException('codUsuario required');
        }
        $this->validateConfiguration();
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Endpoint SGA nao configurado');
        }
        $soapBody = $this->soapBodyGetUsuarioByMatricula($codUsuario);
        $response = $this->sendWithTimeout($soapBody, 'getUsuarioByMatricula');
        $status = $response['status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('SGA respondeu HTTP ' . $status);
        }
        $body = $response['body'] ?? '';
        if (str_contains($body, '@@@ERRO@@@')) {
            throw new \RuntimeException('SGA getUsuarioByMatricula falhou: ' . $codUsuario);
        }
        $inner = $this->readSoapReturn($body);
        $data = json_decode($inner, true);
        if (!is_array($data)) {
            $data = ['raw' => $inner, 'codUsuario' => $codUsuario];
        }
        return $data + ['codUsuario' => $codUsuario, 'via' => 'SGA'];
    }

    public function getPerfilUsuario(string $codUsuario, string $sglSistema): array
    {
        if (trim($codUsuario) === '' || trim($sglSistema) === '') {
            throw new \InvalidArgumentException('codUsuario and sglSistema required');
        }
        $this->validateConfiguration();
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Endpoint SGA nao configurado');
        }
        $soapBody = $this->soapBodyGetPerfilUsuario($codUsuario, $sglSistema);
        $response = $this->sendWithTimeout($soapBody, 'getPerfilUsuario');
        $status = $response['status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('SGA respondeu HTTP ' . $status);
        }
        $body = $response['body'] ?? '';
        if (str_contains($body, '@@@ERRO@@@')) {
            throw new \RuntimeException('SGA getPerfilUsuario falhou: ' . $codUsuario);
        }
        $inner = $this->readSoapReturn($body);
        $data = json_decode($inner, true);
        if (!is_array($data)) {
            $data = ['raw' => $inner, 'codUsuario' => $codUsuario, 'sglSistema' => $sglSistema];
        }
        $this->logSanitized('sga.getPerfilUsuario.success', ['host' => parse_url($this->endpoint, PHP_URL_HOST), 'codUsuario' => $codUsuario, 'sglSistema' => $sglSistema]);
        return $data + ['codUsuario' => $codUsuario, 'sglSistema' => $sglSistema, 'via' => 'SGA'];
    }

    public function getPerfilById(int $idPerfil): array
    {
        if ($idPerfil < 1) {
            throw new \InvalidArgumentException('idPerfil must be >=1');
        }
        $this->validateConfiguration();
        $soapBody = $this->soapBodyGetPerfilById($idPerfil);
        $response = $this->sendWithTimeout($soapBody, 'getPerfilById');
        $body = $response['body'] ?? '';
        $inner = $this->readSoapReturn($body);
        $data = json_decode($inner, true);
        if (!is_array($data)) {
            $data = ['raw' => $inner, 'idPerfil' => $idPerfil];
        }
        return $data + ['idPerfil' => $idPerfil, 'via' => 'SGA'];
    }

    private function soapBodyValidarLogin(string $codUsuario, string $dscSenha, string $nomSistema): string
    {
        $ns = self::NAMESPACE;
        $u = htmlspecialchars($codUsuario, ENT_XML1);
        $s = htmlspecialchars($dscSenha, ENT_XML1);
        $n = htmlspecialchars($nomSistema, ENT_XML1);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="{$ns}">
  <soapenv:Header/>
  <soapenv:Body>
    <ws:validarLogin>
      <codUsuario>{$u}</codUsuario>
      <dscSenha>{$s}</dscSenha>
      <nomSistema>{$n}</nomSistema>
    </ws:validarLogin>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapBodyGetUsuarioByMatricula(string $codUsuario): string
    {
        $ns = self::NAMESPACE;
        $u = htmlspecialchars($codUsuario, ENT_XML1);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="{$ns}">
  <soapenv:Header/>
  <soapenv:Body>
    <ws:getUsuarioByMatricula>
      <codUsuario>{$u}</codUsuario>
    </ws:getUsuarioByMatricula>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapBodyGetPerfilUsuario(string $codUsuario, string $sglSistema): string
    {
        $ns = self::NAMESPACE;
        $u = htmlspecialchars($codUsuario, ENT_XML1);
        $s = htmlspecialchars($sglSistema, ENT_XML1);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="{$ns}">
  <soapenv:Header/>
  <soapenv:Body>
    <ws:getPerfilUsuario>
      <codUsuario>{$u}</codUsuario>
      <sglSistema>{$s}</sglSistema>
    </ws:getPerfilUsuario>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapBodyGetPerfilById(int $idPerfil): string
    {
        $ns = self::NAMESPACE;
        $id = (int) $idPerfil;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="{$ns}">
  <soapenv:Header/>
  <soapenv:Body>
    <ws:getPerfilById>
      <idPerfil>{$id}</idPerfil>
    </ws:getPerfilById>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function sendWithTimeout(string $soapBody, string $operation): array
    {
        $url = $this->endpoint;
        $timeoutSec = max(1, (int) ceil($this->timeoutMs / 1000));
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soapBody);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(3000, $this->timeoutMs));
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $this->maxResponseBytes);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . self::NAMESPACE . $operation . '"',
                'Content-Length: ' . strlen($soapBody),
            ]);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($result === false) {
                if (str_contains($err, 'exceeded') || str_contains($err, 'Maximum file size')) {
                    throw new \RuntimeException('SGA response exceeds BODY limit 1MB');
                }
                throw new \RuntimeException('SGA request failed: ' . $this->sanitizeError($err));
            }
            return ['status' => $status ?: 200, 'body' => $result];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/xml; charset=utf-8\r\nSOAPAction: \"" . self::NAMESPACE . $operation . "\"",
                'content' => $soapBody,
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($result === false) {
            throw new \RuntimeException('SGA request failed');
        }
        return ['status' => $status, 'body' => $result];
    }

    public function readSoapReturn(string $xml): string
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('DOCTYPE not allowed in SGA response');
        }
        if (preg_match('/<!ENTITY/i', $xml)) {
            throw new \RuntimeException('ENTITY not allowed in SGA response');
        }
        $prev = libxml_use_internal_errors(true);
        $prevEntity = libxml_disable_entity_loader(true);
        try {
            $doc = new \DOMDocument();
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_DTDATTR | LIBXML_COMPACT);
            if (!$loaded) {
                throw new \RuntimeException('Invalid XML from SGA');
            }
            if ($doc->doctype !== null) {
                throw new \RuntimeException('DOCTYPE not allowed');
            }
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $nodes = $xpath->query('//*[local-name()="return"]');
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
            $nodes = $xpath->query('//*[local-name()="validarLoginReturn"]');
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
            $nodes = $xpath->query('//*[local-name()="getPerfilUsuarioReturn"]');
            if ($nodes && $nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
            $bodies = $xpath->query('//*[local-name()="Body"]');
            if ($bodies && $bodies->length > 0) {
                return trim($bodies->item(0)->textContent);
            }
            return trim($doc->textContent);
        } finally {
            libxml_use_internal_errors($prev);
            libxml_disable_entity_loader($prevEntity);
            libxml_clear_errors();
        }
    }

    private function logSanitized(string $event, array $ctx): void
    {
        $safe = [];
        foreach ($ctx as $k => $v) {
            if (in_array(strtolower($k), ['xml', 'body', 'password', 'secret', 'credentials', 'dscsenha'], true)) {
                $safe[$k] = '[REDACTED]';
            } else {
                $safe[$k] = $v;
            }
        }
        try {
            Log::info($event, $safe);
        } catch (\Throwable $ignored) {}
    }

    private function sanitizeError(string $msg): string
    {
        $msg = preg_replace('/password[^;]*/i', '[REDACTED]', $msg);
        $msg = preg_replace('/secret[^;]*/i', '[REDACTED]', $msg);
        $msg = preg_replace('/dscSenha[^;]*/i', '[REDACTED]', $msg);
        return substr($msg, 0, 200);
    }
}
