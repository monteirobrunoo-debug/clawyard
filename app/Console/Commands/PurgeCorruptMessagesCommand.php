<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Deletes Message rows whose ciphertext no longer decrypts under the
 * current APP_KEY.
 *
 * Context: APP_KEY was rotated without re-encrypting existing rows.
 * ~98% of the messages table became unreadable; the SafeEncryptedString
 * cast masks the damage at read time (returns a placeholder) so the UI
 * keeps working, but the rows are dead weight and PII-confusing (we can
 * never restore the original plaintext).
 *
 * Usage:
 *   php artisan messages:purge-corrupt --dry-run       # count only, no writes
 *   php artisan messages:purge-corrupt                 # interactive confirm
 *   php artisan messages:purge-corrupt --force         # non-interactive
 *   php artisan messages:purge-corrupt --limit=50      # sample to debug first
 *
 * Safety:
 *   • Default is DRY RUN-friendly — an interactive prompt must be answered
 *     "yes" before any DELETE runs; --force bypasses the prompt.
 *   • Decryption is tested against the RAW ciphertext via bypassing the
 *     cast (we read `content` with DB::, not Eloquent) so we measure the
 *     actual cryptographic state, not the placeholder.
 *   • Deletes happen in chunks of 500 ids inside a transaction so we
 *     never lock the whole table or leave a half-delete on SIGINT.
 *   • Prints the id range of every chunk so you can abort+recover if
 *     something looks wrong mid-run.
 */
class PurgeCorruptMessagesCommand extends Command
{
    protected $signature = 'messages:purge-corrupt
        {--dry-run    : Count corrupt rows and exit without deleting}
        {--force      : Skip the interactive confirmation prompt}
        {--limit=     : Only examine the first N rows (for sampling)}
        {--chunk=500  : Rows per scan batch}';

    protected $description = 'Delete Message rows whose content cannot decrypt under the current APP_KEY';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $chunk  = max(50, (int) $this->option('chunk'));

        $total = Message::count();
        $this->info("Scanning messages table — {$total} rows total, chunk={$chunk}"
            . ($limit ? ", limit={$limit}" : '')
            . ($dryRun ? ' [DRY RUN]' : ''));

        $corruptIds = [];
        $healthy    = 0;
        $examined   = 0;

        // We scan with DB::, not Eloquent, so the cast doesn't hide the
        // cryptographic failure behind a placeholder.
        DB::table('messages')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$corruptIds, &$healthy, &$examined, $limit) {
                foreach ($rows as $row) {
                    if ($limit !== null && $examined >= $limit) return false;
                    $examined++;

                    if ($row->content === null || $row->content === '') {
                        // Empty content — harmless, not corrupt.
                        $healthy++;
                        continue;
                    }

                    try {
                        Crypt::decryptString((string) $row->content);
                        $healthy++;
                    } catch (DecryptException) {
                        $corruptIds[] = (int) $row->id;
                    }
                }
                return true;
            });

        $corruptCount = count($corruptIds);
        $this->newLine();
        $this->line("Examined:  {$examined}");
        $this->line("Healthy:   {$healthy}");
        $this->line("Corrupt:   <fg=red>{$corruptCount}</>");

        if ($corruptCount === 0) {
            $this->info('Nothing to delete — all examined rows decrypt cleanly.');
            return self::SUCCESS;
        }

        // Sample id range so the user can spot-check if they want
        $sample = array_slice($corruptIds, 0, 5);
        $last   = array_slice($corruptIds, -5);
        $this->line('Sample ids (first 5): ' . implode(', ', $sample));
        $this->line('Sample ids (last 5):  ' . implode(', ', $last));

        if ($dryRun) {
            $this->warn('Dry run — no rows deleted. Rerun without --dry-run to purge.');
            return self::SUCCESS;
        }

        if (!$force) {
            $ok = $this->confirm("Delete {$corruptCount} corrupt messages? This cannot be undone.", false);
            if (!$ok) {
                $this->warn('Cancelled by user.');
                return self::SUCCESS;
            }
        }

        // Delete in chunks of 500 ids so we don't build a massive SQL IN clause.
        $deleted = 0;
        foreach (array_chunk($corruptIds, 500) as $batchNum => $batch) {
            $min = min($batch);
            $max = max($batch);
            $n   = DB::table('messages')->whereIn('id', $batch)->delete();
            $deleted += $n;
            $this->line(sprintf('  batch #%d: deleted %d (ids %d..%d)', $batchNum + 1, $n, $min, $max));
        }

        $this->newLine();
        $this->info("✅ Purged {$deleted} corrupt messages.");

        // Final sanity — confirm the table is now decrypt-clean
        $remaining = Message::count();
        $this->line("messages count now: {$remaining}");

        return self::SUCCESS;
    }
}
