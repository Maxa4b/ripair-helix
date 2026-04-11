<?php

namespace App\Services\Prospecting;

use App\Models\Prospecting\Company;

class CompanyContactEnrichmentService
{
    public function __construct(
        private readonly GooglePlacesContactEnrichmentService $googlePlacesContactEnrichmentService,
        private readonly WebsiteContactDiscoveryService $websiteContactDiscoveryService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{processed:int, enriched:int, skipped:int, failed:int}
     */
    public function enrichMissingContacts(int $limit = 250, array $filters = []): array
    {
        $query = Company::query()
            ->where(function ($builder): void {
                $builder->whereNull('email')
                    ->orWhere('email', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '');
            })
            ->whereNotNull('name')
            ->whereNotNull('city');

        if (($filters['source'] ?? null) !== null) {
            $query->where('source', $filters['source']);
        }

        if (($filters['segment'] ?? null) !== null) {
            $query->where('segment', $filters['segment']);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Company> $companies */
        $companies = $query->orderBy('relevance_score', 'desc')->orderBy('id')->limit(max(1, $limit))->get();

        $stats = [
            'processed' => 0,
            'enriched' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($companies as $company) {
            $stats['processed']++;

            try {
                $payload = [
                    'google_place_id' => $company->google_place_id,
                    'website' => $company->website,
                    'phone' => $company->phone,
                    'email' => $company->email,
                ];

                $googleData = $this->googlePlacesContactEnrichmentService->enrichCompany($company);
                if ($googleData !== null) {
                    $payload['google_place_id'] = $payload['google_place_id'] ?? $googleData['google_place_id'];
                    $payload['website'] = $payload['website'] ?? $googleData['website'];
                    $payload['phone'] = $payload['phone'] ?? $googleData['phone'];
                }

                $websiteData = $this->websiteContactDiscoveryService->discover($payload['website']);
                $payload['email'] = $payload['email'] ?? $websiteData['email'];
                $payload['phone'] = $payload['phone'] ?? $websiteData['phone'];

                $changed = false;
                foreach ($payload as $field => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if ($company->{$field} === null || $company->{$field} === '') {
                        $company->{$field} = $value;
                        $changed = true;
                    }
                }

                if (! $changed) {
                    $stats['skipped']++;

                    continue;
                }

                $company->version = (int) $company->version + 1;
                $company->save();
                $stats['enriched']++;
            } catch (\Throwable) {
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
