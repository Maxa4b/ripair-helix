<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\HelixAvailabilityBlock
 */
class AvailabilityBlockResource extends JsonResource
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
            'type' => $this->type,
            'title' => $this->title,
            'start_datetime' => optional($this->start_datetime)->toIso8601String(),
            'end_datetime' => optional($this->end_datetime)->toIso8601String(),
            'recurrence_rule' => $this->recurrence_rule,
            'recurrence_until' => optional($this->recurrence_until)->toIso8601String(),
            'color' => $this->color,
            'notes' => $this->notes,
            'created_by' => new HelixUserResource($this->whenLoaded('author')),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
