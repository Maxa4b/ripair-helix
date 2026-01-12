<?php

namespace App\Http\Controllers;

use App\Http\Resources\AvailabilityBlockResource;
use App\Models\HelixAvailabilityBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AvailabilityBlockController extends Controller
{
    public function index(Request $request)
    {
        $query = HelixAvailabilityBlock::query()->with('author');

        if ($start = $request->input('start')) {
            $query->where('end_datetime', '>=', Carbon::parse($start));
        }

        if ($end = $request->input('end')) {
            $query->where('start_datetime', '<=', Carbon::parse($end));
        }

        if ($type = $request->input('type')) {
            $types = is_array($type) ? $type : explode(',', $type);
            $query->whereIn('type', $types);
        }

        $query->orderBy('start_datetime');

        return AvailabilityBlockResource::collection($query->get());
    }

    public function store(Request $request): AvailabilityBlockResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['open', 'closed', 'maintenance', 'offsite'])],
            'title' => ['nullable', 'string', 'max:140'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'recurrence_rule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date', 'after_or_equal:start_datetime'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string'],
        ]);

        $block = HelixAvailabilityBlock::create([
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'start_datetime' => Carbon::parse($data['start_datetime']),
            'end_datetime' => Carbon::parse($data['end_datetime']),
            'recurrence_rule' => $data['recurrence_rule'] ?? null,
            'recurrence_until' => isset($data['recurrence_until']) ? Carbon::parse($data['recurrence_until']) : null,
            'color' => $data['color'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return new AvailabilityBlockResource($block->load('author'));
    }

    public function update(Request $request, HelixAvailabilityBlock $availabilityBlock): AvailabilityBlockResource
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['open', 'closed', 'maintenance', 'offsite'])],
            'title' => ['nullable', 'string', 'max:140'],
            'start_datetime' => ['sometimes', 'date'],
            'end_datetime' => ['sometimes', 'date'],
            'recurrence_rule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string'],
        ]);

        if (isset($data['start_datetime'])) {
            $availabilityBlock->start_datetime = Carbon::parse($data['start_datetime']);
        }

        if (isset($data['end_datetime'])) {
            $availabilityBlock->end_datetime = Carbon::parse($data['end_datetime']);
        }

        if (isset($data['recurrence_until'])) {
            $availabilityBlock->recurrence_until = $data['recurrence_until']
                ? Carbon::parse($data['recurrence_until'])
                : null;
        }

        $availabilityBlock->fill(array_diff_key($data, array_flip(['start_datetime', 'end_datetime', 'recurrence_until'])));
        $availabilityBlock->save();

        return new AvailabilityBlockResource($availabilityBlock->load('author'));
    }

    public function destroy(HelixAvailabilityBlock $availabilityBlock): JsonResponse
    {
        $availabilityBlock->delete();

        return response()->json([], 204);
    }
}
