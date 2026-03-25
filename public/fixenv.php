<?php
if (($_GET['k'] ?? '') !== 'claw2026fix') { http_response_code(403); die('403'); }
echo "<pre>\n";
$base = dirname(__DIR__);

// Read NVIDIA config from .env
$env = [];
foreach (file($base.'/.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=') && !str_starts_with($line, '#')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}

$apiKey  = $env['NVIDIA_API_KEY'] ?? '';
$baseUrl = $env['NVIDIA_BASE_URL'] ?? 'https://integrate.api.nvidia.com/v1';
$model   = $env['NVIDIA_MODEL']   ?? 'meta/llama-3.1-8b-instruct';

echo "NVIDIA_API_KEY: " . ($apiKey ? substr($apiKey,0,20)."... ✅" : "MISSING ❌") . "\n";
echo "NVIDIA_BASE_URL: {$baseUrl}\n";
echo "NVIDIA_MODEL: {$model}\n\n";

// Test NVIDIA API - list models
echo "--- Testing NVIDIA API ---\n";
$ch = curl_init($baseUrl . '/models');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER    => ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT       => 10,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: {$code}\n";
$data = json_decode($res, true);
if ($code === 200 && isset($data['data'])) {
    echo "Models available: " . count($data['data']) . "\n";
    // Show if our model exists
    $models = array_column($data['data'], 'id');
    echo "Model '{$model}': " . (in_array($model, $models) ? "✅ Available" : "❌ NOT FOUND") . "\n";
} else {
    echo "Response: " . substr($res, 0, 400) . "\n";
}

// Test a simple chat completion
echo "\n--- Testing chat completion ---\n";
$ch2 = curl_init($baseUrl . '/chat/completions');
$payload = json_encode(['model' => $model, 'messages' => [['role'=>'user','content'=>'hi']], 'max_tokens' => 10]);
curl_setopt_array($ch2, [
    CURLOPT_HTTPHEADER    => ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"],
    CURLOPT_POST          => true,
    CURLOPT_POSTFIELDS    => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT       => 15,
]);
$res2  = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "HTTP: {$code2}\n";
echo "Response: " . substr($res2, 0, 500) . "\n";
echo "</pre>";
