<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Services\CsvExplorer\CsvExplorerFilesystemService;
use App\Services\CsvExplorer\CsvExplorerJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExplorerController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CsvExplorerFilesystemService $filesystem,
        private readonly CsvExplorerJobStore $jobStore,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $listing = $this->filesystem->list((string) ($data['path'] ?? ''));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'data' => $listing,
        ]);
    }

    public function stream(Request $request): StreamedResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $file = $this->filesystem->fileForStream((string) $data['path']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->stream(function () use ($file): void {
            $handle = fopen($file['absolute_path'], 'rb');

            if ($handle === false) {
                throw new \RuntimeException('Impossible d ouvrir le fichier CSV.');
            }

            try {
                while (! feof($handle)) {
                    $chunk = fread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                    @ob_flush();
                    @flush();
                }
            } finally {
                fclose($handle);
            }
        }, 200, [
            'Content-Type' => $file['mime_type'],
            'Content-Length' => (string) $file['size'],
            'Content-Disposition' => 'inline; filename="' . addslashes($file['name']) . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function startJob(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'encoding' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $file = $this->filesystem->fileForStream((string) $data['path']);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $job = $this->jobStore->create([
            'file_path' => (string) $data['path'],
            'server_path' => $file['absolute_path'],
            'snapshot' => [
                'file' => [
                    'name' => $file['name'],
                    'size' => $file['size'],
                    'type' => $file['mime_type'],
                    'lastModified' => strtotime((string) $file['modified_at']) * 1000,
                    'source' => 'remote',
                    'path' => (string) $data['path'],
                ],
                'status' => 'analyzing',
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
                'startedAt' => (int) round(microtime(true) * 1000),
                'completedAt' => null,
            ],
            'config' => [
                'delimiter' => $data['delimiter'] ?? 'auto',
                'encoding' => $data['encoding'] ?? 'utf-8',
            ],
        ]);

        $this->launchScanProcess($job['job_id']);

        return response()->json([
            'data' => $this->jobStore->sanitizeForApi($job),
        ], 202);
    }

    public function showJob(Request $request, string $jobId): JsonResponse
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

    public function cancelJob(Request $request, string $jobId): JsonResponse
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

    private function launchScanProcess(string $jobId): void
    {
        $artisan = base_path('artisan');
        $phpBinary = (string) config('csv_explorer.php_binary', 'php');
        $workingDirectory = base_path();

        if (DIRECTORY_SEPARATOR === '\\') {
            $process = new Process([$phpBinary, $artisan, 'csv-explorer:scan', $jobId], $workingDirectory);
            $process->disableOutput();
            $process->start();
            return;
        }

        $command = sprintf(
            'nohup %s %s csv-explorer:scan %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($jobId)
        );

        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->disableOutput();
        $process->run();
    }
}
