<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";

$base = dirname(__DIR__);
echo "Release: " . basename($base) . " | PHP: " . PHP_VERSION . "\n";

// Check .user.ini
echo ".user.ini exists: " . (file_exists(__DIR__ . '/.user.ini') ? "YES ✅" : "NO ❌") . "\n";

// Check opcache settings
echo "opcache.validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
echo "opcache.revalidate_freq: " . ini_get('opcache.revalidate_freq') . "\n\n";

// Read key
$key = '';
foreach (file($base . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
        $key = trim(substr($line, 18), " \t\n\r\0\x0B\"'");
        break;
    }
}
echo "Key in .env: " . (empty($key) ? "MISSING ❌" : substr($key,0,14)."...".substr($key,-4) . " ✅") . "\n";

// Config cache
$cachePath = $base . '/bootstrap/cache/config.php';
$cfg = file_exists($cachePath) ? @include($cachePath) : [];
$cached = $cfg['services']['anthropic']['api_key'] ?? '';
echo "Key in cache: " . (empty($cached) ? "MISSING ❌" : substr($cached,0,14)."...".substr($cached,-4) . " ✅") . "\n\n";

// Rebuild cache
echo "--- Rebuilding config cache ---\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1 && php artisan config:cache 2>&1");

// Verify AppServiceProvider fix
$asp = file_get_contents($base . '/app/Providers/AppServiceProvider.php');
echo "AppServiceProvider fix: " . (str_contains($asp, 'never trust stale putenv') ? "YES ✅" : "OLD VERSION ❌") . "\n\n";

// Test Anthropic API
$ch = curl_init('https://api.anthropic.com/v1/models');
curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ["x-api-key: {$key}", "anthropic-version: 2023-06-01"], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "Anthropic API test: HTTP {$code} " . ($code === 200 ? "✅" : "❌") . "\n";

opcache_invalidate($cachePath, true);
opcache_reset();
echo "OPcache reset ✅\n";
echo "\nDone — test an agent now.\n</pre>";
