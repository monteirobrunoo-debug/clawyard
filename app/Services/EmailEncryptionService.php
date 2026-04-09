<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hybrid post-quantum email encryption.
 *
 * Cipher design:
 *   1. Kyber-1024 KEM  → 32-byte shared secret (post-quantum key exchange)
 *   2. HKDF-SHA-256    → 32-byte AES-256-GCM key  (key derivation)
 *   3. AES-256-GCM     → encrypt subject + body    (authenticated encryption)
 *
 * The encrypted payload is embedded in a standard HTML email body, fully
 * readable by Outlook, Gmail and any RFC-5322-compliant mail client.
 * Decryption requires the recipient's Kyber-1024 secret key.
 */
class EmailEncryptionService
{
    private const CIPHER_SUITE = 'kyber1024-aes256gcm-v1';
    private const INFO_STRING  = 'clawyard-email-encryption-v1';

    public function __construct(private KyberEncryptionService $kyber) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Key management
    // ─────────────────────────────────────────────────────────────────────────

    /** Persist a recipient public key (upsert by email). */
    public function storePublicKey(string $email, string $publicKeyB64): void
    {
        $raw         = base64_decode($publicKeyB64);
        $fingerprint = hash('sha256', $raw);

        DB::table('email_encryption_keys')->upsert(
            [
                'email'           => strtolower(trim($email)),
                'public_key'      => $publicKeyB64,
                'key_fingerprint' => $fingerprint,
                'updated_at'      => now(),
                'created_at'      => now(),
            ],
            ['email'],
            ['public_key', 'key_fingerprint', 'updated_at']
        );
    }

    /** Retrieve public key for a given email address, or null if not found. */
    public function getPublicKey(string $email): ?string
    {
        $row = DB::table('email_encryption_keys')
            ->where('email', strtolower(trim($email)))
            ->first();

        return $row?->public_key;
    }

    /** Delete a stored public key. */
    public function deletePublicKey(string $email): void
    {
        DB::table('email_encryption_keys')
            ->where('email', strtolower(trim($email)))
            ->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Package storage (short-token URLs — safe for email clients)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persist an encrypted package and return a short token.
     * Used when the payload is too large for a URL hash (e.g. has attachments).
     */
    public function storePackage(array $package): string
    {
        do {
            $token = bin2hex(random_bytes(6)); // 12 hex chars
        } while (DB::table('encrypted_email_packages')->where('token', $token)->exists());

        DB::table('encrypted_email_packages')->insert([
            'token'      => $token,
            'package'    => json_encode($package, JSON_UNESCAPED_SLASHES),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        return $token;
    }

    /** Retrieve a stored package by token, or null if not found / expired. */
    public function getPackageByToken(string $token): ?array
    {
        $row = DB::table('encrypted_email_packages')
            ->where('token', $token)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        return $row ? json_decode($row->package, true) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Encryption
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Encrypt an email for a recipient using their Kyber-1024 public key.
     *
     * @param  string  $subject        Plain-text subject.
     * @param  string  $body           Plain-text / HTML body.
     * @param  string  $publicKeyB64   Recipient's base64-encoded Kyber-1024 public key.
     * @return array   Encrypted package (all fields are base64 strings).
     */
    /**
     * @param  array  $attachments  Optional list of attachments to encrypt inside the payload.
     *                              Each entry: ['name' => string, 'mime' => string, 'data' => base64-encoded bytes]
     */
    public function encryptEmail(string $subject, string $body, string $publicKeyB64, array $attachments = []): array
    {
        // 1. Kyber KEM → shared secret
        ['ciphertext' => $kemCt, 'shared_secret' => $ssB64] = $this->kyber->encapsulate($publicKeyB64);
        $sharedSecret = base64_decode($ssB64);

        // 2. HKDF → AES-256-GCM key
        $aesKey = hash_hkdf('sha256', $sharedSecret, 32, self::INFO_STRING, '');

        // 3. AES-256-GCM encrypt the message (+ attachments inside the payload)
        $payload = ['subject' => $subject, 'body' => $body];
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }
        $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $iv        = random_bytes(12);
        $tag       = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed: ' . openssl_error_string());
        }

        $fingerprint = hash('sha256', base64_decode($publicKeyB64));

        return [
            'version'            => self::CIPHER_SUITE,
            'kem_ciphertext'     => $kemCt,
            'iv'                 => base64_encode($iv),
            'ciphertext'         => base64_encode($ciphertext),
            'tag'                => base64_encode($tag),
            'key_fingerprint'    => $fingerprint,
            'encrypted_at'       => now()->toIso8601String(),
        ];
    }

    /**
     * Decrypt an encrypted email package using the recipient's secret key.
     *
     * @param  array   $package        Encrypted package returned by encryptEmail().
     * @param  string  $secretKeyB64   Recipient's base64-encoded Kyber-1024 secret key.
     * @return array{subject: string, body: string}
     * @throws \RuntimeException on decryption failure.
     */
    public function decryptEmail(array $package, string $secretKeyB64): array
    {
        if (($package['version'] ?? '') !== self::CIPHER_SUITE) {
            throw new \RuntimeException('Unsupported cipher suite: ' . ($package['version'] ?? 'unknown'));
        }

        // 1. Kyber KEM decapsulate → shared secret
        $ssB64        = $this->kyber->decapsulate($secretKeyB64, $package['kem_ciphertext']);
        $sharedSecret = base64_decode($ssB64);

        // 2. HKDF → AES-256-GCM key
        $aesKey = hash_hkdf('sha256', $sharedSecret, 32, self::INFO_STRING, '');

        // 3. AES-256-GCM decrypt
        $plaintext = openssl_decrypt(
            base64_decode($package['ciphertext']),
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            base64_decode($package['iv']),
            base64_decode($package['tag'])
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — wrong key or tampered ciphertext.');
        }

        $data = json_decode($plaintext, true);
        if (!isset($data['subject'], $data['body'])) {
            throw new \RuntimeException('Decrypted payload has unexpected format.');
        }

        return [
            'subject'     => $data['subject'],
            'body'        => $data['body'],
            'attachments' => $data['attachments'] ?? [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Outlook-compatible HTML email builder
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build an Outlook-compatible HTML email body for an encrypted message.
     *
     * The body uses a standard HTML layout that renders correctly in Outlook,
     * Gmail, Apple Mail and other RFC-5322 clients.  The encrypted JSON blob
     * is embedded as a visually-separated block so the recipient can copy it
     * into the ClawYard decryption interface.
     *
     * @param  array   $package     Encrypted package from encryptEmail().
     * @param  string  $senderName  Display name of the sender.
     * @param  string  $appUrl      Base URL of the ClawYard instance.
     * @return string  Complete HTML email body (inline styles, Outlook-safe).
     */
    public function buildOutlookHtml(array $package, string $senderName = 'ClawYard', string $appUrl = '', string $token = ''): string
    {
        $appUrl  = 'https://clawyard.partyard.eu';
        $jsonRaw = json_encode($package, JSON_UNESCAPED_SLASHES);
        $json    = htmlspecialchars($jsonRaw, ENT_QUOTES, 'UTF-8');

        // Use short token URL when available (safe for email clients with large payloads)
        // Otherwise fall back to URL hash (package entirely client-side, no server storage)
        if ($token !== '') {
            $decryptUrl = $appUrl . '/decrypt/' . $token;
        } else {
            $hash       = base64_encode($jsonRaw);
            $decryptUrl = $appUrl . '/decrypt#' . $hash;
        }

        return <<<HTML
<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!--[if gte mso 9]>
<xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<![endif]-->
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;">
<tr><td align="center" style="padding:30px 12px;">

  <!-- WRAPPER -->
  <table width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

    <!-- HEADER -->
    <tr>
      <td style="background:#001f3f;padding:24px 32px;">
        <div style="color:#76b900;font-size:20px;font-weight:bold;margin:0;">🔒 Secure Channel</div>
        <div style="color:#aaa;font-size:11px;margin-top:4px;">Kyber-1024 / AES-256-GCM / NIST FIPS 203 Compliant</div>
      </td>
    </tr>

    <!-- CONTENT -->
    <tr>
      <td style="padding:32px 32px 24px;">

        <!-- Badge -->
        <div style="display:inline-block;background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:4px;padding:4px 12px;font-size:12px;font-weight:bold;margin-bottom:20px;">
          🔒 MENSAGEM ENCRIPTADA
        </div>

        <!-- Intro text -->
        <p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 8px;">
          Recebeste uma mensagem encriptada de <strong>{$senderName}</strong>.
        </p>
        <p style="color:#666;font-size:13px;line-height:1.6;margin:0 0 28px;">
          Clica no botão abaixo — o conteúdo encriptado é carregado automaticamente.<br>
          Só precisas de colar o teu <strong>Secret Key</strong> para desencriptar.
        </p>

        <!-- PRIMARY CTA — all inline styles, works in Outlook/Gmail/Apple Mail -->
        <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
          <tr>
            <td style="background:#76b900;border-radius:6px;" align="center">
              <a href="{$decryptUrl}" target="_blank"
                 style="display:inline-block;background:#76b900;color:#ffffff;text-decoration:none;padding:16px 44px;border-radius:6px;font-size:16px;font-weight:bold;font-family:Arial,sans-serif;letter-spacing:0.3px;">
                🔓 Abrir e Desencriptar
              </a>
            </td>
          </tr>
        </table>

        <!-- Divider -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
          <tr>
            <td style="border-top:1px solid #eee;font-size:0;">&nbsp;</td>
          </tr>
        </table>

        <!-- Fallback instructions -->
        <p style="color:#999;font-size:12px;margin:0 0 8px;">
          Se o botão não funcionar, vai a
          <a href="{$appUrl}/decrypt" style="color:#76b900;text-decoration:none;">{$appUrl}/decrypt</a>
          e cola manualmente o bloco JSON abaixo:
        </p>

        <!-- JSON blob — plain text fallback, no JS needed -->
        <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:14px;font-family:'Courier New',Courier,monospace;font-size:10px;color:#555;word-break:break-all;white-space:pre-wrap;line-height:1.5;">
{$json}
        </div>

      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td style="border-top:1px solid #eee;padding:14px 32px;">
        <p style="margin:0;font-size:11px;color:#aaa;">ClawYard · IT Partyard LDA · Setúbal, Portugal</p>
      </td>
    </tr>

  </table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
