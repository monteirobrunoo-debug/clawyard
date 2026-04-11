<?php

namespace App\Console\Commands;

use App\Services\QnapIndexService;
use Illuminate\Console\Command;

class QnapIndexCommand extends Command
{
    protected $signature   = 'qnap:index {--path=/var/www/qnapbackup : Base path to scan}';
    protected $description = 'Index all QNAP backup files into the RAG knowledge base';

    public function handle(): int
    {
        $path = $this->option('path');
        $this->info("🗂️  Indexing QNAP files from: {$path}");
        $this->newLine();

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }

        $service = new QnapIndexService($path);
        $bar     = null;

        $stats = $service->indexAll(function (string $msg) {
            $this->line("  {$msg}");
        });

        $this->newLine();
        $this->info("✅ Done!");
        $this->table(
            ['Total', 'Indexed', 'Skipped', 'Errors'],
            [[$stats['total'], $stats['indexed'], $stats['skipped'], $stats['errors']]]
        );

        return 0;
    }
}
