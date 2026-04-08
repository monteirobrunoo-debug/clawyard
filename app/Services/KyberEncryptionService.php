<?php

namespace App\Services;

/**
 * CRYSTALS-Kyber 1024 (ML-KEM-1024) Post-Quantum KEM
 *
 * Pure-PHP port of the reference C implementation.
 * Security level: AES-256 equivalent (NIST Category 5).
 *
 * Parameters: n=256, q=3329, k=4, η₁=2, η₂=2, du=11, dv=5
 */
class KyberEncryptionService
{
    // ── Kyber-1024 domain parameters ──────────────────────────────────────────
    private const Q    = 3329;
    private const N    = 256;
    private const K    = 4;
    private const ETA1 = 2;
    private const ETA2 = 2;
    private const DU   = 11;
    private const DV   = 5;

    // Montgomery constants
    private const QINV  = 62209;  // q^{-1} mod 2^16
    private const INV_F = 1441;   // normalisation for INTT (R^2 / 128 mod q in mont form)
    private const TMONT = 1353;   // R^2 mod q = 2^32 mod q (for poly_tomont)

    // Byte sizes
    public const POLYBYTES    = 384;
    public const POLYVECBYTES = self::K * self::POLYBYTES;            // 1536
    private const POLYVECC_B  = self::K * 352;                        // 1408 (du=11)
    private const POLYC_B     = 160;                                   // (dv=5)

    public const PUBLICKEYBYTES    = self::POLYVECBYTES + 32;         // 1568
    public const SECRETKEYBYTES    = self::POLYVECBYTES               // 3168
                                   + self::PUBLICKEYBYTES + 64;       // +H(pk)+z
    public const CIPHERTEXTBYTES   = self::POLYVECC_B + self::POLYC_B; // 1568
    public const SHAREDSECRETBYTES = 32;

    // ── NTT zetas: ζ^{brv7(k)} * R  mod q  (Montgomery form) ─────────────────
    private static array $Z = [
        -1044,  -758,  -359, -1517,  1493,  1422,   287,   202,
         -171,   622,  1577,   182,   962, -1202, -1474,  1468,
          573, -1325,   264,   383,  -829,  1458, -1602,  -130,
         -681,  1017,   732,   608, -1542,   411,  -205, -1571,
         1223,   652,  -552,  1015, -1293,  1491,  -282, -1544,
          516,    -8,  -320,  -666, -1618, -1162,   126,  1469,
         -853,   -90,  -271,   830,   107, -1421,  -247,  -951,
         -398,   961, -1508,  -725,   448, -1065,   677, -1275,
        -1103,   430,   555,   843, -1251,   871,  1550,   105,
          422,   587,   177,  -235,  -291,  -460,  1574,  1653,
         -246,   778,  1159,  -147,  -777,  1483,  -602,  1119,
        -1590,   644,  -872,   349,   418,   329,  -156,   -75,
          817,  1097,   603,   610,  1322, -1285, -1465,   384,
        -1215,  -136,  1218, -1335,  -874,   220, -1187, -1659,
        -1185, -1530, -1278,   794, -1510,  -854,  -870,   478,
         -108,  -308,   996,   991,   958, -1460,  1522,  1628,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Public KEM API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a Kyber-1024 key pair.
     *
     * @return array{public_key: string, secret_key: string}  Base64-encoded.
     */
    public function generateKeyPair(): array
    {
        $d    = random_bytes(32);
        $z    = random_bytes(32);
        [$pk, $sk_inner] = $this->indcpaKeypair($d);
        $H_pk = hash('sha3-256', $pk, true);
        $sk   = $sk_inner . $pk . $H_pk . $z;

        return [
            'public_key' => base64_encode($pk),
            'secret_key' => base64_encode($sk),
        ];
    }

    /**
     * Encapsulate: produce shared secret + ciphertext from a public key.
     *
     * @param  string  $publicKeyB64  Base64-encoded 1568-byte public key.
     * @return array{ciphertext: string, shared_secret: string}  Base64.
     */
    public function encapsulate(string $publicKeyB64): array
    {
        $pk    = base64_decode($publicKeyB64);
        $m     = random_bytes(32);
        $H_pk  = hash('sha3-256', $pk, true);
        $G     = hash('sha3-512', $m . $H_pk, true);
        $K_bar = substr($G, 0, 32);
        $r     = substr($G, 32, 32);
        $ct    = $this->indcpaEnc($pk, $m, $r);
        $H_ct  = hash('sha3-256', $ct, true);
        $K     = hash('shake256', $K_bar . $H_ct, true, ['length' => 32]);

        return [
            'ciphertext'    => base64_encode($ct),
            'shared_secret' => base64_encode($K),
        ];
    }

    /**
     * Decapsulate: recover shared secret from secret key + ciphertext.
     *
     * @param  string  $secretKeyB64   Base64 3168-byte secret key.
     * @param  string  $ciphertextB64  Base64 1568-byte ciphertext.
     * @return string  Base64-encoded 32-byte shared secret.
     */
    public function decapsulate(string $secretKeyB64, string $ciphertextB64): string
    {
        $sk = base64_decode($secretKeyB64);
        $ct = base64_decode($ciphertextB64);

        $sk_inner = substr($sk, 0, self::POLYVECBYTES);
        $pk       = substr($sk, self::POLYVECBYTES, self::PUBLICKEYBYTES);
        $H_pk     = substr($sk, self::POLYVECBYTES + self::PUBLICKEYBYTES, 32);
        $z        = substr($sk, self::POLYVECBYTES + self::PUBLICKEYBYTES + 32, 32);

        $m_prime  = $this->indcpaDec($sk_inner, $ct);
        $G        = hash('sha3-512', $m_prime . $H_pk, true);
        $K_bar    = substr($G, 0, 32);
        $r_prime  = substr($G, 32, 32);
        $ct_prime = $this->indcpaEnc($pk, $m_prime, $r_prime);
        $H_ct     = hash('sha3-256', $ct, true);

        // Implicit rejection: use z on mismatch (constant-time select)
        $K_in = hash_equals($ct, $ct_prime) ? $K_bar : $z;
        $K    = hash('shake256', $K_in . $H_ct, true, ['length' => 32]);

        return base64_encode($K);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IND-CPA PKE
    // ─────────────────────────────────────────────────────────────────────────

    private function indcpaKeypair(string $d): array
    {
        $G     = hash('sha3-512', $d . chr(self::K), true);
        $rho   = substr($G, 0, 32);
        $sigma = substr($G, 32, 32);

        $A = $this->genMatrix($rho, false); // A (not transposed)

        $nonce = 0;
        $s = $e = [];
        for ($i = 0; $i < self::K; $i++) {
            $s[] = $this->polyGetNoise($sigma, $nonce++, self::ETA1);
        }
        for ($i = 0; $i < self::K; $i++) {
            $e[] = $this->polyGetNoise($sigma, $nonce++, self::ETA1);
        }

        $s_hat = array_map([$this, 'polyNTT'], $s);
        $e_hat = array_map([$this, 'polyNTT'], $e);

        // t_hat = A * s_hat + e_hat  (all in NTT domain)
        $t = [];
        for ($i = 0; $i < self::K; $i++) {
            $acc  = $this->polyvecBaseMulAcc($A[$i], $s_hat);
            $acc  = $this->polyToMont($acc);
            $t[$i] = $this->polyReduce($this->polyAdd($acc, $e_hat[$i]));
        }

        return [
            $this->polyvecToBytes($t) . $rho,  // public key
            $this->polyvecToBytes($s_hat),      // secret key
        ];
    }

    private function indcpaEnc(string $pk, string $m, string $coins): string
    {
        $t_hat = $this->polyvecFromBytes(substr($pk, 0, self::POLYVECBYTES));
        $rho   = substr($pk, self::POLYVECBYTES, 32);
        $At    = $this->genMatrix($rho, true); // A^T

        $nonce = 0;
        $r = $e1 = [];
        for ($i = 0; $i < self::K; $i++) {
            $r[]  = $this->polyGetNoise($coins, $nonce++, self::ETA1);
        }
        for ($i = 0; $i < self::K; $i++) {
            $e1[] = $this->polyGetNoise($coins, $nonce++, self::ETA2);
        }
        $e2 = $this->polyGetNoise($coins, $nonce, self::ETA2);

        $r_hat = array_map([$this, 'polyNTT'], $r);

        // u = INTT(A^T * r_hat) + e1
        $u = [];
        for ($i = 0; $i < self::K; $i++) {
            $u[$i] = $this->polyReduce(
                $this->polyAdd($this->polyInvNTT($this->polyvecBaseMulAcc($At[$i], $r_hat)), $e1[$i])
            );
        }

        // v = INTT(t_hat^T * r_hat) + e2 + msg
        $msg = $this->polyFromMsg($m);
        $v   = $this->polyReduce(
            $this->polyAdd(
                $this->polyAdd($this->polyInvNTT($this->polyvecBaseMulAcc($t_hat, $r_hat)), $e2),
                $msg
            )
        );

        return $this->polyvecCompress($u) . $this->polyCompress($v);
    }

    private function indcpaDec(string $sk, string $ct): string
    {
        $u     = $this->polyvecDecompress(substr($ct, 0, self::POLYVECC_B));
        $v     = $this->polyDecompress(substr($ct, self::POLYVECC_B));
        $s_hat = $this->polyvecFromBytes(substr($sk, 0, self::POLYVECBYTES));
        $u_hat = array_map([$this, 'polyNTT'], $u);
        $mp    = $this->polyReduce(
            $this->polySub($v, $this->polyInvNTT($this->polyvecBaseMulAcc($s_hat, $u_hat)))
        );
        return $this->polyToMsg($mp);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Matrix generation (SHAKE-128 rejection sampling)
    // ─────────────────────────────────────────────────────────────────────────

    private function genMatrix(string $rho, bool $transposed): array
    {
        $A = [];
        for ($i = 0; $i < self::K; $i++) {
            $A[$i] = [];
            for ($j = 0; $j < self::K; $j++) {
                [$x, $y] = $transposed ? [$i, $j] : [$j, $i];
                $A[$i][$j] = $this->polyUniform($rho . chr($x) . chr($y));
            }
        }
        return $A;
    }

    private function polyUniform(string $seed): array
    {
        $poly = array_fill(0, self::N, 0);
        $ctr  = 0;
        $buf  = hash('shake128', $seed, true, ['length' => 672]);
        $pos  = 0;
        $blen = 672;

        while ($ctr < self::N) {
            if ($pos + 3 > $blen) {
                $extra = hash('shake128', $seed . chr($blen), true, ['length' => 168]);
                $buf  .= $extra;
                $blen += 168;
            }
            $b0 = ord($buf[$pos]);
            $b1 = ord($buf[$pos + 1]);
            $b2 = ord($buf[$pos + 2]);
            $pos += 3;
            $d1 = $b0 | (($b1 & 0x0F) << 8);
            $d2 = ($b1 >> 4) | ($b2 << 4);
            if ($d1 < self::Q) {
                $poly[$ctr++] = $d1;
            }
            if ($ctr < self::N && $d2 < self::Q) {
                $poly[$ctr++] = $d2;
            }
        }
        return $poly;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Noise sampling (Centred Binomial Distribution, η=2 via SHAKE-256)
    // ─────────────────────────────────────────────────────────────────────────

    private function polyGetNoise(string $sigma, int $nonce, int $eta): array
    {
        $buf = hash('shake256', $sigma . chr($nonce), true, ['length' => $eta * 64]);
        return $this->polyCBD($buf);
    }

    private function polyCBD(string $buf): array
    {
        $poly = array_fill(0, self::N, 0);
        for ($i = 0; $i < self::N / 8; $i++) {
            $t = unpack('V', substr($buf, 4 * $i, 4))[1];
            $d = ($t & 0x55555555) + (($t >> 1) & 0x55555555);
            for ($j = 0; $j < 8; $j++) {
                $poly[8 * $i + $j] = (($d >> (4 * $j)) & 0x3) - (($d >> (4 * $j + 2)) & 0x3);
            }
        }
        return $poly;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NTT / INTT (7-layer butterfly, Montgomery-form zetas)
    // ─────────────────────────────────────────────────────────────────────────

    private function polyNTT(array $p): array
    {
        $f = $p;
        $k = 1;
        for ($len = 128; $len >= 2; $len >>= 1) {
            for ($start = 0; $start < self::N; $start += 2 * $len) {
                $zeta = self::$Z[$k++];
                for ($j = $start; $j < $start + $len; $j++) {
                    $t            = $this->fqMul($zeta, $f[$j + $len]);
                    $f[$j + $len] = $this->barrett($f[$j] - $t);
                    $f[$j]        = $this->barrett($f[$j] + $t);
                }
            }
        }
        return $f;
    }

    private function polyInvNTT(array $p): array
    {
        $f = $p;
        $k = 127;
        for ($len = 2; $len <= 128; $len <<= 1) {
            for ($start = 0; $start < self::N; $start += 2 * $len) {
                $zeta = self::$Z[$k--];
                for ($j = $start; $j < $start + $len; $j++) {
                    $t            = $f[$j];
                    $f[$j]        = $this->barrett($t + $f[$j + $len]);
                    $f[$j + $len] = $t - $f[$j + $len];
                    $f[$j + $len] = $this->fqMul($zeta, $f[$j + $len]);
                }
            }
        }
        for ($j = 0; $j < self::N; $j++) {
            $f[$j] = $this->fqMul($f[$j], self::INV_F);
        }
        return $f;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Base multiplication in Z_q[x]/(x^2 - ζ)
    // ─────────────────────────────────────────────────────────────────────────

    private function polyBaseMul(array $a, array $b): array
    {
        $r = array_fill(0, self::N, 0);
        for ($i = 0; $i < self::N / 4; $i++) {
            $z = self::$Z[64 + $i];
            // First pair: zeta = +z
            $r[4*$i]   = $this->barrett($this->fqMul($this->fqMul($a[4*$i+1], $b[4*$i+1]), $z)
                                        + $this->fqMul($a[4*$i], $b[4*$i]));
            $r[4*$i+1] = $this->barrett($this->fqMul($a[4*$i], $b[4*$i+1])
                                        + $this->fqMul($a[4*$i+1], $b[4*$i]));
            // Second pair: zeta = -z
            $r[4*$i+2] = $this->barrett($this->fqMul($this->fqMul($a[4*$i+3], $b[4*$i+3]), -$z)
                                        + $this->fqMul($a[4*$i+2], $b[4*$i+2]));
            $r[4*$i+3] = $this->barrett($this->fqMul($a[4*$i+2], $b[4*$i+3])
                                        + $this->fqMul($a[4*$i+3], $b[4*$i+2]));
        }
        return $r;
    }

    private function polyvecBaseMulAcc(array $av, array $bv): array
    {
        $r = $this->polyBaseMul($av[0], $bv[0]);
        for ($i = 1; $i < self::K; $i++) {
            $t = $this->polyBaseMul($av[$i], $bv[$i]);
            for ($j = 0; $j < self::N; $j++) {
                $r[$j] += $t[$j];
            }
        }
        return $this->polyReduce($r);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Polynomial arithmetic helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function polyAdd(array $a, array $b): array
    {
        $r = [];
        for ($i = 0; $i < self::N; $i++) {
            $r[$i] = $a[$i] + $b[$i];
        }
        return $r;
    }

    private function polySub(array $a, array $b): array
    {
        $r = [];
        for ($i = 0; $i < self::N; $i++) {
            $r[$i] = $a[$i] - $b[$i];
        }
        return $r;
    }

    private function polyReduce(array $p): array
    {
        return array_map([$this, 'barrett'], $p);
    }

    /** Multiply each coefficient by R (= 2^16 mod q) to correct Montgomery scaling. */
    private function polyToMont(array $p): array
    {
        return array_map(fn($a) => $this->fqMul($a, self::TMONT), $p);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Montgomery & Barrett modular arithmetic
    // ─────────────────────────────────────────────────────────────────────────

    private function montgomeryReduce(int $a): int
    {
        $a16 = $a & 0xFFFF;
        if ($a16 >= 0x8000) $a16 -= 0x10000;        // signed int16
        $t = ($a16 * self::QINV) & 0xFFFF;
        if ($t >= 0x8000) $t -= 0x10000;             // signed int16
        return ($a - $t * self::Q) >> 16;
    }

    private function fqMul(int $a, int $b): int
    {
        return $this->montgomeryReduce($a * $b);
    }

    private function barrett(int $a): int
    {
        static $v = 20159; // floor((2^26 + Q/2) / Q) = 20159
        $t = (int)(($v * $a) >> 26);
        return $a - $t * self::Q;
    }

    private function csubQ(int $a): int
    {
        $a -= self::Q;
        $a += ($a >> 31) & self::Q;
        return $a;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lossless polynomial serialisation (12-bit packed)
    // ─────────────────────────────────────────────────────────────────────────

    private function polyToBytes(array $poly): string
    {
        $buf = '';
        for ($i = 0; $i < self::N / 2; $i++) {
            $t0 = $this->barrett($poly[2 * $i]);
            if ($t0 < 0) $t0 += self::Q;
            $t1 = $this->barrett($poly[2 * $i + 1]);
            if ($t1 < 0) $t1 += self::Q;
            $buf .= chr($t0 & 0xFF)
                  . chr(($t0 >> 8) | (($t1 & 0x0F) << 4))
                  . chr($t1 >> 4);
        }
        return $buf;
    }

    private function polyFromBytes(string $buf): array
    {
        $poly = array_fill(0, self::N, 0);
        for ($i = 0; $i < self::N / 2; $i++) {
            $b0 = ord($buf[3 * $i]);
            $b1 = ord($buf[3 * $i + 1]);
            $b2 = ord($buf[3 * $i + 2]);
            $poly[2 * $i]     = $b0 | (($b1 & 0x0F) << 8);
            $poly[2 * $i + 1] = ($b1 >> 4) | ($b2 << 4);
        }
        return $poly;
    }

    private function polyvecToBytes(array $pv): string
    {
        return implode('', array_map([$this, 'polyToBytes'], $pv));
    }

    private function polyvecFromBytes(string $buf): array
    {
        $pv = [];
        for ($i = 0; $i < self::K; $i++) {
            $pv[] = $this->polyFromBytes(substr($buf, $i * self::POLYBYTES, self::POLYBYTES));
        }
        return $pv;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Message encoding / decoding (1 bit per coefficient)
    // ─────────────────────────────────────────────────────────────────────────

    private function polyFromMsg(string $msg): array
    {
        $poly = array_fill(0, self::N, 0);
        for ($i = 0; $i < 32; $i++) {
            $b = ord($msg[$i]);
            for ($j = 0; $j < 8; $j++) {
                // bit=0 → 0, bit=1 → (q+1)/2 = 1665
                $poly[8 * $i + $j] = (-(($b >> $j) & 1)) & ((self::Q + 1) >> 1);
            }
        }
        return $poly;
    }

    private function polyToMsg(array $poly): string
    {
        $msg = '';
        for ($i = 0; $i < 32; $i++) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $t = $this->barrett($poly[8 * $i + $j]);
                if ($t < 0) $t += self::Q;
                // round(2t / q) mod 2  — maps near-0 → 0, near-q/2 → 1
                $bit   = (int)((($t << 1) + (self::Q >> 1)) / self::Q) & 1;
                $byte |= $bit << $j;
            }
            $msg .= chr($byte);
        }
        return $msg;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lossy ciphertext compression / decompression
    // ─────────────────────────────────────────────────────────────────────────

    /** Compress polyvec u → 1408 bytes  (du=11 bits/coef, k=4). */
    private function polyvecCompress(array $pv): string
    {
        $buf = '';
        foreach ($pv as $poly) {
            for ($i = 0; $i < self::N / 8; $i++) {
                $t = [];
                for ($j = 0; $j < 8; $j++) {
                    $u = $this->barrett($poly[8 * $i + $j]);
                    if ($u < 0) $u += self::Q;
                    $t[$j] = (int)((($u << self::DU) + (self::Q >> 1)) / self::Q) & 0x7FF;
                }
                $buf .= chr($t[0])
                      . chr(($t[0] >> 8)  | ($t[1] << 3))
                      . chr(($t[1] >> 5)  | ($t[2] << 6))
                      . chr($t[2] >> 2)
                      . chr(($t[2] >> 10) | ($t[3] << 1))
                      . chr(($t[3] >> 7)  | ($t[4] << 4))
                      . chr(($t[4] >> 4)  | ($t[5] << 7))
                      . chr($t[5] >> 1)
                      . chr(($t[5] >> 9)  | ($t[6] << 2))
                      . chr(($t[6] >> 6)  | ($t[7] << 5))
                      . chr($t[7] >> 3);
            }
        }
        return $buf;
    }

    /** Decompress 1408-byte ciphertext component → polyvec u. */
    private function polyvecDecompress(string $buf): array
    {
        $pv = [];
        for ($p = 0; $p < self::K; $p++) {
            $poly = array_fill(0, self::N, 0);
            for ($i = 0; $i < self::N / 8; $i++) {
                $base = $p * 352 + $i * 11;
                $b = [];
                for ($k = 0; $k < 11; $k++) {
                    $b[$k] = ord($buf[$base + $k]);
                }
                $t = [
                    $b[0]           | ($b[1] << 8),
                    ($b[1] >> 3)    | ($b[2] << 5),
                    ($b[2] >> 6)    | ($b[3] << 2)  | ($b[4] << 10),
                    ($b[4] >> 1)    | ($b[5] << 7),
                    ($b[5] >> 4)    | ($b[6] << 4),
                    ($b[6] >> 7)    | ($b[7] << 1)  | ($b[8] << 9),
                    ($b[8] >> 2)    | ($b[9] << 6),
                    ($b[9] >> 5)    | ($b[10] << 3),
                ];
                for ($j = 0; $j < 8; $j++) {
                    $poly[8 * $i + $j] = (int)((($t[$j] & 0x7FF) * self::Q + 1024) >> self::DU);
                }
            }
            $pv[] = $poly;
        }
        return $pv;
    }

    /** Compress poly v → 160 bytes  (dv=5 bits/coef). */
    private function polyCompress(array $poly): string
    {
        $buf = '';
        for ($i = 0; $i < self::N / 8; $i++) {
            $t = [];
            for ($j = 0; $j < 8; $j++) {
                $u = $this->barrett($poly[8 * $i + $j]);
                if ($u < 0) $u += self::Q;
                $t[$j] = (int)((($u << self::DV) + (self::Q >> 1)) / self::Q) & 0x1F;
            }
            $buf .= chr( $t[0]        | ($t[1] << 5))
                  . chr(($t[1] >> 3)  | ($t[2] << 2) | ($t[3] << 7))
                  . chr(($t[3] >> 1)  | ($t[4] << 4))
                  . chr(($t[4] >> 4)  | ($t[5] << 1) | ($t[6] << 6))
                  . chr(($t[6] >> 2)  | ($t[7] << 3));
        }
        return $buf;
    }

    /** Decompress 160-byte ciphertext component → poly v. */
    private function polyDecompress(string $buf): array
    {
        $poly = array_fill(0, self::N, 0);
        for ($i = 0; $i < self::N / 8; $i++) {
            $base = $i * 5;
            $b    = [
                ord($buf[$base]),
                ord($buf[$base + 1]),
                ord($buf[$base + 2]),
                ord($buf[$base + 3]),
                ord($buf[$base + 4]),
            ];
            $t = [
                $b[0] & 31,
                ($b[0] >> 5) | (($b[1] & 3) << 3),
                ($b[1] >> 2) & 31,
                ($b[1] >> 7) | (($b[2] & 15) << 1),
                ($b[2] >> 4) | (($b[3] &  1) << 4),
                ($b[3] >> 1) & 31,
                ($b[3] >> 6) | (($b[4] &  7) << 2),
                $b[4] >> 3,
            ];
            for ($j = 0; $j < 8; $j++) {
                $poly[8 * $i + $j] = (int)(($t[$j] * self::Q + 16) >> self::DV);
            }
        }
        return $poly;
    }
}
