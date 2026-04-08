<?php

namespace App\Http\Controllers;

use App\Services\EmailEncryptionService;
use App\Services\KyberEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Key-management and decryption API for Kyber-1024 email encryption.
 *
 * Routes (all require auth):
 *   POST   /api/keys/generate         — generate a new Kyber-1024 key pair
 *   POST   /api/keys/store            — store your public key on the server
 *   GET    /api/keys/{email}          — fetch a contact's public key
 *   DELETE /api/keys                  — delete your own public key
 *   POST   /api/email/decrypt         — decrypt a received encrypted-email package
 */
class EmailEncryptionController extends Controller
{
    public function __construct(
        private KyberEncryptionService  $kyber,
        private EmailEncryptionService  $encSvc,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Key generation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a fresh Kyber-1024 key pair.
     *
     * The SECRET KEY is returned ONCE and never stored on the server.
     * The user must save it securely — it cannot be recovered.
     */
    public function generateKeyPair(): JsonResponse
    {
        $pair = $this->kyber->generateKeyPair();

        return response()->json([
            'success'          => true,
            'public_key'       => $pair['public_key'],
            'secret_key'       => $pair['secret_key'],
            'public_key_size'  => KyberEncryptionService::PUBLICKEYBYTES . ' bytes',
            'secret_key_size'  => KyberEncryptionService::SECRETKEYBYTES . ' bytes',
            'cipher_suite'     => 'Kyber-1024 (NIST FIPS 203 ML-KEM-1024)',
            'warning'          => 'Save your secret key now — it will NOT be stored on the server.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Key storage
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store (or replace) the authenticated user's Kyber-1024 public key.
     * This allows others to send encrypted emails to this user.
     */
    public function storePublicKey(Request $request): JsonResponse
    {
        $request->validate([
            'public_key' => 'required|string',
        ]);

        $raw = base64_decode($request->input('public_key'), true);
        if ($raw === false || strlen($raw) !== KyberEncryptionService::PUBLICKEYBYTES) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid public key — expected a ' . KyberEncryptionService::PUBLICKEYBYTES . '-byte Kyber-1024 public key (base64-encoded).',
            ], 422);
        }

        $email = auth()->user()->email;
        $this->encSvc->storePublicKey($email, $request->input('public_key'));

        return response()->json([
            'success'         => true,
            'message'         => 'Public key stored for ' . $email,
            'key_fingerprint' => hash('sha256', $raw),
        ]);
    }

    /**
     * Retrieve the public key for any email address (so senders can encrypt to them).
     */
    public function getPublicKey(string $email): JsonResponse
    {
        $pk = $this->encSvc->getPublicKey($email);

        if (!$pk) {
            return response()->json([
                'success' => false,
                'error'   => 'No public key registered for ' . $email,
            ], 404);
        }

        $raw = base64_decode($pk);
        return response()->json([
            'success'         => true,
            'email'           => $email,
            'public_key'      => $pk,
            'key_fingerprint' => hash('sha256', $raw),
        ]);
    }

    /**
     * Delete the authenticated user's public key.
     */
    public function deletePublicKey(): JsonResponse
    {
        $email = auth()->user()->email;
        $this->encSvc->deletePublicKey($email);

        return response()->json([
            'success' => true,
            'message' => 'Public key deleted for ' . $email,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Decryption
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Decrypt a received encrypted-email package.
     *
     * The secret key is passed in the request body and is never persisted.
     * All decryption happens server-side in-memory for this request only.
     */
    public function decryptEmail(Request $request): JsonResponse
    {
        $request->validate([
            'secret_key' => 'required|string',
            'package'    => 'required|array',
            'package.version'        => 'required|string',
            'package.kem_ciphertext' => 'required|string',
            'package.iv'             => 'required|string',
            'package.ciphertext'     => 'required|string',
            'package.tag'            => 'required|string',
        ]);

        $raw = base64_decode($request->input('secret_key'), true);
        if ($raw === false || strlen($raw) !== KyberEncryptionService::SECRETKEYBYTES) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid secret key — expected a ' . KyberEncryptionService::SECRETKEYBYTES . '-byte Kyber-1024 secret key (base64-encoded).',
            ], 422);
        }

        try {
            $decrypted = $this->encSvc->decryptEmail(
                $request->input('package'),
                $request->input('secret_key')
            );

            return response()->json([
                'success'     => true,
                'subject'     => $decrypted['subject'],
                'body'        => $decrypted['body'],
                'attachments' => $decrypted['attachments'] ?? [],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 400);
        }
    }
}
