<?php

namespace Tests\Feature;

use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProspectingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_fetch_prospecting_stats(): void
    {
        $user = $this->createHelixUser('owner');
        Sanctum::actingAs($user);

        Company::query()->create($this->companyPayload([
            'name' => 'Alpha',
            'contact_status' => 'non_contacte',
        ]));
        Company::query()->create($this->companyPayload([
            'name' => 'Beta',
            'contact_status' => 'en_cours_de_contact',
        ]));
        Company::query()->create($this->companyPayload([
            'name' => 'Gamma',
            'contact_status' => 'contacte',
        ]));
        Company::query()->create($this->companyPayload([
            'name' => 'Disabled',
            'contact_status' => 'non_contacte',
            'is_disabled' => true,
        ]));

        $response = $this->getJson('/api/prospecting/stats');

        $response->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.non_contacte', 1)
            ->assertJsonPath('data.en_cours_de_contact', 1)
            ->assertJsonPath('data.contacte', 1);
    }

    public function test_manager_can_disable_company_and_default_listing_hides_it(): void
    {
        $user = $this->createHelixUser('manager');
        Sanctum::actingAs($user);

        $company = Company::query()->create($this->companyPayload([
            'name' => 'Hide Me',
            'version' => 1,
        ]));

        $patchResponse = $this->patchJson("/api/prospecting/companies/{$company->id}", [
            'is_disabled' => true,
            'version' => 1,
        ]);

        $patchResponse->assertOk()
            ->assertJsonPath('data.is_disabled', true);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'is_disabled' => true,
        ]);

        $listResponse = $this->getJson('/api/prospecting/companies');

        $listResponse->assertOk();
        $this->assertSame([], $listResponse->json('data'));
    }

    public function test_status_patch_creates_history_and_increments_version(): void
    {
        $user = $this->createHelixUser('manager');
        Sanctum::actingAs($user);

        $company = Company::query()->create($this->companyPayload([
            'name' => 'Delta',
            'version' => 2,
        ]));

        $response = $this->patchJson("/api/prospecting/companies/{$company->id}/status", [
            'contact_status' => 'en_cours_de_contact',
            'contact_owner' => 'Equipe SDR',
            'notes' => 'Premier appel planifie',
            'version' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.contact_status', 'en_cours_de_contact')
            ->assertJsonPath('data.version', 3);

        $company->refresh();
        $this->assertSame(3, $company->version);
        $this->assertDatabaseHas('company_contact_history', [
            'company_id' => $company->id,
            'previous_status' => 'non_contacte',
            'new_status' => 'en_cours_de_contact',
            'changed_by' => $user->id,
        ]);
    }

    public function test_status_patch_rejects_stale_version(): void
    {
        $user = $this->createHelixUser('manager');
        Sanctum::actingAs($user);

        $company = Company::query()->create($this->companyPayload([
            'name' => 'Epsilon',
            'version' => 4,
        ]));

        $response = $this->patchJson("/api/prospecting/companies/{$company->id}/status", [
            'contact_status' => 'contacte',
            'version' => 3,
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('company_contact_history', 0);
    }

    private function createHelixUser(string $role): HelixUser
    {
        return HelixUser::query()->create([
            'first_name' => 'Test',
            'last_name' => ucfirst($role),
            'email' => $role . '@example.test',
            'password_hash' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function companyPayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => (string) Str::ulid(),
            'name' => 'Prospect',
            'country' => 'France',
            'contact_status' => 'non_contacte',
            'relevance_score' => 50,
            'is_disabled' => false,
            'version' => 1,
        ], $overrides);
    }
}
