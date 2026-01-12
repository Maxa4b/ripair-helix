<?php

namespace App\Http\Controllers;

use App\Http\Resources\HelixUserResource;
use App\Models\HelixUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle an authentication attempt.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var ?HelixUser $user */
        $user = HelixUser::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            abort(423, 'Account disabled');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('helix-api')->plainTextToken;

        return response()->json([
            'user' => new HelixUserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Destroy the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        return response()->json()->setStatusCode(204);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): HelixUserResource
    {
        return new HelixUserResource($request->user());
    }
}
