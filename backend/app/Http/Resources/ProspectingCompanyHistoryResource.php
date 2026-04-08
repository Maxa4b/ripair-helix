<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Prospecting\CompanyContactHistory
 */
class ProspectingCompanyHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_status' => $this->previous_status,
            'new_status' => $this->new_status,
            'previous_owner' => $this->previous_owner,
            'new_owner' => $this->new_owner,
            'previous_notes' => $this->previous_notes,
            'new_notes' => $this->new_notes,
            'source' => $this->source,
            'change_note' => $this->change_note,
            'changed_by' => $this->changed_by,
            'changed_by_name' => $this->changed_by_name,
            'changed_at' => optional($this->changed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
