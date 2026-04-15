<?php

namespace App\Services\CompanyEnrichment;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CompanyEnrichmentJobStore
{
    public function __construct(
        private readonly CompanyEnrichmentFilesystemService $filesystem,
    ) {
    }

    public function create(array $payload): array
    {
        $jobId = (string) ($payload['job_id'] ?? Str::uuid());
        $now = $this->timestamp();

        $job = array_merge([
            'job_id' => $jobId,
            'status' => 'queued',
            'mode' => 'run-all',
            'input_path' => '',
            'input_absolute_path' => '',
            'config_path' => '',
            'output_directory' => '',
            'log_path' => '',
            'cancel_requested' => false,
            'runner_pid' => null,
            'process_pid' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'snapshot' => $this->emptySnapshot(),
        ], $payload);

        $this->write($jobId, $job);

        return $job;
    }

    public function get(string $jobId): array
    {
        $path = $this->jobPath($jobId);

        if (! File::exists($path)) {
            throw new RuntimeException('Job company enrichment introuvable.');
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Etat du job company enrichment invalide.');
        }

        return $decoded;
    }

    public function put(string $jobId, array $job): array
    {
        $job['updated_at'] = $this->timestamp();
        $this->write($jobId, $job);

        return $job;
    }

    public function update(string $jobId, callable $mutator): array
    {
        $job = $this->get($jobId);
        $updated = $mutator($job);

        if (! is_array($updated)) {
            throw new RuntimeException('Le mutateur de job doit retourner un tableau.');
        }

        return $this->put($jobId, $updated);
    }

    public function requestCancel(string $jobId): array
    {
        return $this->update($jobId, function (array $job): array {
            $job['cancel_requested'] = true;

            if (($job['status'] ?? null) === 'queued') {
                $job['status'] = 'cancelled';
                $job['snapshot']['status'] = 'cancelled';
                $job['snapshot']['completedAt'] = $this->timestamp();
            }

            return $job;
        });
    }

    public function shouldCancel(string $jobId): bool
    {
        try {
            $job = $this->get($jobId);
        } catch (RuntimeException) {
            return true;
        }

        return (bool) ($job['cancel_requested'] ?? false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 15): array
    {
        return collect(File::glob($this->jobsDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [])
            ->map(function (string $path): ?array {
                $decoded = json_decode((string) File::get($path), true);

                return is_array($decoded) ? $this->sanitizeForApi($decoded) : null;
            })
            ->filter()
            ->sortByDesc(fn (array $job) => (int) ($job['updated_at'] ?? 0))
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    public function sanitizeForApi(array $job): array
    {
        $snapshot = $job['snapshot'] ?? [];
        $snapshot['logTail'] = $this->readLogTail((string) ($job['log_path'] ?? ''));
        $snapshot['artifacts'] = $this->filesystem->listArtifacts((string) ($job['output_directory'] ?? ''));
        $job['snapshot'] = $snapshot;

        unset($job['input_absolute_path'], $job['output_directory'], $job['log_path'], $job['runner_pid'], $job['process_pid']);

        return $job;
    }

    private function readLogTail(string $path, int $lineLimit = 80): array
    {
        if ($path === '' || ! File::exists($path)) {
            return [];
        }

        $content = (string) File::get($path);
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];

        return array_values(array_slice(array_filter($lines, static fn ($line) => $line !== ''), -$lineLimit));
    }

    private function write(string $jobId, array $job): void
    {
        $directory = $this->jobsDirectory();
        File::ensureDirectoryExists($directory);

        $path = $this->jobPath($jobId);
        $tempPath = $path . '.tmp';
        File::put($tempPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::move($tempPath, $path);
    }

    private function jobPath(string $jobId): string
    {
        return rtrim($this->jobsDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    private function jobsDirectory(): string
    {
        return (string) config('company_enrichment.jobs_directory', storage_path('app/private/prospecting/enrichment/jobs'));
    }

    private function timestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function emptySnapshot(): array
    {
        return [
            'status' => 'idle',
            'mode' => 'run-all',
            'currentPhase' => null,
            'progress' => 0,
            'phases' => [],
            'artifacts' => [],
            'logTail' => [],
            'error' => null,
            'warning' => null,
            'startedAt' => null,
            'completedAt' => null,
        ];
    }
}
