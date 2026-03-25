<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }

$envPath = dirname(__DIR__) . '/.env';
$content = file_exists($envPath) ? file_get_contents($envPath) : '';

echo "<pre>\n";
echo "ENV PATH: {$envPath}\n\n";

// Show key status (masked)
$keysToCheck = ['ANTHROPIC_API_KEY','ANTHROPIC_MODEL','TAVILY_API_KEY','SAM_GOV_API_KEY','APP_ENV','APP_KEY'];
foreach ($keysToCheck as $k) {
    preg_match('/^' . $k . '=(.+)$/m', $content, $m);
    $val = $m[1] ?? '*** MISSING ***';
    if (strlen($val) > 8 && $k !== 'APP_ENV' && $k !== 'ANTHROPIC_MODEL') {
        $val = substr($val, 0, 6) . '...' . substr($val, -4);
    }
    echo "{$k}={$val}\n";
}

// If ANTHROPIC_API_KEY is missing, add it from GET param
if (!preg_match('/^ANTHROPIC_API_KEY=/m', $content) && !empty($_GET['ak'])) {
    $key = trim($_GET['ak']);
    $content .= "\nANTHROPIC_API_KEY={$key}\n";
    file_put_contents($envPath, $content);
    echo "\n✅ ANTHROPIC_API_KEY added to .env\n";
}

// Clear config cache
echo "\n--- Config cache ---\n";
echo shell_exec('cd ' . dirname(__DIR__) . ' && php artisan config:clear 2>&1 && php artisan config:cache 2>&1');

if (function_exists('opcache_reset')) { opcache_reset(); echo "✅ OPcache reset\n"; }
echo "</pre>";
