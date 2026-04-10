<?php

namespace App\Console\Commands;

use App\Services\Prospecting\CompanyGeocodingService;
use Illuminate\Console\Command;

class GeocodeProspectingCompaniesCommand extends Command
{
    protected $signature = 'prospecting:geocode-missing
        {--limit=500 : Nombre maximum d\'entreprises a geocoder}
        {--source= : Filtre optionnel sur companies.source}
        {--segment= : Filtre optionnel sur companies.segment}';

    protected $description = 'Geocode en batch les entreprises prospecting deja importees mais sans lat/lng.';

    public function handle(CompanyGeocodingService $companyGeocodingService): int
    {
        try {
            $stats = $companyGeocodingService->geocodeMissingCompanies(
                (int) $this->option('limit'),
                [
                    'source' => $this->option('source'),
                    'segment' => $this->option('segment'),
                ]
            );

            $this->info(sprintf(
                'Geocodage termine. Traitees: %d, geocodees: %d, sautees: %d, echecs: %d.',
                $stats['processed'],
                $stats['geocoded'],
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
