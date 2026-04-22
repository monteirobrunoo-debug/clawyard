<?php

namespace App\Support;

/**
 * PII redactor — scrubs sensitive tokens out of prompts/messages before they
 * leave the server (e.g. before Http::post to Anthropic). Opt-in via
 * config('services.anthropic.redact_pii'). The redaction is one-way: the
 * scrubbed prompt goes upstream; the original stays in the Laravel request
 * and gets persisted (encrypted) in messages.content.
 *
 * Rules are deliberately conservative — we'd rather let a legitimate token
 * through than strip a technical term (e.g. an IEC-number, NSN). If you
 * need stricter redaction toggle the aggressive profile via
 * config('services.anthropic.redact_profile', 'aggressive').
 */
class PiiRedactor
{
    /** Apply redaction to a free-form string. */
    public static function scrub(string $text): string
    {
        if ($text === '') return $text;

        // Credit / debit card numbers (13–19 digits, optional separators).
        // Anchored to digit clusters so NSN codes (usually 13 digits no
        // spaces, but uniquely prefixed "NSN") are left alone.
        $text = preg_replace_callback(
            '/\b(?:\d[ \-]?){12,18}\d\b/',
            fn($m) => self::looksLikeCard($m[0]) ? '[CARD_REDACTED]' : $m[0],
            $text
        );

        // IBAN (country-code + 2 check digits + up to 30 alphanum, common formats)
        $text = preg_replace(
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
            '[IBAN_REDACTED]',
            $text
        );

        // Portuguese NIF/NIPC — exactly 9 digits, not preceded by another digit.
        // Protect against false-positives on SAP doc-nums by requiring word-
        // boundary + the "NIF" or "contribuinte" token nearby OR a bare 9-digit
        // run in email/signature-like contexts.
        $text = preg_replace_callback(
            '/(?<!\d)(\d{9})(?!\d)/',
            function ($m) {
                // Strong Portuguese VAT validation — the check-digit rule means
                // random 9-digit NSN-like codes almost never pass.
                return self::isValidPortugueseNif($m[1]) ? '[NIF_REDACTED]' : $m[0];
            },
            $text
        );

        // Email addresses — keep the domain so the model still understands
        // the context ("the client from @oceanpact.com") but hide the user.
        $text = preg_replace(
            '/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            '[EMAIL_REDACTED]@$2',
            $text
        );

        // International phone numbers — keep the country code for context,
        // mask the rest. Handles +351 900 000 000 / 00351-900-000-000 /
        // (+44) 20 7946 0958 etc. Requires at least 8 digits total so NSN
        // and port-call reference numbers are not caught.
        $text = preg_replace_callback(
            '/(?:\+|00)\s*(\d{1,3})[\s\-().]*((?:\d[\s\-().]*){7,14})/',
            fn($m) => '+' . $m[1] . ' [PHONE_REDACTED]',
            $text
        );

        // Portuguese national ID (Cartão de Cidadão) — 8 digits + 1 digit + 2 letters
        $text = preg_replace(
            '/\b\d{8}\s*\d\s*[A-Z]{2}\d\b/',
            '[CC_REDACTED]',
            $text
        );

        // Generic passwords / secrets in key=value syntax
        $text = preg_replace(
            '/\b(password|passwd|pwd|secret|token|apikey|api_key|bearer)\s*[:=]\s*[^\s,;}]+/i',
            '$1=[REDACTED]',
            $text
        );

        // Private keys (PEM headers) — zap the whole block
        $text = preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
            '[PRIVATE_KEY_REDACTED]',
            $text
        );

        return $text;
    }

    /**
     * Apply scrub() to a Claude-style message payload. Handles both:
     *   [ ['role' => 'user', 'content' => 'string'], ... ]
     *   [ ['role' => 'user', 'content' => [ ['type'=>'text','text'=>'...'], ['type'=>'document',...] ]], ... ]
     *
     * Binary blocks (documents/images) are left untouched — the redactor
     * only works on text. If you need PDF-content redaction run a server-
     * side PDF parser first and scrub the extracted text.
     */
    public static function scrubMessages(array $messages): array
    {
        foreach ($messages as &$msg) {
            if (!isset($msg['content'])) continue;
            if (is_string($msg['content'])) {
                $msg['content'] = self::scrub($msg['content']);
                continue;
            }
            if (is_array($msg['content'])) {
                foreach ($msg['content'] as &$block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $block['text'] = self::scrub($block['text']);
                    }
                }
                unset($block);
            }
        }
        unset($msg);
        return $messages;
    }

    /** Luhn check to distinguish real card numbers from random 13–19 digit runs. */
    protected static function looksLikeCard(string $raw): bool
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 13 || strlen($digits) > 19) return false;
        $sum = 0; $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
            $sum += $n;
            $alt = !$alt;
        }
        return ($sum % 10) === 0;
    }

    /** Portuguese NIF check-digit (modulo 11 weighted). */
    protected static function isValidPortugueseNif(string $nif): bool
    {
        if (!preg_match('/^\d{9}$/', $nif)) return false;
        // Valid NIF prefixes: 1,2,3 (individuals), 5 (companies), 6 (public),
        // 8 (sole proprietor), 9 (provisional). Reject 0/4/7 — usually coded
        // identifiers (NSN, SKU) start there.
        if (!in_array($nif[0], ['1','2','3','5','6','8','9'], true)) return false;
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }
        $check = 11 - ($sum % 11);
        if ($check >= 10) $check = 0;
        return $check === (int) $nif[8];
    }
}
