<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Services\CsvExplorer\CsvExplorerFilesystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExplorerController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CsvExplorerFilesystemService $filesystem,
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
}
