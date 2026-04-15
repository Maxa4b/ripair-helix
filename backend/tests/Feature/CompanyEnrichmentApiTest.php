<?php

namespace Tests\Feature;

use App\Models\HelixUser;
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
        $this->inputRoot = $base . '/inputs';
        $this->jobsDirectory = $base . '/jobs';
        $this->outputRoot = $base . '/runs';
        $this->pipelineRoot = base_path('company_enrichment');

        File::ensureDirectoryExists($this->inputRoot . '/nested');
        File::ensureDirectoryExists($this->jobsDirectory);
        File::ensureDirectoryExists($this->outputRoot);

        File::put($this->inputRoot . '/nested/sample.csv', "siren,name\n123456789,Acme\n");

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
