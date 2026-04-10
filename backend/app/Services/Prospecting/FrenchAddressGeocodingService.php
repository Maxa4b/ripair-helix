<?php

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\Http;

class FrenchAddressGeocodingService
{
    /**
     * @return array{lat: float, lng: float, score: float}|null
     */
    public function geocode(string $addressLine, ?string $postalCode = null, ?string $city = null, ?string $country = 'France'): ?array
    {
        $query = trim(implode(' ', array_filter([
            $addressLine,
            $postalCode,
            $city,
            $country ?: 'France',
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

        if ($query === '') {
            return null;
        }

        $response = Http::timeout((int) config('prospecting.geocoding.timeout', 20))
            ->acceptJson()
            ->get((string) config('prospecting.geocoding.endpoint'), [
                'q' => $query,
                'limit' => 1,
            ])
            ->throw();

        $features = $response->json('features');
        if (! is_array($features) || $features === []) {
            return null;
        }

        $feature = $features[0];
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2 || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) {
            return null;
        }

        $score = $feature['properties']['score'] ?? null;
        if (is_numeric($score) && (float) $score < (float) config('prospecting.geocoding.min_score', 0.6)) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
            'score' => is_numeric($score) ? (float) $score : 0.0,
        ];
    }
}
