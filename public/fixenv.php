<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";

$base = dirname(__DIR__);
$envPath = $base . '/.env';

// 1. Read raw key from .env
$key = '';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
        $key = trim(substr($line, 18));
        break;
    }
}
echo "Key in .env: " . (empty($key) ? "*** MISSING ***" : substr($key,0,10)."...".substr($key,-4)) . "\n";

// 2. Check config cache
$cachePath = $base . '/bootstrap/cache/config.php';
if (file_exists($cachePath)) {
    $cfg = include $cachePath;
    $cached = $cfg['services']['anthropic']['api_key'] ?? '*** NOT IN CACHE ***';
    echo "Key in config cache: " . (empty($cached) ? "EMPTY" : substr($cached,0,10)."...".substr($cached,-4)) . "\n";
} else {
    echo "Config cache: NOT FOUND\n";
}

// 3. Rebuild config cache
echo "\n--- Rebuilding config cache ---\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1 && php artisan config:cache 2>&1");

// 4. Check cache again
if (file_exists($cachePath)) {
    $cfg2 = include $cachePath;
    $cached2 = $cfg2['services']['anthropic']['api_key'] ?? '*** NOT IN CACHE ***';
    echo "Key in NEW cache: " . (empty($cached2) ? "EMPTY" : substr($cached2,0,10)."...".substr($cached2,-4)) . "\n";
}

// 5. Restart FPM via pid file
echo "\n--- Restarting PHP-FPM ---\n";
$pidFiles = glob('/var/run/php/*.pid') ?: [];
if (empty($pidFiles)) $pidFiles = glob('/run/php/*.pid') ?: [];
foreach ($pidFiles as $pid_file) {
    $pid = (int)trim(file_get_contents($pid_file));
    if ($pid > 0 && posix_kill($pid, SIGUSR2)) {
        echo "✅ Sent SIGUSR2 to FPM pid {$pid} ({$pid_file})\n";
    } else {
        echo "⚠️  Could not signal FPM from {$pid_file} (pid={$pid})\n";
    }
}
if (empty($pidFiles)) {
    echo "⚠️  No FPM pid files found\n";
    // Try killall as fallback
    echo shell_exec("sudo killall -USR2 php-fpm 2>&1 || true");
}

echo "\nDone.\n</pre>";
