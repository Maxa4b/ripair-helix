<?php

namespace Tests\Feature;

use App\Models\Prospecting\Company;
use App\Services\Prospecting\CompanyContactEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProspectingContactEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enriches_phone_website_and_email_from_google_places_and_public_website(): void
    {
        config()->set('prospecting.contact_enrichment.google_places_api_key', 'test-key');

        Http::fake([
            'https://places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [
                    [
                        'id' => 'places/abc123',
                        'displayName' => ['text' => 'Alpha Electronique'],
                        'formattedAddress' => '10 Rue des Cartes, 38000 Grenoble, France',
                    ],
                ],
            ]),
            'https://places.googleapis.com/v1/places/*' => Http::response([
                'id' => 'places/abc123',
                'websiteUri' => 'https://alpha-electronique.example',
                'nationalPhoneNumber' => '04 76 00 00 00',
            ]),
            'https://alpha-electronique.example' => Http::response('<html><body><a href="/contact">Contact</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://alpha-electronique.example/contact' => Http::response('<html><body><a href="mailto:contact@alpha-electronique.example">Email</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $company = Company::query()->create([
            'company_id' => (string) Str::ulid(),
            'name' => 'Alpha Electronique',
            'source' => 'sirene_auto',
            'address' => '10 Rue des Cartes',
            'postal_code' => '38000',
            'city' => 'Grenoble',
            'country' => 'France',
            'contact_status' => 'non_contacte',
            'relevance_score' => 82,
            'version' => 1,
        ]);

        $stats = app(CompanyContactEnrichmentService::class)->enrichMissingContacts(10, [
            'source' => 'sirene_auto',
        ]);

        $company->refresh();

        $this->assertSame([
            'processed' => 1,
            'enriched' => 1,
            'skipped' => 0,
            'failed' => 0,
        ], $stats);
        $this->assertSame('places/abc123', $company->google_place_id);
        $this->assertSame('https://alpha-electronique.example', $company->website);
        $this->assertSame('04 76 00 00 00', $company->phone);
        $this->assertSame('contact@alpha-electronique.example', $company->email);
        $this->assertSame(2, $company->version);
    }
}
