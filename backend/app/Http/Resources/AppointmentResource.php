<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Appointment
 */
class AppointmentResource extends JsonResource
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
            'service_label' => $this->service_label,
            'duration_min' => $this->duration_min,
            'start_datetime' => optional($this->start_datetime)->toIso8601String(),
            'end_datetime' => optional($this->end_datetime)->toIso8601String(),
            'status' => $this->status,
            'status_updated_at' => optional($this->status_updated_at)->toIso8601String(),
            'assigned_user' => new HelixUserResource($this->whenLoaded('assignedUser')),
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
                'address' => $this->customer_address,
            ],
            'store_code' => $this->store_code,
            'price_estimate_cents' => $this->price_estimate_cents,
            'discount_pct' => $this->discount_pct,
            'source' => $this->source,
            'internal_notes' => $this->internal_notes,
            'meta' => $this->meta,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'notes' => AppointmentNoteResource::collection($this->whenLoaded('notes')),
            'events' => AppointmentEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
