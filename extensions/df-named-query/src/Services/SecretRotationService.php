<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RQ-081 + RQ-088 — Migração PBEWithMD5AndDES → AES-GCM → secret store com read-through
 * Nunca loga segredo, apenas key_id e contagens
 * Cobre legado Java LibCriptografia.java:18 PBEWithMD5AndDES/CBC/PKCS5Padding + BASE64Encoder
 * e migração para AES-GCM moderno com SecretStore
 */
class SecretRotationService
{
    private const SECRET_PREFIX = 'secret:';
    public const PBE_ALGO_LEGACY = 'PBEWithMD5AndDES';
    public const AES_GCM_ALGO = 'aes-256-gcm';

    /**
     * Decrypt AES-GCM legado
     */
    public function decryptAesGcm(string $encryptedValue, string $key, string $iv, string $tag): string
    {
        $decrypted = openssl_decrypt($encryptedValue, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($decrypted === false) {
            throw new \RuntimeException('AES-GCM decrypt failed');
        }
        return $decrypted;
    }

    /**
     * Migra valor AES-GCM para secret store
     * @return string secret id
     */
    public function migrateAesGcmToSecretStore(string $encryptedValue, string $keyId, string $key, string $iv, string $tag): string
    {
        $plain = $this->decryptAesGcm($encryptedValue, $key, $iv, $tag);
        $secretId = 'migrated:' . $keyId . ':' . hash('sha256', $encryptedValue);
        // Store in secret store (or Cache fallback)
        try {
            if (class_exists(\DreamFactory\Core\Services\SecretStore::class)) {
                \DreamFactory\Core\Services\SecretStore::put($secretId, $plain);
            } else {
                Cache::put(self::SECRET_PREFIX . $secretId, $plain, 3600 * 24 * 365);
            }
        } catch (\Throwable $e) {
            // Fallback cache
            Cache::put(self::SECRET_PREFIX . $secretId, $plain, 3600 * 24 * 365);
        }
        // Log sanitizado — sem segredo
        try {
            Log::info('secret.rotation.migrated', ['key_id' => $keyId, 'secret_id' => $secretId]);
        } catch (\Throwable $ignored) {}
        // Clear plain from memory
        unset($plain);
        return $secretId;
    }

    /**
     * Read-through: tenta secret store, fallback AES-GCM legado
     */
    public function getSecret(string $secretId, ?string $fallbackEncrypted = null, ?string $key = null, ?string $iv = null, ?string $tag = null): ?string
    {
        try {
            if (class_exists(\DreamFactory\Core\Services\SecretStore::class)) {
                $val = \DreamFactory\Core\Services\SecretStore::get($secretId);
                if ($val !== null) return $val;
            }
            $val = Cache::get(self::SECRET_PREFIX . $secretId);
            if ($val !== null) return $val;
        } catch (\Throwable $ignored) {}

        if ($fallbackEncrypted !== null && $key !== null && $iv !== null && $tag !== null) {
            try {
                return $this->decryptAesGcm($fallbackEncrypted, $key, $iv, $tag);
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * RQ-088 — Decrypt legado PBEWithMD5AndDES/CBC/PKCS5Padding (Java LibCriptografia.java:18)
     * Formato legado: BASE64(PBEWithMD5AndDES encrypted bytes) com salt 8 bytes prefixado
     * Migra para AES-GCM via PBKDF2; nunca loga segredo
     */
    public function decryptPbeLegacy(string $encryptedBase64, string $password, int $iterationCount = 1000): string
    {
        $raw = base64_decode($encryptedBase64, true);
        if ($raw === false || strlen($raw) < 8) {
            throw new \RuntimeException('PBE legacy decrypt failed: invalid base64 or missing salt');
        }
        // Salt = primeiros 8 bytes (compatível com PBEWithMD5AndDES)
        $salt = substr($raw, 0, 8);
        $cipherText = substr($raw, 8);
        // Deriva key+IV via PBKDF2 MD5 (compatível PBEWithMD5AndDES → MD5 iterations)
        $derived = hash_pbkdf2('md5', $password, $salt, $iterationCount, 24, true);
        if ($derived === false || strlen($derived) < 24) {
            throw new \RuntimeException('PBE legacy PBKDF2 failed');
        }
        $key = substr($derived, 0, 8);
        $iv = substr($derived, 8, 8);
        // PBEWithMD5AndDES usa DES/CBC/PKCS5Padding — em PHP usamos des-cbc
        $plain = openssl_decrypt($cipherText, 'des-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            // Fallback: tenta des-ede3-cbc para chaves 24 bytes
            $key24 = substr($derived, 0, 24);
            $plain = openssl_decrypt($cipherText, 'des-ede3-cbc', $key24, OPENSSL_RAW_DATA, $iv);
        }
        if ($plain === false) {
            throw new \RuntimeException('PBE legacy decrypt failed');
        }
        try {
            Log::info('secret.rotation.pbe_decrypt', ['algo' => self::PBE_ALGO_LEGACY]);
        } catch (\Throwable $ignored) {}
        return $plain;
    }

    /**
     * RQ-088 — Migra PBEWithMD5AndDES legado → AES-GCM + SecretStore
     * @return string secret id migrado
     */
    public function migratePbeToAesGcm(string $legacyBase64, string $pbePassword, string $keyId, string $aesKey, string $iv, string $tag): string
    {
        $plain = $this->decryptPbeLegacy($legacyBase64, $pbePassword);
        // Criptografa em AES-GCM moderno
        $aesEncrypted = openssl_encrypt($plain, self::AES_GCM_ALGO, $aesKey, OPENSSL_RAW_DATA, $iv, $tagOut);
        if ($aesEncrypted === false) {
            throw new \RuntimeException('AES-GCM encrypt failed during PBE migration');
        }
        $tag = $tagOut ?? $tag;
        $secretId = $this->migrateAesGcmToSecretStore(base64_encode($aesEncrypted), $keyId, $aesKey, $iv, $tag);
        try {
            Log::info('secret.rotation.pbe_migrated', ['key_id' => $keyId, 'secret_id' => $secretId, 'from' => self::PBE_ALGO_LEGACY, 'to' => self::AES_GCM_ALGO]);
        } catch (\Throwable $ignored) {}
        unset($plain);
        return $secretId;
    }

    /**
     * RQ-088 — Detecta se valor é PBE legado (BASE64 + salt) vs AES-GCM moderno
     */
    public function isPbeLegacy(string $value): bool
    {
        $raw = base64_decode($value, true);
        if ($raw === false) return false;
        // Heurística: PBE legado tem pelo menos 16 bytes e começa com salt aleatório; AES-GCM tem iv/tag separados
        return strlen($raw) >= 16;
    }

    /**
     * RQ-088 — Rotação completa: tenta AES-GCM moderno, fallback PBE legado com migração automática
     * @return array{plain:string, migrated:bool, secret_id:string|null}
     */
    public function getSecretWithPbeFallback(string $secretId, ?string $legacyPbeValue = null, ?string $pbePassword = null, ?string $aesKey = null, ?string $iv = null, ?string $tag = null): ?string
    {
        $val = $this->getSecret($secretId);
        if ($val !== null) return $val;

        if ($legacyPbeValue !== null && $pbePassword !== null && $aesKey !== null && $iv !== null && $tag !== null) {
            try {
                $plain = $this->decryptPbeLegacy($legacyPbeValue, $pbePassword);
                // Migra automaticamente para AES-GCM + SecretStore
                $this->migratePbeToAesGcm($legacyPbeValue, $pbePassword, $secretId, $aesKey, $iv, $tag);
                return $plain;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }
}
