<?php

namespace App\Console\Commands;

use App\Models\SharedContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneSharedContexts extends Command
{
    protected $signature   = 'shared-context:prune';
    protected $description = 'Remove expired SharedContext entries from the PSI intelligence bus';

    public function handle(): int
    {
        $deleted = SharedContext::where('expires_at', '<', now())->delete();

        $this->info("✅ SharedContext pruning complete — {$deleted} expired entries removed.");
        Log::info("shared-context:prune — deleted {$deleted} entries", ['ts' => now()->toIso8601String()]);

        return self::SUCCESS;
    }
}
