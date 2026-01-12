<?php

namespace App\Http\Controllers;

use App\Http\Resources\HelixUserResource;
use App\Models\HelixUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HelixUserController extends Controller
{
    public function index(Request $request)
    {
        $query = HelixUser::query();

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = $request->string('search')->trim()) {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->orderBy('first_name')->orderBy('last_name');

        if ($request->boolean('paginate', false)) {
            $perPage = (int) $request->input('per_page', 20);

            return HelixUserResource::collection($query->paginate($perPage));
        }

        return HelixUserResource::collection($query->get());
    }

    public function store(Request $request): HelixUserResource
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('helix_users', 'email')->whereNull('deleted_at'),
            ],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['owner', 'manager', 'technician', 'frontdesk'])],
            'phone' => ['nullable', 'string', 'max:25'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = HelixUser::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            'color' => $data['color'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return new HelixUserResource($user);
    }

    public function update(Request $request, HelixUser $user): HelixUserResource
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'email' => [
                'sometimes',
                'email',
                'max:190',
                Rule::unique('helix_users', 'email')
                    ->ignore($user->id)
                    ->whereNull('deleted_at'),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(['owner', 'manager', 'technician', 'frontdesk'])],
            'phone' => ['nullable', 'string', 'max:25'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('password', $data) && $data['password']) {
            $user->password_hash = Hash::make($data['password']);
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        return new HelixUserResource($user->refresh());
    }

    public function destroy(HelixUser $user): JsonResponse
    {
        $user->is_active = false;
        $user->email = $this->anonymisedEmail($user->email);
        $user->save();
        $user->delete();

        return response()->json([], 204);
    }

    protected function anonymisedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, 'local');
        $suffix = Str::uuid()->toString();

        return sprintf('%s+deleted-%s@%s', $local, $suffix, $domain);
    }
}
