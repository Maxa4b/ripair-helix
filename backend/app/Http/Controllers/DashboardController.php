<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = Carbon::now();
        $endOfWeek = $now->copy()->endOfWeek();

        $nextAppointments = Appointment::query()
            ->where('start_datetime', '>=', $now)
            ->whereIn('status', ['booked', 'confirmed', 'in_progress'])
            ->orderBy('start_datetime')
            ->limit(5)
            ->get();

        $counts = [
            'today' => Appointment::whereDate('start_datetime', $now->toDateString())->count(),
            'week' => Appointment::whereBetween('start_datetime', [$now->copy()->startOfWeek(), $endOfWeek])->count(),
            'pending_confirmation' => Appointment::where('status', 'booked')->count(),
            'cancel_pending' => Appointment::where('status', 'cancelled')
                ->whereNull('cancel_token_used_at')
                ->count(),
        ];

        return response()->json([
            'next_appointments' => AppointmentResource::collection($nextAppointments),
            'counts' => $counts,
        ]);
    }
}
