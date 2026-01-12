<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\HelixAppointmentEvent
 */
class AppointmentEventResource extends JsonResource
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
            'type' => $this->type,
            'payload' => $this->payload,
            'author' => new HelixUserResource($this->whenLoaded('author')),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
