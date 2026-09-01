<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RQ-081 — Migração AES-GCM -> secret store com read-through
 * Nunca loga segredo, apenas key_id e contagens
 */
class SecretRotationService
{
    private const SECRET_PREFIX = 'secret:';

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
}
