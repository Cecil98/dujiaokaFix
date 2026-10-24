<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class EncryptedOrPlaintext implements CastsAttributes
{
    /**
     * Decrypt Laravel payloads, but keep legacy plaintext values readable.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! $this->looksEncrypted((string) $value)) {
            return $value;
        }

        // Do not silently return ciphertext when APP_KEY is wrong: that would
        // break payment signing in a very hard-to-diagnose way.
        return Crypt::decryptString((string) $value);
    }

    /**
     * Encrypt new values, but never double-encrypt an existing payload.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if ($this->looksEncrypted((string) $value)) {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }

    public static function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
