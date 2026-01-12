<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\HelixAppointmentNote
 */
class AppointmentNoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'author' => new HelixUserResource($this->whenLoaded('author')),
            'body' => $this->body,
            'visibility' => $this->visibility,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
