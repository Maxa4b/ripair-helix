<?php

namespace App\Http\Controllers;

use App\Exceptions\ConcurrentWriteException;
use App\Http\Controllers\Concerns\EnsuresHelixRoleAccess;
use App\Http\Resources\ProspectingCompanyResource;
use App\Models\Prospecting\Company;
use App\Services\Prospecting\CompanyMutationService;
use App\Services\Prospecting\CompanyQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProspectingCompanyController extends Controller
{
    use EnsuresHelixRoleAccess;

    public function __construct(
        private readonly CompanyQueryService $companyQueryService,
        private readonly CompanyMutationService $companyMutationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $query = Company::query();
        $this->companyQueryService->applyFilters($query, $request->only([
            'bounds',
            'status',
            'segment',
            'q',
            'zone',
            'contact_owner',
            'missing_contact',
            'only_geocoded',
            'include_disabled',
        ]));

        $query->orderByDesc('relevance_score')->orderBy('name');

        $total = (clone $query)->count();
        $limit = max(1, min((int) $request->input('limit', 1500), 2500));
        $companies = $query->limit($limit)->get();

        return response()->json([
            'data' => ProspectingCompanyResource::collection($companies)->resolve(),
            'meta' => [
                'total' => $total,
                'returned' => $companies->count(),
                'limit' => $limit,
                'bounds' => $this->companyQueryService->parseBounds($request->input('bounds')),
            ],
        ]);
    }

    public function show(Request $request, Company $company): ProspectingCompanyResource
    {
        $this->ensureHelixRoles($request, ['owner', 'manager']);

        $company->load([
            'history' => fn ($query) => $query->limit(30),
        ]);

        return new ProspectingCompanyResource($company);
    }

    public function updateStatus(Request $request, Company $company): JsonResponse|ProspectingCompanyResource
    {
        $actor = $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'contact_status' => ['required', Rule::in(['non_contacte', 'en_cours_de_contact', 'contacte'])],
            'contact_owner' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
            'is_disabled' => ['nullable', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return new ProspectingCompanyResource(
                $this->companyMutationService->mutate(
                    $company,
                    [
                        'contact_status' => $data['contact_status'],
                        'contact_owner' => $data['contact_owner'] ?? $company->contact_owner,
                        'notes' => $data['notes'] ?? $company->notes,
                    ],
                    $actor,
                    'ui',
                    'Changement de statut rapide',
                    (int) $data['version']
                )
            );
        } catch (ConcurrentWriteException) {
            return response()->json([
                'message' => 'Conflit de mise a jour. Rechargez les donnees avant de reessayer.',
            ], 409);
        }
    }

    public function update(Request $request, Company $company): JsonResponse|ProspectingCompanyResource
    {
        $actor = $this->ensureHelixRoles($request, ['owner', 'manager']);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'segment' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'google_place_id' => ['nullable', 'string', 'max:190'],
            'relevance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contact_status' => ['nullable', Rule::in(['non_contacte', 'en_cours_de_contact', 'contacte'])],
            'contact_owner' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
            'is_disabled' => ['nullable', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $expectedVersion = (int) $data['version'];
        unset($data['version']);

        try {
            return new ProspectingCompanyResource(
                $this->companyMutationService->mutate(
                    $company,
                    $data,
                    $actor,
                    'ui',
                    'Edition detail entreprise',
                    $expectedVersion
                )
            );
        } catch (ConcurrentWriteException) {
            return response()->json([
                'message' => 'Conflit de mise a jour. Rechargez les donnees avant de reessayer.',
            ], 409);
        }
    }
}
