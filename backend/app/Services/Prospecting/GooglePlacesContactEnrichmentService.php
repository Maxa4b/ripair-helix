<?php

namespace App\Services\Prospecting;

use App\Models\Prospecting\Company;
use Illuminate\Support\Facades\Http;

class GooglePlacesContactEnrichmentService
{
    /**
     * @return array{google_place_id:?string, website:?string, phone:?string}|null
     */
    public function enrichCompany(Company $company): ?array
    {
        $apiKey = (string) config('prospecting.contact_enrichment.google_places_api_key', '');
        if ($apiKey === '') {
            return null;
        }

        $placeId = $company->google_place_id ?: $this->searchPlaceId($company, $apiKey);
        if ($placeId === null) {
            return null;
        }

        $response = Http::timeout((int) config('prospecting.contact_enrichment.http_timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'id,displayName,formattedAddress,nationalPhoneNumber,websiteUri',
            ])
            ->get(rtrim((string) config('prospecting.contact_enrichment.google_places_details_base'), '/') . '/' . rawurlencode($placeId))
            ->throw();

        return [
            'google_place_id' => $response->json('id') ?: $placeId,
            'website' => $this->normalizeWebsite($response->json('websiteUri')),
            'phone' => $this->cleanPhone($response->json('nationalPhoneNumber')),
        ];
    }

    private function searchPlaceId(Company $company, string $apiKey): ?string
    {
        $payload = [
            'textQuery' => $this->buildTextQuery($company),
            'languageCode' => 'fr',
        ];

        if ($company->lat !== null && $company->lng !== null) {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $company->lat,
                        'longitude' => $company->lng,
                    ],
                    'radius' => 2000.0,
                ],
            ];
        }

        $response = Http::timeout((int) config('prospecting.contact_enrichment.http_timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress',
            ])
            ->post((string) config('prospecting.contact_enrichment.google_places_search_endpoint'), $payload)
            ->throw();

        $places = $response->json('places');
        if (! is_array($places) || $places === []) {
            return null;
        }

        $firstPlace = $places[0];
        $placeId = $firstPlace['id'] ?? null;

        return is_string($placeId) && $placeId !== '' ? $placeId : null;
    }

    private function buildTextQuery(Company $company): string
    {
        return trim(implode(', ', array_filter([
            $company->name,
            $company->address,
            $company->postal_code,
            $company->city,
            $company->country ?: 'France',
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));
    }

    private function normalizeWebsite(mixed $value): ?string
    {
        $website = trim((string) ($value ?? ''));
        if ($website === '') {
            return null;
        }

        if (! preg_match('/^https?:\/\//i', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }

        return $website;
    }

    private function cleanPhone(mixed $value): ?string
    {
        $phone = trim((string) ($value ?? ''));

        return $phone === '' ? null : $phone;
    }
}
