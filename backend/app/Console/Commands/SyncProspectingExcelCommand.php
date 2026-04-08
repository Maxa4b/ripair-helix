<?php

namespace App\Console\Commands;

use App\Services\Prospecting\ExcelMirrorSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncProspectingExcelCommand extends Command
{
    protected $signature = 'prospecting:excel-sync
        {mode=resync : import|export|resync}
        {--file= : Chemin du miroir Excel}
        {--sheet= : Nom de feuille XLSX}';

    protected $description = 'Lance une synchronisation Excel miroir pour la prospection.';

    public function handle(ExcelMirrorSyncService $excelMirrorSyncService): int
    {
        try {
            $job = $excelMirrorSyncService->sync([
                'mode' => $this->argument('mode'),
                'file_path' => $this->option('file'),
                'sheet_name' => $this->option('sheet'),
            ]);

            $this->info(sprintf(
                'Sync %s terminee. Traitees: %d, maj: %d, ignorees: %d, erreurs: %d.',
                $job->mode,
                $job->rows_processed,
                $job->rows_updated,
                $job->rows_skipped,
                $job->rows_failed
            ));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
