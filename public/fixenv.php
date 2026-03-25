<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";
$base = dirname(__DIR__);
echo "Site: " . $_SERVER['HTTP_HOST'] . "\n";
echo "Release: " . basename($base) . "\n\n";

// Check .env for key
$key = '';
foreach (file($base.'/.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
        $key = trim(substr($line, 18), " \t\"'");
        break;
    }
}
echo "Key in .env: " . ($key ? substr($key,0,20)."... ✅" : "MISSING ❌") . "\n";

// Check config cache
$cfg = file_exists($base.'/bootstrap/cache/config.php') ? @include($base.'/bootstrap/cache/config.php') : [];
$cached = $cfg['services']['anthropic']['api_key'] ?? '';
echo "Key in cache: " . ($cached ? substr($cached,0,20)."... ✅" : "MISSING ❌") . "\n\n";

// Fix: rebuild cache
echo shell_exec("cd {$base} && php artisan config:clear 2>&1 && php artisan config:cache 2>&1");
opcache_reset();
echo "✅ Done — test an agent now.\n</pre>";
