<?php

namespace App\Console\Commands;

use App\Services\CsvExplorer\CsvExplorerJobStore;
use Illuminate\Console\Command;
use SplFileObject;
use Throwable;

class CsvExplorerScanCommand extends Command
{
    protected $signature = 'csv-explorer:scan {jobId : Identifiant du job CSV Explorer}';

    protected $description = 'Scanne un gros CSV du VPS en arriere-plan pour CSV Explorer.';

    private const PREVIEW_LIMIT = 1500;
    private const RECENT_LIMIT = 4000;
    private const ISSUE_LIMIT = 24;
    private const FLUSH_EVERY_ROWS = 2000;

    public function handle(CsvExplorerJobStore $jobStore): int
    {
        $jobId = (string) $this->argument('jobId');

        try {
            $job = $jobStore->get($jobId);
            $job['status'] = 'reading';
            $job['snapshot']['status'] = 'reading';
            $job['snapshot']['startedAt'] = $job['snapshot']['startedAt'] ?? $this->timestamp();
            $jobStore->put($jobId, $job);

            $path = (string) ($job['server_path'] ?? '');
            if ($path === '' || ! is_file($path)) {
                throw new \RuntimeException('Fichier CSV introuvable sur le serveur.');
            }

            $delimiter = $this->resolveDelimiter($path, (string) ($job['config']['delimiter'] ?? 'auto'));
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $file->setCsvControl($delimiter);

            $headers = [];
            $previewRows = [];
            $recentRows = [];
            $issues = [];
            $rowId = 0;
            $invalidRowCount = 0;
            $totalRowsRead = 0;
            $bytesProcessed = 0;
            $headerResolved = false;
            $size = max(1, filesize($path) ?: 1);

            foreach ($file as $row) {
                $bytesProcessed = max($bytesProcessed, $file->ftell());

                if (! is_array($row) || ! $this->hasAnyCell($row)) {
                    continue;
                }

                $values = array_map(static fn ($value) => is_scalar($value) ? trim((string) $value) : '', $row);

                if (! $headerResolved) {
                    $headers = $this->sanitizeHeaders($values);
                    $headerResolved = true;
                    continue;
                }

                if (count($values) !== count($headers)) {
                    $invalidRowCount++;
                    $issues[] = [
                        'id' => sprintf('shape-%d-%d', $rowId, count($values)),
                        'level' => 'warning',
                        'code' => count($values) > count($headers) ? 'extra_columns' : 'missing_columns',
                        'row' => $rowId + 2,
                        'message' => sprintf(
                            'La ligne %d contient %d colonnes pour %d headers.',
                            $rowId + 2,
                            count($values),
                            count($headers)
                        ),
                    ];
                    $issues = array_slice($issues, -self::ISSUE_LIMIT);
                }

                $normalized = array_slice($values, 0, count($headers));
                while (count($normalized) < count($headers)) {
                    $normalized[] = '';
                }

                $normalizedRow = [
                    'id' => $rowId,
                    'rowNumber' => $rowId,
                    'values' => $normalized,
                ];

                if (count($previewRows) < self::PREVIEW_LIMIT) {
                    $previewRows[] = $normalizedRow;
                }

                $recentRows[] = $normalizedRow;
                if (count($recentRows) > self::RECENT_LIMIT) {
                    $recentRows = array_slice($recentRows, -self::RECENT_LIMIT);
                }

                $rowId++;
                $totalRowsRead++;

                if ($totalRowsRead % self::FLUSH_EVERY_ROWS === 0) {
                    if ($jobStore->shouldCancel($jobId)) {
                        $this->persistCancelled($jobStore, $jobId, $job, $headers, $previewRows, $recentRows, $delimiter, $totalRowsRead, $bytesProcessed, $invalidRowCount, $issues, $size);
                        return self::SUCCESS;
                    }

                    $job = $this->persistProgress($jobStore, $jobId, $job, $headers, $previewRows, $recentRows, $delimiter, $totalRowsRead, $bytesProcessed, $invalidRowCount, $issues, $size);
                }
            }

            if ($jobStore->shouldCancel($jobId)) {
                $this->persistCancelled($jobStore, $jobId, $job, $headers, $previewRows, $recentRows, $delimiter, $totalRowsRead, $bytesProcessed, $invalidRowCount, $issues, $size);
                return self::SUCCESS;
            }

            $job['status'] = 'completed';
            $job['snapshot'] = $this->buildSnapshot(
                $job,
                'ready',
                $headers,
                $previewRows,
                $recentRows,
                $delimiter,
                $totalRowsRead,
                $bytesProcessed,
                $invalidRowCount,
                $issues,
                $size
            );
            $job['snapshot']['progress'] = 1;
            $job['snapshot']['bytesProcessed'] = $size;
            $job['snapshot']['completedAt'] = $this->timestamp();
            $jobStore->put($jobId, $job);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            try {
                $job = $jobStore->get($jobId);
                $job['status'] = 'error';
                $job['snapshot']['status'] = 'error';
                $job['snapshot']['error'] = $throwable->getMessage();
                $job['snapshot']['completedAt'] = $this->timestamp();
                $jobStore->put($jobId, $job);
            } catch (Throwable) {
            }

            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function persistProgress(
        CsvExplorerJobStore $jobStore,
        string $jobId,
        array $job,
        array $headers,
        array $previewRows,
        array $recentRows,
        string $delimiter,
        int $totalRowsRead,
        int $bytesProcessed,
        int $invalidRowCount,
        array $issues,
        int $fileSize,
    ): array {
        $job['status'] = 'reading';
        $job['snapshot'] = $this->buildSnapshot(
            $job,
            'reading',
            $headers,
            $previewRows,
            $recentRows,
            $delimiter,
            $totalRowsRead,
            $bytesProcessed,
            $invalidRowCount,
            $issues,
            $fileSize
        );

        return $jobStore->put($jobId, $job);
    }

    private function persistCancelled(
        CsvExplorerJobStore $jobStore,
        string $jobId,
        array $job,
        array $headers,
        array $previewRows,
        array $recentRows,
        string $delimiter,
        int $totalRowsRead,
        int $bytesProcessed,
        int $invalidRowCount,
        array $issues,
        int $fileSize,
    ): void {
        $job['status'] = 'cancelled';
        $job['snapshot'] = $this->buildSnapshot(
            $job,
            'cancelled',
            $headers,
            $previewRows,
            $recentRows,
            $delimiter,
            $totalRowsRead,
            $bytesProcessed,
            $invalidRowCount,
            $issues,
            $fileSize
        );
        $job['snapshot']['completedAt'] = $this->timestamp();

        $jobStore->put($jobId, $job);
    }

    private function buildSnapshot(
        array $job,
        string $status,
        array $headers,
        array $previewRows,
        array $recentRows,
        string $delimiter,
        int $totalRowsRead,
        int $bytesProcessed,
        int $invalidRowCount,
        array $issues,
        int $fileSize,
    ): array {
        return [
            'file' => $job['snapshot']['file'],
            'status' => $status,
            'headers' => $headers,
            'previewRows' => $previewRows,
            'recentRows' => $recentRows,
            'delimiter' => $delimiter,
            'totalRowsRead' => $totalRowsRead,
            'bytesProcessed' => min($fileSize, $bytesProcessed),
            'progress' => min(1, max(0, $bytesProcessed / max(1, $fileSize))),
            'invalidRowCount' => $invalidRowCount,
            'issues' => array_slice($issues, -self::ISSUE_LIMIT),
            'warning' => null,
            'error' => null,
            'startedAt' => $job['snapshot']['startedAt'] ?? $this->timestamp(),
            'completedAt' => null,
        ];
    }

    private function resolveDelimiter(string $path, string $preferred): string
    {
        if ($preferred !== 'auto') {
            return $preferred === '\t' ? "\t" : $preferred;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ';';
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $candidates = [',', ';', "\t", '|'];
                $scores = [];

                foreach ($candidates as $candidate) {
                    $scores[$candidate] = substr_count($line, $candidate);
                }

                arsort($scores);

                return (string) array_key_first($scores);
            }
        } finally {
            fclose($handle);
        }

        return ';';
    }

    private function sanitizeHeaders(array $headers): array
    {
        $counts = [];

        return array_map(function ($value, $index) use (&$counts) {
            $normalized = trim((string) $value);
            $normalized = $normalized !== '' ? $normalized : 'column_' . ($index + 1);
            $count = $counts[$normalized] ?? 0;
            $counts[$normalized] = $count + 1;

            return $count === 0 ? $normalized : sprintf('%s_%d', $normalized, $count + 1);
        }, $headers, array_keys($headers));
    }

    private function hasAnyCell(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    private function timestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
