<?php

namespace App\Console\Commands;

use App\Services\Prospecting\CompanyImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportProspectingCompaniesCommand extends Command
{
    protected $signature = 'prospecting:import
        {file : Chemin vers le fichier CSV ou XLSX}
        {--source= : Source logique de l\'import}
        {--segment= : Segment force pour toutes les lignes}
        {--sheet= : Nom de feuille XLSX a lire}';

    protected $description = 'Importe des entreprises de prospection dans Helix.';

    public function handle(CompanyImportService $companyImportService): int
    {
        try {
            $job = $companyImportService->importFromPath((string) $this->argument('file'), [
                'source' => $this->option('source'),
                'segment' => $this->option('segment'),
                'sheet_name' => $this->option('sheet'),
            ]);

            $this->info(sprintf(
                'Import termine. Creees: %d, mises a jour: %d, dedupees: %d, rejetees: %d.',
                $job->rows_created,
                $job->rows_updated,
                $job->rows_deduplicated,
                $job->rows_rejected
            ));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
