<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\SupplierWebIntelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async sync de web-intel para 1 supplier.
 *
 * Pedido directo do Bruno (2026-05-21): "Os agentes tem de verificar
 * na web o que faz os fornecedores...". Despachado em batch pelo
 * comando suppliers:sync-web-intel ou pontualmente quando o admin
 * cria/edita um supplier.
 *
 * Tries=1 (Tavily + Claude → cost real; melhor falhar e o admin
 * reler do que retry agressivo). Timeout 60s (chamadas externas).
 */
class SyncSupplierWebIntelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $supplierId)
    {
        // queue 'low' para não competir com tender analysis e Marta
        // (que são interactivos / on-demand). Web-intel pode demorar.
        $this->onQueue('low');
    }

    public function handle(SupplierWebIntelService $svc): void
    {
        $s = Supplier::find($this->supplierId);
        if (!$s) {
            Log::info('SyncSupplierWebIntelJob: supplier not found', ['id' => $this->supplierId]);
            return;
        }

        $res = $svc->syncOne($s);
        Log::info('SyncSupplierWebIntelJob done', [
            'supplier_id' => $s->id,
            'name'        => $s->name,
            'status'      => $res['status'] ?? '?',
            'ok'          => $res['ok'] ?? false,
        ]);
    }
}
