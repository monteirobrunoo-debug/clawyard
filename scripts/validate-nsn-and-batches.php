<?php
/**
 * Validation script para NSN trait + batch analyses + Octane health.
 *
 * Uso:
 *   sudo -u forge -i bash -c 'cd ~/clawyard.partyard.eu/current && php scripts/validate-nsn-and-batches.php'
 *
 * Cobre 5 checks de uma só vez (em vez de heredocs frágeis no terminal):
 *   1. NSN cache hit (flush + re-lookup com anti-hallucination patch)
 *   2. Trait NsnLookupTrait dispara em MilDefAgent (Cor. Rodrigues)
 *   3. Estado dos batches #237 #300 #286 — análise persistida via batch?
 *   4. Octane health endpoint + worker count + uptime
 *   5. Últimos erros HR/Logística no laravel.log (Pedro 408 follow-up)
 *
 * Não toca nada — só faz reads. Safe to re-run.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\TenderServiceAnalysis;
use App\Services\AgentTools\NsnLookupTool;

function section(string $title): void { echo "\n═══ {$title} ═══\n"; }

// ─── 1. NSN — flush cache + re-lookup com fix anti-hallucination ─────────
section('1. NSN lookup pós anti-hallucination fix');

$testNsn = '5331-01-234-5678';
$cacheKey = 'nsn_lookup:v1:' . $testNsn;
$hadCache = Cache::has($cacheKey);
Cache::forget($cacheKey);
echo "  cache pré-existia: " . ($hadCache ? 'SIM (limpa, vai re-fetch)' : 'NÃO') . "\n";

$tool = app(NsnLookupTool::class);
$t0 = microtime(true);
$res = $tool->execute(['nsn' => $testNsn, 'item_hint' => 'O-ring'], ['agent_key' => 'cli']);
$dt = (int) ((microtime(true) - $t0) * 1000);

echo "  status: " . ($res['ok'] ? '✓ OK' : '✗ FAIL') . " | tempo: {$dt}ms | custo: \$" . ($res['cost_usd'] ?? 0) . "\n";
if ($res['ok']) {
    $oem = preg_match('/OEM: (.+)/', $res['result'], $m) ? trim($m[1]) : '(vazio)';
    $isFake = stripos($oem, 'mintie') !== false || stripos($oem, 'oshkosh') !== false;
    echo "  OEM: \"{$oem}\" " . ($isFake ? '⚠ AINDA FAKE (deploy ainda não passou?)' : '✓ limpo') . "\n";
    if (str_contains($res['result'], 'Distribuidores')) {
        $distLines = substr_count($res['result'], '  •');
        echo "  distribuidores: {$distLines}\n";
    }
} else {
    echo "  error: " . ($res['error'] ?? '?') . "\n";
}

// ─── 2. Trait NSN dispara em MilDefAgent ─────────────────────────────────
section('2. NsnLookupTrait em MilDefAgent (Cor. Rodrigues)');
try {
    $mildef = app(\App\Agents\MilDefAgent::class);
    $ref = new ReflectionMethod($mildef, 'augmentWithNsnLookup');
    $ref->setAccessible(true);
    $out = $ref->invoke($mildef, "preciso fornecedor europeu para NSN {$testNsn}", null);
    $injected = is_string($out) && str_contains($out, 'NSN LOOKUP');
    echo '  trait disparou:        ' . ($injected ? '✓ SIM' : '✗ NÃO') . "\n";
    echo '  tamanho prompt:        ' . strlen($out) . " chars (esperado ≥ 800)\n";
    if (!$injected) {
        echo "  ⚠ trait não disparou — verifica que MilDefAgent tem `use NsnLookupTrait`\n";
    }
} catch (\Throwable $e) {
    echo "  ✗ EXCEPTION: " . $e->getMessage() . "\n";
}

// ─── 3. Batches #237 #300 #286 ───────────────────────────────────────────
section('3. Análises batch #237 #300 #286');
$rows = TenderServiceAnalysis::whereIn('tender_id', [237, 300, 286])
    ->orderBy('tender_id')
    ->get(['id', 'tender_id', 'status', 'sections', 'total_cost_usd', 'generated_at']);

if ($rows->isEmpty()) {
    echo "  ⚠ Nenhuma análise para 237/300/286 — confirma se foram submetidas via batch.\n";
} else {
    foreach ($rows as $r) {
        $sections = (array) ($r->sections ?? []);
        $viaBatch = false;
        foreach ($sections as $s) {
            if (is_array($s) && ($s['via_batch'] ?? false) === true) { $viaBatch = true; break; }
        }
        $age = $r->generated_at?->diffForHumans() ?? '?';
        $tag = $viaBatch ? '[BATCH]' : '[LIVE] ';
        $agentN = count(array_keys($sections));
        echo sprintf(
            "  T#%-4d %s analysis #%d | %d agentes | \$%s | %s | %s\n",
            $r->tender_id, $tag, $r->id, $agentN,
            number_format((float) $r->total_cost_usd, 4),
            $r->status, $age
        );
    }
}

// ─── 4. Octane health ────────────────────────────────────────────────────
section('4. Octane health');
$health = @file_get_contents('http://127.0.0.1:8000/health');
$ok = $health !== false && str_contains((string) $health, 'ok');
echo '  /health: ' . ($ok ? '✓ 200 OK' : '✗ falhou') . "\n";

$workerCount = (int) trim((string) shell_exec("pgrep -fc 'octane' 2>/dev/null"));
echo '  workers count: ' . $workerCount . "\n";

$uptime = trim((string) shell_exec('systemctl show clawyard-octane --property=ActiveEnterTimestamp --value 2>/dev/null'));
echo '  Octane started: ' . ($uptime ?: '(systemd não responde)') . "\n";

// ─── 5. HR/Logística — erros recentes ────────────────────────────────────
section('5. Erros recentes HR/Logística (Pedro follow-up)');
$log = '/home/forge/clawyard.partyard.eu/current/storage/logs/laravel.log';
if (!is_readable($log)) {
    echo "  log não legível ({$log})\n";
} else {
    $hits = trim((string) shell_exec(
        'grep -E "HrAgent|LogisticsAgent|RunTenderAnalysisJob|SAP HR context failed|MaxAttempts" '
        . escapeshellarg($log) . ' | tail -8'
    ));
    if ($hits === '') {
        echo "  (sem erros recentes — saudável)\n";
    } else {
        foreach (explode("\n", $hits) as $line) {
            // Truncate noisy stack traces
            $line = mb_substr($line, 0, 220);
            echo "  · {$line}\n";
        }
    }
}

echo "\n═══ FIM ═══\n";
