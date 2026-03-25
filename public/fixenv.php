<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";

$base = dirname(__DIR__);
echo "Release: " . basename($base) . " | PHP: " . PHP_VERSION . "\n";

// Check if new stream() code is deployed
$agent = $base . '/app/Agents/AcingovAgent.php';
$src   = file_get_contents($agent);
echo "New stream() code: " . (str_contains($src, 'Force flush') ? "YES ✅" : "NO ❌ — DEPLOY NEEDED") . "\n";
echo "AppServiceProvider fix: " . (str_contains(file_get_contents($base.'/app/Providers/AppServiceProvider.php'), 'never trust') ? "YES ✅" : "NO ❌") . "\n";

// PHP output buffering info
echo "\nOutput buffer levels: " . ob_get_level() . "\n";
echo "output_buffering ini: " . ini_get('output_buffering') . "\n";
echo "implicit_flush: " . ini_get('implicit_flush') . "\n";
echo ".user.ini exists: " . (file_exists(__DIR__.'/.user.ini') ? "YES ✅" : "NO ❌") . "\n";

// Rebuild config + opcache
echo "\n--- Config cache ---\n";
echo shell_exec("cd {$base} && php artisan config:clear 2>&1 && php artisan config:cache 2>&1");
opcache_reset();
echo "OPcache reset ✅\n";
echo "</pre>";
