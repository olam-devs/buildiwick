<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('app:secure-legacy-transaction-evidence {--force : Move legacy transaction evidence from the public disk without prompting}')]
#[Description('Move legacy transaction evidence files from public storage to the private local disk.')]
class SecureLegacyTransactionEvidence extends Command
{
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Copy legacy transaction evidence to private storage and delete the public copies?')) {
            $this->warn('No files were changed. Re-run with --force to proceed non-interactively.');

            return self::SUCCESS;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $moved = 0;
        $skipped = 0;

        foreach (Transaction::query()->whereNotNull('evidence_link')->orderBy('id')->cursor() as $transaction) {
            $path = str_replace('\\', '/', (string) $transaction->evidence_link);
            if (! str_starts_with($path, 'evidence/') || str_contains('/'.$path.'/', '/../')) {
                $skipped++;
                $this->warn("Skipped unexpected evidence path on transaction {$transaction->id}.");
                continue;
            }

            if (! $public->exists($path)) {
                if (! $private->exists($path)) {
                    $skipped++;
                    $this->warn("Evidence file is missing on both disks for transaction {$transaction->id}.");
                }
                continue;
            }

            if (! $private->exists($path)) {
                $source = $public->readStream($path);
                if (! is_resource($source)) {
                    $skipped++;
                    $this->error("Could not read public evidence for transaction {$transaction->id}.");
                    continue;
                }

                try {
                    $copied = $private->writeStream($path, $source);
                } finally {
                    fclose($source);
                }

                if (! $copied || ! $private->exists($path) || $private->size($path) !== $public->size($path)) {
                    $skipped++;
                    $this->error("Private evidence copy verification failed for transaction {$transaction->id}; public copy retained.");
                    continue;
                }
            }

            if (! $public->delete($path)) {
                $skipped++;
                $this->error("Private copy is safe, but the public duplicate could not be deleted for transaction {$transaction->id}.");
                continue;
            }

            $moved++;
        }

        $this->info("Secured {$moved} transaction evidence file(s); skipped {$skipped}. Transaction records and evidence paths were not changed.");

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
