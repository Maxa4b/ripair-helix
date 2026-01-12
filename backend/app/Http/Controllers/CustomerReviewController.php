<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerReviewResource;
use App\Models\CustomerReview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerReviewController extends Controller
{
    protected function ensureCanModerate(Request $request): void
    {
        $user = $request->user();
        $role = is_object($user) ? ($user->role ?? null) : null;
        if (!in_array($role, ['owner', 'manager'], true)) {
            abort(403, 'Forbidden');
        }
    }

    public function index(Request $request)
    {
        $this->ensureCanModerate($request);

        $query = CustomerReview::query();

        $status = $request->string('status')->trim()->toString();
        if ($status === '') {
            $status = 'pending';
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->trim()) {
            $query->where(function ($q) use ($search): void {
                $q->where('comment', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('created_at');

        if ($request->boolean('paginate', false)) {
            $perPage = (int) $request->input('per_page', 25);
            return CustomerReviewResource::collection($query->paginate($perPage));
        }

        $limit = (int) $request->input('limit', 200);
        $limit = max(1, min(500, $limit));

        return CustomerReviewResource::collection($query->limit($limit)->get());
    }

    public function update(Request $request, CustomerReview $review): CustomerReviewResource
    {
        $this->ensureCanModerate($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        $review->status = $data['status'];
        $review->admin_note = $data['admin_note'] ?? null;
        $review->moderated_at = now();
        $review->moderated_by = $request->user()->id;
        $review->save();

        return new CustomerReviewResource($review->refresh());
    }
}

