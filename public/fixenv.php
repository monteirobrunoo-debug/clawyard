<?php
// Temporary fix script — remove after use
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }

$envPath = dirname(__DIR__) . '/.env';
$content = file_exists($envPath) ? file_get_contents($envPath) : null;

echo "<pre>\n";

if (!$content) {
    echo "ERROR: .env not found at {$envPath}\n";
    die();
}

// Fix VORTAL_USERNAME
$fixed = preg_replace(
    '/^VORTAL_USERNAME=(?!")(.+)$/m',
    'VORTAL_USERNAME="$1"',
    $content
);

$changed = ($fixed !== $content);
if ($changed) {
    file_put_contents($envPath, $fixed);
    echo "✅ FIXED: VORTAL_USERNAME now has quotes\n";
} else {
    echo "ℹ️  VORTAL_USERNAME already OK\n";
}

// Show relevant .env lines
echo "\n--- Relevant .env lines ---\n";
foreach (explode("\n", $fixed) as $line) {
    if (preg_match('/^(VORTAL|ACINGOV|UNIDO|UNGM|SAM_GOV|TAVILY|APP_ENV|APP_KEY)/', $line)) {
        // Mask passwords
        $safe = preg_replace('/PASSWORD=.+/', 'PASSWORD=***', $line);
        $safe = preg_replace('/API_KEY=.+/', 'API_KEY=***', $safe);
        echo $safe . "\n";
    }
}

// Clear Laravel config cache
echo "\n--- Clearing config cache ---\n";
$output = shell_exec('cd ' . dirname(__DIR__) . ' && php artisan config:clear 2>&1 && php artisan config:cache 2>&1');
echo $output . "\n";

// Reset opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache reset\n";
}

echo "\nDone. Delete this file: public/fixenv.php\n";
echo "</pre>\n";
