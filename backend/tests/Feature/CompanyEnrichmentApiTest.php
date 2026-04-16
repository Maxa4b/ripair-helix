<?php

namespace Tests\Feature;

use App\Models\HelixUser;
use App\Models\Prospecting\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyEnrichmentApiTest extends TestCase
{
    use RefreshDatabase;

    private string $inputRoot;

    private string $jobsDirectory;

    private string $outputRoot;

    private string $pipelineRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('framework/testing/company-enrichment');
        File::deleteDirectory($base);
        $this->inputRoot = $base . '/inputs';
        $this->jobsDirectory = $base . '/jobs';
        $this->outputRoot = $base . '/runs';
        $this->pipelineRoot = base_path('company_enrichment');

        File::ensureDirectoryExists($this->inputRoot . '/nested');
        File::ensureDirectoryExists($this->jobsDirectory);
        File::ensureDirectoryExists($this->outputRoot);

        File::put($this->inputRoot . '/nested/sample.csv', "siren,name\n123456789,Acme\n");
        File::put($this->inputRoot . '/nested/seed_domains.csv', "siren,source_url,source_type\n123456789,https://acme.example,seed_file\n");

        config()->set('company_enrichment.input_root', $this->inputRoot);
        config()->set('company_enrichment.jobs_directory', $this->jobsDirectory);
        config()->set('company_enrichment.output_root', $this->outputRoot);
        config()->set('company_enrichment.pipeline_root', $this->pipelineRoot);
        config()->set('company_enrichment.default_config', base_path('company_enrichment/config.example.yaml'));
        config()->set('company_enrichment.disable_process_launch', true);
    }

    public function test_manager_can_list_company_enrichment_input_files(): void
    {
        Sanctum::actingAs($this->createHelixUser('manager'));

        $response = $this->getJson('/api/prospecting/enrichment/files?path=nested');

        $response->assertOk()
            ->assertJsonPath('data.current_path', 'nested')
            ->assertJsonPath('data.entries.0.name', 'sample.csv');
    }

    public function test_owner_can_create_company_enrichment_job(): void
    {
        Sanctum::actingAs($this->createHelixUser('owner'));

        $response = $this->postJson('/api/prospecting/enrichment/jobs', [
            'input_path' => 'nested/sample.csv',
            'mode' => 'run-all',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.mode', 'run-all')
            ->assertJsonPath('data.input_path', 'nested/sample.csv')
            ->assertJsonPath('data.snapshot.inputFile.name', 'sample.csv');

        $this->assertNotEmpty(File::files($this->jobsDirectory));
    }

    public function test_owner_can_create_company_enrichment_job_with_seed_file(): void
    {
        Sanctum::actingAs($this->createHelixUser('owner'));

        $response = $this->postJson('/api/prospecting/enrichment/jobs', [
            'input_path' => 'nested/sample.csv',
            'seed_input_path' => 'nested/seed_domains.csv',
            'mode' => 'run-all',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.seed_input_path', 'nested/seed_domains.csv')
            ->assertJsonPath('data.snapshot.seedFile.name', 'seed_domains.csv');

        $jobJson = collect(File::files($this->jobsDirectory))
            ->first(fn (\SplFileInfo $file) => str_ends_with($file->getFilename(), '.json'));

        $this->assertNotNull($jobJson);
        $payload = json_decode((string) File::get($jobJson->getPathname()), true);
        $this->assertIsArray($payload);
        $runtimeConfigPath = $payload['config_path'] ?? null;
        $this->assertIsString($runtimeConfigPath);
        $this->assertStringContainsString('seed_candidates_path', (string) File::get($runtimeConfigPath));
        $this->assertStringContainsString('seed_domains.csv', (string) File::get($runtimeConfigPath));
    }

    public function test_owner_can_generate_domain_seed_from_prospecting_companies(): void
    {
        Sanctum::actingAs($this->createHelixUser('owner'));

        Company::query()->create([
            'company_id' => 'cmp_seed_01',
            'name' => 'Acme Industrie',
            'siren' => '123456789',
            'website' => 'https://www.acme-industrie.fr/contact',
            'phone' => '0102030405',
            'address' => '1 rue de Paris',
            'city' => 'Paris',
            'source' => 'sirene',
            'relevance_score' => 92,
            'is_disabled' => false,
        ]);

        Company::query()->create([
            'company_id' => 'cmp_seed_02',
            'name' => 'Acme Social',
            'siren' => '123456789',
            'website' => 'https://facebook.com/acme-industrie',
            'source' => 'sirene',
            'relevance_score' => 50,
            'is_disabled' => false,
        ]);

        $response = $this->postJson('/api/prospecting/enrichment/seed');

        $response->assertOk()
            ->assertJsonPath('data.file.path', '_generated/domain_seed.csv')
            ->assertJsonPath('data.file.name', 'domain_seed.csv')
            ->assertJsonPath('data.rows_written', 1)
            ->assertJsonPath('data.unique_sirens', 1);

        $seedPath = $this->inputRoot . '/_generated/domain_seed.csv';
        $this->assertTrue(File::exists($seedPath));

        $content = (string) File::get($seedPath);
        $this->assertStringContainsString('siren,source_url,domain,source_type', $content);
        $this->assertStringContainsString('123456789', $content);
        $this->assertStringContainsString('https://acme-industrie.fr/contact', $content);
        $this->assertStringNotContainsString('facebook.com', $content);
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
}
