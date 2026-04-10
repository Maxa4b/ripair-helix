<?php

namespace App\Services\Prospecting;

use App\Models\Prospecting\Company;

class CompanyGeocodingService
{
    public function __construct(
        private readonly FrenchAddressGeocodingService $frenchAddressGeocodingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{processed:int, geocoded:int, skipped:int, failed:int}
     */
    public function geocodeMissingCompanies(int $limit = 500, array $filters = []): array
    {
        $query = Company::query()
            ->where(function ($builder): void {
                $builder->whereNull('lat')->orWhereNull('lng');
            })
            ->whereNotNull('address')
            ->whereNotNull('postal_code')
            ->whereNotNull('city');

        if (($filters['source'] ?? null) !== null) {
            $query->where('source', $filters['source']);
        }

        if (($filters['segment'] ?? null) !== null) {
            $query->where('segment', $filters['segment']);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Company> $companies */
        $companies = $query->orderBy('id')->limit(max(1, $limit))->get();

        $stats = [
            'processed' => 0,
            'geocoded' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($companies as $company) {
            $stats['processed']++;

            try {
                $geocoded = $this->frenchAddressGeocodingService->geocode(
                    (string) $company->address,
                    $company->postal_code,
                    $company->city,
                    $company->country
                );

                if ($geocoded === null) {
                    $stats['skipped']++;

                    continue;
                }

                $company->lat = $geocoded['lat'];
                $company->lng = $geocoded['lng'];
                $company->version = (int) $company->version + 1;
                $company->save();

                $stats['geocoded']++;
            } catch (\Throwable) {
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
