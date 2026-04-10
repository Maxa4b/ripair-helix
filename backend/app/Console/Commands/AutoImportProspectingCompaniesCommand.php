<?php

namespace App\Console\Commands;

use App\Services\Prospecting\SireneProspectingImportService;
use Illuminate\Console\Command;

class AutoImportProspectingCompaniesCommand extends Command
{
    protected $signature = 'prospecting:auto-import
        {source? : Chemin local CSV ou URL du stock SIRENE}
        {--limit=10000 : Nombre maximum de lignes a traiter}
        {--min-score= : Score minimum a retenir}
        {--departments= : Filtre optionnel par departements, ex: 31,38,69}';

    protected $description = 'Genere automatiquement des prospects depuis un stock SIRENE compatible B2B electronique.';

    public function handle(SireneProspectingImportService $sireneProspectingImportService): int
    {
        $source = (string) ($this->argument('source') ?: config('prospecting.sirene.stock_url', ''));
        if ($source === '') {
            $this->error('Aucune source SIRENE fournie. Passe un chemin CSV local ou configure PROSPECTING_SIRENE_STOCK_URL.');

            return self::FAILURE;
        }

        try {
            $job = $sireneProspectingImportService->importFromSource($source, [
                'limit' => (int) $this->option('limit'),
                'min_score' => $this->option('min-score') !== null ? (int) $this->option('min-score') : null,
                'departments' => $this->option('departments'),
            ]);

            $this->info(sprintf(
                'Import SIRENE termine. Creees: %d, mises a jour: %d, dedupees: %d, rejetees: %d.',
                $job->rows_created,
                $job->rows_updated,
                $job->rows_deduplicated,
                $job->rows_rejected
            ));

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
