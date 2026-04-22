<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Drop-in replacement for Laravel's built-in `encrypted` cast that never
 * throws DecryptException on reads.
 *
 * Context: on 2026-04-22 we discovered that ~98% of Message rows were
 * written under a PREVIOUS APP_KEY that no longer exists — so the default
 * `encrypted` cast crashes any controller that serialises the model
 * (notably NvidiaController::history()). This cast swallows the decrypt
 * error and returns a human-readable placeholder, so the endpoint keeps
 * working while we decide whether to wipe the corrupted rows or recover
 * the old key.
 *
 * Writes still use the CURRENT APP_KEY via Crypt::encryptString() — so
 * any NEW row saved through the model stays fully encrypted and will
 * decrypt correctly. Only legacy rows show the placeholder.
 *
 * To revert to strict behaviour: swap the `$casts` entries back to
 * 'encrypted' and restore the failing rows with the old APP_KEY.
 */
class SafeEncryptedString implements CastsAttributes
{
    public const PLACEHOLDER = '[conteúdo não recuperável — APP_KEY rodada]';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException $e) {
            // Log once-per-request-per-row at debug so we can count damage
            // without spamming the log. In production we expect thousands
            // of these on every history() call until the rows are cleaned.
            Log::debug('SafeEncryptedString: decrypt failed', [
                'model'   => get_class($model),
                'id'      => $model->getKey(),
                'field'   => $key,
                'message' => $e->getMessage(),
            ]);
            return self::PLACEHOLDER;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // CRITICAL: if the caller is trying to "save" the placeholder we
        // returned from get(), that means this row was unreadable and
        // someone's just re-saving the model with another field changed
        // (e.g. updated_at). We MUST NOT overwrite the original ciphertext
        // with an encryption of "[conteúdo não recuperável…]" — doing so
        // would destroy any chance of recovering the content if the old
        // APP_KEY is ever found. Return the raw stored ciphertext unchanged.
        if ($value === self::PLACEHOLDER && isset($attributes[$key])) {
            return (string) $attributes[$key];
        }

        return Crypt::encryptString((string) $value);
    }
}
