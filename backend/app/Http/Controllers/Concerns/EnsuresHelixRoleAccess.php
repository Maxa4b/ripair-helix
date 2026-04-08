<?php

namespace App\Http\Controllers\Concerns;

use App\Models\HelixUser;
use Illuminate\Http\Request;

trait EnsuresHelixRoleAccess
{
    protected function ensureHelixRoles(Request $request, array $allowedRoles): HelixUser
    {
        $user = $request->user();
        $role = is_object($user) ? ($user->role ?? null) : null;

        if (! $user instanceof HelixUser || ! in_array($role, $allowedRoles, true)) {
            abort(403, 'Forbidden');
        }

        return $user;
    }
}
