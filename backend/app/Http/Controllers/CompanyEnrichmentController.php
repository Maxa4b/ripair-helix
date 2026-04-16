<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Services\CompanyEnrichment\CompanyEnrichmentFilesystemService;
use App\Services\CompanyEnrichment\CompanyEnrichmentJobStore;
use App\Services\CompanyEnrichment\CompanyEnrichmentSeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class CompanyEnrichmentController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CompanyEnrichmentFilesystemService $filesystem,
        private readonly CompanyEnrichmentJobStore $jobStore,
        private readonly CompanyEnrichmentSeedService $seedService,
    ) {
    }

    public function files(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $listing = $this->filesystem->listInputs((string) ($data['path'] ?? ''));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'data' => $listing,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        return response()->json([
            'data' => $this->jobStore->list((int) config('company_enrichment.job_list_limit', 15)),
        ]);
    }

    public function generateSeed(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $payload = $this->seedService->generateFromProspectingCompanies();

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'input_path' => ['required', 'string', 'max:2048'],
            'seed_input_path' => ['nullable', 'string', 'max:2048'],
            'mode' => ['nullable', 'string', 'in:run-all,ingest,resolve-domains,crawl,score-emails,export-final'],
        ]);

        try {
            $input = $this->filesystem->inputFile((string) $data['input_path']);
            $seedInput = isset($data['seed_input_path']) && is_string($data['seed_input_path']) && $data['seed_input_path'] !== ''
                ? $this->filesystem->inputFile((string) $data['seed_input_path'])
                : null;
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $jobId = (string) Str::uuid();
        $mode = (string) ($data['mode'] ?? 'run-all');
        $outputDirectory = $this->filesystem->makeOutputDirectory($jobId);
        $configPath = $this->materializeRuntimeConfig($outputDirectory, $seedInput['absolute_path'] ?? null);
        $job = $this->jobStore->create([
            'job_id' => $jobId,
            'mode' => $mode,
            'input_path' => (string) $data['input_path'],
            'input_absolute_path' => $input['absolute_path'],
            'seed_input_path' => $seedInput['relative_path'] ?? null,
            'seed_input_absolute_path' => $seedInput['absolute_path'] ?? null,
            'config_path' => $configPath,
            'output_directory' => $outputDirectory,
            'log_path' => rtrim((string) config('company_enrichment.jobs_directory'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId . '.log',
            'snapshot' => [
                'status' => 'queued',
                'mode' => $mode,
                'currentPhase' => null,
                'progress' => 0,
                'phases' => $this->buildPhases($mode),
                'artifacts' => [],
                'logTail' => [],
                'error' => null,
                'warning' => null,
                'startedAt' => null,
                'completedAt' => null,
                'inputFile' => [
                    'name' => $input['name'],
                    'size' => $input['size'],
                    'path' => $input['relative_path'],
                    'modifiedAt' => $input['modified_at'],
                ],
                'seedFile' => $seedInput ? [
                    'name' => $seedInput['name'],
                    'size' => $seedInput['size'],
                    'path' => $seedInput['relative_path'],
                    'modifiedAt' => $seedInput['modified_at'],
                ] : null,
                'actor' => [
                    'id' => $actor->id,
                    'name' => $actor->full_name,
                ],
            ],
        ]);

        $this->launchRunnerProcess($jobId);

        return response()->json([
            'data' => $this->jobStore->sanitizeForApi($job),
        ], 202);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        try {
            $job = $this->jobStore->get($jobId);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response()->json([
            'data' => $this->jobStore->sanitizeForApi($job),
        ]);
    }

    public function cancel(Request $request, string $jobId): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        try {
            $job = $this->jobStore->requestCancel($jobId);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response()->json([
            'data' => $this->jobStore->sanitizeForApi($job),
        ]);
    }

    public function downloadArtifact(Request $request, string $jobId, string $artifactKey): BinaryFileResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        try {
            $job = $this->jobStore->get($jobId);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        $artifact = collect($this->filesystem->listArtifacts((string) ($job['output_directory'] ?? '')))
            ->firstWhere('key', $artifactKey);

        if (! is_array($artifact)) {
            abort(404, 'Artefact introuvable.');
        }

        $file = $this->filesystem->artifactForDownload((string) $job['output_directory'], (string) $artifact['relative_path']);

        return response()->download($file['absolute_path'], $file['name'], [
            'Content-Type' => $file['mime_type'],
        ]);
    }

    private function buildPhases(string $mode): array
    {
        $all = ['ingest', 'resolve_domains', 'crawl', 'score_emails', 'export_final'];

        $selected = match ($mode) {
            'ingest' => ['ingest'],
            'resolve-domains' => ['resolve_domains'],
            'crawl' => ['crawl'],
            'score-emails' => ['score_emails'],
            'export-final' => ['export_final'],
            default => $all,
        };

        return array_map(static fn (string $phase): array => [
            'key' => $phase,
            'status' => 'pending',
            'startedAt' => null,
            'completedAt' => null,
        ], $selected);
    }

    private function materializeRuntimeConfig(string $outputDirectory, ?string $seedCandidatesPath): string
    {
        $configPath = $this->filesystem->defaultConfigPath();
        if ($seedCandidatesPath === null || $seedCandidatesPath === '') {
            return $configPath;
        }

        $content = (string) File::get($configPath);
        if ($content === '') {
            throw new RuntimeException('Configuration YAML vide pour company enrichment.');
        }

        $escapedPath = "'" . str_replace("'", "''", $seedCandidatesPath) . "'";
        $replacementCount = 0;
        $updated = preg_replace_callback(
            '/^(\s*)seed_candidates_path:\s*.*$/m',
            static fn (array $matches): string => $matches[1] . 'seed_candidates_path: ' . $escapedPath,
            $content,
            1,
            $replacementCount
        );

        if (! is_string($updated)) {
            throw new RuntimeException('Impossible de generer la configuration runtime company enrichment.');
        }

        if ($replacementCount === 0 || ! str_contains($updated, $seedCandidatesPath)) {
            $updated .= PHP_EOL . 'domain_resolution:' . PHP_EOL . '  seed_candidates_path: ' . $escapedPath . PHP_EOL;
        }

        $runtimeConfigPath = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime.config.yaml';
        File::put($runtimeConfigPath, $updated);

        return $runtimeConfigPath;
    }

    private function launchRunnerProcess(string $jobId): void
    {
        if ((bool) config('company_enrichment.disable_process_launch', false)) {
            return;
        }

        $artisan = base_path('artisan');
        $phpBinary = (string) config('company_enrichment.php_binary', 'php');
        $workingDirectory = base_path();

        if (DIRECTORY_SEPARATOR === '\\') {
            $process = new Process([$phpBinary, $artisan, 'company-enrichment:run', $jobId], $workingDirectory);
            $process->disableOutput();
            $process->start();
            return;
        }

        $command = sprintf(
            'nohup %s %s company-enrichment:run %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($jobId)
        );

        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->disableOutput();
        $process->run();
    }
}
