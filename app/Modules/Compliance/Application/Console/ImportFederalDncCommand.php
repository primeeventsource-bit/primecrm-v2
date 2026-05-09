<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Console;

use App\Modules\Compliance\Application\Jobs\ImportFederalDncDeltaJob;
use Illuminate\Console\Command;

/**
 * Operator-facing command that dispatches a federal DNC delta import.
 *
 * Usage:
 *   php artisan compliance:dnc:import-federal /path/to/delta-file.txt
 *   php artisan compliance:dnc:import-federal s3-relative/path.txt --disk=s3
 *
 * Real federal DNC fetching from telemarketing.donotcall.gov happens via
 * a separate cron + SFTP script outside the application. That script
 * places the delta file on the configured disk and invokes this command.
 * Keeping the SFTP step out of PHP keeps the application unaware of the
 * subscriber credentials and avoids dragging in an SFTP dependency.
 */
final class ImportFederalDncCommand extends Command
{
    protected $signature = 'compliance:dnc:import-federal
                            {path : Path to the delta file on the chosen disk}
                            {--disk=local : Filesystem disk name}
                            {--source-label=federal_dnc : Label recorded in added_by}
                            {--sync : Run synchronously instead of queueing}';

    protected $description = 'Import a federal DNC delta file into the dnc_entries table.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $disk = (string) $this->option('disk');
        $label = (string) $this->option('source-label');

        if ($this->option('sync')) {
            $this->info("Importing {$disk}:{$path} synchronously…");
            (new ImportFederalDncDeltaJob($path, $disk, $label))
                ->handle(app(\App\Core\Shared\Services\PhoneNormalizer::class));
            $this->info('Done.');
        } else {
            ImportFederalDncDeltaJob::dispatch($path, $disk, $label);
            $this->info("Queued federal DNC import: {$disk}:{$path}");
        }

        return self::SUCCESS;
    }
}
