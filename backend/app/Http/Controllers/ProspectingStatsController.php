<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Models\Prospecting\Company;
use App\Services\Prospecting\CompanyQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProspectingStatsController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CompanyQueryService $companyQueryService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $filters = $request->only([
            'status',
            'segment',
            'q',
            'zone',
            'contact_owner',
            'missing_contact',
            'include_disabled',
        ]);

        $cacheKey = 'prospecting_stats:' . md5(json_encode($filters));

        $payload = Cache::remember($cacheKey, now()->addSeconds(20), function () use ($filters): array {
            $query = Company::query();
            $this->companyQueryService->applyFilters($query, $filters);

            $base = clone $query;
            $total = (clone $base)->count();
            $nonContacte = (clone $base)->where('contact_status', 'non_contacte')->count();
            $inProgress = (clone $base)->where('contact_status', 'en_cours_de_contact')->count();
            $contacte = (clone $base)->where('contact_status', 'contacte')->count();
            $withCoordinates = (clone $base)->whereNotNull('lat')->whereNotNull('lng')->count();
            $missingContacts = (clone $base)->where(function ($inner): void {
                $inner->whereNull('email')
                    ->orWhere('email', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '');
            })->count();
            $coverageRate = $total > 0 ? round((($inProgress + $contacte) / $total) * 100, 2) : 0.0;

            return [
                'total' => $total,
                'non_contacte' => $nonContacte,
                'en_cours_de_contact' => $inProgress,
                'contacte' => $contacte,
                'with_coordinates' => $withCoordinates,
                'missing_contacts' => $missingContacts,
                'coverage_rate' => $coverageRate,
            ];
        });

        return response()->json([
            'data' => $payload,
        ]);
    }
}
