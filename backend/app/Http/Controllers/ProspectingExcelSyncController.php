<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Services\Prospecting\ExcelMirrorSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProspectingExcelSyncController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly ExcelMirrorSyncService $excelMirrorSyncService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['import', 'export', 'resync'])],
            'file_path' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:120'],
        ]);

        $job = $this->excelMirrorSyncService->sync($data, $actor);

        return response()->json([
            'data' => $job->toArray(),
        ], 201);
    }
}
