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
    public function buildOutlookHtml(array $package, string $senderName = 'ClawYard', string $appUrl = ''): string
    {
        $appUrl     = 'https://clawyard.partyard.eu';
        // Encode JSON in URL hash — not sent to server, auto-fills /decrypt page
        $jsonRaw    = json_encode($package, JSON_UNESCAPED_SLASHES);
        $hash       = base64_encode($jsonRaw);
        $decryptUrl = $appUrl . '/decrypt#' . $hash;
        $json       = htmlspecialchars($jsonRaw, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!--[if gte mso 9]>
<xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<![endif]-->
<style type="text/css">
  body, #bodyTable { margin:0; padding:0; background:#f4f4f4; font-family:Arial,sans-serif; }
  .wrapper  { max-width:620px; margin:30px auto; background:#fff; border-radius:8px; overflow:hidden; }
  .header   { background:#001f3f; padding:24px 32px; }
  .header p { margin:0; color:#76b900; font-size:20px; font-weight:bold; }
  .header small { color:#aaa; font-size:11px; display:block; margin-top:3px; }
  .content  { padding:28px 32px; }
  .badge    { display:inline-block; background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7;
              border-radius:4px; padding:3px 10px; font-size:12px; font-weight:bold; margin-bottom:16px; }
  p  { color:#444; font-size:14px; line-height:1.7; margin:0 0 12px; }
  .cta-btn  { display:inline-block; background:#76b900; color:#fff !important; text-decoration:none;
              padding:14px 36px; border-radius:6px; font-size:16px; font-weight:bold; margin:8px 0 20px; }
  .copy-btn { display:inline-block; background:#f0f0f0; color:#333 !important; text-decoration:none;
              padding:10px 24px; border-radius:6px; font-size:14px; margin-left:10px; }
  .blob-box { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;
              padding:14px; font-family:'Courier New',monospace; font-size:10px;
              color:#555; word-break:break-all; white-space:pre-wrap; margin:16px 0 8px; }
  .footer   { border-top:1px solid #eee; padding:16px 32px; font-size:11px; color:#aaa; }
</style>
</head>
<body>
<table id="bodyTable" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" style="padding:20px 12px;">
  <div class="wrapper">
    <div class="header">
      <p>🐾 ClawYard</p>
      <small>Mensagem encriptada · Kyber-1024 + AES-256-GCM</small>
    </div>
    <div class="content">
      <span class="badge">🔒 MENSAGEM ENCRIPTADA</span>
      <p>Recebeste uma mensagem encriptada de <strong>{$senderName}</strong>.<br>
         Clica no botão para a desencriptar — o conteúdo é carregado automaticamente.</p>

      <a class="cta-btn" href="{$decryptUrl}" target="_blank">🔓 Abrir e Desencriptar →</a>

      <p style="font-size:12px;color:#999;margin:0 0 6px">Em alternativa, copia o bloco abaixo e cola em <a href="{$appUrl}/decrypt" style="color:#76b900">{$appUrl}/decrypt</a></p>
      <div class="blob-box">{$json}</div>
    </div>
    <div class="footer">
      ClawYard · IT Partyard LDA · Setúbal, Portugal
    </div>
  </div>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
