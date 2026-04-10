<?php

namespace Tests\Feature;

use App\Models\Prospecting\Company;
use App\Services\Prospecting\CompanyGeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProspectingGeocodingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_geocodes_missing_companies_from_address(): void
    {
        Http::fake([
            '*' => Http::response([
                'features' => [
                    [
                        'geometry' => [
                            'coordinates' => [5.724524, 45.188529],
                        ],
                        'properties' => [
                            'score' => 0.96,
                        ],
                    ],
                ],
            ]),
        ]);

        $company = Company::query()->create([
            'company_id' => (string) Str::ulid(),
            'name' => 'Alpha Electronique',
            'source' => 'sirene_auto',
            'address' => '10 RUE DES CARTES',
            'postal_code' => '38000',
            'city' => 'GRENOBLE',
            'country' => 'France',
            'contact_status' => 'non_contacte',
            'relevance_score' => 80,
            'version' => 1,
        ]);

        $stats = app(CompanyGeocodingService::class)->geocodeMissingCompanies(10, [
            'source' => 'sirene_auto',
        ]);

        $company->refresh();

        $this->assertSame([
            'processed' => 1,
            'geocoded' => 1,
            'skipped' => 0,
            'failed' => 0,
        ], $stats);
        $this->assertEqualsWithDelta(45.188529, (float) $company->lat, 0.000001);
        $this->assertEqualsWithDelta(5.724524, (float) $company->lng, 0.000001);
        $this->assertSame(2, $company->version);
    }
}
