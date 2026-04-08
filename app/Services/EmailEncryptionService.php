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
    public function encryptEmail(string $subject, string $body, string $publicKeyB64): array
    {
        // 1. Kyber KEM → shared secret
        ['ciphertext' => $kemCt, 'shared_secret' => $ssB64] = $this->kyber->encapsulate($publicKeyB64);
        $sharedSecret = base64_decode($ssB64);

        // 2. HKDF → AES-256-GCM key
        $aesKey = hash_hkdf('sha256', $sharedSecret, 32, self::INFO_STRING, '');

        // 3. AES-256-GCM encrypt the message
        $plaintext = json_encode(['subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE);
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

        return ['subject' => $data['subject'], 'body' => $data['body']];
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
    public function buildOutlookHtml(array $package, string $senderName = 'ClawYard Maritime', string $appUrl = ''): string
    {
        $appUrl    = $appUrl ?: config('app.url', 'https://clawyard.com');
        $json      = htmlspecialchars(json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $timestamp = $package['encrypted_at'] ?? now()->toIso8601String();
        $fingerprint = substr($package['key_fingerprint'] ?? '', 0, 16) . '…';
        $compactJson = htmlspecialchars(json_encode($package), ENT_QUOTES, 'UTF-8');

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
  .wrapper { max-width:680px; margin:30px auto; background:#fff; border-radius:8px; overflow:hidden; }
  .header  { background:#001f3f; padding:28px 36px; }
  .header-title { color:#76b900; font-size:22px; font-weight:bold; margin:0; }
  .header-sub   { color:#aaa; font-size:12px; margin:4px 0 0; }
  .content { padding:32px 36px; }
  .badge   { display:inline-block; background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7;
             border-radius:4px; padding:3px 10px; font-size:12px; font-weight:bold; margin-bottom:18px; }
  h2 { color:#001f3f; font-size:18px; margin:0 0 12px; }
  p  { color:#444; font-size:14px; line-height:1.7; margin:0 0 14px; }
  .meta-table { width:100%; border-collapse:collapse; font-size:12px; color:#888; margin-bottom:20px; }
  .meta-table td { padding:3px 0; }
  .meta-table td:first-child { width:140px; }
  .blob-box { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;
              padding:16px; font-family:'Courier New',monospace; font-size:11px;
              color:#333; word-break:break-all; white-space:pre-wrap; margin:0 0 20px; }
  .cta-btn  { display:inline-block; background:#76b900; color:#fff; text-decoration:none;
              padding:12px 28px; border-radius:6px; font-size:14px; font-weight:bold;
              margin-bottom:24px; }
  .steps ol { color:#444; font-size:14px; padding-left:22px; margin:0; }
  .steps li { margin-bottom:8px; line-height:1.6; }
  .footer   { border-top:1px solid #eee; padding:20px 36px; font-size:11px; color:#aaa; }
</style>
</head>
<body>
<table id="bodyTable" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" style="padding:20px 12px;">
  <div class="wrapper">
    <!-- Header -->
    <div class="header">
      <p class="header-title">🐾 ClawYard Maritime</p>
      <p class="header-sub">Post-Quantum Encrypted Message · Kyber-1024 + AES-256-GCM</p>
    </div>

    <!-- Body -->
    <div class="content">
      <span class="badge">🔒 KYBER-1024 ENCRYPTED</span>

      <h2>You have received an encrypted message</h2>
      <p>
        This email was encrypted end-to-end using <strong>CRYSTALS-Kyber 1024</strong>
        (NIST FIPS 203 post-quantum KEM) combined with <strong>AES-256-GCM</strong>
        authenticated encryption.  Only you — the holder of the matching Kyber-1024
        secret key — can decrypt its contents.
      </p>

      <table class="meta-table">
        <tr><td><strong>Sender</strong></td><td>{$senderName}</td></tr>
        <tr><td><strong>Encrypted at</strong></td><td>{$timestamp}</td></tr>
        <tr><td><strong>Key fingerprint</strong></td><td style="font-family:monospace">{$fingerprint}</td></tr>
        <tr><td><strong>Cipher suite</strong></td><td>Kyber-1024 + HKDF-SHA-256 + AES-256-GCM</td></tr>
      </table>

      <!-- Encrypted payload -->
      <p><strong>Encrypted payload</strong> — copy the block below to decrypt:</p>
      <div class="blob-box">{$json}</div>

      <!-- CTA -->
      <a class="cta-btn" href="{$appUrl}/decrypt" target="_blank">Decrypt on ClawYard →</a>

      <div class="steps">
        <p><strong>How to decrypt:</strong></p>
        <ol>
          <li>Open <a href="{$appUrl}" target="_blank" style="color:#76b900">{$appUrl}</a> and log in.</li>
          <li>Navigate to <em>Profile → Decrypt Message</em>.</li>
          <li>Paste your <strong>Kyber-1024 secret key</strong> and the encrypted payload above.</li>
          <li>Click <strong>Decrypt</strong> to reveal the original message.</li>
        </ol>
      </div>
    </div>

    <!-- Footer -->
    <div class="footer">
      ClawYard Maritime &nbsp;|&nbsp; HP-Group / IT Partyard LDA<br>
      Setúbal, Portugal &nbsp;·&nbsp; <a href="mailto:info@clawyard.com" style="color:#76b900">info@clawyard.com</a><br>
      <br>
      This message uses CRYSTALS-Kyber 1024 (NIST FIPS 203) and AES-256-GCM.<br>
      If you did not request this message, please disregard it.
    </div>
  </div>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
