<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Drop-in replacement for Laravel's built-in `encrypted:array` cast that
 * never throws DecryptException on reads.
 *
 * See SafeEncryptedString for rationale — same story: rows written under
 * a rotated APP_KEY crash JSON serialisation otherwise.
 *
 * On decrypt failure we return a SMALL marker array rather than null so
 * downstream code that expects array-access keeps working (no
 * `foreach (null as ...)` warnings, no null-dereference on $meta['x']).
 */
class SafeEncryptedArray implements CastsAttributes
{
    public const PLACEHOLDER = [
        '_encrypted_fallback' => true,
        '_note'               => 'APP_KEY rodada — metadata original não recuperável',
    ];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $plain = Crypt::decryptString((string) $value);
        } catch (DecryptException $e) {
            Log::debug('SafeEncryptedArray: decrypt failed', [
                'model'   => get_class($model),
                'id'      => $model->getKey(),
                'field'   => $key,
                'message' => $e->getMessage(),
            ]);
            return self::PLACEHOLDER;
        }

        $decoded = json_decode($plain, true);
        // If decryption worked but the JSON is corrupt, keep the
        // fallback shape rather than returning null (same reasoning).
        return is_array($decoded) ? $decoded : self::PLACEHOLDER;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // CRITICAL: never re-encrypt our placeholder over the original
        // ciphertext — see SafeEncryptedString::set() for full rationale.
        if ($value === self::PLACEHOLDER && isset($attributes[$key])) {
            return (string) $attributes[$key];
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
