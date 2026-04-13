<?php

namespace App\Services\CsvExplorer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CsvExplorerJobStore
{
    public function create(array $payload): array
    {
        $jobId = (string) Str::uuid();
        $now = $this->timestamp();

        $job = array_merge([
            'job_id' => $jobId,
            'status' => 'queued',
            'snapshot' => $this->emptySnapshot(),
            'file_path' => '',
            'server_path' => '',
            'cancel_requested' => false,
            'config' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ], $payload);

        $this->write($jobId, $job);

        return $job;
    }

    public function get(string $jobId): array
    {
        $path = $this->jobPath($jobId);

        if (! File::exists($path)) {
            throw new RuntimeException('Job CSV Explorer introuvable.');
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Etat du job CSV Explorer invalide.');
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

    public function sanitizeForApi(array $job): array
    {
        unset($job['server_path'], $job['config']);

        return $job;
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
        return (string) config('csv_explorer.jobs_directory', storage_path('app/private/csv-explorer/jobs'));
    }

    private function timestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function emptySnapshot(): array
    {
        return [
            'file' => null,
            'status' => 'idle',
            'headers' => [],
            'previewRows' => [],
            'recentRows' => [],
            'delimiter' => null,
            'totalRowsRead' => 0,
            'bytesProcessed' => 0,
            'progress' => 0,
            'invalidRowCount' => 0,
            'issues' => [],
            'warning' => null,
            'error' => null,
            'startedAt' => null,
            'completedAt' => null,
        ];
    }
}
