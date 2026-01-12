<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\HelixAppointmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Appointment::query()->with('assignedUser');

        if ($start = $request->input('start')) {
            $query->where('start_datetime', '>=', Carbon::parse($start));
        }

        if ($end = $request->input('end')) {
            $query->where('end_datetime', '<=', Carbon::parse($end));
        }

        if ($status = $request->input('status')) {
            $statuses = is_array($status) ? $status : explode(',', $status);
            $query->whereIn('status', $statuses);
        }

        if ($assigned = $request->input('assigned_user_id')) {
            $query->where('assigned_user_id', $assigned);
        }

        if ($store = $request->input('store_code')) {
            $query->where('store_code', $store);
        }

        if ($search = $request->string('search')->trim()) {
            $query->where(function ($q) use ($search): void {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $query->orderBy('start_datetime');

        if ($request->boolean('paginate', true)) {
            $perPage = (int) $request->input('per_page', 25);

            return AppointmentResource::collection($query->paginate($perPage));
        }

        return AppointmentResource::collection($query->get());
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $appointment->load(['assignedUser', 'notes.author', 'events.author']);

        return new AppointmentResource($appointment);
    }

    public function store(Request $request): AppointmentResource
    {
        $data = $request->validate([
            'service_label' => ['required', 'string', 'max:255'],
            'duration_min' => ['nullable', 'integer', 'min:5'],
            'start_datetime' => ['required', 'date_format:c'],
            'customer.name' => ['required', 'string', 'max:200'],
            'customer.email' => ['nullable', 'email', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'price_estimate_cents' => ['nullable', 'integer', 'min:0'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'assigned_user_id' => ['nullable', 'exists:helix_users,id'],
            'store_code' => ['nullable', 'string', 'max:20'],
            'internal_notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['booked', 'confirmed', 'in_progress', 'done', 'cancelled', 'no_show'])],
            'meta' => ['nullable', 'array'],
        ]);

        $start = Carbon::parse($data['start_datetime']);
        $duration = $data['duration_min'] ?? 60;
        $end = (clone $start)->addMinutes($duration);

        $appointment = Appointment::create([
            'service_label' => $data['service_label'],
            'duration_min' => $duration,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'customer_name' => data_get($data, 'customer.name'),
            'customer_email' => data_get($data, 'customer.email'),
            'customer_phone' => data_get($data, 'customer.phone'),
            'customer_address' => data_get($data, 'customer.address'),
            'price_estimate_cents' => $data['price_estimate_cents'] ?? null,
            'discount_pct' => $data['discount_pct'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'store_code' => $data['store_code'] ?? 'CESTAS',
            'internal_notes' => $data['internal_notes'] ?? null,
            'status' => $data['status'] ?? 'booked',
            'source' => 'manual',
            'meta' => $data['meta'] ?? null,
        ]);

        HelixAppointmentEvent::create([
            'appointment_id' => $appointment->id,
            'type' => 'status_change',
            'payload' => [
                'from' => null,
                'to' => $appointment->status,
            ],
            'author_id' => $request->user()->id,
        ]);

        return new AppointmentResource($appointment->load('assignedUser'));
    }

    public function update(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate([
            'service_label' => ['sometimes', 'string', 'max:255'],
            'duration_min' => ['sometimes', 'integer', 'min:5'],
            'start_datetime' => ['sometimes', 'date_format:c'],
            'status' => ['sometimes', Rule::in(['booked', 'confirmed', 'in_progress', 'done', 'cancelled', 'no_show'])],
            'assigned_user_id' => ['nullable', 'exists:helix_users,id'],
            'price_estimate_cents' => ['nullable', 'integer', 'min:0'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'store_code' => ['nullable', 'string', 'max:20'],
            'internal_notes' => ['nullable', 'string'],
            'customer.name' => ['nullable', 'string', 'max:200'],
            'customer.email' => ['nullable', 'email', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $beforeStatus = $appointment->status;

        if (array_key_exists('duration_min', $data) || array_key_exists('start_datetime', $data)) {
            $duration = $data['duration_min'] ?? $appointment->duration_min;
            $start = isset($data['start_datetime'])
                ? Carbon::parse($data['start_datetime'])
                : Carbon::parse($appointment->start_datetime);

            $appointment->start_datetime = $start;
            $appointment->duration_min = $duration;
            $appointment->end_datetime = (clone $start)->addMinutes($duration);
        }

        if (isset($data['customer'])) {
            $appointment->customer_name = data_get($data, 'customer.name', $appointment->customer_name);
            $appointment->customer_email = data_get($data, 'customer.email', $appointment->customer_email);
            $appointment->customer_phone = data_get($data, 'customer.phone', $appointment->customer_phone);
            $appointment->customer_address = data_get($data, 'customer.address', $appointment->customer_address);
        }

        unset($data['customer']);

        $appointment->fill($data);
        $appointment->save();

        if ($beforeStatus !== $appointment->status) {
            HelixAppointmentEvent::create([
                'appointment_id' => $appointment->id,
                'type' => 'status_change',
                'payload' => [
                    'from' => $beforeStatus,
                    'to' => $appointment->status,
                ],
                'author_id' => $request->user()->id,
            ]);
        }

        return new AppointmentResource(
            $appointment->load(['assignedUser', 'notes.author', 'events.author'])
        );
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->update([
            'status' => 'cancelled',
            'status_updated_at' => now(),
        ]);

        return response()->json([], 204);
    }
}
