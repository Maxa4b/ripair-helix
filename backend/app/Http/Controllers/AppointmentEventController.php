<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentEventResource;
use App\Models\Appointment;

class AppointmentEventController extends Controller
{
    public function index(Appointment $appointment)
    {
        $events = $appointment->events()->with('author')->orderByDesc('created_at')->get();

        return AppointmentEventResource::collection($events);
    }
}
