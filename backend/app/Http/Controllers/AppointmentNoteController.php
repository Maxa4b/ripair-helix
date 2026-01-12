<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentNoteResource;
use App\Models\Appointment;
use App\Models\HelixAppointmentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentNoteController extends Controller
{
    public function index(Appointment $appointment)
    {
        $notes = $appointment->notes()->with('author')->orderByDesc('created_at')->get();

        return AppointmentNoteResource::collection($notes);
    }

    public function store(Request $request, Appointment $appointment): AppointmentNoteResource
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'visibility' => ['nullable', Rule::in(['internal', 'technician', 'public'])],
        ]);

        $note = HelixAppointmentNote::create([
            'appointment_id' => $appointment->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'visibility' => $data['visibility'] ?? 'internal',
        ]);

        return new AppointmentNoteResource($note->load('author'));
    }

    public function destroy(Appointment $appointment, HelixAppointmentNote $note): JsonResponse
    {
        abort_unless($note->appointment_id === $appointment->id, 404);

        $note->delete();

        return response()->json([], 204);
    }
}
