<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Services\Prospecting\CompanyImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProspectingImportController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CompanyImportService $companyImportService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:120'],
            'segment' => ['nullable', 'string', 'max:120'],
            'sheet_name' => ['nullable', 'string', 'max:120'],
            'file_path' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'mimes:csv,xlsx'],
        ]);

        $filePath = $data['file_path'] ?? null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $folder = storage_path('app/private/prospecting/imports');
            File::ensureDirectoryExists($folder);
            $filename = now()->format('Ymd_His') . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            $file->move($folder, $filename);
            $filePath = $folder . DIRECTORY_SEPARATOR . $filename;
        }

        if (! is_string($filePath) || $filePath === '') {
            return response()->json(['message' => 'Aucun fichier d\'import fourni.'], 422);
        }

        $job = $this->companyImportService->importFromPath($filePath, [
            'source' => $data['source'] ?? null,
            'segment' => $data['segment'] ?? null,
            'sheet_name' => $data['sheet_name'] ?? null,
        ], $actor);

        return response()->json([
            'data' => $job->toArray(),
        ], 201);
    }
}
