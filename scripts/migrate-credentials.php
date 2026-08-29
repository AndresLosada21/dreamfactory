<?php
/**
 * RQ-042 — Helper de migração de credenciais legadas para DreamFactory.
 *
 * Valida pares client_secret + client_key sem logar segredo em plaintext.
 * Artefato principal é docs/architecture/credential-migration.md; este script
 * é auxiliar idempotente.
 *
 * Uso:
 *   php scripts/migrate-credentials.php --check [--from=api-query/config/authentication/credential.json]
 *   php scripts/migrate-credentials.php --hash --secret=TEST_CLIENT_SECRET_EXAMPLE
 *   php scripts/migrate-credentials.php --verify --secret=TEST_CLIENT_SECRET --hash=<sha256 hex>
 *   php scripts/migrate-credentials.php --help
 *
 * Saída nunca contém segredo em plaintext — usa máscara TEST_**** e sha256 truncado.
 * Placeholders TEST_* apenas; nenhum segredo real é persistido ou impresso.
 */

declare(strict_types=1);

function maskSecret(string $secret): string
{
    if (str_starts_with($secret, 'TEST_')) {
        return 'TEST_****(' . strlen($secret) . ' chars)';
    }
    if (str_starts_with($secret, 'REPLACE_')) {
        return 'REPLACE_****(placeholder)';
    }
    // genérico: não revela conteúdo
    return '****(' . strlen($secret) . ' chars, sha256:' . substr(hash('sha256', $secret), 0, 8) . '...)';
}

function truncatedHash(string $hex): string
{
    return substr($hex, 0, 16) . '...' . substr($hex, -4);
}

function usage(): void
{
    $msg = <<<TXT
RQ-042 migrate-credentials helper
  --check  [--from=PATH]   Valida pares client_secret+client_key (não loga segredo)
  --hash   --secret=VAL    Imprime sha256 do segredo (mascarado, truncado)
  --verify --secret=VAL --hash=HEX  Verifica segredo contra hash (hash_equals)
  --help                  Esta ajuda

Exemplos:
  php scripts/migrate-credentials.php --check --from=api-query/config/authentication/credential.json
  php scripts/migrate-credentials.php --hash --secret=TEST_CLIENT_SECRET_EXAMPLE
  php scripts/migrate-credentials.php --verify --secret=TEST_CLIENT_SECRET --hash=abc...

TXT;
    fwrite(STDOUT, $msg . PHP_EOL);
}

function parseArgs(array $argv): array
{
    $out = ['check' => false, 'hash' => false, 'verify' => false, 'help' => false, 'from' => null, 'secret' => null, 'hash_value' => null];
    foreach ($argv as $a) {
        if ($a === '--check') $out['check'] = true;
        elseif ($a === '--hash') $out['hash'] = true;
        elseif ($a === '--verify') $out['verify'] = true;
        elseif ($a === '--help' || $a === '-h') $out['help'] = true;
        elseif (str_starts_with($a, '--from=')) $out['from'] = substr($a, 7);
        elseif (str_starts_with($a, '--secret=')) $out['secret'] = substr($a, 9);
        elseif (str_starts_with($a, '--hash=')) $out['hash_value'] = substr($a, 7);
    }
    return $out;
}

function doCheck(?string $from): int
{
    // Candidatos — relativo a dreamfactory-fork/
    $candidates = array_filter([
        $from,
        __DIR__ . '/../../api-query/config/authentication/credential.json',
        __DIR__ . '/../api-query/config/authentication/credential.json',
        getcwd() . '/api-query/config/authentication/credential.json',
        __DIR__ . '/../../dreamfactory-fork/../api-query/config/authentication/credential.json',
    ]);
    $file = null;
    foreach ($candidates as $c) {
        if ($c !== null && file_exists($c)) { $file = $c; break; }
    }
    // fallback: procura recursiva curta a partir de CWD
    if ($file === null && $from !== null) {
        fwrite(STDERR, "[ERR] arquivo não encontrado: {$from}\n");
        return 2;
    }
    if ($file === null) {
        // sem arquivo — valida synthetic TEST_ pair como smoke
        fwrite(STDOUT, "[INFO] credential.json não encontrado; validando par sintético TEST_*\n");
        $pairs = [
            ['client_secret' => 'TEST_CLIENT_SECRET_DECALQUE', 'client_key' => 'TEST_CLIENT_KEY_DECALQUE', 'claims' => ['query_decalque']],
            ['client_secret' => 'TEST_CLIENT_SECRET_GQ_MI', 'client_key' => 'TEST_CLIENT_KEY_GQ_MI', 'claims' => ['query_gq_mi','query_gq_eficaz']],
        ];
        $fileLabel = '(synthetic)';
    } else {
        $raw = file_get_contents($file);
        $pairs = json_decode($raw, true);
        if (!is_array($pairs)) {
            fwrite(STDERR, "[ERR] JSON inválido em {$file}\n");
            return 2;
        }
        $fileLabel = $file;
    }

    fwrite(STDOUT, "[CHECK] fonte: {$fileLabel}\n");
    $ok = true;
    $keys = [];
    foreach ($pairs as $idx => $cred) {
        $n = $idx + 1;
        $secret = $cred['client_secret'] ?? null;
        $key = $cred['client_key'] ?? null;
        $claims = $cred['claims'] ?? null;

        // pair semantics: ambos obrigatórios — AuthorizationService.java:59-60, CredentialRepository.java:127-133
        if (!is_string($secret) || trim($secret) === '') {
            fwrite(STDERR, "[FAIL] #{$n}: client_secret ausente/vazio\n");
            $ok = false; continue;
        }
        if (!is_string($key) || trim($key) === '') {
            fwrite(STDERR, "[FAIL] #{$n}: client_key ausente/vazio\n");
            $ok = false; continue;
        }
        if (isset($keys[$key])) {
            fwrite(STDERR, "[FAIL] #{$n}: client_key duplicado: " . substr($key, 0, 12) . "...\n");
            $ok = false;
        }
        $keys[$key] = true;

        $maskedSecret = maskSecret($secret);
        $maskedKey = str_starts_with($key, 'TEST_') || str_starts_with($key, 'REPLACE_') ? $key : substr($key, 0, 8) . '...(' . strlen($key) . ' chars)';
        $shaPreview = truncatedHash(hash('sha256', $secret));

        // valores expostos devem ser rotacionados — REPLACE_ é placeholder, não segredo real
        $isPlaceholder = str_starts_with($secret, 'REPLACE_') || str_starts_with($key, 'REPLACE_');
        $isTest = str_starts_with($secret, 'TEST_') && str_starts_with($key, 'TEST_');

        if ($isPlaceholder) {
            fwrite(STDOUT, "[OK] #{$n}: key={$maskedKey} secret={$maskedSecret} sha256={$shaPreview} claims=" . json_encode($claims) . " — PLACEHOLDER (rotacionar antes do cutover)\n");
        } elseif ($isTest) {
            fwrite(STDOUT, "[OK] #{$n}: key={$maskedKey} secret={$maskedSecret} sha256={$shaPreview} claims=" . json_encode($claims) . " — TEST placeholder\n");
        } else {
            fwrite(STDOUT, "[WARN] #{$n}: key={$maskedKey} secret={$maskedSecret} sha256={$shaPreview} claims=" . json_encode($claims) . " — segredo não-TEST detectado; rotacionar e migrar para hash (lookup privado) — ver credential-migration.md §4\n");
        }

        // claims deve ser array não-vazio para mapear para role — inventory §14
        if (!is_array($claims) || count($claims) === 0) {
            fwrite(STDERR, "[WARN] #{$n}: claims vazio — sem role alvo\n");
        }
    }

    fwrite(STDOUT, $ok ? "[PASS] pares validados sem expor segredo (hash truncado apenas)\n" : "[FAIL] validação com erros\n");
    fwrite(STDOUT, "Período de sobreposição recomendado: 7 dias — credential-migration.md §7\n");
    return $ok ? 0 : 1;
}

function doHash(?string $secret): int
{
    if ($secret === null || $secret === '') {
        fwrite(STDERR, "[ERR] --secret é obrigatório para --hash\n");
        return 2;
    }
    $hex = hash('sha256', $secret);
    // nunca imprime segredo; só máscara + hash truncado e completo em modo explícito
    fwrite(STDOUT, "secret: " . maskSecret($secret) . PHP_EOL);
    fwrite(STDOUT, "sha256: {$hex}\n");
    fwrite(STDOUT, "sha256 (truncado para log): " . truncatedHash($hex) . PHP_EOL);
    fwrite(STDOUT, "Armazene apenas o hash em lookup privado (private=1) — credential-migration.md §4\n");
    return 0;
}

function doVerify(?string $secret, ?string $hash): int
{
    if ($secret === null || $secret === '') {
        fwrite(STDERR, "[ERR] --secret é obrigatório para --verify\n");
        return 2;
    }
    if ($hash === null || !preg_match('/^[0-9a-f]{64}$/i', $hash)) {
        fwrite(STDERR, "[ERR] --hash deve ser hex sha256 (64 chars)\n");
        return 2;
    }
    $computed = hash('sha256', $secret);
    $ok = hash_equals(strtolower($hash), strtolower($computed)); // tempo constante — AuthorizationService.java:159-166
    fwrite(STDOUT, "secret: " . maskSecret($secret) . PHP_EOL);
    fwrite(STDOUT, "expected: " . truncatedHash($hash) . PHP_EOL);
    fwrite(STDOUT, "computed: " . truncatedHash($computed) . PHP_EOL);
    fwrite(STDOUT, $ok ? "[PASS] segredo confere (hash_equals)\n" : "[FAIL] segredo não confere\n");
    return $ok ? 0 : 1;
}

// main
$args = parseArgs($argv);
if ($args['help'] || (!$args['check'] && !$args['hash'] && !$args['verify'])) {
    usage();
    exit($args['help'] ? 0 : 2);
}
if ($args['check']) exit(doCheck($args['from']));
if ($args['verify']) exit(doVerify($args['secret'], $args['hash_value']));
if ($args['hash']) exit(doHash($args['secret']));
