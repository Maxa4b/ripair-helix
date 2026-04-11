<?php

namespace App\Console\Commands;

use App\Services\Prospecting\CompanyContactEnrichmentService;
use Illuminate\Console\Command;

class EnrichProspectingCompanyContactsCommand extends Command
{
    protected $signature = 'prospecting:enrich-contacts
        {--limit=250 : Nombre maximum d\'entreprises a enrichir}
        {--source= : Filtre optionnel sur companies.source}
        {--segment= : Filtre optionnel sur companies.segment}';

    protected $description = 'Enrichit email/telephone/website a partir de Google Places et des sites web publics.';

    public function handle(CompanyContactEnrichmentService $companyContactEnrichmentService): int
    {
        try {
            $stats = $companyContactEnrichmentService->enrichMissingContacts(
                (int) $this->option('limit'),
                [
                    'source' => $this->option('source'),
                    'segment' => $this->option('segment'),
                ]
            );

            $this->info(sprintf(
                'Enrichissement termine. Traitees: %d, enrichies: %d, sautees: %d, echecs: %d.',
                $stats['processed'],
                $stats['enriched'],
                $stats['skipped'],
                $stats['failed']
            ));

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
