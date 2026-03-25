<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";

$base = dirname(__DIR__);
$envPath = $base . '/.env';
echo "Release path: {$base}\n";
echo "Serving PHP: " . PHP_VERSION . "\n\n";

// Read key from .env file directly
$key = '';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
        $key = trim(substr($line, 18), " \t\n\r\0\x0B\"'");
        break;
    }
}
echo "Key from .env: " . (empty($key) ? "*** MISSING ***" : substr($key,0,14)."[...]".substr($key,-6)) . "\n";
echo "Key length: " . strlen($key) . "\n\n";

// Test Anthropic API directly with curl
echo "--- Testing Anthropic API directly ---\n";
$ch = curl_init('https://api.anthropic.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER    => ["x-api-key: {$key}", "anthropic-version: 2023-06-01"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT       => 10,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP Status: {$code}\n";
echo "Response: " . substr($res, 0, 300) . "\n\n";

// Force opcache invalidate config.php
$configCache = $base . '/bootstrap/cache/config.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($configCache, true);
    echo "✅ opcache_invalidate on config.php\n";
}
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ opcache_reset (this worker)\n";
}

// Rebuild config cache
echo "\n--- Config cache rebuild ---\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1 && php artisan config:cache 2>&1");

// Verify
$cfg = include $configCache;
$cached = $cfg['services']['anthropic']['api_key'] ?? 'MISSING';
echo "Cached key: " . (strlen($cached) > 4 ? substr($cached,0,14)."[...]".substr($cached,-6) : $cached) . "\n";

echo "\nDone. Test an agent now.\n</pre>";
